<?php
namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Student;
use App\Models\StudentFine;
use App\Models\FeeType;

class FineController extends Controller
{
    /**
     * Add an ad-hoc fine to a student
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'student_id' => 'required|exists:students,id',
            'fee_type_id' => 'required|exists:fee_types,id',
            'amount' => 'required|numeric|min:0',
            'reason' => 'required|string',
            'due_date' => 'required|date',
        ]);

        $student = Student::findOrFail($validated['student_id']);
        
        // In a real scenario, add RBAC check here to ensure the teacher 
        // has access to this student's class.

        $fine = StudentFine::create([
            'school_id' => $student->school_id,
            'student_id' => $student->id,
            'fee_type_id' => $validated['fee_type_id'],
            'added_by' => auth()->id(),
            'amount' => $validated['amount'],
            'reason' => $validated['reason'],
            'due_date' => $validated['due_date'],
            'status' => 'unpaid'
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Fine assigned successfully',
            'data' => $fine
        ]);
    }
}
