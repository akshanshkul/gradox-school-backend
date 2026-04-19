<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Session;
use Illuminate\Support\Facades\DB;

class SessionController extends Controller
{
    public function index(Request $request)
    {
        $sessions = Session::where('school_id', $request->user()->school_id)
            ->orderBy('start_date', 'desc')
            ->get();
        return response()->json(['success' => true, 'data' => $sessions]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'is_active' => 'boolean',
        ]);

        $schoolId = $request->user()->school_id;
        $validated['school_id'] = $schoolId;

        return DB::transaction(function () use ($validated, $schoolId) {
            // If new session is active, deactivate others
            if (!empty($validated['is_active'])) {
                Session::where('school_id', $schoolId)->update(['is_active' => false]);
            }

            $session = Session::create($validated);
            return response()->json(['success' => true, 'message' => 'Academic session created successfully', 'data' => $session]);
        });
    }

    public function activate(Request $request, $id)
    {
        $schoolId = $request->user()->school_id;
        $session = Session::where('school_id', $schoolId)->findOrFail($id);

        DB::transaction(function () use ($schoolId, $session) {
            Session::where('school_id', $schoolId)->update(['is_active' => false]);
            $session->update(['is_active' => true]);
        });

        return response()->json(['success' => true, 'message' => "Session {$session->name} is now active"]);
    }

    public function destroy(Request $request, $id)
    {
        $schoolId = $request->user()->school_id;
        $session = Session::where('school_id', $schoolId)->findOrFail($id);

        if ($session->is_active) {
            return response()->json(['success' => false, 'message' => 'Cannot delete the active session. Activate another session first.'], 400);
        }

        // Simple deletion - in production we'd check for linked student records/fees
        $session->delete();

        return response()->json(['success' => true, 'message' => 'Session deleted successfully']);
    }
}
