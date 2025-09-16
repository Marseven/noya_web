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
        Schema::create('merchants', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('address');
            $table->string('entity_file')->nullable();
            $table->string('other_document_file')->nullable();
            $table->string('tel')->nullable();
            $table->string('email')->nullable();
            $table->unsignedBigInteger('merchant_parent_id')->nullable();
            $table->enum('status', ['PENDING', 'BLOCKED', 'APPROVED', 'SUSPENDED'])->default('PENDING');
            $table->enum('type', ['Distributor', 'Wholesaler', 'Subwholesaler', 'PointOfSell']);
            $table->decimal('lat', 10, 8)->nullable();
            $table->decimal('long', 11, 8)->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            $table->foreign('merchant_parent_id')->references('id')->on('merchants')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('merchants');
    }
};
