<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incidents', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->enum('severity', ['low', 'medium', 'high', 'critical'])->default('medium');
            $table->enum('status', ['open', 'investigating', 'resolved', 'closed'])->default('open');
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('related_visit_id')->nullable();
            $table->unsignedBigInteger('related_visitor_id')->nullable();
            $table->unsignedBigInteger('reported_by');
            $table->string('action_taken')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->foreign('related_visit_id')->references('id')->on('visit_requests')->onDelete('set null');
            $table->foreign('related_visitor_id')->references('id')->on('visitors')->onDelete('set null');
            $table->foreign('reported_by')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incidents');
    }
};
