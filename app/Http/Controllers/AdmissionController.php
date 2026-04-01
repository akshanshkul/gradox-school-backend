<?php

namespace App\Http\Controllers;

use App\Models\AdmissionApplication;
use Illuminate\Http\Request;
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
        $request->validate([
            'school_id' => 'required|exists:schools,id',
            'school_class_id' => 'required|exists:school_classes,id',
            'student_name' => 'required|string|max:255',
            'photo' => 'nullable|image|max:5120',
            'parent_name' => 'nullable|string|max:255',
            'email' => 'nullable|email',
            'phone' => 'nullable|string',
            'metadata' => 'nullable|string', // JSON string from frontend FD
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

        return response()->json(['message' => 'Application submitted successfully', 'data' => $application], 201);
    }

    public function updateStatus(Request $request, $id)
    {
        $request->validate(['status' => 'required|in:pending,approved,rejected']);
        $application = AdmissionApplication::where('id', $id)->where('school_id', $request->user()->school_id)->firstOrFail();
        $application->update(['status' => $request->status]);
        return response()->json($application);
    }
}
