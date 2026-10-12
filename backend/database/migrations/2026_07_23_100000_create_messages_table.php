<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->string('subject')->nullable();
            $table->text('body');

            // Sender (polymorphic: User or Visitor)
            $table->string('sender_type');
            $table->unsignedBigInteger('sender_id');

            // Recipient (polymorphic: User or Visitor)
            $table->string('recipient_type');
            $table->unsignedBigInteger('recipient_id');

            // Threading
            $table->unsignedBigInteger('parent_id')->nullable();

            // Read tracking
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();

            // Soft delete support
            $table->boolean('sender_deleted')->default(false);
            $table->boolean('recipient_deleted')->default(false);

            // Delivery tracking
            $table->boolean('email_sent')->default(false);
            $table->boolean('sms_sent')->default(false);

            $table->timestamps();

            $table->index(['sender_type', 'sender_id', 'sender_deleted']);
            $table->index(['recipient_type', 'recipient_id', 'recipient_deleted']);
            $table->index('parent_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
