<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Models\Homework;
use App\Models\SchoolClass;
use Illuminate\Http\Request;

class HomeworkController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        
        $query = Homework::where('school_id', $user->school_id)
            ->with(['schoolClass.grade', 'schoolClass.section', 'subject:id,name']);

        // If teacher, only show their own homework or homework for their managed classes
        if (!$user->isAdmin()) {
            $managedClassIds = SchoolClass::where('class_teacher_id', $user->id)->pluck('id')->toArray();
            
            $query->where(function($q) use ($user, $managedClassIds) {
                $q->where('created_by', $user->id)
                  ->orWhereIn('school_class_id', $managedClassIds);
            });
        }

        $homework = $query->orderBy('due_date', 'desc')->get();

        return $this->successResponse($homework);
    }

    public function store(Request $request)
    {
        $request->validate([
            'school_class_id' => 'required|exists:school_classes,id',
            'subject_id' => 'nullable|exists:subjects,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'due_date' => 'required|date',
        ]);

        $homework = Homework::create([
            'school_id' => $request->user()->school_id,
            'created_by' => $request->user()->id,
            'school_class_id' => $request->school_class_id,
            'subject_id' => $request->subject_id,
            'title' => $request->title,
            'description' => $request->description,
            'due_date' => $request->due_date,
        ]);

        return $this->successResponse($homework, 'Homework created successfully', 201);
    }
}
