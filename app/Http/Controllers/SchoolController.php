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
            'role' => 'required|in:admin,incharge,teacher,staff',
            'profile_picture' => 'nullable|image|max:2048',
            'is_teaching' => 'required|string', // "true" or "false" from FormData
            'staff_subtype' => 'nullable|string|max:255',
        ]);

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
            'role' => $request->role,
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

        return response()->json($teacher);
    }

    public function getTeachers(Request $request)
    {
        return response()->json($request->user()->school->users()->where('status', 'active')->get());
    }

    public function getInactiveStaff(Request $request)
    {
        return response()->json($request->user()->school->users()->whereIn('status', ['inactive', 'exit'])->get());
    }

    public function exportTeachers(Request $request)
    {
        return response()->json($request->user()->school->users()->where('status', 'active')->get());
    }

    public function addGrade(Request $request)
    {
        $request->validate(['name' => 'required|string']);
        $grade = Grade::create([
            'name' => $request->name,
            'school_id' => $request->user()->school_id,
        ]);
        return response()->json($grade);
    }

    public function addSection(Request $request)
    {
        $request->validate(['name' => 'required|string']);
        $section = Section::create([
            'name' => $request->name,
            'school_id' => $request->user()->school_id,
        ]);
        return response()->json($section);
    }

    public function addClass(Request $request)
    {
        $request->validate([
            'grade_id' => 'required|exists:grades,id',
            'section_id' => 'required|exists:sections,id',
            'class_teacher_id' => 'nullable|exists:users,id',
            'default_classroom_id' => 'nullable|exists:classrooms,id',
        ]);

        $schoolClass = SchoolClass::create([
            'grade_id' => $request->grade_id,
            'section_id' => $request->section_id,
            'class_teacher_id' => $request->class_teacher_id,
            'default_classroom_id' => $request->default_classroom_id,
            'school_id' => $request->user()->school_id,
        ]);

        return response()->json($schoolClass->load(['grade', 'section', 'classTeacher', 'defaultClassroom']));
    }

    public function updateClass(Request $request, $id)
    {
        $request->validate([
            'grade_id' => 'required|exists:grades,id',
            'section_id' => 'required|exists:sections,id',
            'class_teacher_id' => 'nullable|exists:users,id',
            'default_classroom_id' => 'nullable|exists:classrooms,id',
        ]);

        $schoolClass = SchoolClass::where('id', $id)
            ->where('school_id', $request->user()->school_id)
            ->firstOrFail();

        $schoolClass->update([
            'grade_id' => $request->grade_id,
            'section_id' => $request->section_id,
            'class_teacher_id' => $request->class_teacher_id,
            'default_classroom_id' => $request->default_classroom_id,
        ]);

        return response()->json($schoolClass->load(['grade', 'section', 'classTeacher', 'defaultClassroom', 'subjects']));
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

        return response()->json($schoolClass->load('subjects'));
    }

    public function addSubject(Request $request)
    {
        $request->validate(['name' => 'required|string', 'code' => 'nullable|string']);
        $subject = Subject::create([
            'name' => $request->name,
            'code' => $request->code,
            'school_id' => $request->user()->school_id,
        ]);
        return response()->json($subject);
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

        return response()->json($classroom);
    }

    public function updateTeacherDetails($id, Request $request)
    {
        $teacher = User::where('id', $id)->where('school_id', $request->user()->school_id)->firstOrFail();

        $request->validate([
            'role' => 'nullable|in:admin,incharge,teacher,staff',
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
        ]);

        if ($request->has('role'))
            $teacher->role = $request->role;
        if ($request->has('is_teaching'))
            $teacher->is_teaching = $request->is_teaching;
        if ($request->has('staff_subtype'))
            $teacher->staff_subtype = $request->staff_subtype;

        $details = $teacher->teacher_details ?? [];
        if ($request->has('education'))
            $details['education'] = $request->education;
        if ($request->has('personal_email'))
            $details['personal_email'] = $request->personal_email;
        if ($request->has('specializations'))
            $details['specializations'] = $request->specializations;

        $teacher->teacher_details = $details;
        $teacher->save();

        return response()->json(['success' => true, 'teacher' => $teacher]);
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

        return response()->json($event->load('schoolClass'));
    }

    public function deleteEvent($id, Request $request)
    {
        $event = SchoolEvent::where('id', $id)->where('school_id', $request->user()->school_id)->firstOrFail();
        $event->delete();
        return response()->json(['success' => true]);
    }

    public function getEvents(Request $request)
    {
        return response()->json(
            SchoolEvent::where('school_id', $request->user()->school_id)
                ->with('schoolClass.grade', 'schoolClass.section')
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

        $period = SchoolPeriod::create([
            ...$request->all(),
            'school_id' => $request->user()->school_id,
        ]);

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

        return response()->json([
            'success' => true,
            'new_password' => $newPassword,
            'message' => 'Password reset successful and sent to staff email.'
        ]);
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

        return response()->json($config);
    }

    public function getData(Request $request)
    {
        $school = $request->user()->school;
        return response()->json([
            'school' => $school,
            'grades' => $school->grades,
            'sections' => $school->sections,
            'classes' => $school->classes()->with(['grade', 'section', 'classTeacher', 'defaultClassroom', 'subjects'])->get(),
            'subjects' => $school->subjects,
            'classrooms' => $school->classrooms,
            'teachers' => $school->users()->where('status', 'active')->get(),
            'events' => SchoolEvent::where('school_id', $school->id)->with('schoolClass')->get(),
            'role_configs' => RoleWorkloadConfig::where('school_id', $school->id)->get(),
            'periods' => SchoolPeriod::where('school_id', $school->id)->orderBy('sort_order')->orderBy('start_time')->get(),
        ]);
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
            'admission_form_config' => 'nullable|string',
            'landing_theme_config' => 'nullable|string',
            'email_settings' => 'nullable|string',
        ]);

        $logoPath = $school->logo_path;
        if ($request->hasFile('school_logo')) {
            $path = $request->file('school_logo')->store('school/logos', ['disk' => 's3']);
            if ($path) {
                $logoPath = Storage::disk('s3')->url($path);
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
        $themeConfig = $request->has('landing_theme_config') ? json_decode($request->landing_theme_config, true) : $school->landing_theme_config;

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
        ]);

        return response()->json($school);
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
            return response()->json(['error' => 'No identifier provided'], 400);
        }

        $school = $query->first();

        if (!$school) {
            return response()->json(['error' => 'School not found'], 404);
        }

        return response()->json([
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
        $schoolId = $request->user()->school_id;

        $inquiryCount = \App\Models\Inquiry::where('school_id', $schoolId)->where('status', 'pending')->count();
        $admissionCount = \App\Models\AdmissionApplication::where('school_id', $schoolId)->where('status', 'pending')->count();

        return response()->json([
            'inquiries' => $inquiryCount,
            'admissions' => $admissionCount,
            'total' => $inquiryCount + $admissionCount
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

        $period = \App\Models\SchoolPeriod::where('id', $id)
            ->where('school_id', $request->user()->school_id)
            ->firstOrFail();

        $period->update($request->only(['name', 'start_time', 'end_time', 'type']));

        return response()->json($period);
    }
}
