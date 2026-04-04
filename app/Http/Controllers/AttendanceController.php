<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'date' => 'required|date',
        ]);

        $attendances = Attendance::where('school_id', $request->user()->school_id)
            ->where('date', $request->date)
            ->with('user:id,name,role,profile_picture')
            ->get();

        return response()->json($attendances);
    }

    public function mark(Request $request)
    {
        $request->validate([
            'date'               => 'required|date',
            'records'            => 'required|array',
            'records.*.user_id'  => 'required|exists:users,id',
            'records.*.status'   => 'required|in:present,absent,late,half_day',
            'records.*.remarks'  => 'nullable|string',
        ]);

        $schoolId = $request->user()->school_id;
        $date     = $request->date;
        $userId   = $request->user()->id;
        $isBackDate = \Carbon\Carbon::parse($date)->isBefore(now()->startOfDay());

        $savedRecords = [];
        foreach ($request->records as $record) {
            $attendance = Attendance::where([
                'user_id'   => $record['user_id'],
                'school_id' => $schoolId,
                'date'      => $date,
            ])->first();

            if ($attendance) {
                // If the record exists, mark as regularized (as requested: "if submit than update oly as requler rize")
                $attendance->update([
                    'status'            => $record['status'],
                    'remarks'           => $record['remarks'] ?? null,
                    'is_regularized'    => true,
                    'regularized_by'    => $userId,
                    'regularize_remark' => 'Updated via Daily Register',
                ]);
                $savedRecords[] = $attendance;
            } else {
                // Initial record creation
                $savedRecords[] = Attendance::create([
                    'user_id'           => $record['user_id'],
                    'school_id'         => $schoolId,
                    'date'              => $date,
                    'status'            => $record['status'],
                    'remarks'           => $record['remarks'] ?? null,
                    // If it's a back date, it must be regularized (as requested: "back date attance not mark ot update if update only as requrised")
                    'is_regularized'    => $isBackDate,
                    'regularized_by'    => $isBackDate ? $userId : null,
                    'regularize_remark' => $isBackDate ? 'Back-dated registration' : null,
                ]);
            }
        }

        $savedIds = collect($savedRecords)->pluck('id');
        $records  = Attendance::whereIn('id', $savedIds)
            ->with(['user:id,name,role,profile_picture', 'regularizedBy:id,name'])
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Attendance successfully recorded.',
            'records' => $records,
        ]);
    }

    /**
     * Fetch attendance history for a single staff member in a given month/year.
     */
    public function history(Request $request)
    {
        $request->validate([
            'user_id'    => 'required|exists:users,id',
            'month'      => 'nullable|integer|between:1,12',
            'year'       => 'nullable|integer|min:2020',
            'start_date' => 'nullable|date',
            'end_date'   => 'nullable|date|after_or_equal:start_date',
        ]);

        $query = Attendance::where('school_id', $request->user()->school_id)
            ->where('user_id', $request->user_id)
            ->with('regularizedBy:id,name')
            ->orderBy('date');

        if ($request->filled('start_date') && $request->filled('end_date')) {
            $query->whereBetween('date', [$request->start_date, $request->end_date]);
        } else if ($request->filled('month') && $request->filled('year')) {
            $query->whereMonth('date', $request->month)
                ->whereYear('date', $request->year);
        } else {
            // Fallback to current month if nothing provided
            $query->whereMonth('date', now()->month)
                ->whereYear('date', now()->year);
        }

        $attendances = $query->get();

        return response()->json($attendances);
    }

    /**
     * Regularize (admin-correct or back-fill) an attendance record.
     */
    public function regularize(Request $request)
    {
        $request->validate([
            'user_id'           => 'required|exists:users,id',
            'date'              => 'required|date',
            'status'            => 'required|in:present,absent,late,half_day',
            'regularize_remark' => 'required|string|max:500',
        ]);

        $attendance = Attendance::updateOrCreate(
            [
                'user_id'   => $request->user_id,
                'school_id' => $request->user()->school_id,
                'date'      => $request->date,
            ],
            [
                'status'            => $request->status,
                'is_regularized'    => true,
                'regularize_remark' => $request->regularize_remark,
                'regularized_by'    => $request->user()->id,
            ]
        );

        return response()->json($attendance->load('regularizedBy:id,name'));
    }
}
