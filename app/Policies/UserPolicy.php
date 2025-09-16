<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPrivilege('users.view');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, User $model): bool
    {
        // Users can view their own profile or if they have the privilege
        return $user->id === $model->id || $user->hasPrivilege('users.view');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->hasPrivilege('users.create');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, User $model): bool
    {
        // Users can update their own profile or if they have the privilege
        return $user->id === $model->id || $user->hasPrivilege('users.update');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, User $model): bool
    {
        // Users cannot delete themselves, but can delete others if they have the privilege
        return $user->id !== $model->id && $user->hasPrivilege('users.delete');
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, User $model): bool
    {
        return $user->hasPrivilege('users.restore');
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, User $model): bool
    {
        return $user->hasPrivilege('users.force_delete');
    }

    /**
     * Determine whether the user can manage roles for other users.
     */
    public function manageRoles(User $user): bool
    {
        return $user->hasPrivilege('users.manage_roles');
    }

    /**
     * Determine whether the user can change user status.
     */
    public function changeStatus(User $user): bool
    {
        return $user->hasPrivilege('users.change_status');
    }
}