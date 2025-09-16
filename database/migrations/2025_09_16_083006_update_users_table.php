<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('role_id')->nullable()->after('id');
            $table->string('first_name')->after('role_id');
            $table->string('last_name')->after('first_name');
            $table->enum('status', ['PENDING', 'BLOCKED', 'APPROVED', 'SUSPENDED'])->default('PENDING')->after('password');
            $table->boolean('google_2fa_active')->default(false)->after('email_verified_at');
            $table->string('google_2fa_secret')->nullable()->after('google_2fa_active');
            $table->text('google_2fa_recovery_codes')->nullable()->after('google_2fa_secret');
            $table->softDeletes();
            
            $table->foreign('role_id')->references('id')->on('roles')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['role_id']);
            $table->dropColumn([
                'role_id', 
                'first_name', 
                'last_name', 
                'status', 
                'google_2fa_active', 
                'google_2fa_secret', 
                'google_2fa_recovery_codes'
            ]);
            $table->dropSoftDeletes();
        });
    }
};
