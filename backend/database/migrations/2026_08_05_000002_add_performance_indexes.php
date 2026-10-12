<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visit_requests', function (Blueprint $table) {
            $table->index(['company_id', 'status', 'requested_time'], 'vr_company_status_time');
            $table->index(['host_id', 'status'], 'vr_host_status');
            $table->index(['visitor_id', 'status'], 'vr_visitor_status');
            $table->index(['branch_id', 'status', 'requested_time'], 'vr_branch_status_time');
            $table->index(['requested_time'], 'vr_requested_time');
            $table->index(['updated_at'], 'vr_updated_at');
        });

        Schema::table('check_in_out_logs', function (Blueprint $table) {
            $table->index(['visit_request_id', 'checkout_time'], 'ciol_visit_checkout');
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->index(['entity', 'entity_id'], 'al_entity_entity_id');
            $table->index(['action', 'company_id', 'created_at'], 'al_action_company_time');
        });

        Schema::table('qr_tokens', function (Blueprint $table) {
            $table->index(['visit_request_id', 'status'], 'qt_visit_status');
        });
    }

    public function down(): void
    {
        Schema::table('visit_requests', function (Blueprint $table) {
            $table->dropIndex('vr_company_status_time');
            $table->dropIndex('vr_host_status');
            $table->dropIndex('vr_visitor_status');
            $table->dropIndex('vr_branch_status_time');
            $table->dropIndex('vr_requested_time');
            $table->dropIndex('vr_updated_at');
        });

        Schema::table('check_in_out_logs', function (Blueprint $table) {
            $table->dropIndex('ciol_visit_checkout');
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex('al_entity_entity_id');
            $table->dropIndex('al_action_company_time');
        });

        Schema::table('qr_tokens', function (Blueprint $table) {
            $table->dropIndex('qt_visit_status');
        });
    }
};
