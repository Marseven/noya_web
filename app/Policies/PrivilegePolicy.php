<?php

namespace App\Policies;

use App\Models\Privilege;
use App\Models\User;

class PrivilegePolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPrivilege('privileges.view');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Privilege $privilege): bool
    {
        return $user->hasPrivilege('privileges.view');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->hasPrivilege('privileges.create');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Privilege $privilege): bool
    {
        return $user->hasPrivilege('privileges.update');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Privilege $privilege): bool
    {
        return $user->hasPrivilege('privileges.delete');
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Privilege $privilege): bool
    {
        return $user->hasPrivilege('privileges.restore');
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Privilege $privilege): bool
    {
        return $user->hasPrivilege('privileges.force_delete');
    }
}