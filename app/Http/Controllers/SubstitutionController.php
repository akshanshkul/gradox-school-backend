<?php

namespace App\Http\Controllers;

use App\Models\PeriodSubstitution;
use App\Models\TimetableEntry;
use Illuminate\Http\Request;
use Carbon\Carbon;

class SubstitutionController extends Controller
{
    /**
     * Get all active substitutions for a specific date.
     */
    public function index(Request $request)
    {
        $request->validate(['date' => 'required|date']);
        $date = $request->date;

        $substitutions = PeriodSubstitution::where('school_id', $request->user()->school_id)
            ->where('date', $date)
            ->with([
                'timetableEntry.schoolClass',
                'timetableEntry.subject',
                'timetableEntry.teacher:id,name',
                'substituteTeacher:id,name'
            ])
            ->get();

        return response()->json($substitutions);
    }

    /**
     * Fetch timetable entries for a teacher on a specific date (based on day_of_week).
     */
    public function fetchConflicts(Request $request)
    {
        $request->validate([
            'date'    => 'required|date',
            'user_id' => 'required|exists:users,id',
        ]);

        $date      = Carbon::parse($request->date);
        $dayOfWeek = $date->format('l'); // 'Monday', 'Tuesday', etc.
        $schoolId  = $request->user()->school_id;

        $periods = TimetableEntry::where('school_id', $schoolId)
            ->where('user_id', $request->user_id)
            ->where('day_of_week', $dayOfWeek)
            ->where('is_active', true)
            ->with(['schoolClass', 'subject', 'classroom'])
            ->orderBy('start_time')
            ->get();

        // Also fetch existing substitutions for these periods on this date
        $existing = PeriodSubstitution::where('school_id', $schoolId)
            ->where('date', $request->date)
            ->whereIn('timetable_entry_id', $periods->pluck('id'))
            ->get()
            ->keyBy('timetable_entry_id');

        return response()->json([
            'periods'   => $periods,
            'existing'  => $existing
        ]);
    }

    /**
     * Bulk save or update substitutions.
     */
    public function store(Request $request)
    {
        $request->validate([
            'date'                      => 'required|date',
            'substitutions'             => 'required|array',
            'substitutions.*.entry_id'  => 'required|exists:timetable_entries,id',
            'substitutions.*.sub_id'    => 'required|exists:users,id',
            'substitutions.*.reason'    => 'nullable|in:absence,half_day,official_duty,other',
            'substitutions.*.remarks'   => 'nullable|string',
        ]);

        $schoolId = $request->user()->school_id;
        $date     = $request->date;
        $results  = [];

        foreach ($request->substitutions as $item) {
            $results[] = PeriodSubstitution::updateOrCreate(
                [
                    'school_id'          => $schoolId,
                    'timetable_entry_id' => $item['entry_id'],
                    'date'               => $date,
                ],
                [
                    'substitute_teacher_id' => $item['sub_id'],
                    'reason'                => $item['reason'] ?? 'absence',
                    'remarks'               => $item['remarks'] ?? null,
                    'is_active'             => true,
                ]
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Substitutions saved successfully.',
            'data'    => $results
        ]);
    }

    /**
     * Remove a substitution.
     */
    public function destroy(Request $request, $id)
    {
        $sub = PeriodSubstitution::where('school_id', $request->user()->school_id)
            ->findOrFail($id);
        
        $sub->delete();

        return response()->json(['success' => true]);
    }
}
