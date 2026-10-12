<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_policies', function (Blueprint $table) {
            // Admin-level SLA. NULL = inherit sla_minutes (host SLA), which
            // keeps the original single-SLA behaviour for every existing row.
            $table->unsignedInteger('admin_sla_minutes')->nullable()->after('sla_minutes');
            $table->boolean('auto_escalation_enabled')->default(true)->after('admin_sla_minutes');
            // Minutes before the deadline when the owner gets a warning.
            $table->unsignedInteger('sla_warning_minutes')->default(5)->after('auto_escalation_enabled');
            // false = wall-clock 24/7 SLA (the original behaviour).
            $table->boolean('sla_pause_off_hours')->default(false)->after('sla_warning_minutes');
            $table->boolean('sla_count_weekends')->default(true)->after('sla_pause_off_hours');
            $table->boolean('sla_count_holidays')->default(true)->after('sla_count_weekends');
            // Delivery channels for SLA warnings / automatic escalations.
            $table->boolean('sla_notify_in_app')->default(true)->after('sla_count_holidays');
            $table->boolean('sla_notify_email')->default(true)->after('sla_notify_in_app');
            $table->boolean('sla_notify_sms')->default(false)->after('sla_notify_email');
        });
    }

    public function down(): void
    {
        Schema::table('company_policies', function (Blueprint $table) {
            $table->dropColumn([
                'admin_sla_minutes',
                'auto_escalation_enabled',
                'sla_warning_minutes',
                'sla_pause_off_hours',
                'sla_count_weekends',
                'sla_count_holidays',
                'sla_notify_in_app',
                'sla_notify_email',
                'sla_notify_sms',
            ]);
        });
    }
};
