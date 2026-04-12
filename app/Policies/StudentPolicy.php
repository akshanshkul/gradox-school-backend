<?php

namespace App\Policies;

use App\Models\Student;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class StudentPolicy
{
    use HandlesAuthorization;

    public function view(User $user, Student $student)
    {
        if ($user->isAdmin() || $user->hasPermission('manage_all_students')) {
            return true;
        }

        $currentRecord = $student->currentRecord;
        if (!$currentRecord) return false;

        return $currentRecord->schoolClass->class_teacher_id === $user->id;
    }

    public function update(User $user, Student $student)
    {
        if ($user->isAdmin() || $user->hasPermission('manage_all_students')) {
            return true;
        }

        $currentRecord = $student->currentRecord;
        if (!$currentRecord) return false;

        return $currentRecord->schoolClass->class_teacher_id === $user->id;
    }
}
