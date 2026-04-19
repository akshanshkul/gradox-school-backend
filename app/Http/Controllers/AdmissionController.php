<?php

namespace App\Http\Controllers;

use App\Models\AdmissionApplication;
use App\Mail\AdmissionConfirmation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

class AdmissionController extends Controller
{
    public function index(Request $request)
    {
        $admissions = AdmissionApplication::where('school_id', $request->user()->school_id)
            ->with(['schoolClass.grade', 'schoolClass.section'])
            ->orderBy('created_at', 'desc')
            ->get();
        return response()->json($admissions);
    }
 
    public function getNextAdmissionNumber(Request $request)
    {
        $schoolId = $request->user()->school_id;
        $lastNumber = \App\Models\Student::where('school_id', $schoolId)
            ->whereRaw("admission_number REGEXP '^[0-9]+$'")
            ->orderByRaw("CAST(admission_number AS UNSIGNED) DESC")
            ->value('admission_number');
 
        $next = $lastNumber ? (int)$lastNumber + 1 : 100001;
        if ($next < 100001) $next = 100001;
 
        return response()->json(['next_number' => (string)$next]);
    }

    public function getNextRollNumber(Request $request)
    {
        $request->validate([
            'school_class_id' => 'required|exists:school_classes,id',
        ]);

        $school = $request->user()->school;
        $session = $school->getActiveSession()->id;

        $usedRolls = \App\Models\StudentAcademicRecord::where('school_class_id', $request->school_class_id)
            ->where('academic_year', $session)
            ->whereNotNull('roll_number')
            ->whereRaw("roll_number REGEXP '^[0-9]+$'")
            ->pluck('roll_number')
            ->map(fn($val) => (int)$val)
            ->sort()
            ->values()
            ->toArray();

        // Gap detection logic
        $nextRoll = 1;
        foreach ($usedRolls as $roll) {
            if ($roll === $nextRoll) {
                $nextRoll++;
            } elseif ($roll > $nextRoll) {
                break; // Found a gap!
            }
        }

        return response()->json(['next_roll' => (string)$nextRoll]);
    }

    public function resequenceRollNumbers(Request $request)
    {
        $request->validate([
            'school_class_id' => 'required|exists:school_classes,id',
        ]);

        return DB::transaction(function() use ($request) {
            $school = $request->user()->school;
            $session = $school->getActiveSession()->id;

            $records = \App\Models\StudentAcademicRecord::where('school_class_id', $request->school_class_id)
                ->where('academic_year', $session)
                ->with('student')
                ->get()
                ->sortBy(fn($record) => $record->student->name, SORT_NATURAL | SORT_FLAG_CASE);

            $currentRoll = 1;
            foreach ($records as $record) {
                $record->update(['roll_number' => (string)$currentRoll]);
                $currentRoll++;
            }

            return response()->json(['message' => 'Roll numbers re-sequenced successfully based on alphabetical order.']);
        });
    }

