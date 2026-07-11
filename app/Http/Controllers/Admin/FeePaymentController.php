<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\FeePayment;
use App\Models\FeeAssignment;
use App\Models\PaymentTransaction;
use App\Models\RazorpayTransaction;
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

        // Stats for header. Cash + UPI + cheque + bank_transfer count as
        // "collected"; method=scholarship rows are waivers, tracked
        // separately. Before this split, "Total Revenue" silently included
        // every waived rupee, inflating the school's actual cash inflow.
        $today = date('Y-m-d');
        $base = fn() => PaymentTransaction::whereHas('receipt', fn($q) => $q->where('school_id', $schoolId));

        $stats = [
            'total_collected' => (clone $base())->where('method', '!=', 'scholarship')->sum('amount'),
            'today_collected' => (clone $base())->where('method', '!=', 'scholarship')->whereDate('payment_date', $today)->sum('amount'),
            'total_waived'    => (clone $base())->where('method', 'scholarship')->sum('amount'),
            'today_waived'    => (clone $base())->where('method', 'scholarship')->whereDate('payment_date', $today)->sum('amount'),
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
                // "Waive Balance" checkbox path. Previously this just bumped
                // the aggregate $payment->waived_amount and left the ledger
                // empty — so /school/fees/transactions only showed the cash
                // payment and the waiver disappeared from history. Now we
                // also write a PaymentTransaction row (method=scholarship)
                // so the auditor can see exactly when, who, and how much
                // was waived.
                $waiveRemainder = max(0, (float) $payment->total_amount - (float) $payment->paid_amount - (float) $payment->waived_amount);
                if ($waiveRemainder > 0.01) {
                    $payment->transactions()->create([
                        'amount' => $waiveRemainder,
                        'payment_date' => $validated['payment_date'],
                        'method' => 'scholarship',
                        'added_by' => auth()->id(),
                        'remarks' => trim(($validated['remarks'] ?? '') . ' (Balance Waived)'),
                    ]);
                    $payment->waived_amount = (float) $payment->waived_amount + $waiveRemainder;
                }
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

    /**
     * List every Razorpay attempt for the school — paid, pending, failed,
     * refunded. Powers the Online Payments tab so the accountant can audit
     * what came through the gateway separately from the cash/UPI ledger.
     *
     * Each row carries:
     *   - razorpay_order_id / razorpay_payment_id   (for reconciliation)
     *   - status                                    ('created' | 'paid' | 'failed' | 'refunded')
     *   - amount (₹) + payment_date                 (from the linked PaymentTransaction)
     *   - student name + admission number           (from receipt.student)
     *   - fee type                                  (from assignment.feeType)
     *
     * Status is a free-form string on the table — we only "know" 'paid'
     * because the webhook sets it. Everything else lives in metadata until
     * a captured payment lands.
     */
    public function razorpayIndex(Request $request)
    {
        $schoolId = $request->user()->school_id;

        $query = RazorpayTransaction::with([
            'transaction.receipt.student:id,name,admission_number',
            'transaction.receipt.assignment.feeType:id,name',
        ])
        ->whereHas('transaction.receipt', fn($q) => $q->where('school_id', $schoolId))
        ->orderBy('created_at', 'desc');

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('razorpay_order_id', 'like', "%{$s}%")
                  ->orWhere('razorpay_payment_id', 'like', "%{$s}%")
                  ->orWhereHas('transaction.receipt.student', function ($sq) use ($s) {
                      $sq->where('name', 'like', "%{$s}%")
                         ->orWhere('admission_number', 'like', "%{$s}%");
                  });
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $rows = $query->paginate(15);

        // Stats — count rows per status + sum of paid amounts (linked
        // transactions). We deliberately compute "succeeded" as the sum on
        // status='paid' rows only, since 'created' rows aren't actual money.
        $base = fn() => RazorpayTransaction::whereHas('transaction.receipt', fn($q) => $q->where('school_id', $schoolId));
        $stats = [
            'total_paid_count'   => (clone $base())->where('status', 'paid')->count(),
            'total_failed_count' => (clone $base())->where('status', 'failed')->count(),
            'total_pending_count'=> (clone $base())->whereIn('status', ['created', 'attempted', 'pending'])->count(),
            'total_paid_amount'  => (clone $base())->where('status', 'paid')
                ->join('payment_transactions', 'payment_transactions.id', '=', 'razorpay_transactions.payment_transaction_id')
                ->sum('payment_transactions.amount'),
        ];

        return response()->json([
            'success' => true,
            'data' => array_merge($rows->toArray(), ['stats' => $stats]),
        ]);
    }

    /**
     * Denormalized receipt payload for the printable invoice. The list page
     * only knows about a single PaymentTransaction row at a time, but an
     * invoice needs the *whole* receipt — school header, student info, fee
     * type, all linked transactions (cash + waiver), and the running totals.
     *
     * Tenant-scoped via the `school_id` check on FeePayment.
     */
    public function receiptShow(Request $request, int $feePaymentId)
    {
        $schoolId = $request->user()->school_id;

        $payment = FeePayment::with([
            'student:id,name,admission_number,date_of_birth,parent_name,phone',
            'student.currentRecord.schoolClass.grade:id,name',
            'student.currentRecord.schoolClass.section:id,name',
            'assignment.feeType:id,name,frequency_type',
            'transactions' => fn($q) => $q->orderBy('created_at')->with('addedBy:id,name'),
            'school:id,name,email,address,registration_no,contact_number,logo_path,slug',
        ])
        ->where('id', $feePaymentId)
        ->where('school_id', $schoolId)
        ->first();

        if (!$payment) {
            return response()->json(['success' => false, 'message' => 'Receipt not found'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $payment,
        ]);
    }
}
