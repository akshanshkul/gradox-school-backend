<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;


use App\Models\Student;
use App\Models\StudentAcademicRecord;
use Illuminate\Support\Facades\DB;

class StudentController extends Controller
{
    public function index(Request $request)
    {
        $school = $request->user()->school;
        $currentSession = $school->current_session ?? date('Y');

        $query = Student::where('school_id', $school->id)
            ->with(['currentRecord' => function($q) use ($currentSession) {
                $q->where('academic_year', $currentSession);
            }, 'currentRecord.schoolClass.grade', 'currentRecord.section'])
            ->orderBy('name', 'asc');

        // RBAC: Non-Admins can only see students in classes they manage
        if (!$request->user()->isAdmin() && !$request->user()->hasPermission('manage_all_students')) {
            $query->whereHas('currentRecord.schoolClass', function($q) use ($request) {
                $q->where('class_teacher_id', $request->user()->id);
            });
        }

        if ($request->has('class_id') && !is_null($request->class_id)) {
            $query->whereHas('currentRecord', function($q) use ($request) {
                $q->where('school_class_id', $request->class_id);
            });
        }

        if ($request->has('search') && !is_null($request->search)) {
            $query->where(function($q) use ($request) {
                $q->where('name', 'like', '%' . $request->search . '%')
                  ->orWhere('admission_number', 'like', '%' . $request->search . '%');
            });
        }

        return $this->successResponse($query->paginate(20));
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:students,email',
            'admission_number' => 'required|string|unique:students,admission_number',
            'school_class_id' => 'required|exists:school_classes,id',
            'section_id' => 'nullable|exists:sections,id',
            'date_of_birth' => 'required|date',
            'gender' => 'required|in:male,female,other',
        ]);

        return DB::transaction(function() use ($request) {
            $school = $request->user()->school;
            
            $student = Student::create([
                'school_id' => $school->id,
                'name' => $request->name,
                'email' => $request->email,
                'admission_number' => $request->admission_number,
                'date_of_birth' => $request->date_of_birth,
                'gender' => $request->gender,
                'admission_date' => now(),
                'status' => 'active'
            ]);

            StudentAcademicRecord::create([
                'student_id' => $student->id,
                'school_class_id' => $request->school_class_id,
                'section_id' => $request->section_id,
                'academic_year' => $school->current_session ?? date('Y'),
                'roll_number' => $request->roll_number,
                'status' => 'active'
            ]);

            // Create Login
            $student->login()->create([
                'admission_number' => $student->admission_number,
                'email' => $student->email,
                'password' => bcrypt(str_replace('-', '', $student->date_of_birth->format('Y-m-d')))
            ]);

            return $this->successResponse($student->load('currentRecord'), 'Student admitted successfully', 201);
        });
    }

    public function show($id, Request $request)
    {
        $student = Student::where('school_id', $request->user()->school_id)
            ->with([
                'academicRecords.schoolClass.grade', 
                'academicRecords.section', 
                'currentRecord.schoolClass.grade', 
                'currentRecord.section', 
                'documents.type', 
                'login'
            ])
            ->findOrFail($id);

        return $this->successResponse($student);
    }

    public function update(Request $request, $id)
    {
        $student = Student::where('school_id', $request->user()->school_id)->findOrFail($id);
        
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:students,email,' . $id,
            'admission_number' => 'required|string|unique:students,admission_number,' . $id,
            'aadhaar_number' => 'nullable|string|size:12',
            'gender' => 'required|in:male,female,other',
            'date_of_birth' => 'required|date',
            'school_class_id' => 'required|exists:school_classes,id',
            'section_id' => 'nullable|exists:sections,id',
            'roll_number' => 'nullable|string',
        ]);

        return DB::transaction(function() use ($request, $student) {
            $school = $request->user()->school;
            $currentSession = $school->current_session ?? date('Y');

            // 1. Update Student Basic/Bio Data
            $student->update([
                'name' => $request->name,
                'email' => $request->email,
                'admission_number' => $request->admission_number,
                'aadhaar_number' => $request->aadhaar_number,
                'phone' => $request->phone,
                'parent_name' => $request->parent_name,
                'parent_phone' => $request->parent_phone,
                'parent_occupation' => $request->parent_occupation,
                'address' => $request->address,
                'gender' => $request->gender,
                'date_of_birth' => $request->date_of_birth,
            ]);

            // 2. Update/Sync Academic Record for Current Session
            $academicRecord = StudentAcademicRecord::where('student_id', $student->id)
                ->where('academic_year', $currentSession)
                ->first();

            if ($academicRecord) {
                $academicRecord->update([
                    'school_class_id' => $request->school_class_id,
                    'section_id' => $request->section_id,
                    'roll_number' => $request->roll_number,
                ]);
            } else {
                StudentAcademicRecord::create([
                    'student_id' => $student->id,
                    'school_class_id' => $request->school_class_id,
                    'section_id' => $request->section_id,
                    'academic_year' => $currentSession,
                    'roll_number' => $request->roll_number,
                    'status' => 'active'
                ]);
            }

            return $this->successResponse($student->load('currentRecord'), 'Student profile updated successfully');
        });
    }

    public function getRoster($classId, Request $request)
    {
        $school = $request->user()->school;
        $currentSession = $school->current_session ?? date('Y');

        $students = Student::where('school_id', $school->id)
            ->whereHas('currentRecord', function($q) use ($classId, $currentSession) {
                $q->where('school_class_id', $classId)
                  ->where('academic_year', $currentSession);
            })
            ->with(['currentRecord' => function($q) use ($currentSession) {
                $q->where('academic_year', $currentSession);
            }])
            ->get()
            ->sortBy(function($student) {
                return (int) $student->currentRecord->roll_number;
            })
            ->values();

        return $this->successResponse($students, 'Student roster retrieved successfully');
    }
}

