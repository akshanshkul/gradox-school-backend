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
            ->whereDate('date', $request->date)
            ->with('user:id,name,role,profile_picture')
            ->get();

        return response()->json($attendances);
    }

    public function mark(Request $request)
    {
        $request->validate([
            'date' => 'required|date',
            'records' => 'required|array',
            'records.*.user_id' => 'required|exists:users,id',
            'records.*.status' => 'required|in:present,absent,late,half_day',
            'records.*.remarks' => 'nullable|string'
        ]);

        $schoolId = $request->user()->school_id;
        $date = $request->date;
        
        $savedRecords = [];
        foreach ($request->records as $record) {
            $savedRecords[] = Attendance::updateOrCreate(
                [
                    'user_id' => $record['user_id'],
                    'school_id' => $schoolId,
                    'date' => $date
                ],
                [
                    'status' => $record['status'],
                    'remarks' => $record['remarks'] ?? null
                ]
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Attendance successfully recorded.',
            'records' => collect($savedRecords)->load('user:id,name,role,profile_picture')
        ]);
    }
}
