<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visit_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('visitor_id');
            $table->unsignedBigInteger('host_id');
            $table->string('purpose');
            $table->dateTime('requested_time');
            $table->integer('duration'); // In minutes
            $table->string('meeting_room_id')->nullable();
            $table->string('status')->default('pending'); // pending, approved, rejected, escalated, checked_in, checked_out, archived
            $table->unsignedBigInteger('escalated_to')->nullable(); // references users.id
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('comments')->nullable(); // Host, manager, or rejection reasons
            $table->timestamps();

            // Foreign keys
            $table->foreign('visitor_id')->references('id')->on('visitors')->onDelete('cascade');
            $table->foreign('host_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('escalated_to')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visit_requests');
    }
};
