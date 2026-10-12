<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reschedule_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('visit_request_id');
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->unsignedBigInteger('requested_by_visitor')->nullable();
            $table->string('requested_by_role');
            $table->dateTime('old_requested_time');
            $table->integer('old_duration');
            $table->dateTime('new_requested_time');
            $table->integer('new_duration');
            $table->string('reason')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->text('reviewer_comments')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->timestamps();

            $table->foreign('visit_request_id')->references('id')->on('visit_requests')->onDelete('cascade');
            $table->foreign('requested_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('requested_by_visitor')->references('id')->on('visitors')->onDelete('set null');
            $table->foreign('reviewed_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reschedule_requests');
    }
};
