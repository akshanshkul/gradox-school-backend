<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\FeeType;

class FeeTypeController extends Controller
{
    public function index(Request $request)
    {
        $schoolId = $request->user()->school_id;
        $feeTypes = FeeType::where('school_id', $schoolId)->get();
        return response()->json(['success' => true, 'data' => $feeTypes]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'frequency_type' => 'required|in:monthly,yearly,one_time,custom',
            'event_type' => 'nullable|string',
            'description' => 'nullable|string',
            'is_active' => 'boolean'
        ]);

        $validated['school_id'] = $request->user()->school_id;

        $feeType = FeeType::create($validated);

        return response()->json(['success' => true, 'message' => 'Fee Type created successfully', 'data' => $feeType]);
    }

    public function update(Request $request, $id)
    {
        $schoolId = $request->user()->school_id;
        $feeType = FeeType::where('school_id', $schoolId)->findOrFail($id);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'frequency_type' => 'required|in:monthly,yearly,one_time,custom',
            'event_type' => 'nullable|string',
            'description' => 'nullable|string',
            'is_active' => 'boolean'
        ]);

        $feeType->update($validated);

        return response()->json(['success' => true, 'message' => 'Fee Type updated successfully', 'data' => $feeType]);
    }

    public function destroy(Request $request, $id)
    {
        $schoolId = $request->user()->school_id;
        $feeType = FeeType::where('school_id', $schoolId)->findOrFail($id);

        // Check if there are active assignments
        if ($feeType->assignments()->count() > 0) {
            return response()->json(['success' => false, 'message' => 'Cannot delete Fee Type with active assignments'], 400);
        }

        $feeType->delete();

        return response()->json(['success' => true, 'message' => 'Fee Type deleted successfully']);
    }
}
