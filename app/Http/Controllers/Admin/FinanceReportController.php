<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\FeeAssignment;
use App\Models\FeePayment;
use App\Models\PaymentTransaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class FinanceReportController extends Controller
{
    /**
     * Get a high-level overview of school finances
     */
    public function getOverview(Request $request)
    {
        $schoolId = $request->user()->school_id;

        // 1. Total Expected (Current Active Session)
        $totalExpected = FeeAssignment::where('school_id', $schoolId)->sum('amount');

        // 2. Total Collected 
        $totalCollected = PaymentTransaction::whereHas('receipt', function($q) use ($schoolId) {
            $q->where('school_id', $schoolId);
        })->sum('amount');

        // 3. Outstanding
        $totalPending = $totalExpected - $totalCollected;

        // 4. Collection Efficiency
        $collectionRate = $totalExpected > 0 ? round(($totalCollected / $totalExpected) * 100, 1) : 0;

        // 5. Recent Transactions
        $recentTransactions = PaymentTransaction::whereHas('receipt', function($q) use ($schoolId) {
                $q->where('school_id', $schoolId);
            })
            ->with(['receipt.student'])
            ->orderBy('payment_date', 'desc')
            ->limit(10)
            ->get()
            ->map(function($tx) {
                return [
                    'id' => $tx->id,
                    'student' => $tx->receipt->student->name,
                    'amount' => $tx->amount,
                    'method' => strtoupper($tx->method),
                    'date' => $tx->payment_date->format('Y-m-d'),
                    'status' => 'verified' // Usually online payments would need more logic here
                ];
            });

        // 6. Daily Collection (Last 7 Days)
        $dailyCollection = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = Carbon::today()->subDays($i);
            $amount = PaymentTransaction::whereHas('receipt', function($q) use ($schoolId) {
                    $q->where('school_id', $schoolId);
                })
                ->whereDate('payment_date', $date)
                ->sum('amount');

            $dailyCollection[] = [
                'day' => $date->format('D'),
                'amount' => (float)$amount,
                'date' => $date->format('Y-m-d')
            ];
        }

        return $this->successResponse([
            'totalExpected' => (float)$totalExpected,
            'totalCollected' => (float)$totalCollected,
            'totalPending' => (float)$totalPending,
            'collectionRate' => $collectionRate . '%',
            'recentTransactions' => $recentTransactions,
            'dailyCollection' => $dailyCollection
        ]);
    }

    /**
     * Get a detailed transaction ledger (Paginated)
     */
    public function getLedger(Request $request)
    {
        $schoolId = $request->user()->school_id;

        $query = PaymentTransaction::whereHas('receipt', function($q) use ($schoolId) {
                $q->where('school_id', $schoolId);
            })
            ->with(['receipt.student', 'receipt.assignment.feeType'])
            ->orderBy('payment_date', 'desc');

        if ($request->has('search')) {
            $search = $request->search;
            $query->whereHas('receipt.student', function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('admission_number', 'like', "%{$search}%");
            });
        }

        return $this->successResponse($query->paginate(20));
    }
}
