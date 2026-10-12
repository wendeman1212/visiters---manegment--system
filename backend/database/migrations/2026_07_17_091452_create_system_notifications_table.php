<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('event');
            $table->string('recipient_type');
            $table->unsignedBigInteger('recipient_id')->nullable();
            $table->string('title');
            $table->text('message');
            $table->json('data')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['recipient_type', 'recipient_id', 'is_read']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_notifications');
    }
};
