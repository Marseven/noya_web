<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $adminRoleId = DB::table('roles')->where('name', 'Admin')->value('id');
        $deleteMerchantPrivilegeId = DB::table('privileges')->where('nom', 'merchants.delete')->value('id');

        if (!$adminRoleId || !$deleteMerchantPrivilegeId) {
            return;
        }

        $existing = DB::table('role_privileges')
            ->where('role_id', (int) $adminRoleId)
            ->where('privilege_id', (int) $deleteMerchantPrivilegeId)
            ->first();

        if ($existing) {
            DB::table('role_privileges')
                ->where('role_id', (int) $adminRoleId)
                ->where('privilege_id', (int) $deleteMerchantPrivilegeId)
                ->update([
                    'deleted_at' => null,
                    'updated_at' => now(),
                ]);

            return;
        }

        DB::table('role_privileges')->insert([
            'role_id' => (int) $adminRoleId,
            'privilege_id' => (int) $deleteMerchantPrivilegeId,
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => null,
        ]);
    }

    public function down(): void
    {
        $adminRoleId = DB::table('roles')->where('name', 'Admin')->value('id');
        $deleteMerchantPrivilegeId = DB::table('privileges')->where('nom', 'merchants.delete')->value('id');

        if (!$adminRoleId || !$deleteMerchantPrivilegeId) {
            return;
        }

        DB::table('role_privileges')
            ->where('role_id', (int) $adminRoleId)
            ->where('privilege_id', (int) $deleteMerchantPrivilegeId)
            ->update([
                'deleted_at' => now(),
                'updated_at' => now(),
            ]);
    }
};
