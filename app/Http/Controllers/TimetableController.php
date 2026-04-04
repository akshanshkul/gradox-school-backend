<?php

namespace App\Http\Controllers;

use App\Models\TimetableEntry;
use Illuminate\Http\Request;

class TimetableController extends Controller
{
    public function addEntry(Request $request)
    {
        $this->validateEntry($request);

        $school_id = $request->user()->school_id;

        // Auto-assign classroom if not provided
        if (!$request->classroom_id) {
            $classroomId = $this->findAvailableRoom($request, $school_id);
            if (!$classroomId) {
                return response()->json(['error' => 'No available classrooms found for this period.'], 422);
            }
            $request->merge(['classroom_id' => $classroomId]);
        }

        // Conflict check
        $conflict = $this->checkConflicts($request, $school_id);
        if ($conflict) return $conflict;

        // Verify teaching status
        $teacher = \App\Models\User::find($request->user_id);
        if ($teacher && !$teacher->is_teaching) {
            return response()->json(['error' => 'Selected staff member is not part of the teaching faculty.'], 422);
        }

        $entry = TimetableEntry::create(array_merge($request->all(), ['school_id' => $school_id]));

        return response()->json($entry->load(['schoolClass', 'subject', 'teacher', 'classroom']));
    }

    public function updateEntry(Request $request, $id)
    {
        $entry = TimetableEntry::where('school_id', $request->user()->school_id)->findOrFail($id);
        
        $school_id = $request->user()->school_id;
        
        // Auto-assign classroom if not provided
        if (!$request->classroom_id) {
            $classroomId = $this->findAvailableRoom($request, $school_id, $id);
            if (!$classroomId) {
                return response()->json(['error' => 'No available classrooms found for this period.'], 422);
            }
            $request->merge(['classroom_id' => $classroomId]);
        }

        // Conflict check (excluding current entry)
        $conflict = $this->checkConflicts($request, $school_id, $id);
        if ($conflict) return $conflict;

        // Verify teaching status
        $teacher = \App\Models\User::find($request->user_id);
        if ($teacher && !$teacher->is_teaching) {
            return response()->json(['error' => 'Selected staff member is not part of the teaching faculty.'], 422);
        }

        $entry->update($request->all());

        return response()->json($entry->load(['schoolClass', 'subject', 'teacher', 'classroom']));
    }

    public function deleteEntry(Request $request, $id)
    {
        $entry = TimetableEntry::where('school_id', $request->user()->school_id)->findOrFail($id);
        $entry->delete();
        return response()->json(['message' => 'Entry deleted successfully']);
    }

    private function validateEntry(Request $request)
    {
        return $request->validate([
            'school_class_id' => 'required|exists:school_classes,id',
            'subject_id' => 'required|exists:subjects,id',
            'user_id' => 'required|exists:users,id',
            'classroom_id' => 'nullable|exists:classrooms,id',
            'date' => 'required|date',
            'day_of_week' => 'nullable|string',
            'start_time' => 'required',
            'end_time' => 'required|after:start_time',
        ]);
    }

    private function findAvailableRoom(Request $request, $school_id, $excludeId = null)
    {
        $classModel = \App\Models\SchoolClass::find($request->school_class_id);
        
        // 1. Try Default Classroom
        if ($classModel && $classModel->default_classroom_id) {
            if ($this->isRoomFree($classModel->default_classroom_id, $request, $excludeId)) {
                return $classModel->default_classroom_id;
            }
        }

        // 2. Try class's existing room on same date/day
        $existingRoomEntry = TimetableEntry::where('school_class_id', $request->school_class_id)
            ->where('date', $request->date)
            ->whereNotNull('classroom_id')
            ->first();

        if ($existingRoomEntry && $this->isRoomFree($existingRoomEntry->classroom_id, $request, $excludeId)) {
            return $existingRoomEntry->classroom_id;
        }

        // 3. Fallback: Any available room
        $availableRoom = \App\Models\Classroom::where('school_id', $school_id)
            ->whereDoesntHave('timetableEntries', function ($query) use ($request, $excludeId) {
                $query->where('date', $request->date)
                    ->where(function ($q) use ($request) {
                        $q->whereBetween('start_time', [$request->start_time, $request->end_time])
                            ->orWhereBetween('end_time', [$request->start_time, $request->end_time]);
                    });
                if ($excludeId) {
                    $query->where('id', '!=', $excludeId);
                }
            })->first();

        return $availableRoom ? $availableRoom->id : null;
    }

    private function isRoomFree($roomId, $request, $excludeId = null)
    {
        $query = TimetableEntry::where('classroom_id', $roomId)
            ->where('date', $request->date)
            ->where(function ($q) use ($request) {
                $q->whereBetween('start_time', [$request->start_time, $request->end_time])
                  ->orWhereBetween('end_time', [$request->start_time, $request->end_time]);
            });

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        return !$query->exists();
    }

