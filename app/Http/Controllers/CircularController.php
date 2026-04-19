<?php

namespace App\Http\Controllers;

use App\Models\Circular;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class CircularController extends Controller
{
    /**
     * List all circulars for the school (Admin/Teacher view)
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $schoolId = $user->school_id;
        
        $query = Circular::where('school_id', $schoolId);

        // Filter by type if provided
        if ($request->has('type') && $request->type !== 'all') {
            $query->where('type', $request->type);
        }

        // Teachers only see their own circulars, Admins see everything
        if (!$user->isAdmin()) {
            $query->where('created_by', $user->id);
        }

        $circulars = $query->with(['creator:id,name', 'schoolClass' => function($q) {
            $q->select('id', 'grade_id', 'section_id')->with(['grade:id,name', 'section:id,name']);
        }])
            ->orderBy('published_at', 'desc')
            ->paginate(20);

        return $this->successResponse($circulars);
    }

    /**
     * Create a new circular
     */
    public function store(Request $request)
    {
        $user = $request->user();
        $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'type' => 'required|in:ptm,circular,personal,event,notice',
            'scope' => 'required|in:school,class,student',
            'school_class_id' => 'required_if:scope,class|nullable',
            'student_id' => 'required_if:scope,student|nullable',
            'published_at' => 'nullable|date',
            'expires_at' => 'nullable|date|after_or_equal:published_at',
            'image' => 'nullable|image|max:2048',
            'file' => 'nullable|mimes:pdf|max:5120',
        ]);

        $imagePath = null;
        if ($request->hasFile('image')) {
            $imagePath = $request->file('image')->store('circulars/images');
        }

        $filePath = null;
        if ($request->hasFile('file')) {
            $filePath = $request->file('file')->store('circulars/files');
        }

        $schoolClassId = $request->school_class_id;
        $studentId = $request->student_id;

        // If published_at is today or null, use current exact time to ensure it shows as UNREAD
        $publishedAt = $request->published_at ? \Illuminate\Support\Carbon::parse($request->published_at) : now();
        if ($publishedAt->isToday() && !$request->published_at) {
            $publishedAt = now();
        } elseif ($publishedAt->isToday() && $request->published_at) {
            // If user provided a date but it's today, we still want it to be "now" so it's greater than last_read_at
            $publishedAt = now();
        }

        // Special handling for student lookup by admission number
        if ($request->scope === 'student' && $studentId) {
            $student = \App\Models\Student::where('school_id', $user->school_id)
                ->where(function($q) use ($studentId) {
                    $q->where('id', $studentId)
                      ->orWhere('admission_number', $studentId);
                })->first();

            if (!$student) {
                return $this->errorResponse('Student not found with the provided ID or Admission Number.', 422);
            }
            $studentId = $student->id;
        }

        // Permission check
        if ($request->scope === 'class') {
            if (!$user->isAdmin()) {
                $isClassTeacher = DB::table('school_classes')
                    ->where('id', $schoolClassId)
                    ->where('class_teacher_id', $user->id)
                    ->exists();
                
                if (!$isClassTeacher) {
                    return $this->errorResponse('You are not authorized to post to this class.', 403);
                }
            }
        }

        if ($request->scope === 'student') {
            if (!$user->isAdmin()) {
                // Check if the student is in a class managed by this teacher
                $studentInManagedClass = DB::table('students')
                    ->join('student_academic_records', 'students.id', '=', 'student_academic_records.student_id')
                    ->join('school_classes', 'student_academic_records.school_class_id', '=', 'school_classes.id')
                    ->where('students.id', $studentId)
                    ->where('school_classes.class_teacher_id', $user->id)
                    ->exists();

                if (!$studentInManagedClass) {
                    return $this->errorResponse('You are not authorized to post to this specific student.', 403);
                }
            }
        }

        if ($request->scope === 'school' && !$user->isAdmin()) {
            return $this->errorResponse('Only admins can create school-wide circulars.', 403);
        }

        $circular = Circular::create([
            'school_id' => $user->school_id,
            'created_by' => $user->id,
            'title' => $request->title,
            'description' => $request->description,
            'type' => $request->type,
            'scope' => $request->scope,
            'school_class_id' => $request->scope === 'class' ? $schoolClassId : null,
            'student_id' => $request->scope === 'student' ? $studentId : null,
            'image_path' => $imagePath,
            'file_path' => $filePath,
            'published_at' => $publishedAt,
            'expires_at' => $request->expires_at,
        ]);

        // Trigger Push Notifications
        $this->sendPushNotifications($circular);

        return $this->successResponse($circular, 'Circular created successfully', 201);
    }

    /**
     * Update an existing circular
     */
    public function update(Request $request, $id)
    {
        $user = $request->user();
        $circular = Circular::where('school_id', $user->school_id)->findOrFail($id);

        // Permission check
        if (!$user->isAdmin() && $circular->created_by !== $user->id) {
            return $this->errorResponse('Unauthorized to update this circular.', 403);
        }

        $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'type' => 'required|in:ptm,circular,personal,event,notice',
            'scope' => 'required|in:school,class,student',
            'school_class_id' => 'required_if:scope,class|nullable',
            'student_id' => 'required_if:scope,student|nullable',
            'published_at' => 'nullable|date',
            'expires_at' => 'nullable|date|after_or_equal:published_at',
            'image' => 'nullable|image|max:2048',
            'file' => 'nullable|mimes:pdf|max:5120',
        ]);

        $data = $request->only(['title', 'description', 'type', 'scope', 'expires_at']);
        
        if ($request->published_at) {
            $publishedAt = \Illuminate\Support\Carbon::parse($request->published_at);
            if ($publishedAt->isToday()) {
                $data['published_at'] = now();
            } else {
                $data['published_at'] = $publishedAt;
            }
        }
        
        // Scope logic
        if ($request->scope === 'class') {
            $data['school_class_id'] = $request->school_class_id;
            $data['student_id'] = null;
        } elseif ($request->scope === 'student') {
            $data['student_id'] = $request->student_id; // Assuming ID or admission handled in store-like logic if we want consistency
            $data['school_class_id'] = null;
        } else {
            $data['school_class_id'] = null;
            $data['student_id'] = null;
        }

        // Handle Image Update
        if ($request->hasFile('image')) {
            if ($circular->image_path) {
                Storage::delete($circular->image_path);
            }
            $data['image_path'] = $request->file('image')->store('circulars/images');
        }

        // Handle File Update
        if ($request->hasFile('file')) {
            if ($circular->file_path) {
                Storage::delete($circular->file_path);
            }
            $data['file_path'] = $request->file('file')->store('circulars/files');
        }

        $circular->update($data);

        // Trigger Push Notifications
        $this->sendPushNotifications($circular);

        return $this->successResponse($circular, 'Circular updated successfully');
    }

    /**
     * Send Push Notifications via Expo API
     */
    /**
     * Send Push Notifications via FCM v1 API
     */
    private function sendPushNotifications(Circular $circular)
    {
        $targetStudentIds = [];

        if ($circular->scope === 'school') {
            $targetStudentIds = \App\Models\Student::where('school_id', $circular->school_id)->pluck('id')->toArray();
        } elseif ($circular->scope === 'class') {
            $targetStudentIds = \App\Models\StudentAcademicRecord::where('school_class_id', $circular->school_class_id)
                ->pluck('student_id')->toArray();
        } elseif ($circular->scope === 'student') {
            $targetStudentIds = [$circular->student_id];
        }

        if (empty($targetStudentIds)) {
            Log::info("FCM v1: No target students found for circular ID {$circular->id} with scope {$circular->scope}");
            return;
        }

        $tokens = \App\Models\StudentDeviceToken::whereIn('student_id', $targetStudentIds)->pluck('token')->toArray();

        if (empty($tokens)) {
            Log::info("FCM v1: No registered device tokens found for target students (ID: " . implode(',', $targetStudentIds) . ")");
            return;
        }

        Log::info("FCM v1: Broadcasting circular {$circular->id} to " . count($tokens) . " device tokens.");

        \App\Services\FirebaseV1Service::send(
            $tokens,
            $circular->title,
            $circular->description ? strip_tags(substr($circular->description, 0, 150)) : 'New school notice posted',
            ['type' => 'circular', 'id' => (string)$circular->id]
        );
    }

    /**
     * Delete a circular
     */
    public function destroy(Request $request, $id)
    {
        $circular = Circular::where('school_id', $request->user()->school_id)->findOrFail($id);
        
        // Admins can delete any, teachers only their own
        if (!$request->user()->isAdmin() && $circular->created_by !== $request->user()->id) {
            return $this->errorResponse('Unauthorized to delete this circular.', 403);
        }

        $circular->delete();
        return $this->successResponse(null, 'Circular deleted successfully');
    }

    /**
     * List circulars for the student mobile app
     */
    public function studentIndex(Request $request)
    {
        $studentLogin = $request->user(); // Authenticated as StudentLogin
        $student = $studentLogin->student;
        
        if (!$student) {
            return $this->errorResponse('Student record not found', 404);
        }

        // Get current class
        $currentRecord = $student->academicRecords()
            ->where('academic_year', $student->school->getActiveSession()->id)
            ->first();

        $classId = $currentRecord ? $currentRecord->school_class_id : null;

        $circulars = Circular::where('school_id', $student->school_id)
            ->where('is_active', true)
            ->where('published_at', '<=', now())
            ->where(function($q) use ($classId, $student) {
                // School-wide
                $q->where('scope', 'school')
                // OR Class-specific
                ->orWhere(function($sq) use ($classId) {
                    $sq->where('scope', 'class')->where('school_class_id', $classId);
                })
                // OR Student-specific
                ->orWhere(function($sq) use ($student) {
                    $sq->where('scope', 'student')->where('student_id', $student->id);
                });
            })
            ->with(['creator:id,name', 'schoolClass' => function($q) {
                $q->select('id', 'grade_id', 'section_id')->with(['grade:id,name', 'section:id,name']);
            }])
            ->orderBy('published_at', 'desc')
            ->paginate(20);

        return $this->successResponse($circulars, 'Circulars retrieved successfully');
    }
}
