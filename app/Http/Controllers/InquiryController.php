<?php

namespace App\Http\Controllers;

use App\Models\Inquiry;
use App\Models\School;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use App\Mail\DemoRequestMail;

class InquiryController extends Controller
{
    public function storeDemoRequest(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'school_name' => 'required|string|max:255',
            'mobile' => 'required|string|max:20',
            'email' => 'required|email|max:255',
        ]);

        $data = $request->only(['name', 'school_name', 'mobile', 'email']);

        $recipients = [
            'akshanshkul7830@gmail.com',
            'abhay20gupta@gmail.com',
            'tenextservices@gmail.com',
            'info@gradox.in'
        ];

        try {
            Mail::to($recipients)->send(new DemoRequestMail($data));
            return response()->json(['message' => 'Demo request submitted successfully'], 201);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error sending email: ' . $e->getMessage()], 500);
        }
    }

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