    public function store(Request $request)
    {
        $request->validate([
            'school_id' => 'required|exists:schools,id',
            'school_class_id' => 'required|exists:school_classes,id',
            'student_name' => 'required|string|max:255',
            'photo' => 'nullable|image|max:5120',
            'parent_name' => 'nullable|string|max:255',
            'email' => 'required|email',
            'phone' => 'nullable|string',
        ]);
        
        $url = null;
        if ($request->hasFile('photo')) {
            // Updated to use S3 disk
            $path = $request->file('photo')->store('school-' . $request->school_id . '/admissions/photos', 's3');
            $url = Storage::disk('s3')->url($path);
        }

        // Collect extra fields into metadata
        $metadata = $request->input('metadata', []);
        if (is_string($metadata)) {
            $metadata = json_decode($metadata, true) ?? [];
        }

        // Automatically capture common extra fields if they are sent at root
        $extraFields = ['previous_school', 'residential_address', 'parent_occupation', 'whatsapp_number', 'dob'];
        foreach ($extraFields as $field) {
            if ($request->has($field)) {
                $metadata[$field] = $request->input($field);
            }
        }

        $application = AdmissionApplication::create([
            'school_id' => $request->school_id,
            'school_class_id' => $request->school_class_id,
            'student_name' => $request->student_name,
            'photo_path' => $url,
            'parent_name' => $request->parent_name,
            'email' => $request->email,
            'phone' => $request->phone,
            'metadata' => $metadata,
            'status' => 'pending',
        ]);

        if ($application->email) {
            try {
                Mail::to($application->email)->send(new \App\Mail\AdmissionRequest($application->load(['school', 'schoolClass.grade'])));
            } catch (\Exception $e) {
                // Silently log or handle mail failure
            }
        }

        return response()->json(['message' => 'Application submitted successfully', 'data' => $application], 201);
    }

    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:pending,approved,rejected',
            'metadata' => 'nullable|array'
        ]);

        $application = AdmissionApplication::where('id', $id)
            ->where('school_id', $request->user()->school_id)
            ->firstOrFail();

        $application->status = $request->status;
        
        // If moving to approved, save all biographical metadata
        if ($request->has('metadata')) {
            $currentMetadata = $application->metadata ?? [];
            $application->metadata = array_merge($currentMetadata, $request->input('metadata'));
        }

        $application->save();

        return response()->json($application);
    }

    /**
     * Final Approval - Promotes an application to a Student record.
     */
    public function approve(Request $request, $id)
    {
        $application = AdmissionApplication::with(['schoolClass'])->where('id', $id)
            ->where('school_id', $request->user()->school_id)
            ->firstOrFail();

        $request->validate([
            'admission_number' => 'required|string|unique:students,admission_number',
            'aadhaar_number' => 'required|string|size:12',
            'school_class_id' => 'required|exists:school_classes,id',
            'roll_number' => 'nullable|string',
        ]);

        return \Illuminate\Support\Facades\DB::transaction(function() use ($request, $application) {
            $school = $request->user()->school;
            $metadata = $application->metadata ?? [];

            // 1. Create Student record
            $student = \App\Models\Student::create([
                'school_id' => $school->id,
                'admission_number' => $request->admission_number,
                'aadhaar_number' => $request->aadhaar_number,
                'name' => $application->student_name,
                'email' => $request->guardian_email ?? ($metadata['guardian_email'] ?? $application->email),
                'phone' => $request->guardian_mobile ?? ($metadata['guardian_mobile'] ?? $application->phone),
                'parent_name' => $application->parent_name,
                'parent_occupation' => $request->parent_occupation ?? ($metadata['parent_occupation'] ?? null),
                'gender' => $request->gender ?? ($metadata['gender'] ?? 'male'),
                'date_of_birth' => $request->date_of_birth ?? ($metadata['dob'] ?? ($metadata['date_of_birth'] ?? now()->toDateString())),
                'admission_date' => now(),
                'status' => 'active',
                'photo_path' => $application->photo_path,
                'address' => $request->residential_address ?? ($metadata['residential_address'] ?? null),
                'previous_school' => $request->previous_school ?? ($metadata['previous_school'] ?? null),
                'tc_details' => $request->tc_details ?? ($metadata['tc_details'] ?? null),
            ]);

            // 2. Create Academic Record (Current Session)
            \App\Models\StudentAcademicRecord::create([
                'student_id' => $student->id,
                'school_class_id' => $request->school_class_id,
                'academic_year' => $school->getActiveSession()->id,
                'roll_number' => $request->roll_number,
                'status' => 'active'
            ]);

            // 3. Create Login Account
            $student->login()->create([
                'admission_number' => $student->admission_number,
                'email' => $student->email,
                'password' => bcrypt(str_replace('-', '', $student->date_of_birth)), // YYYYMMDD
            ]);

            // mark 4. Mark Application as Approved/Admitted
            $application->update([
                'status' => 'admitted', 
                'admission_number' => $request->admission_number
            ]);

            // 5. Handle Document Uploads
            if ($request->hasFile('documents')) {
                foreach ($request->file('documents') as $typeSlug => $file) {
                    $docType = \App\Models\DocumentType::where('school_id', $school->id)
                        ->where('slug', $typeSlug)
                        ->first();

                    if ($docType) {
                        // Updated to use S3 disk
                        $path = $file->store('school-' . $school->id . '/students/documents', 's3');
                        $student->documents()->create([
                            'document_type_id' => $docType->id,
                            'file_path' => Storage::disk('s3')->url($path),
                            'file_type' => $file->getClientOriginalExtension(),
                            'status' => 'verified' // Auto-verified since admin uploaded
                        ]);
                    }
                }
            }

            // 6. Send Email
            if ($student->email) {
                try {
                    Mail::to($student->email)->send(new \App\Mail\AdmissionApproved($application->load(['school', 'schoolClass.grade'])));
                } catch (\Exception $e) {}
            }

            return response()->json([
                'message' => 'Admission status: DONE. Student record created.',
                'student' => $student->load('currentRecord')
            ]);
        });
    }
}
