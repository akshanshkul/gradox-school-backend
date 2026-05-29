<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Student;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Services\PlatformAuditService;
use App\Services\PlatformSchoolCreator;
use Illuminate\Http\Request;

class SchoolController extends Controller
{
    public function __construct(private PlatformAuditService $audit)
    {
    }

    public function index(Request $request)
    {
        $query = School::query();

        if ($search = $request->query('q')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        if ($status = $request->query('status')) {
            $query->where('subscription_status', $status);
        }

        if ($plan = $request->query('plan')) {
            $query->where('plan_name', $plan);
        }

        $perPage = min((int) $request->query('per_page', 20), 100);
        $schools = $query->orderByDesc('id')->paginate($perPage);

        $schoolIds = collect($schools->items())->pluck('id');
        $studentCounts = Student::whereIn('school_id', $schoolIds)
            ->selectRaw('school_id, count(*) as c')
            ->groupBy('school_id')
            ->pluck('c', 'school_id');
        $userCounts = User::whereIn('school_id', $schoolIds)
            ->selectRaw('school_id, count(*) as c')
            ->groupBy('school_id')
            ->pluck('c', 'school_id');

        $schools->getCollection()->transform(function ($school) use ($studentCounts, $userCounts) {
            $school->students_count = (int) ($studentCounts[$school->id] ?? 0);
            $school->users_count = (int) ($userCounts[$school->id] ?? 0);
            return $school;
        });

        return response()->json($schools);
    }

    public function show($id)
    {
        $school = School::findOrFail($id);

        $school->students_count = Student::where('school_id', $school->id)->count();
        $school->users_count = User::where('school_id', $school->id)->count();
        $school->latest_payments = SubscriptionPayment::where('school_id', $school->id)
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        return response()->json(['school' => $school]);
    }

    public function store(Request $request, PlatformSchoolCreator $creator)
    {
        $data = $request->validate([
            'school_name' => 'required|string|max:255',
            'school_email' => 'required|email|unique:schools,email',
            'slug' => 'required|string|alpha_dash|max:255|unique:schools,slug',
            'contact_number' => 'nullable|string|max:32',
            'address' => 'nullable|string|max:500',
            'plan_name' => 'nullable|string|max:64',
            'subscription_status' => 'nullable|in:trialing,active,past_due,suspended,expired',
            'subscription_expires_at' => 'nullable|date',
            'grace_days' => 'nullable|integer|min:0|max:365',
            'admin_name' => 'required|string|max:255',
            'admin_email' => 'required|email|unique:users,email',
            'admin_password' => 'required|string|min:8',
        ]);

        $result = $creator->create($data);

        $this->audit->log(
            $request->user()->id,
            'school.create',
            'school',
            $result['school']->id,
            ['admin_user_id' => $result['admin']->id],
            $request
        );

        return response()->json([
            'school' => $result['school'],
            'admin' => $result['admin'],
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $school = School::findOrFail($id);

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:schools,email,' . $school->id,
            'contact_number' => 'sometimes|nullable|string|max:32',
            'address' => 'sometimes|nullable|string|max:500',
            'plan_name' => 'sometimes|nullable|string|max:64',
            'grace_days' => 'sometimes|integer|min:0|max:365',
        ]);

        $school->update($data);

        $this->audit->log(
            $request->user()->id,
            'school.update',
            'school',
            $school->id,
            ['changed' => array_keys($data)],
            $request
        );

        return response()->json(['school' => $school]);
    }

    public function suspend(Request $request, $id)
    {
        $school = School::findOrFail($id);

        $previousExpiry = $school->subscription_expires_at;
        $school->update([
            'subscription_status' => 'suspended',
            'subscription_expires_at' => now()->subDay(),
        ]);

        $this->audit->log(
            $request->user()->id,
            'school.suspend',
            'school',
            $school->id,
            ['previous_expiry' => optional($previousExpiry)->toDateTimeString()],
            $request
        );

        return response()->json(['school' => $school]);
    }

    public function activate(Request $request, $id)
    {
        $school = School::findOrFail($id);

        $data = $request->validate([
            'subscription_expires_at' => 'nullable|date|after:now',
        ]);

        $school->update([
            'subscription_status' => 'active',
            'subscription_expires_at' => $data['subscription_expires_at'] ?? now()->addMonth(),
        ]);

        $this->audit->log(
            $request->user()->id,
            'school.activate',
            'school',
            $school->id,
            ['expires_at' => $school->subscription_expires_at->toDateTimeString()],
            $request
        );

        return response()->json(['school' => $school]);
    }
}
