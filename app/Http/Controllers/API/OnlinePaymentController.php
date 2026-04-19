<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Razorpay\Api\Api;
use App\Models\FeeAssignment;
use App\Models\SchoolPaymentConfig;
use App\Models\FeePayment;
use App\Models\PaymentTransaction;
use App\Models\RazorpayTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Traits\FeeLogicTrait;

class OnlinePaymentController extends Controller
{
    use FeeLogicTrait;

    private function getRazorpayApi($schoolId)
    {
        $config = SchoolPaymentConfig::where('school_id', $schoolId)->where('is_active', true)->first();
        
        if ($config && isset($config->credentials['key_id'])) {
            return [
                'api' => new Api($config->credentials['key_id'], $config->credentials['key_secret']),
                'key_id' => $config->credentials['key_id']
            ];
        }

        // Fallback to default keys
        return [
            'api' => new Api(config('services.razorpay.key'), config('services.razorpay.secret')),
            'key_id' => config('services.razorpay.key')
        ];
    }

    public function initiate(Request $request)
    {
        $validated = $request->validate([
            'fee_assignment_id' => 'required|exists:fee_assignments,id',
            'amount' => 'nullable|numeric|min:1', // Explicitly requested amount
        ]);

        $student = $request->user()->student;
        if (!$student) {
            \Log::warning("Payment initiate failed: User " . $request->user()->id . " has no student record.");
            return response()->json(['success' => false, 'message' => 'Student record not found'], 404);
        }

        $assignment = FeeAssignment::findOrFail($validated['fee_assignment_id']);
        
        // Check if student has an existing payment record for this assignment
        $payment = FeePayment::where('fee_assignment_id', $assignment->id)
            ->where('student_id', $student->id)
            ->first();

        // Amount to pay logic
        $sessionTotal = $this->getSessionTotal($assignment);
        $totalPaid = (float)($payment->paid_amount ?? 0) + (float)($payment->waived_amount ?? 0);

        if ($totalPaid >= $sessionTotal - 0.01) {
            return response()->json(['success' => false, 'message' => 'This fee is already fully paid for the entire session.'], 400);
        }

        // 1. If explicit amount provided, use it
        // 2. Else default to pending dues or one installment
        $amountToPay = (float)($validated['amount'] ?? 0);
        if ($amountToPay <= 0) {
            $amountToPay = (float)($payment->due_amount ?? $assignment->amount);
        }

        $razorpayData = $this->getRazorpayApi($assignment->school_id);
        $api = $razorpayData['api'];

        $orderData = [
            'receipt'         => 'RCPT_' . $assignment->id . '_' . $student->id . '_' . Str::random(4),
            'amount'          => round($amountToPay * 100), // in paise
            'currency'        => 'INR',
        ];

        try {
            $razorpayOrder = $api->order->create($orderData);

            // Create a tracking record for the Razorpay Order
            RazorpayTransaction::create([
                'payment_transaction_id' => null, // Will be linked on success
                'razorpay_order_id' => $razorpayOrder['id'],
                'status' => 'created',
                'metadata' => [
                    'student_id' => $student->id,
                    'fee_assignment_id' => $assignment->id,
                    'amount' => $amountToPay,
                    'init_payload' => $orderData
                ]
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'order_id' => $razorpayOrder['id'],
                    'amount' => $orderData['amount'],
                    'currency' => $orderData['currency'],
                    'assignment_id' => $assignment->id,
                    'razorpay_key' => $razorpayData['key_id'],
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function verify(Request $request)
    {
        $validated = $request->validate([
            'razorpay_order_id' => 'required',
            'razorpay_payment_id' => 'required',
            'razorpay_signature' => 'required',
            'fee_assignment_id' => 'required|exists:fee_assignments,id',
            'amount' => 'required|numeric',
        ]);

        $student = $request->user()->student;
        $assignment = FeeAssignment::findOrFail($validated['fee_assignment_id']);
        $razorpayData = $this->getRazorpayApi($assignment->school_id);
        $api = $razorpayData['api'];

        try {
            $attributes = [
                'razorpay_order_id' => $validated['razorpay_order_id'],
                'razorpay_payment_id' => $validated['razorpay_payment_id'],
                'razorpay_signature' => $validated['razorpay_signature']
            ];
            $api->utility->verifyPaymentSignature($attributes);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Payment verification failed: ' . $e->getMessage()], 400);
        }

        // 1. Find the tracking record
        $rzpTx = RazorpayTransaction::where('razorpay_order_id', $validated['razorpay_order_id'])->first();

        if (!$rzpTx) {
            return response()->json(['success' => false, 'message' => 'Transaction tracking record not found.'], 404);
        }

        if ($rzpTx->status === 'paid') {
            return response()->json(['success' => true, 'message' => 'Payment already processed successfully.']);
        }

        $orderAmount = (float)$validated['amount'];

        try {
            $feePayment = \DB::transaction(function() use ($rzpTx, $validated, $assignment, $student, $orderAmount) {
                // 2. Find or Create FeePayment (Update existing)
                $payment = FeePayment::firstOrCreate(
                    [
                        'school_id' => $assignment->school_id,
                        'student_id' => $student->id,
                        'fee_assignment_id' => $assignment->id,
                    ],
                    [
                        'total_amount' => $assignment->amount,
                        'paid_amount' => 0,
                        'due_amount' => $assignment->amount,
                        'waived_amount' => 0,
                        'receipt_no' => 'RCPT-' . strtoupper(Str::random(10)),
                        'status' => 'unpaid'
                    ]
                );

                // 3. Create Audit Transaction
                $transaction = $payment->transactions()->create([
                    'amount' => $orderAmount,
                    'payment_date' => now(),
                    'method' => 'online',
                    'remarks' => 'Online payment: ' . $validated['razorpay_payment_id']
                ]);

                // 4. Link Razorpay Detail
                $rzpTx->update([
                    'payment_transaction_id' => $transaction->id,
                    'status' => 'paid',
                    'razorpay_payment_id' => $validated['razorpay_payment_id'],
                    'razorpay_signature' => $validated['razorpay_signature'],
                    'metadata' => array_merge($rzpTx->metadata ?? [], ['verified_at' => now()])
                ]);

                // 5. Update Master Record
                $payment->paid_amount = (float)$payment->paid_amount + $orderAmount;
                $payment->due_amount = max(0, (float)$payment->total_amount - (float)$payment->paid_amount - (float)$payment->waived_amount);
                $payment->status = $payment->due_amount <= 0.01 ? 'paid' : 'partial';
                $payment->save();

                // 6. Update Assignment Status
                if ($payment->status === 'paid') {
                    $assignment->update(['status' => 'paid']);
                }

                return $payment;
            });

            return response()->json([
                'success' => true, 
                'message' => 'Payment successful', 
                'receipt_no' => $feePayment->receipt_no
            ]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Database error: ' . $e->getMessage()], 500);
        }
    }
}
