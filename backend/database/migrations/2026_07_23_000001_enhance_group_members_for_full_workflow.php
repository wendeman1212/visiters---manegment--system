<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visit_group_members', function (Blueprint $table) {
            $table->timestamp('checked_in_at')->nullable()->after('qr_used_at');
            $table->timestamp('checked_out_at')->nullable()->after('checked_in_at');
            $table->string('exit_qr_token', 150)->nullable()->unique()->after('checked_out_at');
            $table->timestamp('exit_qr_expires_at')->nullable()->after('exit_qr_token');
            $table->string('badge_status')->default('not_checked_in')->after('exit_qr_expires_at');
        });

        Schema::table('visitor_properties', function (Blueprint $table) {
            $table->unsignedBigInteger('visit_group_member_id')->nullable()->after('visit_request_id');
            $table->foreign('visit_group_member_id')->references('id')->on('visit_group_members')->onDelete('cascade');
        });

        Schema::table('check_in_out_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('visit_group_member_id')->nullable()->after('visit_request_id');
            $table->foreign('visit_group_member_id')->references('id')->on('visit_group_members')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::table('visit_group_members', function (Blueprint $table) {
            $table->dropColumn(['checked_in_at', 'checked_out_at', 'exit_qr_token', 'exit_qr_expires_at', 'badge_status']);
        });

        Schema::table('visitor_properties', function (Blueprint $table) {
            $table->dropForeign(['visit_group_member_id']);
            $table->dropColumn('visit_group_member_id');
        });

        Schema::table('check_in_out_logs', function (Blueprint $table) {
            $table->dropForeign(['visit_group_member_id']);
            $table->dropColumn('visit_group_member_id');
        });
    }
};
