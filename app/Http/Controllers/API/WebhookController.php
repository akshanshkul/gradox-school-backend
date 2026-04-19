<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Razorpay\Api\Api;
use Razorpay\Api\Errors\SignatureVerificationError;
use App\Models\RazorpayTransaction;
use App\Models\FeePayment;
use App\Models\FeeAssignment;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WebhookController extends Controller
{
    /**
     * Handle Razorpay Webhooks
     */
    public function handleRazorpay(Request $request)
    {
        $webhookSecret = env('RAZORPAY_WEBHOOK_SECRET');
        $signature = $request->header('X-Razorpay-Signature');
        
        $payload = $request->getContent();

        // 1. Verify Signature
        if ($webhookSecret) {
            try {
                $api = new Api(env('RAZORPAY_KEY_ID'), env('RAZORPAY_KEY_SECRET'));
                $api->utility->verifyWebhookSignature($payload, $signature, $webhookSecret);
            } catch (SignatureVerificationError $e) {
                Log::error('Razorpay Webhook Signature Verification Failed', [
                    'error' => $e->getMessage(),
                    'signature' => $signature
                ]);
                return response()->json(['success' => false, 'message' => 'Invalid signature'], 400);
            }
        }

        $data = json_decode($payload, true);
        $event = $data['event'] ?? '';

        Log::info('Razorpay Webhook Received', ['event' => $event]);

        // 2. Handle relevant events
        if ($event === 'order.paid' || $event === 'payment.captured') {
            return $this->processPayment($data);
        }

        return response()->json(['success' => true, 'message' => 'Event ignored']);
    }

    private function processPayment($data)
    {
        $payload = $data['payload'];
        $orderId = null;

        if (isset($payload['order']['entity']['id'])) {
            $orderId = $payload['order']['entity']['id'];
        } elseif (isset($payload['payment']['entity']['order_id'])) {
            $orderId = $payload['payment']['entity']['order_id'];
        }

        if (!$orderId) {
            return response()->json(['success' => false, 'message' => 'No order ID found'], 400);
        }

        $rzpTx = RazorpayTransaction::where('razorpay_order_id', $orderId)->first();

        if (!$rzpTx) {
            Log::warning('Razorpay order received via webhook but not found in DB', ['order_id' => $orderId]);
            return response()->json(['success' => false, 'message' => 'Order not found in local DB'], 404);
        }

        if ($rzpTx->status === 'paid') {
            return response()->json(['success' => true, 'message' => 'Payment already processed']);
        }

        // Get student/assignment from metadata saved during initiate
        $studentId = $rzpTx->metadata['student_id'] ?? null;
        $assignmentId = $rzpTx->metadata['fee_assignment_id'] ?? null;
        $orderAmount = $rzpTx->metadata['amount'] ?? 0;

        if (!$studentId || !$assignmentId) {
            Log::error('Razorpay Webhook metadata missing context', ['order_id' => $orderId]);
            return response()->json(['success' => false, 'message' => 'Metadata missing context'], 400);
        }

        try {
            DB::transaction(function () use ($rzpTx, $data, $studentId, $assignmentId, $orderAmount) {
                $assignment = FeeAssignment::findOrFail($assignmentId);

                // 2. Find or Create FeePayment (Update existing)
                $payment = FeePayment::firstOrCreate(
                    [
                        'school_id' => $assignment->school_id,
                        'student_id' => $studentId,
                        'fee_assignment_id' => $assignmentId,
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
                    'remarks' => 'Paid via Razorpay Webhook: ' . ($data['payload']['payment']['entity']['id'] ?? 'unknown')
                ]);

                // 4. Update Razorpay Detail
                $rzpTx->update([
                    'payment_transaction_id' => $transaction->id,
                    'status' => 'paid',
                    'razorpay_payment_id' => $data['payload']['payment']['entity']['id'] ?? $rzpTx->razorpay_payment_id,
                    'metadata' => array_merge($rzpTx->metadata ?? [], ['webhook_payload' => $data])
                ]);

                // 5. Update Fee Payment record
                $payment->paid_amount = (float)$payment->paid_amount + $orderAmount;
                $payment->due_amount = max(0, (float)$payment->total_amount - (float)$payment->paid_amount - (float)$payment->waived_amount);
                $payment->status = $payment->due_amount <= 0.01 ? 'paid' : 'partial';
                $payment->save();

                // 6. Update Assignment Status
                if ($payment->status === 'paid') {
                    $assignment->update(['status' => 'paid']);
                }
            });

            return response()->json(['success' => true, 'message' => 'Payment processed']);

        } catch (\Exception $e) {
            Log::error('Razorpay Webhook Processing Error', [
                'error' => $e->getMessage(),
                'order_id' => $orderId
            ]);
            return response()->json(['success' => false, 'message' => 'Internal Server Error'], 500);
        }
    }
}
