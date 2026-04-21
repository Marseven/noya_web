<?php

namespace Database\Seeders;

use App\Models\Privilege;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class RolesAndPrivilegesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create privileges
        $privileges = [
            // User privileges
            ['nom' => 'users.view', 'description' => 'View users'],
            ['nom' => 'users.create', 'description' => 'Create users'],
            ['nom' => 'users.update', 'description' => 'Update users'],
            ['nom' => 'users.delete', 'description' => 'Delete users'],
            ['nom' => 'users.restore', 'description' => 'Restore users'],
            ['nom' => 'users.force_delete', 'description' => 'Force delete users'],
            ['nom' => 'users.manage_roles', 'description' => 'Manage user roles'],
            ['nom' => 'users.change_status', 'description' => 'Change user status'],

            // Role privileges
            ['nom' => 'roles.view', 'description' => 'View roles'],
            ['nom' => 'roles.create', 'description' => 'Create roles'],
            ['nom' => 'roles.update', 'description' => 'Update roles'],
            ['nom' => 'roles.delete', 'description' => 'Delete roles'],
            ['nom' => 'roles.restore', 'description' => 'Restore roles'],
            ['nom' => 'roles.force_delete', 'description' => 'Force delete roles'],
            ['nom' => 'roles.manage_privileges', 'description' => 'Manage role privileges'],

            // Privilege privileges
            ['nom' => 'privileges.view', 'description' => 'View privileges'],
            ['nom' => 'privileges.create', 'description' => 'Create privileges'],
            ['nom' => 'privileges.update', 'description' => 'Update privileges'],
            ['nom' => 'privileges.delete', 'description' => 'Delete privileges'],
            ['nom' => 'privileges.restore', 'description' => 'Restore privileges'],
            ['nom' => 'privileges.force_delete', 'description' => 'Force delete privileges'],

            // Merchant privileges
            ['nom' => 'merchants.view', 'description' => 'View merchants'],
            ['nom' => 'merchants.create', 'description' => 'Create merchants'],
            ['nom' => 'merchants.update', 'description' => 'Update merchants'],
            ['nom' => 'merchants.delete', 'description' => 'Delete merchants'],
            ['nom' => 'merchants.restore', 'description' => 'Restore merchants'],
            ['nom' => 'merchants.force_delete', 'description' => 'Force delete merchants'],
            ['nom' => 'merchants.manage_users', 'description' => 'Manage merchant users'],
            ['nom' => 'merchants.change_status', 'description' => 'Change merchant status'],

            // Article privileges
            ['nom' => 'articles.view', 'description' => 'View articles'],
            ['nom' => 'articles.create', 'description' => 'Create articles'],
            ['nom' => 'articles.update', 'description' => 'Update articles'],
            ['nom' => 'articles.delete', 'description' => 'Delete articles'],
            ['nom' => 'articles.restore', 'description' => 'Restore articles'],
            ['nom' => 'articles.force_delete', 'description' => 'Force delete articles'],

            // Stock privileges
            ['nom' => 'stocks.view', 'description' => 'View stocks'],
            ['nom' => 'stocks.create', 'description' => 'Create stocks'],
            ['nom' => 'stocks.update', 'description' => 'Update stocks'],
            ['nom' => 'stocks.delete', 'description' => 'Delete stocks'],
            ['nom' => 'stocks.manage', 'description' => 'Manage stock operations'],

            // Order privileges
            ['nom' => 'orders.view', 'description' => 'View orders'],
            ['nom' => 'orders.create', 'description' => 'Create orders'],
            ['nom' => 'orders.update', 'description' => 'Update orders'],
            ['nom' => 'orders.delete', 'description' => 'Delete orders'],
            ['nom' => 'orders.manage', 'description' => 'Manage orders'],

            // Payment privileges
            ['nom' => 'payments.view', 'description' => 'View payments'],
            ['nom' => 'payments.create', 'description' => 'Create payments'],
            ['nom' => 'payments.update', 'description' => 'Update payments'],
            ['nom' => 'payments.delete', 'description' => 'Delete payments'],
            ['nom' => 'payments.manage', 'description' => 'Manage payments'],

            // Cart privileges
            ['nom' => 'carts.view', 'description' => 'View carts'],
            ['nom' => 'carts.create', 'description' => 'Create carts'],
            ['nom' => 'carts.update', 'description' => 'Update carts'],
            ['nom' => 'carts.delete', 'description' => 'Delete carts'],

            // Export privileges
            ['nom' => 'users.export', 'description' => 'Export users data'],
            ['nom' => 'roles.export', 'description' => 'Export roles data'],
            ['nom' => 'privileges.export', 'description' => 'Export privileges data'],
            ['nom' => 'merchants.export', 'description' => 'Export merchants data'],
            ['nom' => 'articles.export', 'description' => 'Export articles data'],
            ['nom' => 'stocks.export', 'description' => 'Export stocks data'],
            ['nom' => 'orders.export', 'description' => 'Export orders data'],
            ['nom' => 'carts.export', 'description' => 'Export carts data'],
            ['nom' => 'payments.export', 'description' => 'Export payments data'],
        ];

        foreach ($privileges as $privilege) {
            Privilege::firstOrCreate(
                ['nom' => $privilege['nom']],
                $privilege
            );
        }

        // Create roles
        $superAdminRole = Role::firstOrCreate(
            ['name' => 'Super Admin'],
            [
                'name' => 'Super Admin',
                'description' => 'Super administrator with all privileges',
                'is_active' => true
            ]
        );

        $adminRole = Role::firstOrCreate(
            ['name' => 'Admin'],
            [
                'name' => 'Admin',
                'description' => 'Administrator with most privileges',
                'is_active' => true
            ]
        );

        $managerRole = Role::firstOrCreate(
            ['name' => 'Manager'],
            [
                'name' => 'Manager',
                'description' => 'Manager with limited privileges',
                'is_active' => true
            ]
        );

        $userRole = Role::firstOrCreate(
            ['name' => 'User'],
            [
                'name' => 'User',
                'description' => 'Basic user role',
                'is_active' => true
            ]
        );

        // Assign all privileges to Super Admin
        $allPrivileges = Privilege::all();
        $superAdminRole->privileges()->sync($allPrivileges->pluck('id'));

        // Assign most privileges to Admin (excluding force delete and some sensitive operations)
        $adminPrivileges = $allPrivileges->filter(function ($privilege) {
            if (str_contains($privilege->nom, 'force_delete')) {
                return false;
            }

            if ($privilege->nom === 'merchants.delete') {
                return false;
            }

            if ($privilege->nom === 'roles.delete') {
                return false;
            }

            return !in_array($privilege->nom, [
                'privileges.create',
                'privileges.update',
                'privileges.delete',
                'privileges.restore',
                'privileges.force_delete',
            ], true);
        });
        $adminRole->privileges()->sync($adminPrivileges->pluck('id'));

        // Assign limited privileges to Manager
        $managerPrivileges = $allPrivileges->filter(function ($privilege) {
            if ($privilege->nom === 'privileges.view') {
                return false;
            }

            if (in_array($privilege->nom, ['merchants.delete', 'merchants.force_delete'], true)) {
                return false;
            }

            return str_contains($privilege->nom, 'view') || 
                   in_array($privilege->nom, ['users.create', 'users.update', 'users.delete', 'users.change_status'], true) ||
                   str_contains($privilege->nom, 'merchants.') ||
                   str_contains($privilege->nom, 'articles.') ||
                   str_contains($privilege->nom, 'stocks.') ||
                   str_contains($privilege->nom, 'orders.') ||
                   str_contains($privilege->nom, 'carts.');
        });
        $managerRole->privileges()->sync($managerPrivileges->pluck('id'));

        // Assign basic privileges to User
        $userPrivileges = $allPrivileges->filter(function ($privilege) {
            if ($privilege->nom === 'users.delete') {
                return true;
            }

            return str_contains($privilege->nom, '.view') && 
                (str_contains($privilege->nom, 'articles.') ||
                 str_contains($privilege->nom, 'orders.') ||
                 str_contains($privilege->nom, 'carts.') ||
                 str_contains($privilege->nom, 'stocks.') ||
                 str_contains($privilege->nom, 'payments.'));
        });
        $userRole->privileges()->sync($userPrivileges->pluck('id'));

        // Create a super admin user
        $superAdmin = User::firstOrCreate(
            ['email' => 'admin@noyaweb.com'],
            [
                'first_name' => 'Super',
                'last_name' => 'Admin',
                'email' => 'admin@noyaweb.com',
                'password' => Hash::make('password123'),
                'role_id' => $superAdminRole->id,
                'status' => 'APPROVED',
                'email_verified_at' => now()
            ]
        );

        $this->command->info('Roles and privileges seeded successfully!');
        $this->command->info('Super Admin created: admin@noyaweb.com / password123');
    }
}
