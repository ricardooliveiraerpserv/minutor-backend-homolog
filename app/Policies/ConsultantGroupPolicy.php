<?php

namespace App\Policies;

use App\Models\ConsultantGroup;
use App\Models\User;

class ConsultantGroupPolicy
{
    /**
     * Determine whether the user can view any consultant groups.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('consultant_groups.view') ||
               $user->hasPermissionTo('admin.full_access');
    }

    /**
     * Determine whether the user can view the consultant group.
     */
    public function view(User $user, ConsultantGroup $consultantGroup): bool
    {
        return $user->hasPermissionTo('consultant_groups.view') ||
               $user->hasPermissionTo('admin.full_access');
    }

    /**
     * Determine whether the user can create consultant groups.
     */
    public function create(User $user): bool
    {
        return $user->hasPermissionTo('consultant_groups.create') ||
               $user->hasPermissionTo('admin.full_access');
    }

    /**
     * Determine whether the user can update the consultant group.
     */
    public function update(User $user, ConsultantGroup $consultantGroup): bool
    {
        return $user->hasPermissionTo('consultant_groups.update') ||
               $user->hasPermissionTo('admin.full_access');
    }

    /**
     * Determine whether the user can delete the consultant group.
     */
    public function delete(User $user, ConsultantGroup $consultantGroup): bool
    {
        return $user->hasPermissionTo('consultant_groups.delete') ||
               $user->hasPermissionTo('admin.full_access');
    }

    /**
     * Determine whether the user can restore the consultant group.
     */
    public function restore(User $user, ConsultantGroup $consultantGroup): bool
    {
        return $user->hasPermissionTo('consultant_groups.delete') ||
               $user->hasPermissionTo('admin.full_access');
    }

    /**
     * Determine whether the user can permanently delete the consultant group.
     */
    public function forceDelete(User $user, ConsultantGroup $consultantGroup): bool
    {
        return $user->hasPermissionTo('admin.full_access');
    }
}

