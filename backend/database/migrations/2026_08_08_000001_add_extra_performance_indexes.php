<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->index(['role', 'status'], 'users_role_status');
            $table->index(['company_id', 'role', 'status'], 'users_company_role_status');
            $table->index(['department_id'], 'users_department_id');
        });

        Schema::table('incidents', function (Blueprint $table) {
            $table->index(['status', 'severity'], 'incidents_status_severity');
        });

        Schema::table('check_in_out_logs', function (Blueprint $table) {
            $table->index(['checkin_time'], 'ciol_checkin_time');
            $table->index(['checkout_time'], 'ciol_checkout_time');
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->index(['branch_id', 'created_at'], 'al_branch_time');
            $table->index(['actor_type', 'actor_id'], 'al_actor');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_role_status');
            $table->dropIndex('users_company_role_status');
            $table->dropIndex('users_department_id');
        });

        Schema::table('incidents', function (Blueprint $table) {
            $table->dropIndex('incidents_status_severity');
        });

        Schema::table('check_in_out_logs', function (Blueprint $table) {
            $table->dropIndex('ciol_checkin_time');
            $table->dropIndex('ciol_checkout_time');
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex('al_branch_time');
            $table->dropIndex('al_actor');
        });
    }
};
