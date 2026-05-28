<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Models\Circular;
use Illuminate\Http\Request;
use Carbon\Carbon;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        
        // 1. Personal Notifications
        $personal = Notification::where('school_id', $user->school_id)
            ->where('notifiable_type', get_class($user))
            ->where('notifiable_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function($n) {
                return [
                    'id' => $n->id,
                    'title' => $n->title,
                    'message' => $n->message,
                    'type' => $n->type,
                    'data' => $n->data,
                    'read_at' => $n->read_at,
                    'created_at' => $n->created_at,
                    'is_circular' => false
                ];
            });
            
        // 2. Global Circulars (relevant to user)
        $query = Circular::where('school_id', $user->school_id)
            ->where('is_active', true)
            ->where('published_at', '<=', now());

        // Scope filtering based on user type (Teacher/User vs Student)
        if (get_class($user) === \App\Models\User::class) {
            $query->where(function($q) use ($user) {
                $q->where('scope', 'school')
                  ->orWhere(function($sq) use ($user) {
                      $sq->where('scope', 'class')
                        ->whereIn('school_class_id', function($sub) use ($user) {
                            $sub->select('id')->from('school_classes')->where('class_teacher_id', $user->id);
                        });
                  });
            });
        } else {
            // StudentLogin logic
            $student = $user->student;
            $classId = $student->academicRecords()
                ->where('academic_year', $student->school->current_session)
                ->value('school_class_id');

            $query->where(function($q) use ($classId, $student) {
                $q->where('scope', 'school')
                  ->orWhere(function($sq) use ($classId) {
                      $sq->where('scope', 'class')->where('school_class_id', $classId);
                  })
                  ->orWhere(function($sq) use ($student) {
                      $sq->where('scope', 'student')->where('student_id', $student->id);
                  });
            });
        }

        $circulars = $query->orderBy('published_at', 'desc')
            ->get()
            ->map(function($c) {
                return [
                    'id' => 'c_' . $c->id,
                    'title' => $c->title,
                    'message' => $c->description,
                    'type' => $c->type, // ptm, circular, event
                    'created_at' => $c->published_at,
                    'is_circular' => true,
                    'read_at' => null, // Circulars don't have per-user read_at in this schema yet
                    'data' => ['circular_id' => $c->id]
                ];
            });

        $all = $personal->concat($circulars)->sortByDesc('created_at')->values();

        return $this->successResponse($all);
    }

    public function markAsRead(Request $request, $id)
    {
        if (str_starts_with($id, 'c_')) {
            return $this->successResponse(null, 'Circular marked as read');
        }

        $notification = Notification::where('notifiable_type', get_class($request->user()))
            ->where('notifiable_id', $request->user()->id)
            ->findOrFail($id);

        $notification->update(['read_at' => now()]);

        return $this->successResponse(null, 'Notification marked as read');
    }

    public function getStats(Request $request)
    {
        $user = $request->user();
        
        $unreadCount = Notification::where('notifiable_type', get_class($user))
            ->where('notifiable_id', $user->id)
            ->whereNull('read_at')
            ->count();
            
        return $this->successResponse(['unread_count' => $unreadCount]);
    }
}
