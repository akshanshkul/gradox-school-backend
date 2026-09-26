<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Grade;
use App\Models\Section;
use App\Models\SchoolClass;
use App\Models\Subject;

use App\Models\Classroom;
use App\Models\SchoolEvent;
use App\Models\RoleWorkloadConfig;
use App\Models\SchoolPeriod;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class SchoolController extends Controller
{
    public function addTeacher(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => [
                'required',
                'email',
                \Illuminate\Validation\Rule::unique('users', 'email')->where(function ($query) use ($request) {
                    return $query->where('school_id', $request->user()->school_id);
                }),
            ],
            'password' => 'required|string|min:8',
            'role_id' => 'required_without:role|exists:roles,id',
            'role' => 'required_without:role_id|string',
            'profile_picture' => 'nullable|image|max:2048',
            'is_teaching' => 'required|string', // "true" or "false" from FormData
            'staff_subtype' => 'nullable|string|max:255',
        ]);

        $roleId = $request->role_id;
        if (!$roleId && $request->role) {
            $role = Role::where('slug', $request->role)->where('school_id', $request->user()->school_id)->first();
            if (!$role) {
                return response()->json(['success' => 0, 'message' => 'Valid role mapping not found.'], 422);
            }
            $roleId = $role->id;
        }

        $profilePicturePath = null;
        if ($request->hasFile('profile_picture')) {
            $path = $request->file('profile_picture')->store('user_profile', ['disk' => 's3']);
            if ($path) {
                // Determine public URL from S3
                $profilePicturePath = Storage::disk('s3')->url($path);
            } else {
                \Illuminate\Support\Facades\Log::error('Failed to upload profile picture to S3.');
            }
        }

        $teacher = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'school_id' => $request->user()->school_id,
            'role_id' => $roleId,
            'is_teaching' => $request->is_teaching === 'true',
            'staff_subtype' => $request->staff_subtype,
            'profile_picture' => $profilePicturePath,
            'teacher_details' => [
                'education' => [],
                'specializations' => [],
                'personal_email' => null
            ]
        ]);

        try {
            \Illuminate\Support\Facades\Mail::to($teacher->email)->send(new \App\Mail\WelcomeTeamMember($teacher, $request->password));
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Welcome mail failed: " . $e->getMessage());
        }

        return $this->successResponse($teacher->load('role_relation'), 'Teacher added successfully and welcome mail sent.');
    }

    public function getTeachers(Request $request)
    {
        try {
            set_time_limit(120);
            // IMPORTANT: include `teacher_details`, `permission_overrides`,
            // and `staff_subtype` in the SELECT.
            //
            // These power the staff profile page's editable sections —
            // Specializations (teacher_details.specializations), Education
            // (teacher_details.education), Off-Periods, RBAC overrides.
            // Omitting them silently broke the "Assign Subject" flow:
            // backend saves successfully, but after the forced refresh
            // the teacher arrived without teacher_details and the panel
            // re-rendered with "No subjects currently assigned" even
            // though the JSON column was set on disk.
            return $this->successResponse(
                $request->user()->school->users()
                    ->where('status', 'active')
                    ->select(
                        'users.id',
                        'users.name',
                        'users.profile_picture',
                        'users.email',
                        'users.role_id',
                        'users.school_id',
                        'users.is_teaching',
                        'users.staff_subtype',
                        'users.teacher_details',
                        'users.permission_overrides'
                    )
                    ->with('role_relation:id,name,slug')
                    ->get()
            );
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function getClasses(Request $request)
    {
        try {
            set_time_limit(120);
            return $this->successResponse(
                $request->user()->school->classes()
                    ->select('id', 'grade_id', 'section_id', 'class_teacher_id', 'default_classroom_id', 'school_id')
                    ->with([
                        'grade:id,name',
                        'section:id,name',
                        'classTeacher:id,name',
                        'defaultClassroom:id,name',
                        'subjects:id,name'
                    ])
                    ->get()
            );
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function getSessions(Request $request)
    {
        return $this->successResponse(
            \App\Models\Session::where('school_id', $request->user()->school_id)
                ->orderBy('start_date', 'desc')
                ->get()
        );
    }

    public function getInactiveStaff(Request $request)
    {
        return $this->successResponse($request->user()->school->users()->whereIn('status', ['inactive', 'exit'])->with('role_relation')->get());
    }

    public function exportTeachers(Request $request)
    {
        return $this->successResponse($request->user()->school->users()->where('status', 'active')->with('role_relation')->get());
    }

    public function addGrade(Request $request)
    {
        $request->validate(['name' => 'required|string']);

        $canonicalName = $this->getCanonicalGradeName($request->name);
        if (!$canonicalName) {
            return $this->errorResponse('Please enter a valid grade level (Pre-Nursery, Nursery, LKG, UKG, or 1st to 12th).', 422);
        }

        $exists = Grade::where('school_id', $request->user()->school_id)
            ->whereRaw('LOWER(name) = ?', [strtolower($canonicalName)])
            ->exists();

        if ($exists) {
            return $this->errorResponse("Class '{$canonicalName}' already exists.", 422);
        }

        $grade = Grade::create([
            'name' => $canonicalName,
            'school_id' => $request->user()->school_id,
        ]);
        return $this->successResponse($grade, 'Grade level created successfully');
    }

    public function getGrades(Request $request)
    {
        return $this->successResponse(
            Grade::where('school_id', $request->user()->school_id)
                ->orderBy('name')
                ->get()
        );
    }

    public function addSection(Request $request)
    {
        $request->validate(['name' => 'required|string']);

        $canonicalName = $this->getCanonicalSectionName($request->name);
        if (!$canonicalName) {
            return $this->errorResponse('Please enter a valid section (e.g. A, B, or A1). Only single letters or letters followed by a single digit are allowed.', 422);
        }

        $exists = Section::where('school_id', $request->user()->school_id)
            ->whereRaw('LOWER(name) = ?', [strtolower($canonicalName)])
            ->exists();

        if ($exists) {
            return $this->errorResponse("Section '{$canonicalName}' already exists.", 422);
        }

        $section = Section::create([
            'name' => $canonicalName,
            'school_id' => $request->user()->school_id,
        ]);
        return $this->successResponse($section, 'Section created successfully');
    }

    public function getSections(Request $request)
    {
        return $this->successResponse(
            Section::where('school_id', $request->user()->school_id)
                ->orderBy('name')
                ->get()
        );
    }

    public function addClass(Request $request)
    {
        $request->validate([
            'grade_id' => 'required|exists:grades,id',
            'section_id' => 'required|exists:sections,id',
            'default_classroom_id' => 'nullable|exists:classrooms,id',
            'periods_per_day' => 'nullable|integer|min:1|max:12',
        ]);

        $schoolClass = SchoolClass::create([
            'grade_id' => $request->grade_id,
            'section_id' => $request->section_id,
            'class_teacher_id' => $request->class_teacher_id,
            'default_classroom_id' => $request->default_classroom_id,
            'periods_per_day' => $request->periods_per_day,
            'school_id' => $request->user()->school_id,
        ]);

        if ($schoolClass->class_teacher_id) {
            try {
                \App\Models\Notification::create([
                    'school_id' => $schoolClass->school_id,
                    'notifiable_type' => \App\Models\User::class,
                    'notifiable_id' => $schoolClass->class_teacher_id,
                    'title' => 'Class Assigned',
                    'message' => "You have been assigned as the Class Teacher for {$schoolClass->grade->name} {$schoolClass->section->name}.",
                    'type' => 'success',
                    'data' => ['type' => 'class_assigned', 'class_id' => $schoolClass->id]
                ]);
            } catch (\Exception $e) {
            }
        }

        return $this->successResponse($schoolClass->load(['grade', 'section', 'classTeacher', 'defaultClassroom']), 'Class mapping created successfully');
    }

    public function updateClass(Request $request, $id)
    {
        $request->validate([
            'grade_id' => 'required|exists:grades,id',
            'section_id' => 'required|exists:sections,id',
            'default_classroom_id' => 'nullable|exists:classrooms,id',
            'periods_per_day' => 'nullable|integer|min:1|max:12',
        ]);

        $schoolClass = SchoolClass::where('id', $id)
            ->where('school_id', $request->user()->school_id)
            ->firstOrFail();

        $this->authorize('update', $schoolClass);

        $schoolClass->update([
            'grade_id' => $request->grade_id,
            'section_id' => $request->section_id,
            'class_teacher_id' => $request->class_teacher_id,
            'default_classroom_id' => $request->default_classroom_id,
            'periods_per_day' => $request->periods_per_day,
        ]);

        if ($schoolClass->wasChanged('class_teacher_id') && $schoolClass->class_teacher_id) {
            try {
                \App\Models\Notification::create([
                    'school_id' => $schoolClass->school_id,
                    'notifiable_type' => \App\Models\User::class,
                    'notifiable_id' => $schoolClass->class_teacher_id,
                    'title' => 'Class Assigned',
                    'message' => "You have been assigned as the Class Teacher for {$schoolClass->grade->name} {$schoolClass->section->name}.",
                    'type' => 'success',
                    'data' => ['type' => 'class_assigned', 'class_id' => $schoolClass->id]
                ]);
            } catch (\Exception $e) {
            }
        }

        return $this->successResponse($this->formatClassWithSubjects($schoolClass), 'Class configuration updated successfully');
    }

    public function syncSubjects(Request $request, $id)
    {
        $request->validate([
            'subjects' => 'array',
            'subjects.*.id' => 'required|exists:subjects,id',
            'subjects.*.periods_per_week' => 'nullable|integer|min:1',
            'subjects.*.teacher_id' => 'nullable|exists:users,id',
        ]);

        $schoolClass = SchoolClass::where('id', $id)
            ->where('school_id', $request->user()->school_id)
            ->firstOrFail();

        $syncData = [];
        foreach ($request->subjects as $item) {
            $syncData[$item['id']] = [
                'periods_per_week' => $item['periods_per_week'] ?? 1,
                'teacher_id' => $item['teacher_id'] ?? null,
            ];
        }

        $schoolClass->subjects()->sync($syncData);

        return $this->successResponse($this->formatClassWithSubjects($schoolClass), 'Subjects synced successfully to class');
    }

    public function updateClassSubjectDetails(Request $request, $classId, $subjectId)
    {
        $user = $request->user();
        $schoolClass = SchoolClass::where('id', $classId)
            ->where('school_id', $user->school_id)
            ->firstOrFail();

        $pivot = \DB::table('class_subject')
            ->where('school_class_id', $classId)
            ->where('subject_id', $subjectId)
            ->first();

        if (!$pivot) {
            return $this->errorResponse('Subject not assigned to this class.', 404);
        }

        // Authorization checks:
        // 1. School Admin (isAdmin() is true)
        // 2. Class Teacher (schoolClass->class_teacher_id === user->id)
        // 3. Subject Teacher (pivot->teacher_id === user->id)
        $isAuthorized = $user->isAdmin()
            || ((int) $schoolClass->class_teacher_id === (int) $user->id)
            || ((int) $pivot->teacher_id === (int) $user->id);

        if (!$isAuthorized) {
            return $this->errorResponse('You are not authorized to edit this subject\'s notes or syllabus.', 403);
        }

        $request->validate([
            'notes' => 'nullable|array',
            'notes.*.id' => 'nullable|integer',
            'notes.*.title' => 'required|string|max:255',
            'notes.*.file_url' => 'nullable|string',
            'notes.*.description' => 'nullable|string',
            'syllabus' => 'nullable|array',
            'syllabus.*.id' => 'nullable|integer',
            'syllabus.*.topic' => 'required|string|max:255',
            'syllabus.*.description' => 'nullable|string',
            'syllabus.*.status' => 'required|in:pending,in-progress,completed',
            'teacher_id' => 'nullable|exists:users,id',
            'periods_per_week' => 'nullable|integer|min:1',
            // HTML produced by the Lesson Plan rich-text editor. Capped at
            // 5MB string to stay well below MySQL longText (16MB) and keep
            // request bodies sane. Real-world lesson plans rarely exceed
            // a few hundred KB even after importing a long .docx.
            'lesson_plan' => 'nullable|string|max:5242880',
        ]);

        $updateData = [];

        // Only admins or class teachers can change the assigned teacher or periods per week
        $canManageAssignment = $user->isAdmin() || ((int) $schoolClass->class_teacher_id === (int) $user->id);
        if ($canManageAssignment) {
            if ($request->has('teacher_id'))
                $updateData['teacher_id'] = $request->teacher_id;
            if ($request->has('periods_per_week'))
                $updateData['periods_per_week'] = $request->periods_per_week;
        }

        // Lesson plan is editable by anyone the auth check above already
        // allowed (admin, class teacher, OR subject teacher). It lives on
        // the pivot row itself — no child table — so we just merge it into
        // the same update payload as teacher_id / periods_per_week. We
        // explicitly normalise empty string → null so an editor cleared
        // back to blank stores NULL instead of an empty <p></p>.
        if ($request->has('lesson_plan')) {
            $plan = $request->input('lesson_plan');
            $stripped = trim(strip_tags((string) $plan));
            $updateData['lesson_plan'] = $stripped === '' ? null : $plan;
        }

        if (!empty($updateData)) {
            \DB::table('class_subject')
                ->where('school_class_id', $classId)
                ->where('subject_id', $subjectId)
                ->update($updateData);
        }

        // 1. Sync notes
        if ($request->has('notes')) {
            $existingNoteIds = \DB::table('class_subject_notes')
                ->where('class_subject_id', $pivot->id)
                ->pluck('id')
                ->toArray();

            $updatedNoteIds = [];
            foreach ($request->notes ?: [] as $noteData) {
                if (!empty($noteData['id']) && in_array((int) $noteData['id'], $existingNoteIds)) {
                    \DB::table('class_subject_notes')
                        ->where('id', $noteData['id'])
                        ->update([
                            'title' => $noteData['title'],
                            'file_url' => $noteData['file_url'] ?? null,
                            'description' => $noteData['description'] ?? null,
                            'updated_at' => now(),
                        ]);
                    $updatedNoteIds[] = (int) $noteData['id'];
                } else {
                    $newId = \DB::table('class_subject_notes')->insertGetId([
                        'class_subject_id' => $pivot->id,
                        'title' => $noteData['title'],
                        'file_url' => $noteData['file_url'] ?? null,
                        'description' => $noteData['description'] ?? null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $updatedNoteIds[] = $newId;
                }
            }

            // Delete notes that are no longer in the request
            $notesToDelete = array_diff($existingNoteIds, $updatedNoteIds);
            if (!empty($notesToDelete)) {
                \DB::table('class_subject_notes')
                    ->whereIn('id', $notesToDelete)
                    ->delete();
            }
        }

        // 2. Sync syllabus
        if ($request->has('syllabus')) {
            $existingSyllabusIds = \DB::table('class_subject_syllabus')
                ->where('class_subject_id', $pivot->id)
                ->pluck('id')
                ->toArray();

            $updatedSyllabusIds = [];
            foreach ($request->syllabus ?: [] as $syllabusData) {
                if (!empty($syllabusData['id']) && in_array((int) $syllabusData['id'], $existingSyllabusIds)) {
                    \DB::table('class_subject_syllabus')
                        ->where('id', $syllabusData['id'])
                        ->update([
                            'topic' => $syllabusData['topic'],
                            'description' => $syllabusData['description'] ?? null,
                            'status' => $syllabusData['status'],
                            'updated_at' => now(),
                        ]);
                    $updatedSyllabusIds[] = (int) $syllabusData['id'];
                } else {
                    $newId = \DB::table('class_subject_syllabus')->insertGetId([
                        'class_subject_id' => $pivot->id,
                        'topic' => $syllabusData['topic'],
                        'description' => $syllabusData['description'] ?? null,
                        'status' => $syllabusData['status'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $updatedSyllabusIds[] = $newId;
                }
            }

            // Delete syllabus items that are no longer in the request
            $syllabusToDelete = array_diff($existingSyllabusIds, $updatedSyllabusIds);
            if (!empty($syllabusToDelete)) {
                \DB::table('class_subject_syllabus')
                    ->whereIn('id', $syllabusToDelete)
                    ->delete();
            }
        }

        $updatedPivot = \DB::table('class_subject')
            ->where('school_class_id', $classId)
            ->where('subject_id', $subjectId)
            ->first();

        if ($updatedPivot) {
            $updatedPivot->notes = \DB::table('class_subject_notes')
                ->where('class_subject_id', $updatedPivot->id)
                ->get()
                ->toArray();
            $updatedPivot->syllabus = \DB::table('class_subject_syllabus')
                ->where('class_subject_id', $updatedPivot->id)
                ->get()
                ->toArray();
        }

        // Flush caches
        try {
            \App\Services\SafeCache::forgetPrefix("school_{$user->school_id}_url_cache");
        } catch (\Throwable $e) {
        }

        return $this->successResponse($updatedPivot, 'Class subject details updated successfully');
    }

    public function uploadSubjectNoteFile(Request $request, $classId, $subjectId)
    {
        $request->validate([
            'file' => 'required|file|max:15360', // 15MB max for PDFs/materials
        ]);

        $user = $request->user();

        // Make sure class exists in this school
        $schoolClass = SchoolClass::where('id', $classId)
            ->where('school_id', $user->school_id)
            ->firstOrFail();

        $path = $request->file('file')->store('school/notes', ['disk' => 's3']);
        if (!$path) {
            return $this->errorResponse('Failed to upload file to cloud storage.', 500);
        }

        $url = \Storage::disk('s3')->url($path);

        return $this->successResponse([
            'file_url' => $url,
            'file_name' => $request->file('file')->getClientOriginalName(),
        ], 'File uploaded successfully');
    }

    public function getSubjects(Request $request)
    {
        return $this->successResponse(
            \App\Models\Subject::where('school_id', $request->user()->school_id)
                ->orderBy('name', 'asc')
                ->get()
        );
    }

    public function addSubject(Request $request)
    {
        $request->validate(['name' => 'required|string', 'code' => 'nullable|string']);
        $subject = Subject::create([
            'name' => $request->name,
            'code' => $request->code,
            'school_id' => $request->user()->school_id,
        ]);
        return $this->successResponse($subject, 'Subject added successfully');
    }

    public function addClassroom(Request $request)
    {
        $request->validate([
            'name' => 'required|string',
            'type' => 'nullable|string',
            'capacity' => 'nullable|integer'
        ]);

        $classroom = Classroom::create([
            'name' => $request->name,
            'type' => $request->type,
            'capacity' => $request->capacity,
            'school_id' => $request->user()->school_id,
        ]);

        return $this->successResponse($classroom, 'Classroom added successfully');
    }

    public function getClassrooms(Request $request)
    {
        return $this->successResponse(
            Classroom::where('school_id', $request->user()->school_id)
                ->orderBy('name')
                ->get()
        );
    }

    /**
     * Active teaching assignments for one staff member, derived from
     * the `class_subject` pivot — i.e. "what is this teacher actually
     * teaching right now?"
     *
     * This is intentionally separate from the JSON
     * `users.teacher_details->specializations` list, which represents
     * INSTITUTIONAL QUALIFICATIONS ("certified to teach X"), not
     * current assignments ("is teaching X to class Y this term").
     *
     * The two are deliberately decoupled in the schema — a teacher
     * can be qualified for 8 subjects but only teaching 3 this year,
     * or vice versa (substitute scenarios). This endpoint feeds the
     * read-only "Currently Teaching" panel that sits next to the
     * editable Specializations panel on the staff profile.
     *
     * Shape: { rows: [{ class_id, class_label, subject_id,
     *                   subject_name, periods_per_week }] }
     */
    public function getTeachingAssignments($id, Request $request)
    {
        $user = $request->user();

        // Multi-tenant guard — only allow lookups against teachers in
        // the caller's school.
        $teacher = User::where('id', $id)
            ->where('school_id', $user->school_id)
            ->firstOrFail();

        // The pivot table `class_subject` carries teacher_id. We join
        // through SchoolClass so we can filter by school_id (defence
        // in depth — the FK alone could leak across schools through
        // a bad relation in future).
        $rows = \DB::table('class_subject as cs')
            ->join('school_classes as c', 'c.id', '=', 'cs.school_class_id')
            ->leftJoin('grades as g', 'g.id', '=', 'c.grade_id')
            ->leftJoin('sections as s', 's.id', '=', 'c.section_id')
            ->join('subjects as sub', 'sub.id', '=', 'cs.subject_id')
            ->where('cs.teacher_id', $teacher->id)
            ->where('c.school_id', $user->school_id)
            ->select(
                'c.id as class_id',
                'g.name as grade_name',
                's.name as section_name',
                'sub.id as subject_id',
                'sub.name as subject_name',
                'cs.periods_per_week'
            )
            ->orderBy('g.name')
            ->orderBy('s.name')
            ->orderBy('sub.name')
            ->get()
            ->map(function ($r) {
                return [
                    'class_id' => $r->class_id,
                    'class_label' => trim(($r->grade_name ?? '') . ' - ' . ($r->section_name ?? ''), ' -'),
                    'subject_id' => $r->subject_id,
                    'subject_name' => $r->subject_name,
                    'periods_per_week' => $r->periods_per_week,
                ];
            });

        return $this->successResponse(['rows' => $rows]);
    }

    public function updateTeacherDetails($id, Request $request)
    {
        $teacher = User::where('id', $id)->where('school_id', $request->user()->school_id)->firstOrFail();

        $request->validate([
            'role_id' => 'sometimes|required|exists:roles,id',
            'is_teaching' => 'nullable|boolean',
            'staff_subtype' => 'nullable|string|max:255',
            'education' => 'nullable|array',
            'education.*.level' => 'required|string',
            'education.*.degree' => 'required|string',
            'education.*.institution' => 'required|string',
            'education.*.year' => 'required|string',
            'personal_email' => 'nullable|email',
            'specializations' => 'nullable|array',
            'specializations.*.subject_id' => 'required|integer',
            'specializations.*.type' => 'required|string',
            'specializations.*.specific_grades' => 'nullable|string',
            'permission_overrides' => 'nullable|array', // Transitioned from 'permissions'
        ]);

        if ($request->has('role_id')) {
            $role = Role::where('id', $request->role_id)->where('school_id', $request->user()->school_id)->first();
            if ($role) {
                $teacher->role_id = $role->id;
            }
        }

        if ($request->has('is_teaching'))
            $teacher->is_teaching = $request->is_teaching;
        if ($request->has('staff_subtype'))
            $teacher->staff_subtype = $request->staff_subtype;
        if ($request->has('permission_overrides'))
            $teacher->permission_overrides = $request->permission_overrides;

        $details = $teacher->teacher_details ?? [];
        if ($request->has('education'))
            $details['education'] = $request->education;
        if ($request->has('personal_email'))
            $details['personal_email'] = $request->personal_email;
        if ($request->has('specializations'))
            $details['specializations'] = $request->specializations;
        if ($request->has('off_periods'))
            $details['off_periods'] = $request->off_periods;

        $teacher->teacher_details = $details;
        $teacher->save();

        // Notify teacher of profile update
        try {
            \App\Models\Notification::create([
                'school_id' => $teacher->school_id,
                'notifiable_type' => get_class($teacher),
                'notifiable_id' => $teacher->id,
                'title' => 'Profile Updated',
                'message' => 'Your institutional profile details have been updated by the administrator.',
                'type' => 'info',
                'data' => ['type' => 'profile_update']
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Failed to create notification: " . $e->getMessage());
        }

        return $this->successResponse($teacher->load('role_relation'), 'Teacher details updated successfully');
    }

    public function deleteTeacher($id, Request $request)
    {
        $teacher = User::where('id', $id)->where('school_id', $request->user()->school_id)->firstOrFail();

        $request->validate([
            'status' => 'required|in:inactive,exit',
            'exit_date' => 'required_if:status,exit|nullable|date',
        ]);

        $teacher->update([
            'status' => $request->status,
            'exit_date' => $request->status === 'exit' ? $request->exit_date : null,
        ]);

        return response()->json(['success' => true]);
    }

    public function deleteGrade($id, Request $request)
    {
        $grade = Grade::where('id', $id)->where('school_id', $request->user()->school_id)->firstOrFail();
        $grade->delete();
        return response()->json(['success' => true]);
    }

    public function deleteSection($id, Request $request)
    {
        $section = Section::where('id', $id)->where('school_id', $request->user()->school_id)->firstOrFail();
        $section->delete();
        return response()->json(['success' => true]);
    }

    public function deleteClass($id, Request $request)
    {
        $class = SchoolClass::where('id', $id)->where('school_id', $request->user()->school_id)->firstOrFail();
        $class->delete();
        return response()->json(['success' => true]);
    }

    public function deleteSubject($id, Request $request)
    {
        $subject = Subject::where('id', $id)->where('school_id', $request->user()->school_id)->firstOrFail();
        $subject->delete();
        return response()->json(['success' => true]);
    }

    public function deleteClassroom($id, Request $request)
    {
        $classroom = Classroom::where('id', $id)->where('school_id', $request->user()->school_id)->firstOrFail();
        $classroom->delete();
        return response()->json(['success' => true]);
    }

    public function addEvent(Request $request)
    {
        $request->validate([
            'name' => 'required|string',
            'type' => 'required|in:holiday,event',
            'duration' => 'required|in:full,half',
            'target_type' => 'required|in:all,class',
            'school_class_id' => 'nullable|exists:school_classes,id',
            'date' => 'required|date',
        ]);

        $event = SchoolEvent::create([
            ...$request->all(),
            'school_id' => $request->user()->school_id,
        ]);

        return $this->successResponse($event->load('schoolClass'), 'Calendar event created successfully');
    }

    public function deleteEvent($id, Request $request)
    {
        $event = SchoolEvent::where('id', $id)->where('school_id', $request->user()->school_id)->firstOrFail();
        $event->delete();
        return response()->json(['success' => true]);
    }

    public function getEvents(Request $request)
    {
        return $this->successResponse(
            SchoolEvent::where('school_id', $request->user()->school_id)
                ->with('schoolClass.grade', 'schoolClass.section')
                ->get()
        );
    }

    public function getPeriods(Request $request)
    {
        return $this->successResponse(
            SchoolPeriod::where('school_id', $request->user()->school_id)
                ->orderBy('start_time')
                ->orderBy('sort_order')
                ->get()
        );
    }

    public function addPeriod(Request $request)
    {
        $request->validate([
            'name' => 'required|string',
            'start_time' => 'required',
            'end_time' => 'required',
            'type' => 'required|in:class,lunch,assembly,break',
            'sort_order' => 'nullable|integer',
        ]);

        $school_id = $request->user()->school_id;
        $period = SchoolPeriod::create([
            ...$request->all(),
            'school_id' => $school_id,
        ]);

        // Recalculate sort order for the school
        $this->recalculatePeriodSortOrder($school_id);

        return response()->json($period);
    }

    public function deletePeriod($id, Request $request)
    {
        $period = SchoolPeriod::where('id', $id)->where('school_id', $request->user()->school_id)->firstOrFail();
        $period->delete();

        return response()->json(['success' => true]);
    }

    public function resetStaffPassword(Request $request, $id)
    {
        $staff = User::where('id', $id)->where('school_id', $request->user()->school_id)->firstOrFail();

        $newPassword = \Illuminate\Support\Str::random(10);
        $staff->update([
            'password' => Hash::make($newPassword)
        ]);

        try {
            \Illuminate\Support\Facades\Mail::to($staff->email)->send(new \App\Mail\StaffPasswordResetMail($staff, $newPassword));

            // App Notification
            \App\Models\Notification::create([
                'school_id' => $staff->school_id,
                'notifiable_type' => get_class($staff),
                'notifiable_id' => $staff->id,
                'title' => 'Security Alert: Password Reset',
                'message' => 'Your account password has been reset by the administrator. Please check your email for the new credentials.',
                'type' => 'warning',
                'data' => ['type' => 'password_reset']
            ]);
        } catch (\Exception $e) {
            // Log the error but return the password so the admin can give it manually if mail fails
            \Illuminate\Support\Facades\Log::error("Mail failed: " . $e->getMessage());
        }

        return $this->successResponse([
            'new_password' => $newPassword,
        ], 'Password reset successful and sent to staff email.');
    }

    public function getBootstrapData(Request $request)
    {
        $user = $request->user();
        $user->load(['school', 'role_relation']);
        $user->loadCount('managedClasses');

        $school = $user->school;

        $effectiveGraceDays = $school->grace_days > 0
            ? (int) $school->grace_days
            : (int) env('SUBSCRIPTION_GRACE_DAYS', 0);

        return $this->successResponse([
            'school' => [
                'id' => $school->id,
                'name' => $school->name,
                'slug' => $school->slug,
                'logo_path' => $school->logo_path,
                'current_session' => $school->current_session,
                'plan_name' => $school->plan_name,
                'subscription_status' => $school->subscription_status,
            ],
            'effective_grace_days' => $effectiveGraceDays,
            'user_permissions' => $user->role_relation ? $user->role_relation->permissions : [],
            'managed_classes_count' => $user->managed_classes_count,
        ]);
    }

    public function getConfiguration(Request $request)
    {
        $schoolId = $request->user()->school_id;
        $requestedKeys = $request->query('only') ? explode(',', $request->query('only')) : null;
        $shouldLoad = fn($key) => !$requestedKeys || in_array($key, $requestedKeys);

        $data = [];

        if ($shouldLoad('sections'))
            $data['sections'] = Section::where('school_id', $schoolId)->orderBy('name')->get();

        if ($shouldLoad('subjects'))
            $data['subjects'] = Subject::where('school_id', $schoolId)->orderBy('name')->get();

        if ($shouldLoad('classrooms'))
            $data['classrooms'] = Classroom::where('school_id', $schoolId)->orderBy('name')->get();

        if ($shouldLoad('periods'))
            $data['periods'] = SchoolPeriod::where('school_id', $schoolId)
                ->orderBy('start_time')
                ->orderBy('sort_order')
                ->get();

        return $this->successResponse($data);
    }

    public function updateRoleConfig(Request $request)
    {
        $request->validate([
            'role_name' => 'required|in:teacher,incharge,admin',
            'min_classes_per_day' => 'required|integer|min:0',
            'max_classes_per_day' => 'required|integer|gte:min_classes_per_day',
        ]);

        $config = RoleWorkloadConfig::updateOrCreate(
            ['school_id' => $request->user()->school_id, 'role_name' => $request->role_name],
            $request->only(['min_classes_per_day', 'max_classes_per_day'])
        );

        return $this->successResponse($config, 'Role workload configuration updated');
    }

    public function getData(Request $request)
    {
        try {
            set_time_limit(120);
            $schoolId = $request->user()->school_id;
            $user = $request->user();

            // Support for "light" requests: only load specific keys if requested
            $requestedKeys = $request->query('only') ? explode(',', $request->query('only')) : null;
            $shouldLoad = fn($key) => !$requestedKeys || in_array($key, $requestedKeys);

            // 1. Build the eager loading array dynamically based on requested keys
            $with = [];

            if ($shouldLoad('grades'))
                $with[] = 'grades:id,name,school_id';
            if ($shouldLoad('sections'))
                $with[] = 'sections:id,name,school_id';
            if ($shouldLoad('subjects'))
                $with[] = 'subjects:id,name,code,school_id';
            if ($shouldLoad('classrooms'))
                $with[] = 'classrooms:id,name,capacity,school_id';
            if ($shouldLoad('role_configs'))
                $with[] = 'roleWorkloadConfigs:id,school_id,role_name,min_classes_per_day,max_classes_per_day';
            if ($shouldLoad('periods'))
                $with[] = 'periods:id,school_id,name,start_time,end_time,sort_order,type';

            if ($shouldLoad('events')) {
                $with['events'] = fn($q) => $q->select('id', 'school_id', 'name', 'date', 'type', 'school_class_id')
                    ->with('schoolClass:id,grade_id,section_id');
            }

            if ($shouldLoad('teachers')) {
                $with['users'] = fn($q) => $q->where('status', 'active')
                    ->select('users.id', 'users.name', 'users.profile_picture', 'users.email', 'users.role_id', 'users.school_id', 'users.is_teaching')
                    ->with('role_relation:id,name,slug');
            }

            // Always load school basic info
            $school = \App\Models\School::with($with)->findOrFail($schoolId);
            $school->loadCount([
                'grades',
                'sections',
                'classrooms',
                'subjects',
                'classes',
                'users as teachers_count' => function ($query) {
                    $query->whereHas('role_relation', function ($q) {
                        $q->whereNotIn('slug', ['administrator', 'admin', 'super-admin']);
                    });
                }
            ]);

            $data = [];

            // Always include school basic info
            $data['school'] = [
                'id' => $school->id,
                'name' => $school->name,
                'slug' => $school->slug,
                'logo_path' => $school->logo_path,
                'current_session' => $school->current_session,
                'plan_name' => $school->plan_name,
                'subscription_status' => $school->subscription_status,
                'onboarding_steps' => $school->onboarding_steps,
                'effective_grace_days' => $school->grace_days > 0 ? (int) $school->grace_days : (int) env('SUBSCRIPTION_GRACE_DAYS', 0),
                'counts' => [
                    'grades' => $school->grades_count,
                    'sections' => $school->sections_count,
                    'classrooms' => $school->classrooms_count,
                    'subjects' => $school->subjects_count,
                    'teachers' => $school->teachers_count,
                    'classes' => $school->classes_count,
                ],
            ];

            // Conditionally add keys to response
            if ($shouldLoad('grades'))
                $data['grades'] = $school->grades->toArray();
            if ($shouldLoad('sections'))
                $data['sections'] = $school->sections->toArray();
            if ($shouldLoad('classes')) {
                // Use a JOIN-based query to reduce round-trips to the DB (Critical for Hostinger)
                $classesList = \DB::table('school_classes')
                    ->where('school_classes.school_id', $schoolId)
                    ->leftJoin('grades', 'school_classes.grade_id', '=', 'grades.id')
                    ->leftJoin('sections', 'school_classes.section_id', '=', 'sections.id')
                    ->leftJoin('users as teachers', 'school_classes.class_teacher_id', '=', 'teachers.id')
                    ->leftJoin('classrooms', 'school_classes.default_classroom_id', '=', 'classrooms.id')
                    ->select(
                        'school_classes.*',
                        'grades.name as grade_name',
                        'sections.name as section_name',
                        'teachers.name as teacher_name',
                        'classrooms.name as classroom_name'
                    )
                    ->get();

                // Fetch class subject details including periods, teacher, notes, syllabus, and lesson plan
                $classSubjects = \DB::table('class_subject')
                    ->join('subjects', 'class_subject.subject_id', '=', 'subjects.id')
                    ->select(
                        'class_subject.id as class_subject_id',
                        'class_subject.school_class_id',
                        'class_subject.subject_id as id',
                        'subjects.name',
                        'subjects.code',
                        'class_subject.periods_per_week',
                        'class_subject.teacher_id',
                        // lesson_plan is rich-text HTML edited via the Lesson Plan tab
                        // of the Class Subject Studio. Must be included here so the
                        // editor can re-seed itself after a refetch — otherwise the
                        // text disappears the moment the modal is reopened.
                        'class_subject.lesson_plan'
                    )
                    ->get();

                $classSubjectIds = $classSubjects->pluck('class_subject_id')->toArray();

                // Fetch all notes for these class subjects
                $notes = \DB::table('class_subject_notes')
                    ->whereIn('class_subject_id', $classSubjectIds)
                    ->get()
                    ->groupBy('class_subject_id');

                // Fetch all syllabus for these class subjects
                $syllabus = \DB::table('class_subject_syllabus')
                    ->whereIn('class_subject_id', $classSubjectIds)
                    ->get()
                    ->groupBy('class_subject_id');

                // Map subjects to classes
                $classSubjectsGrouped = $classSubjects->map(function ($sub) use ($notes, $syllabus) {
                    $subNotes = isset($notes[$sub->class_subject_id])
                        ? $notes[$sub->class_subject_id]->map(function ($n) {
                            return [
                                'id' => $n->id,
                                'class_subject_id' => $n->class_subject_id,
                                'title' => $n->title,
                                'file_url' => $n->file_url,
                                'description' => $n->description,
                                'created_at' => $n->created_at,
                                'updated_at' => $n->updated_at,
                            ];
                        })->toArray()
                        : [];

                    $subSyllabus = isset($syllabus[$sub->class_subject_id])
                        ? $syllabus[$sub->class_subject_id]->map(function ($s) {
                            return [
                                'id' => $s->id,
                                'class_subject_id' => $s->class_subject_id,
                                'topic' => $s->topic,
                                'description' => $s->description,
                                'status' => $s->status,
                                'created_at' => $s->created_at,
                                'updated_at' => $s->updated_at,
                            ];
                        })->toArray()
                        : [];

                    return [
                        'id' => $sub->id,
                        'school_class_id' => $sub->school_class_id,
                        'name' => $sub->name,
                        'code' => $sub->code,
                        'pivot' => [
                            'id' => $sub->class_subject_id,
                            'periods_per_week' => $sub->periods_per_week,
                            'teacher_id' => $sub->teacher_id,
                            'notes' => $subNotes,
                            'syllabus' => $subSyllabus,
                            'lesson_plan' => $sub->lesson_plan,
                        ]
                    ];
                })->groupBy('school_class_id');

                $data['classes'] = $classesList->map(function ($cls) use ($classSubjectsGrouped) {
                    return [
                        'id' => $cls->id,
                        'grade_id' => $cls->grade_id,
                        'section_id' => $cls->section_id,
                        'class_teacher_id' => $cls->class_teacher_id,
                        'default_classroom_id' => $cls->default_classroom_id,
                        'school_id' => $cls->school_id,
                        'grade' => ['id' => $cls->grade_id, 'name' => $cls->grade_name],
                        'section' => ['id' => $cls->section_id, 'name' => $cls->section_name],
                        'class_teacher' => ['id' => $cls->class_teacher_id, 'name' => $cls->teacher_name],
                        'default_classroom' => ['id' => $cls->default_classroom_id, 'name' => $cls->classroom_name],
                        'subjects' => isset($classSubjectsGrouped[$cls->id]) ? $classSubjectsGrouped[$cls->id]->values()->toArray() : [],
                    ];
                })->toArray();
            }
            if ($shouldLoad('subjects'))
                $data['subjects'] = $school->subjects->toArray();
            if ($shouldLoad('classrooms'))
                $data['classrooms'] = $school->classrooms->toArray();
            if ($shouldLoad('teachers'))
                $data['teachers'] = $school->users->toArray();
            if ($shouldLoad('events'))
                $data['events'] = $school->events->toArray();
            if ($shouldLoad('role_configs'))
                $data['role_configs'] = $school->roleWorkloadConfigs->toArray();
            if ($shouldLoad('periods'))
                $data['periods'] = $school->periods->sortBy('start_time')->values()->toArray();

            // 3. User-specific filtering (only if classes are being returned)
            if ($shouldLoad('classes') && !$user->isAdmin() && !$user->hasPermission('manage_all_classes')) {
                $assignedClassIds = $user->timetableEntries()->pluck('school_class_id')->unique()->toArray();
                $managedClassIds = $user->managedClasses()->pluck('id')->unique()->toArray();
                $visibleClassIds = array_unique(array_merge($assignedClassIds, $managedClassIds));

                $data['classes'] = array_values(array_filter($data['classes'], fn($cls) => in_array($cls['id'], $visibleClassIds)));
                unset($data['role_configs']);
            }

            return $this->successResponse($data, 'Institutional directory synced');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function updateSettings(Request $request)
    {
        $school = $request->user()->school;

        $request->validate([
            'slug' => 'nullable|string|unique:schools,slug,' . $school->id,
            'custom_domain' => 'nullable|string|unique:schools,custom_domain,' . $school->id,
            'theme_color' => 'nullable|string',
            'tagline' => 'nullable|string',
            'about_text' => 'nullable|string',
            'school_logo' => 'nullable|image|max:2048',
            'email_logo' => 'nullable|image|max:2048',
            'about_image' => 'nullable|image|max:4096',
            'admission_form_config' => 'nullable|string',
            'landing_theme_config' => 'nullable|string',
            'email_settings' => 'nullable|string',
            'current_session' => 'nullable|string',
            'onboarding_steps' => 'nullable|string',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'geofence_radius' => 'nullable|integer|min:0',
            'landing_layout' => 'nullable|integer|in:1,2',
        ]);

        $logoPath = $school->logo_path;
        if ($request->hasFile('school_logo')) {
            $path = $request->file('school_logo')->store('school/logos', ['disk' => 's3']);
            if ($path) {
                $logoPath = Storage::disk('s3')->url($path);
            }
        }

        $themeConfig = $request->has('landing_theme_config') ? json_decode($request->landing_theme_config, true) : $school->landing_theme_config;

        if ($request->hasFile('about_image')) {
            $path = $request->file('about_image')->store('school/landing', ['disk' => 's3']);
            if ($path) {
                $themeConfig['about_image_url'] = Storage::disk('s3')->url($path);
            }
        }

        $emailSettings = $request->has('email_settings') ? json_decode($request->email_settings, true) : ($school->email_settings ?? []);

        if ($request->hasFile('email_logo')) {
            $path = $request->file('email_logo')->store('school/email_logos', ['disk' => 's3']);
            if ($path) {
                $emailSettings['logo_url'] = Storage::disk('s3')->url($path);
            }
        }

        $admissionConfig = $request->has('admission_form_config') ? json_decode($request->admission_form_config, true) : $school->admission_form_config;
        if ($admissionConfig !== null) {
            $admissionConfig = json_decode(json_encode(\App\Models\School::normalizeAdmissionConfig($admissionConfig)), true);
        }

        $school->update([
            // Only overwrite fields the request actually sent. Partial saves
            // (e.g. the dashboard posting only onboarding_steps) used to null
            // the slug, tagline, session and theme color.
            'slug' => $request->has('slug') ? $request->slug : $school->slug,
            'custom_domain' => $request->has('custom_domain') ? $request->custom_domain : $school->custom_domain,
            'theme_color' => $request->has('theme_color') ? $request->theme_color : $school->theme_color,
            'tagline' => $request->has('tagline') ? $request->tagline : $school->tagline,
            'about_text' => $request->has('about_text') ? $request->about_text : $school->about_text,
            'logo_path' => $logoPath,
            'admission_form_config' => $admissionConfig,
            'landing_theme_config' => $themeConfig,
            'email_settings' => $emailSettings,
            'current_session' => $request->has('current_session') ? $request->current_session : $school->current_session,
            'onboarding_steps' => $request->has('onboarding_steps') ? json_decode($request->onboarding_steps, true) : $school->onboarding_steps,
            'latitude' => $request->has('latitude') ? $request->latitude : $school->latitude,
            'longitude' => $request->has('longitude') ? $request->longitude : $school->longitude,
            'geofence_radius' => $request->has('geofence_radius') ? $request->geofence_radius : $school->geofence_radius,
            'landing_layout' => $request->filled('landing_layout') ? (int) $request->landing_layout : ($school->landing_layout ?? 1),
        ]);

        try {
            \App\Services\SafeCache::forgetPrefix("school_{$school->id}_url_cache");
        } catch (\Throwable $e) {
            // best effort
        }

        return $this->successResponse($school, 'Institutional settings updated successfully');
    }

    public function checkAvailability(Request $request)
    {
        $request->validate([
            'type' => 'required|in:slug,custom_domain',
            'value' => 'required|string',
        ]);

        $type = $request->type;
        $value = $request->value;
        $currentSchoolId = $request->user()->school_id;

        $exists = \App\Models\School::where($type === 'slug' ? 'slug' : 'custom_domain', $value)
            ->where('id', '!=', $currentSchoolId)
            ->exists();

        return $this->successResponse([
            'available' => !$exists,
        ], $exists ? ($type === 'slug' ? 'This subdomain is already taken.' : 'This domain is already registered.') : 'Available');
    }

    public function getEmailPreview(Request $request)
    {
        $school = $request->user()->school;

        $brandColor = $request->query('brand_color', $school->email_settings['brand_color'] ?? '#6366f1');
        $footerText = $request->query('footer_text', $school->email_settings['footer_text'] ?? '');
        $logoUrl = $request->query('logo_url', $school->email_settings['logo_url'] ?? $school->logo_path);

        // New interactive branding tokens
        $emailBg = $request->query('email_bg', $school->email_settings['bg_color'] ?? '#0f172a');
        $contentBg = $request->query('content_bg', $school->email_settings['content_bg_color'] ?? '#1e293b');
        $textColor = $request->query('email_text_color', $school->email_settings['text_color'] ?? '#f1f5f9');

        // Flexibility: Allow previewing ANY event slug
        $slug = $request->query('template_slug', 'admission_confirmation');
        $previewContentHtml = $request->query('preview_content');
        $previewSubject = $request->query('preview_subject');

        // Fetch custom HTML from database template (or global default) if no override provided
        $template = \App\Models\EmailTemplate::findBySlug($slug, $school->id);

        $contentHtml = $previewContentHtml ?? 'Thank you for your application.';
        $subject = $previewSubject ?? 'Application Received';

        if (!$previewContentHtml && $template) {
            $mockData = [
                'student_name' => 'John Doe',
                'staff_name' => 'John Doe',
                'user_name' => 'John Doe',
                'admission_number' => 'ADM-' . date('Y') . '-0001',
                'staff_role' => 'Principal',
                'school_name' => $school->name ?? 'Our School',
                'reset_url' => '#',
            ];
            $rendered = $template->render($mockData);
            $contentHtml = $rendered['content_html'];
            $subject = $rendered['subject'];
        } elseif ($previewContentHtml) {
            // Manual render for real-time editor typing
            $mockData = [
                '{{student_name}}' => 'John Doe',
                '{{staff_name}}' => 'John Doe',
                '{{user_name}}' => 'John Doe',
                '{{admission_number}}' => 'ADM-' . date('Y') . '-0001',
                '{{staff_role}}' => 'Principal',
                '{{school_name}}' => $school->name ?? 'Our School',
                '{{reset_url}}' => '#',
            ];
            $contentHtml = strtr($previewContentHtml, $mockData);
        }

        $mockApplication = new \App\Models\AdmissionApplication([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john.doe@example.com',
            'phone' => '+1 (555) 000-0000',
            'admission_number' => 'ADM-' . date('Y') . '-0001',
        ]);

        // Temporarily override school branding for the mock
        $mockApplication->school = clone $school;
        $mockApplication->school->email_settings = [
            'brand_color' => $brandColor,
            'footer_text' => $footerText,
            'logo_url' => $logoUrl,
            'bg_color' => $emailBg,
            'content_bg_color' => $contentBg,
            'text_color' => $textColor,
        ];

        return view('emails.layout', [
            'content' => $contentHtml,
            'school' => $mockApplication->school,
            'subject' => $subject
        ]);
    }

    public function getPublicSchoolInfo(Request $request)
    {
        $domain = $request->query('domain');
        $slug = $request->query('slug');

        if (!$domain && !$slug) {
            return $this->errorResponse('No identifier provided', 400);
        }

        // Whole payload is cached in Valkey per slug/domain (landing page +
        // login screens hit this on every visit). A hit costs zero DB queries.
        // Invalidated on any school / class / landing-content change — see
        // App\Services\PublicSiteCache.
        $cacheKey = $domain
            ? \App\Services\PublicSiteCache::key('domain', $domain)
            : \App\Services\PublicSiteCache::key('slug', $slug);

        $payload = \App\Services\PublicSiteCache::remember(
            $cacheKey,
            fn () => $this->buildPublicSchoolPayload($domain, $slug)
        );

        if (!$payload) {
            return $this->errorResponse('School not found', 404);
        }

        return $this->successResponse($payload);
    }

    /** Builds the public landing payload, or null when the school doesn't exist. */
    private function buildPublicSchoolPayload(?string $domain, ?string $slug): ?array
    {
        $school = \App\Models\School::query()
            ->when($domain, fn ($q) => $q->where('custom_domain', $domain))
            ->when(!$domain, fn ($q) => $q->where('slug', $slug))
            ->first();

        if (!$school) {
            return null;
        }

        // We DO NOT 403 this endpoint outright when the
        // landing_page_widgets module is off, because the school
        // admin / student / parent login screens ALSO call this
        // endpoint to render the school's logo + name + theme color
        // before authentication. Gating it would break login entirely.
        //
        // Instead: always return the BASIC identity (logo, name,
        // theme, tagline) + a `landing_disabled` flag. The marketing
        // landing page checks the flag and renders the "Page Currently
        // Unavailable" card; the login screen ignores the flag and
        // shows its normal UI.
        $landingEnabled = \App\Services\ModuleAccessService::schoolHas($school, 'landing_page_widgets');

        // Expose `admissions_open` so the public landing page can hide the
        // "Apply for Admission" CTA when the school is suspended or has hit
        // its student cap. We compute it here (one place) so the frontend
        // doesn't have to know the rules.
        $school->load('plan');
        $svc = app(\App\Services\StudentLimitService::class);
        $admissionsOpen = $school->subscription_status !== 'suspended'
            && $svc->canAddStudents($school);

        // Always-included identity. These fields are needed by the
        // login screens of all four apps and must NEVER be gated.
        $payload = [
            'id' => $school->id,
            'name' => $school->name,
            'slug' => $school->slug,
            'logo_path' => $school->logo_path,
            'theme_color' => $school->theme_color,
            'tagline' => $school->tagline,
            'contact_number' => $school->contact_number,
            'email' => $school->email,
            // Marketing-disabled flag — frontend branches on this.
            'landing_disabled' => !$landingEnabled,
            'admissions_open' => $admissionsOpen,
            // 1 = classic landing page, 2 = multi-page school website.
            'landing_layout' => (int) ($school->landing_layout ?? 1) === 2 ? 2 : 1,
        ];

        // Rich marketing payload — only included when the module is on.
        // Saves DB load on every login attempt for schools without the
        // module, AND keeps the marketing-vs-auth boundary explicit.
        if ($landingEnabled) {
            $payload = array_merge($payload, [
                'about_text' => $school->about_text,
                'address' => $school->address,
                'current_session' => $school->current_session,
                'admission_form_config' => \App\Models\School::normalizeAdmissionConfig($school->admission_form_config),
                'landing_theme_config' => $school->landing_theme_config,
                'site_content' => $school->site_content,
                'email_settings' => $school->email_settings,
                'banners' => $school->landingBanners->toArray(),
                'sections' => $school->landingSections()->where('is_active', true)->with('cards')->get()->toArray(),
                // Flat list for the class dropdowns of BOTH layouts:
                // [{ id, name: "Grade 1 - A" }] — one joined query.
                'classes' => \Illuminate\Support\Facades\DB::table('school_classes as c')
                    ->leftJoin('grades as g', 'g.id', '=', 'c.grade_id')
                    ->leftJoin('sections as s', 's.id', '=', 'c.section_id')
                    ->where('c.school_id', $school->id)
                    ->orderBy('g.id')
                    ->orderBy('s.name')
                    ->get(['c.id', 'g.name as grade', 's.name as section'])
                    ->map(fn ($r) => [
                        'id' => $r->id,
                        'name' => trim(implode(' - ', array_filter([$r->grade, $r->section], fn ($v) => $v !== null && $v !== ''))),
                    ])
                    ->values()
                    ->all(),
            ]);
        }

        return $payload;
    }
    public function getNotificationCounts(Request $request)
    {
        try {
            set_time_limit(60);
            $schoolId = $request->user()->school_id;

            // Optimized to single query using withCount
            $school = \App\Models\School::where('id', $schoolId)
                ->withCount([
                    'inquiries as inquiries_count' => fn($q) => $q->where('status', 'pending'),
                    'admissionApplications as admissions_count' => fn($q) => $q->where('status', 'pending')
                ])->first();

            $inquiryCount = $school->inquiries_count ?? 0;
            $admissionCount = $school->admissions_count ?? 0;

            return $this->successResponse([
                'inquiries' => $inquiryCount,
                'admissions' => $admissionCount,
                'total' => $inquiryCount + $admissionCount
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function checkPublicSlugAvailability(Request $request)
    {
        $school = \App\Models\School::where('slug', $request->slug)->first();
        if ($school) {
            return response()->json([
                'success' => 1,
                'message' => 'This subdomain is already taken.',
                'data' => [
                    'available' => false
                ]
            ]);
        }

        return response()->json([
            'success' => 1,
            'message' => 'Success',
            'data' => [
                'available' => true
            ]
        ]);
    }

    public function searchSchools(Request $request)
    {
        $query = $request->query('q');
        if (!$query || strlen($query) < 2) {
            return $this->successResponse([]);
        }

        $schools = \App\Models\School::where('name', 'LIKE', "%{$query}%")
            ->select('id', 'name', 'slug', 'logo_path')
            ->limit(10)
            ->get();

        return $this->successResponse($schools);
    }

    public function getConfig(Request $request)
    {
        $school = $request->user()->school;
        return $this->successResponse([
            'landing_theme_config' => $school->landing_theme_config,
            'admission_form_config' => \App\Models\School::normalizeAdmissionConfig($school->admission_form_config),
            'email_settings' => $school->email_settings,
            'about_text' => $school->about_text,
            'tagline' => $school->tagline,
            'custom_domain' => $school->custom_domain,
            'address' => $school->address,
            'contact_number' => $school->contact_number,
            'registration_no' => $school->registration_no,
            'latitude' => $school->latitude,
            'longitude' => $school->longitude,
            'geofence_radius' => $school->geofence_radius,
            'landing_layout' => (int) ($school->landing_layout ?? 1),
        ]);
    }

    public function updatePeriod(Request $request, $id)
    {
        $request->validate([
            'name' => 'required|string',
            'start_time' => 'required',
            'end_time' => 'required|after:start_time',
            'type' => 'required|in:class,lunch,break,assembly',
        ]);

        $period = SchoolPeriod::where('id', $id)
            ->where('school_id', $request->user()->school_id)
            ->firstOrFail();

        $period->update($request->only(['name', 'start_time', 'end_time', 'type']));

        // Recalculate sort order for the school
        $this->recalculatePeriodSortOrder($request->user()->school_id);

        return response()->json($period);
    }

    public function batchStoreClasses(Request $request)
    {
        $request->validate([
            'mappings' => 'required|array',
            'mappings.*.grade' => 'required|string',
            'mappings.*.section' => 'required|string',
        ]);

        $schoolId = $request->user()->school_id;
        $createdCount = 0;

        foreach ($request->mappings as $mapping) {
            $grade = Grade::firstOrCreate([
                'school_id' => $schoolId,
                'name' => trim($mapping['grade'])
            ]);

            $section = Section::firstOrCreate([
                'school_id' => $schoolId,
                'name' => trim($mapping['section'])
            ]);

            $schoolClass = SchoolClass::firstOrCreate([
                'school_id' => $schoolId,
                'grade_id' => $grade->id,
                'section_id' => $section->id
            ]);

            if ($schoolClass->wasRecentlyCreated) {
                $createdCount++;
            }
        }

        return $this->successResponse([
            'count' => $createdCount
        ], "Successfully processed mappings. Found/Created $createdCount new unique classes.");
    }

    private function recalculatePeriodSortOrder($schoolId)
    {
        $periods = SchoolPeriod::where('school_id', $schoolId)
            ->orderBy('start_time')
            ->get();

        foreach ($periods as $index => $period) {
            // Robust typo fix for "Period 7" or similar legacy time entry errors
            if ($period->start_time >= $period->end_time) {
                // Default to a 55min slot if it's broken
                $dt = new \DateTime($period->start_time);
                $dt->modify('+55 minutes');
                $period->end_time = $dt->format('H:i:s');
            }

            $period->sort_order = $index + 1;
            $period->save();
        }
    }

    private function formatClassWithSubjects($schoolClass)
    {
        $schoolClass->load(['grade', 'section', 'classTeacher', 'defaultClassroom', 'subjects']);

        $classSubjectIds = $schoolClass->subjects->map(function ($sub) {
            return $sub->pivot->id;
        })->filter()->toArray();

        $notes = \DB::table('class_subject_notes')
            ->whereIn('class_subject_id', $classSubjectIds)
            ->get()
            ->groupBy('class_subject_id');

        $syllabus = \DB::table('class_subject_syllabus')
            ->whereIn('class_subject_id', $classSubjectIds)
            ->get()
            ->groupBy('class_subject_id');

        $subjectsMapped = $schoolClass->subjects->map(function ($sub) use ($notes, $syllabus) {
            $pivotId = $sub->pivot->id;

            $subNotes = isset($notes[$pivotId])
                ? $notes[$pivotId]->map(function ($n) {
                    return [
                        'id' => $n->id,
                        'class_subject_id' => $n->class_subject_id,
                        'title' => $n->title,
                        'file_url' => $n->file_url,
                        'description' => $n->description,
                        'created_at' => $n->created_at,
                        'updated_at' => $n->updated_at,
                    ];
                })->toArray()
                : [];

            $subSyllabus = isset($syllabus[$pivotId])
                ? $syllabus[$pivotId]->map(function ($s) {
                    return [
                        'id' => $s->id,
                        'class_subject_id' => $s->class_subject_id,
                        'topic' => $s->topic,
                        'description' => $s->description,
                        'status' => $s->status,
                        'created_at' => $s->created_at,
                        'updated_at' => $s->updated_at,
                    ];
                })->toArray()
                : [];

            return [
                'id' => $sub->id,
                'name' => $sub->name,
                'code' => $sub->code,
                'pivot' => [
                    'id' => $pivotId,
                    'periods_per_week' => $sub->pivot->periods_per_week,
                    'teacher_id' => $sub->pivot->teacher_id,
                    'notes' => $subNotes,
                    'syllabus' => $subSyllabus,
                    // Read from the Eloquent pivot — SchoolClass::subjects()
                    // declares lesson_plan in withPivot(...) so the column
                    // comes back populated on the relation load above.
                    'lesson_plan' => $sub->pivot->lesson_plan,
                ]
            ];
        })->toArray();

        return [
            'id' => $schoolClass->id,
            'grade_id' => $schoolClass->grade_id,
            'section_id' => $schoolClass->section_id,
            'class_teacher_id' => $schoolClass->class_teacher_id,
            'default_classroom_id' => $schoolClass->default_classroom_id,
            'school_id' => $schoolClass->school_id,
            'grade' => $schoolClass->grade ? ['id' => $schoolClass->grade->id, 'name' => $schoolClass->grade->name] : null,
            'section' => $schoolClass->section ? ['id' => $schoolClass->section->id, 'name' => $schoolClass->section->name] : null,
            'class_teacher' => $schoolClass->classTeacher ? ['id' => $schoolClass->classTeacher->id, 'name' => $schoolClass->classTeacher->name] : null,
            'default_classroom' => $schoolClass->defaultClassroom ? ['id' => $schoolClass->defaultClassroom->id, 'name' => $schoolClass->defaultClassroom->name] : null,
            'subjects' => $subjectsMapped
        ];
    }

    private function getCanonicalGradeName(string $input): ?string
    {
        $trimmed = trim($input);
        if (empty($trimmed)) {
            return null;
        }

        $clean = strtolower(preg_replace('/[\s\-_]+/', '', $trimmed));
        $clean = preg_replace('/^(grade|class|level)/', '', $clean);

        $directMaps = [
            'prenursery' => 'Pre-Nursery',
            'nursery' => 'Nursery',
            'lkg' => 'LKG',
            'lowerkg' => 'LKG',
            'lowerkindergarten' => 'LKG',
            'ukg' => 'UKG',
            'upperkg' => 'UKG',
            'upperkindergarten' => 'UKG',
            'one' => '1st',
            'two' => '2nd',
            'three' => '3rd',
            'four' => '4th',
            'five' => '5th',
            'six' => '6th',
            'seven' => '7th',
            'eight' => '8th',
            'nine' => '9th',
            'ten' => '10th',
            'eleven' => '11th',
            'twelve' => '12th',
            'first' => '1st',
            'second' => '2nd',
            'third' => '3rd',
            'fourth' => '4th',
            'fifth' => '5th',
            'sixth' => '6th',
            'seventh' => '7th',
            'eighth' => '8th',
            'ninth' => '9th',
            'tenth' => '10th',
            'eleventh' => '11th',
            'twelfth' => '12th',
            '1st' => '1st',
            '2nd' => '2nd',
            '3rd' => '3rd',
            '4th' => '4th',
            '5th' => '5th',
            '6th' => '6th',
            '7th' => '7th',
            '8th' => '8th',
            '9th' => '9th',
            '10th' => '10th',
            '11th' => '11th',
            '12th' => '12th'
        ];

        if (isset($directMaps[$clean])) {
            return $directMaps[$clean];
        }

        if (ctype_digit($clean)) {
            $numericVal = (int) $clean;
            if ($numericVal >= 1 && $numericVal <= 12) {
                $suffixes = [
                    1 => '1st',
                    2 => '2nd',
                    3 => '3rd',
                    4 => '4th',
                    5 => '5th',
                    6 => '6th',
                    7 => '7th',
                    8 => '8th',
                    9 => '9th',
                    10 => '10th',
                    11 => '11th',
                    12 => '12th'
                ];
                return $suffixes[$numericVal];
            }
        }

        $canonicalGrades = [
            'Pre-Nursery',
            'Nursery',
            'LKG',
            'UKG',
            '1st',
            '2nd',
            '3rd',
            '4th',
            '5th',
            '6th',
            '7th',
            '8th',
            '9th',
            '10th',
            '11th',
            '12th'
        ];

        foreach ($canonicalGrades as $cg) {
            if (strtolower(preg_replace('/[\s\-_]+/', '', $cg)) === $clean) {
                return $cg;
            }
        }

        return null;
    }

    private function getCanonicalSectionName(string $input): ?string
    {
        $trimmed = trim($input);
        if (empty($trimmed)) {
            return null;
        }

        $clean = strtoupper(preg_replace('/[\s\-_]+/', '', $trimmed));

        if (substr($clean, 0, 7) === 'SECTION') {
            $clean = substr($clean, 7);
        }

        if (preg_match('/^[A-Z]\d?$/', $clean)) {
            return $clean;
        }

        return null;
    }
}
