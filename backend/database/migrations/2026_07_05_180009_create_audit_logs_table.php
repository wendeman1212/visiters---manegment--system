<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('actor_id')->nullable(); // user or visitor id
            $table->string('actor_type')->nullable(); // 'user', 'visitor', 'system'
            $table->string('action'); // e.g. login, checkin, approve, config
            $table->string('entity')->nullable(); // e.g. users, visit_requests, blacklist
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->text('before_value')->nullable(); // JSON formatted before state
            $table->text('after_value')->nullable(); // JSON formatted after state
            $table->string('ip_address')->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
