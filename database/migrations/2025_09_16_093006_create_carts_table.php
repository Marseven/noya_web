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
        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('article_id')->nullable();
            $table->integer('quantity')->default(1);
            $table->unsignedBigInteger('order_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            $table->unique(['article_id', 'order_id']);
            $table->foreign('article_id')->references('id')->on('articles')->onDelete('set null');
            $table->foreign('order_id')->references('id')->on('orders')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('carts');
    }
};
