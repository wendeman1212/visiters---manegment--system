<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sms_sync_queue', function (Blueprint $table) {
            // Exact text queued for the recipient, so a retry sends the same
            // message even if the event wording changes later.
            $table->text('message')->nullable()->after('event');
            // Offline-sync bookkeeping. attempts counts every send attempt;
            // next_attempt_at throttles retries with a backoff so a gateway that
            // is offline is not hammered, and null means "due now".
            $table->unsignedTinyInteger('attempts')->default(0)->after('status');
            $table->timestamp('last_attempt_at')->nullable()->after('reason');
            $table->timestamp('next_attempt_at')->nullable()->after('last_attempt_at');

            $table->index(['status', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::table('sms_sync_queue', function (Blueprint $table) {
            $table->dropIndex(['status', 'next_attempt_at']);
            $table->dropColumn(['message', 'attempts', 'last_attempt_at', 'next_attempt_at']);
        });
    }
};
