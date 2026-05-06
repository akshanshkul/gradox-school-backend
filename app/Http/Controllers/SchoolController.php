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
            'email' => 'required|email|unique:users,email',
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
            return $this->successResponse(
                $request->user()->school->users()
                    ->where('status', 'active')
                    ->select('users.id', 'users.name', 'users.profile_picture', 'users.email', 'users.role_id', 'users.school_id')
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
        $grade = Grade::create([
            'name' => $request->name,
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
        $section = Section::create([
            'name' => $request->name,
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

        return $this->successResponse($schoolClass->load(['grade', 'section', 'classTeacher', 'defaultClassroom', 'subjects']), 'Class configuration updated successfully');
    }

    public function syncSubjects(Request $request, $id)
    {
        $request->validate([
            'subjects' => 'array',
            'subjects.*.id' => 'required|exists:subjects,id',
            'subjects.*.periods_per_week' => 'nullable|integer|min:1',
        ]);

        $schoolClass = SchoolClass::where('id', $id)
            ->where('school_id', $request->user()->school_id)
            ->firstOrFail();

        $syncData = [];
        foreach ($request->subjects as $item) {
            $syncData[$item['id']] = ['periods_per_week' => $item['periods_per_week'] ?? 1];
        }

        $schoolClass->subjects()->sync($syncData);

        $schoolClass->subjects()->sync($syncData);

        return $this->successResponse($schoolClass->load('subjects'), 'Subjects synced successfully to class');
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
                    ->select('users.id', 'users.name', 'users.profile_picture', 'users.email', 'users.role_id', 'users.school_id')
                    ->with('role_relation:id,name,slug');
            }

            // Always load school basic info
            $school = \App\Models\School::with($with)->findOrFail($schoolId);

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
                'effective_grace_days' => $school->grace_days > 0 ? (int) $school->grace_days : (int) env('SUBSCRIPTION_GRACE_DAYS', 0),
            ];

            // Conditionally add keys to response
            if ($shouldLoad('grades'))
                $data['grades'] = $school->grades->toArray();
            if ($shouldLoad('sections'))
                $data['sections'] = $school->sections->toArray();
            if ($shouldLoad('classes')) {
                // Use a JOIN-based query to reduce round-trips to the DB (Critical for Hostinger)
                $data['classes'] = \DB::table('school_classes')
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
                    ->get()
                    ->map(function($cls) {
                        // Reconstruct the nested structure the frontend expects
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

        $school->update([
            'slug' => $request->slug,
            'custom_domain' => $request->custom_domain,
            'theme_color' => $request->theme_color,
            'tagline' => $request->tagline,
            'about_text' => $request->about_text,
            'logo_path' => $logoPath,
            'admission_form_config' => $admissionConfig,
            'landing_theme_config' => $themeConfig,
            'email_settings' => $emailSettings,
            'current_session' => $request->current_session,
        ]);

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

        $query = \App\Models\School::query();

        if ($domain) {
            $query->where('custom_domain', $domain);
        } elseif ($slug) {
            $query->where('slug', $slug);
        } else {
            return $this->errorResponse('No identifier provided', 400);
        }

        $school = $query->first();

        if (!$school) {
            return $this->errorResponse('School not found', 404);
        }

        return $this->successResponse([
            'id' => $school->id,
            'name' => $school->name,
            'logo_path' => $school->logo_path,
            'theme_color' => $school->theme_color,
            'tagline' => $school->tagline,
            'about_text' => $school->about_text,
            'contact_number' => $school->contact_number,
            'email' => $school->email,
            'admission_form_config' => $school->admission_form_config,
            'landing_theme_config' => $school->landing_theme_config,
            'email_settings' => $school->email_settings,
            'banners' => $school->landingBanners,
            'sections' => $school->landingSections()->where('is_active', true)->with('cards')->get(),
            'classes' => $school->classes()->with(['grade', 'section'])->get(),
        ]);
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
            'admission_form_config' => $school->admission_form_config,
            'email_settings' => $school->email_settings,
            'about_text' => $school->about_text,
            'tagline' => $school->tagline,
            'custom_domain' => $school->custom_domain,
            'address' => $school->address,
            'contact_number' => $school->contact_number,
            'registration_no' => $school->registration_no,
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
}
