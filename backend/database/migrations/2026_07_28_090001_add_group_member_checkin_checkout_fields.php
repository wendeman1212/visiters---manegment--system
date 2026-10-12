<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visit_group_members', function (Blueprint $table) {
            if (!Schema::hasColumn('visit_group_members', 'checked_in_at')) {
                $table->timestamp('checked_in_at')->nullable()->after('qr_used_at');
            }
            if (!Schema::hasColumn('visit_group_members', 'checked_out_at')) {
                $table->timestamp('checked_out_at')->nullable()->after('checked_in_at');
            }
            if (!Schema::hasColumn('visit_group_members', 'exit_qr_token')) {
                $table->string('exit_qr_token', 100)->nullable()->unique()->after('checked_out_at');
            }
            if (!Schema::hasColumn('visit_group_members', 'exit_qr_expires_at')) {
                $table->timestamp('exit_qr_expires_at')->nullable()->after('exit_qr_token');
            }
            if (!Schema::hasColumn('visit_group_members', 'badge_status')) {
                $table->string('badge_status')->default('pending')->after('exit_qr_expires_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('visit_group_members', function (Blueprint $table) {
            $table->dropColumn(['checked_in_at', 'checked_out_at', 'exit_qr_token', 'exit_qr_expires_at', 'badge_status']);
        });
    }
};
