<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\FeePayment;
use App\Models\FeeAssignment;
use App\Models\PaymentTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Traits\FeeLogicTrait;

class FeePaymentController extends Controller
{
    use FeeLogicTrait;
    /**
     * List all payment transactions for the school
     */
    public function index(Request $request)
    {
        $schoolId = $request->user()->school_id;
        
        $query = PaymentTransaction::whereHas('receipt', function($q) use ($schoolId) {
            $q->where('school_id', $schoolId);
        })
        ->with(['receipt.student', 'receipt.assignment.feeType'])
        ->orderBy('created_at', 'desc');

        // Filter by student name / admission number
        if ($request->has('search')) {
            $search = $request->search;
            $query->whereHas('receipt.student', function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('admission_number', 'like', "%{$search}%");
            });
        }

        $transactions = $query->paginate(15);

        // Stats for header
        $today = date('Y-m-d');
        $stats = [
            'total_collected' => PaymentTransaction::whereHas('receipt', function($q) use ($schoolId) {
                $q->where('school_id', $schoolId);
            })->sum('amount'),
            'today_collected' => PaymentTransaction::whereHas('receipt', function($q) use ($schoolId) {
                $q->where('school_id', $schoolId);
            })->whereDate('payment_date', $today)->sum('amount'),
        ];

        return response()->json([
            'success' => true,
            'data' => array_merge($transactions->toArray(), ['stats' => $stats])
        ]);
    }

    /**
     * Record an offline payment (Cash, UPI, Cheque)
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'student_id' => 'required|exists:students,id',
            'fee_assignment_id' => 'required|exists:fee_assignments,id',
            'amount' => 'required|numeric|min:0.01',
            'method' => 'required|in:cash,upi,cheque,bank_transfer',
            'payment_date' => 'required|date',
            'remarks' => 'nullable|string',
            'is_waived' => 'nullable|boolean',
            'waived_amount' => 'nullable|numeric|min:0',
        ]);

        $assignment = FeeAssignment::findOrFail($validated['fee_assignment_id']);
        
        $sessionTotal = $this->getSessionTotal($assignment);
        $payment = FeePayment::where('fee_assignment_id', $assignment->id)
            ->where('student_id', $validated['student_id'])
            ->first();

        if (!$payment) {
            $payment = FeePayment::create([
                'fee_assignment_id' => $assignment->id,
                'student_id' => $validated['student_id'],
                'school_id' => $assignment->school_id,
                'total_amount' => $sessionTotal,
                'paid_amount' => 0,
                'due_amount' => $sessionTotal,
                'waived_amount' => 0,
                'receipt_no' => 'RCPT-' . strtoupper(Str::random(10)),
                'status' => 'unpaid'
            ]);
        } else {
            $payment->update(['total_amount' => $sessionTotal]);
        }

        return DB::transaction(function() use ($payment, $validated, $assignment) {
            $waiveAmt = (float)($validated['waived_amount'] ?? 0);
            $payAmt = (float)$validated['amount'];

            // Validation: Total deduction should not exceed session due amount
            $currentDue = (float)$payment->total_amount - (float)$payment->paid_amount - (float)$payment->waived_amount;
            if ($payAmt + $waiveAmt > $currentDue + 0.01) {
                return response()->json([
                    'success' => false, 
                    'message' => "Total deduction (₹" . ($payAmt + $waiveAmt) . ") exceeds remaining balance (₹$currentDue)"
                ], 400);
            }

            $transaction = $payment->transactions()->create([
                'amount' => $payAmt,
                'payment_date' => $validated['payment_date'],
                'method' => $validated['method'],
                'added_by' => auth()->id(),
                'remarks' => $validated['remarks']
            ]);

            // Update master balances
            $payment->paid_amount = (float)$payment->paid_amount + $payAmt;
            
            // Handle explicit waiver amount
            if ($waiveAmt > 0) {
                $payment->waived_amount = (float)$payment->waived_amount + $waiveAmt;
                
                // Record waiver as a separate transaction for history
                $payment->transactions()->create([
                    'amount' => $waiveAmt,
                    'payment_date' => $validated['payment_date'],
                    'method' => 'scholarship', // Using scholarship as 'waiver' type
                    'added_by' => auth()->id(),
                    'remarks' => $validated['remarks'] . " (Scholarship Granted)"
                ]);
            }

            if (!empty($validated['is_waived'])) {
                // If the "Waive Balance" checkbox is checked, waive EVERYTHING ELSE
                $payment->waived_amount += max(0, $payment->total_amount - $payment->paid_amount - $payment->waived_amount);
            }

            $payment->due_amount = max(0, (float)$payment->total_amount - (float)$payment->paid_amount - (float)$payment->waived_amount);
            $payment->status = $payment->due_amount <= 0.01 ? 'paid' : ($payment->paid_amount > 0 ? 'partial' : 'unpaid');
            
            $payment->save();

            // Send Notifications
            if ($payAmt > 0) {
                $this->notifyStudentPayment($payment->student_id, $payAmt, $assignment->feeType->name, false);
            }
            if ($waiveAmt > 0) {
                $this->notifyStudentPayment($payment->student_id, $waiveAmt, $assignment->feeType->name, true);
            }

            return response()->json([
                'success' => true,
                'message' => 'Payment recorded successfully',
                'data' => [
                    'receipt_no' => $payment->receipt_no,
                    'paid_amount' => $payment->paid_amount,
                    'due_amount' => $payment->due_amount
                ]
            ]);
        });
    }
}