    private function checkConflicts(Request $request, $school_id, $excludeId = null)
    {
        $query = TimetableEntry::where('school_id', $school_id)
            ->where('date', $request->date)
            ->where(function ($query) use ($request) {
                $query->whereBetween('start_time', [$request->start_time, $request->end_time])
                    ->orWhereBetween('end_time', [$request->start_time, $request->end_time]);
            });

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        $conflictingEntries = $query->get();

        foreach ($conflictingEntries as $entry) {
            if ($entry->user_id == $request->user_id && $entry->classroom_id != $request->classroom_id) {
                if ($request->merge_confirmed) {
                    $request->merge(['classroom_id' => $entry->classroom_id]);
                    continue; 
                }
                return response()->json([
                    'error' => "Teacher is already busy in Room {$entry->classroom->name}.",
                    'merge_possible' => true,
                    'existing_classroom_id' => $entry->classroom_id,
                    'existing_classroom_name' => $entry->classroom->name,
                    'existing_class_name' => "{$entry->schoolClass->grade->name}-{$entry->schoolClass->section->name}"
                ], 422);
            }

            if ($entry->classroom_id == $request->classroom_id && $entry->user_id != $request->user_id) {
                return response()->json(['error' => "Classroom is already occupied by another teacher ({$entry->teacher->name})."], 422);
            }

            if ($entry->school_class_id == $request->school_class_id) {
                return response()->json(['error' => 'This class already has a lesson at this time.'], 422);
            }
        }

        return null;
    }

    public function getEntries(Request $request)
    {
        $request->validate([
            'start_date' => 'required|date',
            'end_date'   => 'required|date|after_or_equal:start_date',
        ]);

        $query = TimetableEntry::where('school_id', $request->user()->school_id)
            ->whereBetween('date', [$request->start_date, $request->end_date])
            ->with(['schoolClass.grade', 'schoolClass.section', 'subject', 'teacher:id,name', 'classroom']);

        if ($request->has('school_class_id')) {
            $query->where('school_class_id', $request->school_class_id);
        }

        if ($request->has('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        $entries = $query->orderBy('date')->orderBy('start_time')->get();

        $entryIds = $entries->pluck('id');
        $teacherIds = $entries->pluck('user_id')->unique();
        
        $subs = \App\Models\PeriodSubstitution::whereIn('timetable_entry_id', $entryIds)
            ->whereBetween('date', [$request->start_date, $request->end_date])
            ->with(['substituteTeacher:id,name', 'substituteSubject:id,name'])
            ->get()
            ->groupBy('timetable_entry_id');

        $attendances = \App\Models\Attendance::where('school_id', $request->user()->school_id)
            ->whereIn('user_id', $teacherIds)
            ->whereBetween('date', [$request->start_date, $request->end_date])
            ->get(['user_id', 'date', 'status'])
            ->groupBy(function($a) { return $a->user_id . '_' . $a->getRawOriginal('date'); });

        foreach ($entries as $entry) {
            $entry->substitution = $subs->get($entry->id)?->first();
            
            // Cross-reference attendance
            $dateStr = $entry->getRawOriginal('date');
            $attKey = $entry->user_id . '_' . $dateStr;
            $entry->attendance_status = $attendances->get($attKey)?->first()?->status;
        }

        return response()->json($entries);
    }

    /**
     * Bulk copy / Clone timetable entries from one date range to another.
     */
    public function clone(Request $request)
    {
        $request->validate([
            'source_start' => 'required|date',
            'source_end'   => 'required|date',
            'target_start' => 'required|date',
        ]);

        $schoolId = $request->user()->school_id;
        $sourceStart = \Carbon\Carbon::parse($request->source_start);
        $sourceEnd = \Carbon\Carbon::parse($request->source_end);
        $targetStart = \Carbon\Carbon::parse($request->target_start);

        $daysDiff = $targetStart->diffInDays($sourceStart);

        $entries = TimetableEntry::where('school_id', $schoolId)
            ->whereBetween('date', [$sourceStart, $sourceEnd])
            ->get();

        $newEntries = [];
        foreach ($entries as $entry) {
            $newDate = \Carbon\Carbon::parse($entry->date)->addDays($daysDiff);
            
            // Check if already exists for target date/time/class
            $exists = TimetableEntry::where('school_id', $schoolId)
                ->where('date', $newDate->toDateString())
                ->where('start_time', $entry->start_time)
                ->where('school_class_id', $entry->school_class_id)
                ->exists();

            if (!$exists) {
                $new = $entry->replicate();
                $new->date = $newDate->toDateString();
                $new->save();
                $newEntries[] = $new;
            }
        }

        return response()->json([
            'success' => true, 
            'count' => count($newEntries),
            'message' => "Successfully cloned " . count($newEntries) . " periods to the target week."
        ]);
    }
}
