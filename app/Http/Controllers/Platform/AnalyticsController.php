<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Student;
use App\Models\SubscriptionPayment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AnalyticsController extends Controller
{
    public function overview()
    {
        $now = now();

        $schoolsByStatus = School::selectRaw('subscription_status, count(*) as c')
            ->groupBy('subscription_status')
            ->pluck('c', 'subscription_status');

        $expiringSoon = School::whereNotNull('subscription_expires_at')
            ->whereBetween('subscription_expires_at', [$now, $now->copy()->addDays(14)])
            ->count();

        $revenueThisMonth = SubscriptionPayment::where('status', 'paid')
            ->whereYear('created_at', $now->year)
            ->whereMonth('created_at', $now->month)
            ->sum('amount');

        $revenueAllTime = SubscriptionPayment::where('status', 'paid')->sum('amount');

        $usersByRole = $this->usersByRole();
        $active = $this->activeUsers();
        $studentsByGender = $this->studentsByGender();
        $newSchools = $this->newSchoolsTrend();

        return response()->json([
            'totals' => [
                'schools' => School::count(),
                'students' => Student::count(),
                'users' => User::count(),
                'plans' => DB::table('plans')->count(),
            ],
            'schools_by_status' => $schoolsByStatus,
            'users_by_role' => $usersByRole,
            'students_by_gender' => $studentsByGender,
            'active_users' => $active,
            'new_schools' => $newSchools,
            'expiring_soon' => $expiringSoon,
            'revenue' => [
                'this_month' => (float) $revenueThisMonth,
                'all_time' => (float) $revenueAllTime,
            ],
        ]);
    }

    public function growth()
    {
        $months = collect(range(11, 0))->map(function ($offset) {
            $d = now()->subMonths($offset);
            return [
                'year' => $d->year,
                'month' => $d->month,
                'label' => $d->format('M Y'),
            ];
        });

        $schoolsByMonth = School::selectRaw('YEAR(created_at) y, MONTH(created_at) m, count(*) c')
            ->where('created_at', '>=', now()->subMonths(12)->startOfMonth())
            ->groupBy('y', 'm')
            ->get()
            ->keyBy(fn ($row) => $row->y . '-' . $row->m);

        $revenueByMonth = SubscriptionPayment::selectRaw('YEAR(created_at) y, MONTH(created_at) m, SUM(amount) total')
            ->where('status', 'paid')
            ->where('created_at', '>=', now()->subMonths(12)->startOfMonth())
            ->groupBy('y', 'm')
            ->get()
            ->keyBy(fn ($row) => $row->y . '-' . $row->m);

        $series = $months->map(function ($m) use ($schoolsByMonth, $revenueByMonth) {
            $key = $m['year'] . '-' . $m['month'];
            return [
                'label' => $m['label'],
                'schools' => (int) ($schoolsByMonth[$key]->c ?? 0),
                'revenue' => (float) ($revenueByMonth[$key]->total ?? 0),
            ];
        });

        return response()->json(['series' => $series]);
    }

    public function expiringSoon(Request $request)
    {
        $days = min((int) $request->query('days', 14), 365);
        $now = now();

        $schools = School::whereNotNull('subscription_expires_at')
            ->whereBetween('subscription_expires_at', [$now, $now->copy()->addDays($days)])
            ->orderBy('subscription_expires_at')
            ->get(['id', 'name', 'slug', 'email', 'plan_name', 'subscription_status', 'subscription_expires_at']);

        return response()->json([
            'window_days' => $days,
            'schools' => $schools,
        ]);
    }

    private function usersByRole(): array
    {
        if (!Schema::hasTable('roles')) {
            return [];
        }
        $rows = DB::table('users')
            ->leftJoin('roles', 'users.role_id', '=', 'roles.id')
            ->selectRaw('COALESCE(roles.slug, "unknown") as slug, count(*) as c')
            ->groupBy('slug')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[$row->slug] = (int) $row->c;
        }
        return $out;
    }

    private function studentsByGender(): array
    {
        if (!Schema::hasColumn('students', 'gender')) {
            return [];
        }
        $rows = DB::table('students')
            ->selectRaw('COALESCE(gender, "unspecified") as gender, count(*) as c')
            ->groupBy('gender')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[$row->gender] = (int) $row->c;
        }
        return $out;
    }

    /**
     * Concurrent / recently-active users derived from Sanctum's existing
     * personal_access_tokens.last_used_at column — zero overhead, zero schema
     * changes. A user is "active in window W" if any of their tokens was
     * used in the last W seconds.
     */
    private function activeUsers(): array
    {
        if (!Schema::hasTable('personal_access_tokens')) {
            return ['now' => 0, 'today' => 0, 'week' => 0];
        }
        $now = now();
        $countDistinct = function (\DateTimeInterface $since) {
            return (int) DB::table('personal_access_tokens')
                ->where('tokenable_type', User::class)
                ->whereNotNull('last_used_at')
                ->where('last_used_at', '>=', $since)
                ->distinct('tokenable_id')
                ->count('tokenable_id');
        };

        return [
            'now' => $countDistinct($now->copy()->subMinutes(5)),
            'today' => $countDistinct($now->copy()->subDay()),
            'week' => $countDistinct($now->copy()->subDays(7)),
        ];
    }

    private function newSchoolsTrend(): array
    {
        $now = now();
        return [
            'this_month' => School::whereYear('created_at', $now->year)
                ->whereMonth('created_at', $now->month)
                ->count(),
            'last_month' => School::whereYear('created_at', $now->copy()->subMonth()->year)
                ->whereMonth('created_at', $now->copy()->subMonth()->month)
                ->count(),
            'last_7_days' => School::where('created_at', '>=', $now->copy()->subDays(7))->count(),
        ];
    }
}
