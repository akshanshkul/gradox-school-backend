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
                return $this->errorResponse('No available classrooms found for this period.', 422);
            }
            $request->merge(['classroom_id' => $classroomId]);
        }

        // Conflict check
        $conflict = $this->checkConflicts($request, $school_id);
        if ($conflict)
            return $conflict;

        // Verify teaching status
        $teacher = \App\Models\User::find($request->user_id);
        if ($teacher && !$teacher->is_teaching) {
            return $this->errorResponse('Selected staff member is not part of the teaching faculty.', 422);
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
                return $this->errorResponse('No available classrooms found for this period.', 422);
            }
            $request->merge(['classroom_id' => $classroomId]);
        }

        // Conflict check (excluding current entry)
        $conflict = $this->checkConflicts($request, $school_id, $id);
        if ($conflict)
            return $conflict;

        // Verify teaching status
        $teacher = \App\Models\User::find($request->user_id);
        if ($teacher && !$teacher->is_teaching) {
            return $this->errorResponse('Selected staff member is not part of the teaching faculty.', 422);
        }

        $entry->update($request->all());

        return response()->json($entry->load(['schoolClass', 'subject', 'teacher', 'classroom']));
    }

    public function deleteEntry(Request $request, $id)
    {
        $entry = TimetableEntry::where('school_id', $request->user()->school_id)->findOrFail($id);
        $entry->delete();
        return $this->successResponse(null, 'Entry deleted successfully');
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
                return $this->errorResponse("Teacher is already busy in " . ($entry->classroom?->name ?? 'an unassigned room') . ".", 422, [
                    'merge_possible' => true,
                    'existing_classroom_id' => $entry->classroom_id,
                    'existing_classroom_name' => $entry->classroom?->name ?? 'Unassigned Room',
                    'existing_class_name' => ($entry->schoolClass?->grade?->name ?? '?') . "-" . ($entry->schoolClass?->section?->name ?? '?')
                ]);
            }

            if ($entry->classroom_id == $request->classroom_id && $entry->user_id != $request->user_id) {
                return $this->errorResponse("Classroom is already occupied by another teacher (" . ($entry->teacher?->name ?? 'Unknown') . ").", 422);
            }

            if ($entry->school_class_id == $request->school_class_id) {
                return $this->errorResponse('This class already has a lesson at this time.', 422);
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

        $startDate = \Carbon\Carbon::parse($request->start_date);
        $endDate = \Carbon\Carbon::parse($request->end_date);
        $schoolId = $request->user()->school_id;

        // 1. Fetch entries that have a specific date in this range
        $dateSpecificQuery = TimetableEntry::where('school_id', $schoolId)
            ->whereBetween('date', [$request->start_date, $request->end_date])
            ->with(['schoolClass.grade', 'schoolClass.section', 'subject', 'teacher:id,name', 'classroom']);

        if ($request->has('school_class_id')) {
            $dateSpecificQuery->where('school_class_id', $request->school_class_id);
        }

        if ($request->has('user_id')) {
            $dateSpecificQuery->where('user_id', $request->user_id);
        }

        $dateSpecificEntries = $dateSpecificQuery->get();

        // 2. Fetch recurring entries (date is null, day_of_week is set)
        $recurringQuery = TimetableEntry::where('school_id', $schoolId)
            ->whereNull('date')
            ->whereNotNull('day_of_week')
            ->with(['schoolClass.grade', 'schoolClass.section', 'subject', 'teacher:id,name', 'classroom']);

        if ($request->has('school_class_id')) {
            $recurringQuery->where('school_class_id', $request->school_class_id);
        }

        if ($request->has('user_id')) {
            $recurringQuery->where('user_id', $request->user_id);
        }

        $recurringEntries = $recurringQuery->get();

        // 3. Map recurring entries to actual dates in the requested range
        $allEntries = collect($dateSpecificEntries);
        
        $currentDate = $startDate->copy();
        while ($currentDate <= $endDate) {
            $dayName = $currentDate->format('l'); // e.g. "Friday"
            $dateStr = $currentDate->toDateString();

            foreach ($recurringEntries as $recurring) {
                if (strtolower($recurring->day_of_week) === strtolower($dayName)) {
                    // Check if a date-specific entry already exists for this slot (Override)
                    $exists = $dateSpecificEntries->contains(function ($item) use ($dateStr, $recurring) {
                        return $item->date == $dateStr && 
                               $item->start_time == $recurring->start_time && 
                               $item->school_class_id == $recurring->school_class_id;
                    });

                    if (!$exists) {
                        $virtualEntry = $recurring->replicate();
                        $virtualEntry->id = $recurring->id; // Keep original ID for reference
                        $virtualEntry->date = $dateStr;
                        $virtualEntry->setRelation('schoolClass', $recurring->schoolClass);
                        $virtualEntry->setRelation('subject', $recurring->subject);
                        $virtualEntry->setRelation('teacher', $recurring->teacher);
                        $virtualEntry->setRelation('classroom', $recurring->classroom);
                        $allEntries->push($virtualEntry);
                    }
                }
            }
            $currentDate->addDay();
        }

        $allEntries = $allEntries->sortBy(['date', 'start_time'])->values();

        $entryIds = $allEntries->pluck('id');
        $teacherIds = $allEntries->pluck('user_id')->unique();
        
        $subs = \App\Models\PeriodSubstitution::whereIn('timetable_entry_id', $entryIds)
            ->whereBetween('date', [$request->start_date, $request->end_date])
            ->with(['substituteTeacher:id,name', 'substituteSubject:id,name'])
            ->get()
            ->groupBy(function($s) { return $s->timetable_entry_id . '_' . $s->getRawOriginal('date'); });

        $attendances = \App\Models\Attendance::where('school_id', $schoolId)
            ->whereIn('user_id', $teacherIds)
            ->whereBetween('date', [$request->start_date, $request->end_date])
            ->get(['user_id', 'date', 'status'])
            ->groupBy(function($a) { return $a->user_id . '_' . $a->getRawOriginal('date'); });

        foreach ($allEntries as $entry) {
            // Substitution lookup: needs ID + Date because multiple dates share same recurring ID
            $dateStr = $entry instanceof TimetableEntry ? $entry->getRawOriginal('date') : $entry->date;
            $subKey = $entry->id . '_' . $dateStr;
            $entry->substitution = $subs->get($subKey)?->first();
            
            // Cross-reference attendance
            $attKey = $entry->user_id . '_' . $dateStr;
            $entry->attendance_status = $attendances->get($attKey)?->first()?->status;
        }

        return $this->successResponse($allEntries, 'Timetable entries retrieved successfully');
    }

    /**
     * Bulk copy / Clone timetable entries from one date range to another.
     */
    public function clone(Request $request)
    {
        $request->validate([
            'source_start' => 'required|date',
            'source_end' => 'required|date',
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

        return $this->successResponse([
            'count' => count($newEntries),
        ], "Successfully cloned " . count($newEntries) . " periods to the target week.");
    }
}
