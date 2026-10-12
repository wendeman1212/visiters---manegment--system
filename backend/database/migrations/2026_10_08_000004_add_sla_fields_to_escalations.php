<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('escalations', function (Blueprint $table) {
            // 'manual' = a user escalated it; 'sla' = automatic SLA timeout.
            $table->string('trigger_type', 16)->default('manual')->after('reason');
            // 1 = host SLA breached (host -> admin), 2 = admin SLA breached
            // (admin -> ceo), null = manual escalation with no SLA level.
            $table->unsignedTinyInteger('level')->nullable()->after('trigger_type');
            $table->unsignedInteger('sla_minutes')->nullable()->after('level');
            // Deadline frozen at escalation time so history stays truthful
            // even if the policy changes later.
            $table->timestamp('deadline_at')->nullable()->after('sla_minutes');
            $table->timestamp('notified_at')->nullable()->after('deadline_at');
        });

        // Backfill legacy rows from their reason text.
        DB::table('escalations')->where('reason', 'like', 'Auto-escalation%')->update([
            'trigger_type' => 'sla',
            'level'        => 1,
        ]);
        DB::table('escalations')->where('reason', 'like', 'Admin SLA timeout%')->update([
            'trigger_type' => 'sla',
            'level'        => 2,
        ]);
    }

    public function down(): void
    {
        Schema::table('escalations', function (Blueprint $table) {
            $table->dropColumn(['trigger_type', 'level', 'sla_minutes', 'deadline_at', 'notified_at']);
        });
    }
};
