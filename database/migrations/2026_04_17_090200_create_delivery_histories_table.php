<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('merchant_id')->nullable();
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40);
            $table->text('note')->nullable();
            $table->timestamp('changed_at')->useCurrent();
            $table->timestamps();

            $table->foreign('order_id')->references('id')->on('orders')->onDelete('cascade');
            $table->foreign('merchant_id')->references('id')->on('merchants')->onDelete('set null');
            $table->foreign('changed_by')->references('id')->on('users')->onDelete('set null');
            $table->index(['order_id', 'changed_at']);
            $table->index(['merchant_id', 'changed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_histories');
    }
};

