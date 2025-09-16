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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number')->unique();
            $table->decimal('amount', 10, 2)->nullable();
            $table->unsignedBigInteger('merchant_id')->nullable();
            $table->enum('status', ['INIT', 'PAID', 'PARTIALY_PAID', 'CANCELLED', 'REJECTED', 'DELIVERED'])->default('INIT');
            $table->timestamps();
            $table->softDeletes();
            
            $table->foreign('merchant_id')->references('id')->on('merchants')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
