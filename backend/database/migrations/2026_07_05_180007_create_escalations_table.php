<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('escalations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('visit_request_id');
            $table->unsignedBigInteger('escalated_by'); // Reference to users.id (Host or Manager)
            $table->unsignedBigInteger('escalated_to'); // Reference to users.id (Manager or Admin)
            $table->text('reason');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->foreign('visit_request_id')->references('id')->on('visit_requests')->onDelete('cascade');
            $table->foreign('escalated_by')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('escalated_to')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('escalations');
    }
};
