<?php

namespace App\Policies;

use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class SchoolClassPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user)
    {
        return true; // Filtering happens in the query builder for "hidden" classes
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, SchoolClass $schoolClass)
    {
        if ($user->isAdmin() || $user->hasPermission('manage_all_classes')) {
            return true;
        }

        return $schoolClass->class_teacher_id === $user->id;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, SchoolClass $schoolClass)
    {
        if ($user->isAdmin() || $user->hasPermission('manage_all_classes')) {
            return true;
        }

        return $schoolClass->class_teacher_id === $user->id;
    }
}
