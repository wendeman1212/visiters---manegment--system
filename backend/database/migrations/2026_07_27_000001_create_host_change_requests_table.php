<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('host_change_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('visit_request_id');
            $table->unsignedBigInteger('previous_host_id');
            $table->unsignedBigInteger('new_host_id');
            $table->unsignedBigInteger('new_department_id')->nullable();
            $table->unsignedBigInteger('requested_by');
            $table->string('requested_by_role');
            $table->text('reason');
            $table->text('additional_notes')->nullable();
            $table->string('meeting_room')->nullable();
            $table->string('previous_location')->nullable();
            $table->string('new_location')->nullable();
            $table->string('status')->default('pending'); // pending, accepted, rejected, cancelled
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('transferred_at')->nullable();
            $table->timestamps();

            $table->foreign('visit_request_id')->references('id')->on('visit_requests')->onDelete('cascade');
            $table->foreign('previous_host_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('new_host_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('new_department_id')->references('id')->on('departments')->onDelete('set null');
            $table->foreign('requested_by')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('reviewed_by')->references('id')->on('users')->onDelete('set null');

            $table->index('status');
            $table->index('visit_request_id');
            $table->index('new_host_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('host_change_requests');
    }
};
