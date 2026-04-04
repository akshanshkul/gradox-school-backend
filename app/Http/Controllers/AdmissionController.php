<?php

namespace App\Http\Controllers;

use App\Models\AdmissionApplication;
use App\Mail\AdmissionConfirmation;
use Illuminate\Http\Request;
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

    public function store(Request $request)
    {
        // ... (validation and photo upload logic remains the same)
        $request->validate([
            'school_id' => 'required|exists:schools,id',
            'school_class_id' => 'required|exists:school_classes,id',
            'student_name' => 'required|string|max:255',
            'photo' => 'nullable|image|max:5120',
            'parent_name' => 'nullable|string|max:255',
            'email' => 'required|email',
            'phone' => 'nullable|string',
            'metadata' => 'nullable|string',
        ]);
        
        $url = null;
        if ($request->hasFile('photo')) {
            $path = $request->file('photo')->store('admissions/photos', ['disk' => 's3']);
            $url = Storage::disk('s3')->url($path);
        }

        $metadata = json_decode($request->input('metadata', '{}'), true);

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
        $request->validate(['status' => 'required|in:pending,approved,rejected']);
        $application = AdmissionApplication::where('id', $id)
            ->where('school_id', $request->user()->school_id)
            ->firstOrFail();

        $oldStatus = $application->status;
        $newStatus = $request->status;

        // Generate Admission Number if approved for the first time
        if ($newStatus === 'approved' && empty($application->admission_number)) {
            $year = date('Y');
            $count = AdmissionApplication::where('school_id', $application->school_id)
                ->whereNotNull('admission_number')
                ->count() + 1;
            $application->admission_number = 'ADM-' . $year . '-' . str_pad($count, 3, '0', STR_PAD_LEFT);
        }

        $application->status = $newStatus;
        $application->save();

        // Send confirmation email if status changed to approved
        if ($newStatus === 'approved' && $oldStatus !== 'approved' && $application->email) {
            try {
                Mail::to($application->email)->send(new \App\Mail\AdmissionApproved($application->load(['school', 'schoolClass.grade'])));
            } catch (\Exception $e) {
                // Silently log or handle mail failure
            }
        }

        return response()->json($application);
    }
}
