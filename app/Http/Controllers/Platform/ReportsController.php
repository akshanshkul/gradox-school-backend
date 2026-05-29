<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionPayment;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportsController extends Controller
{
    public function revenue(Request $request)
    {
        $query = $this->revenueQuery($request);
        $payments = $query->paginate((int) ($request->query('per_page', 50)));

        $summary = (clone $query)
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(amount), 0) as total')
            ->first();

        return response()->json([
            'payments' => $payments,
            'summary' => [
                'count' => (int) ($summary->count ?? 0),
                'total' => (float) ($summary->total ?? 0),
            ],
        ]);
    }

    public function revenueCsv(Request $request): StreamedResponse
    {
        $rows = $this->revenueQuery($request)->cursor();
        $filename = 'revenue-' . now()->format('Ymd-His') . '.csv';

        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['ID', 'School', 'Order ID', 'Amount', 'Status', 'Created at']);
            foreach ($rows as $r) {
                fputcsv($handle, [
                    $r->id,
                    $r->school_name,
                    $r->order_id,
                    $r->amount,
                    $r->status,
                    $r->created_at,
                ]);
            }
            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    private function revenueQuery(Request $request)
    {
        $q = SubscriptionPayment::query()
            ->leftJoin('schools', 'subscription_payments.school_id', '=', 'schools.id')
            ->select(
                'subscription_payments.id',
                'subscription_payments.order_id',
                'subscription_payments.amount',
                'subscription_payments.status',
                'subscription_payments.created_at',
                'subscription_payments.school_id',
                'schools.name as school_name'
            )
            ->orderByDesc('subscription_payments.id');

        if ($from = $request->query('from')) {
            $q->where('subscription_payments.created_at', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $q->where('subscription_payments.created_at', '<=', $to);
        }
        if ($status = $request->query('status')) {
            $q->where('subscription_payments.status', $status);
        }
        if ($schoolId = $request->query('school_id')) {
            $q->where('subscription_payments.school_id', $schoolId);
        }

        return $q;
    }
}
