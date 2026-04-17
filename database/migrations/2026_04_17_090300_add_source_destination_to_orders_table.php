<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('source_merchant_id')->nullable()->after('amount');
            $table->unsignedBigInteger('destination_merchant_id')->nullable()->after('source_merchant_id');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreign('source_merchant_id')->references('id')->on('merchants')->onDelete('set null');
            $table->foreign('destination_merchant_id')->references('id')->on('merchants')->onDelete('set null');
            $table->index('source_merchant_id');
            $table->index('destination_merchant_id');
        });

        // Backfill destination from legacy merchant_id for existing orders.
        DB::table('orders')
            ->whereNull('destination_merchant_id')
            ->update([
                'destination_merchant_id' => DB::raw('merchant_id'),
            ]);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['source_merchant_id']);
            $table->dropForeign(['destination_merchant_id']);
            $table->dropIndex(['source_merchant_id']);
            $table->dropIndex(['destination_merchant_id']);
            $table->dropColumn(['source_merchant_id', 'destination_merchant_id']);
        });
    }
};

