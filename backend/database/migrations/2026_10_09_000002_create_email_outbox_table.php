<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_outbox', function (Blueprint $table) {
            $table->id();
            $table->string('to_email')->index();
            $table->string('to_name')->nullable();
            $table->string('subject');
            $table->text('body');
            // Serialized attachments (same shape as VmsNotificationMail data).
            $table->longText('attachments')->nullable();
            // queued | sent | failed
            $table->string('status')->default('queued')->index();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_outbox');
    }
};
