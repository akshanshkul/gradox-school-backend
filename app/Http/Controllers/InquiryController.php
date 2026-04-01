<?php

namespace App\Http\Controllers;

use App\Models\Inquiry;
use App\Models\School;
use Illuminate\Http\Request;

class InquiryController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'school_id' => 'required|exists:schools,id',
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'phone' => 'nullable|string|max:20',
            'message' => 'required|string',
        ]);

        $inquiry = Inquiry::create($request->only([
            'school_id', 'name', 'email', 'phone', 'message'
        ]));

        return response()->json(['message' => 'Inquiry submitted successfully', 'data' => $inquiry], 201);
    }

    public function index(Request $request)
    {
        // Admin views inquiries for their school
        $schoolId = $request->user()->school_id;
        $inquiries = Inquiry::where('school_id', $schoolId)->orderBy('created_at', 'desc')->get();
        return response()->json($inquiries);
    }

    public function updateStatus(Request $request, $id)
    {
        $request->validate(['status' => 'required|in:unread,read']);
        $schoolId = $request->user()->school_id;

        $inquiry = Inquiry::where('id', $id)->where('school_id', $schoolId)->firstOrFail();
        $inquiry->update(['status' => $request->status]);

        return response()->json($inquiry);
    }
}
