<?php

namespace App\Traits;

use Carbon\Carbon;
use App\Models\StudentDeviceToken;
use App\Services\FirebaseV1Service;

trait FeeLogicTrait
{
    /**
     * Calculate session-wide totals and installment coverage
     */
    public function calculateInstallmentMeta($assignment, $monthsPassed)
    {
        // ALWAYS use the calculated session total instead of trusting the DB's current total_amount
        $installmentAmount = (float)$assignment->amount;
        $multiplier = ($assignment->feeType->frequency_type === 'monthly') ? 12 : 1;
        $sessionTotal = $installmentAmount * $multiplier;
        
        $payment = $assignment->payments->first(); // Should only be one per student
        $paidAmount = $payment ? (float)$payment->paid_amount : 0;
        
        // Ensure waivedAmount is not negative (legacy bug recovery)
        $waivedAmount = $payment ? max(0, (float)$payment->waived_amount) : 0;
        
        $totalCoverage = $paidAmount + $waivedAmount;
        
        // Capping target months at multiplier (12 for monthly) to prevent overflow
        $targetMonths = min($multiplier, (int)$monthsPassed + 1);
        $coveredMonths = floor(($totalCoverage + 0.01) / $installmentAmount);
        
        $pendingMonthsCount = max(0, $targetMonths - $coveredMonths);
        
        $status = 'unpaid';
        if ($totalCoverage >= $sessionTotal - 0.01) {
            $status = 'paid';
        } elseif ($totalCoverage > 0) {
            $status = 'partial';
        }

        return [
            'total_amount' => $sessionTotal,
            'installment_amount' => $installmentAmount,
            'paid_amount' => $paidAmount,
            'due_amount' => max(0, $sessionTotal - $totalCoverage),
            'current_installment_due' => max(0, ($targetMonths * $installmentAmount) - $totalCoverage),
            'waived_amount' => $waivedAmount,
            'total_coverage' => $totalCoverage,
            'is_installment_paid' => $pendingMonthsCount === 0,
            'pending_months_count' => $pendingMonthsCount,
            'paid_months_count' => (int)$coveredMonths,
            'target_months' => $targetMonths,
            'status' => $status
        ];
    }

    /**
     * Get the full session total for an assignment
     */
    public function getSessionTotal($assignment)
    {
        $multiplier = ($assignment->feeType->frequency_type === 'monthly') ? 12 : 1;
        return (float)$assignment->amount * $multiplier;
    }

    /**
     * Send push notification to student about their payment/waiver
     */
    protected function notifyStudentPayment($studentId, $amount, $feeTypeName, $isWaiver = false)
    {
        $tokens = StudentDeviceToken::where('student_id', $studentId)->pluck('token')->toArray();
        if (empty($tokens)) return;

        $title = $isWaiver ? "Scholarship Applied! 🎁" : "Payment Confirmed! 🛡️";
        $formattedAmount = "₹" . number_format($amount, 2);
        
        $body = $isWaiver 
            ? "A scholarship of {$formattedAmount} has been applied to your {$feeTypeName} dues. Check your ledger for details."
            : "We have successfully received your payment of {$formattedAmount} for {$feeTypeName}. Thank you!";

        FirebaseV1Service::send($tokens, $title, $body, [
            'type' => 'fee_payment',
            'student_id' => (string)$studentId
        ]);
    }
}
