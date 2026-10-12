<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visitor_properties', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('visit_request_id');
            $table->string('item_name');
            $table->integer('quantity')->default(1);
            $table->string('serial_number')->nullable();
            $table->string('photo_url')->nullable();
            $table->string('property_tag')->nullable();
            $table->enum('status', ['checked_in', 'returned', 'missing'])->default('checked_in');
            $table->timestamp('checked_in_at');
            $table->timestamp('returned_at')->nullable();
            $table->unsignedBigInteger('registered_by');
            $table->timestamps();

            $table->foreign('visit_request_id')->references('id')->on('visit_requests')->onDelete('cascade');
            $table->foreign('registered_by')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visitor_properties');
    }
};
