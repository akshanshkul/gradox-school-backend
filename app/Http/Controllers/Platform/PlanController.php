<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Services\PlatformAuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PlanController extends Controller
{
    public function __construct(private PlatformAuditService $audit)
    {
    }

    public function index()
    {
        return response()->json([
            'plans' => Plan::orderBy('sort_order')->orderBy('price')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|alpha_dash|max:255|unique:plans,slug',
            'price' => 'required|numeric|min:0',
            'currency' => 'nullable|string|max:8',
            'billing_cycle' => 'nullable|in:monthly,annual,lifetime',
            'max_students' => 'nullable|integer|min:0',
            'max_users' => 'nullable|integer|min:0',
            'features' => 'nullable|array',
            'is_active' => 'nullable|boolean',
            'sort_order' => 'nullable|integer',
            'description' => 'nullable|string',
        ]);

        $data['slug'] = $data['slug'] ?? Str::slug($data['name']);

        $plan = Plan::create($data);

        $this->audit->log($request->user()->id, 'plan.create', 'plan', $plan->id, $data, $request);

        return response()->json(['plan' => $plan], 201);
    }

    public function show($id)
    {
        return response()->json(['plan' => Plan::findOrFail($id)]);
    }

    public function update(Request $request, $id)
    {
        $plan = Plan::findOrFail($id);

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'slug' => 'sometimes|string|alpha_dash|max:255|unique:plans,slug,' . $plan->id,
            'price' => 'sometimes|numeric|min:0',
            'currency' => 'sometimes|string|max:8',
            'billing_cycle' => 'sometimes|in:monthly,annual,lifetime',
            'max_students' => 'sometimes|nullable|integer|min:0',
            'max_users' => 'sometimes|nullable|integer|min:0',
            'features' => 'sometimes|nullable|array',
            'is_active' => 'sometimes|boolean',
            'sort_order' => 'sometimes|integer',
            'description' => 'sometimes|nullable|string',
        ]);

        $plan->update($data);

        $this->audit->log($request->user()->id, 'plan.update', 'plan', $plan->id, $data, $request);

        return response()->json(['plan' => $plan]);
    }

    public function destroy(Request $request, $id)
    {
        $plan = Plan::findOrFail($id);
        $plan->delete();

        $this->audit->log($request->user()->id, 'plan.delete', 'plan', (int) $id, [], $request);

        return response()->json(['status' => 'ok']);
    }
}
