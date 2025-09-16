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
        Schema::create('stocks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->unsignedBigInteger('article_id');
            $table->integer('stock')->default(0);
            $table->enum('last_action_type', ['MANUALLY_ADD', 'MANUALLY_WITHDRAW', 'AUTO_ADD', 'AUTO_WITHDRAW'])->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            $table->unique(['merchant_id', 'article_id']);
            $table->foreign('merchant_id')->references('id')->on('merchants')->onDelete('cascade');
            $table->foreign('article_id')->references('id')->on('articles')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stocks');
    }
};
