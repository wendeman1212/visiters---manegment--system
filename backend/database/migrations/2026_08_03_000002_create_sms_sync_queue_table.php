<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_sync_queue', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('visit_request_id')->nullable()->index();
            $table->string('recipient_type')->nullable(); // visitor | host | group_member
            $table->unsignedBigInteger('recipient_id')->nullable();
            $table->string('phone')->index();
            $table->string('event')->index(); // visit_requested | visit_approved | visit_checked_in
            $table->string('status')->default('pending'); // pending | sent | failed | skipped
            $table->string('reason')->nullable();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->timestamps();

            $table->index(['visit_request_id', 'event']);
            $table->index(['phone', 'event']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_sync_queue');
    }
};
