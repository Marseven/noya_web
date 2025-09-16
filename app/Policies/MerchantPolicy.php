<?php

namespace App\Policies;

use App\Models\Merchant;
use App\Models\User;

class MerchantPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPrivilege('merchants.view');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Merchant $merchant): bool
    {
        return $user->hasPrivilege('merchants.view');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->hasPrivilege('merchants.create');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Merchant $merchant): bool
    {
        return $user->hasPrivilege('merchants.update');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Merchant $merchant): bool
    {
        return $user->hasPrivilege('merchants.delete');
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Merchant $merchant): bool
    {
        return $user->hasPrivilege('merchants.restore');
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Merchant $merchant): bool
    {
        return $user->hasPrivilege('merchants.force_delete');
    }

    /**
     * Determine whether the user can manage users for merchants.
     */
    public function manageUsers(User $user): bool
    {
        return $user->hasPrivilege('merchants.manage_users');
    }

    /**
     * Determine whether the user can change merchant status.
     */
    public function changeStatus(User $user): bool
    {
        return $user->hasPrivilege('merchants.change_status');
    }
}