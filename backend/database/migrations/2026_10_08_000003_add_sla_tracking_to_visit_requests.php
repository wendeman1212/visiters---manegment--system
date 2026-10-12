<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visit_requests', function (Blueprint $table) {
            // Level 1 clock (host). NULL = use created_at (legacy rows).
            $table->timestamp('host_sla_started_at')->nullable()->after('escalated_to');
            $table->timestamp('host_sla_warned_at')->nullable()->after('host_sla_started_at');
            // Level 2 clock (admin). NULL = use updated_at for legacy rows.
            $table->timestamp('admin_sla_started_at')->nullable()->after('host_sla_warned_at');
            $table->timestamp('admin_sla_warned_at')->nullable()->after('admin_sla_started_at');
        });

        // Legacy escalated visits: freeze the admin clock at the moment of the
        // last update so their existing deadline does not shift.
        DB::table('visit_requests')
            ->where('status', 'escalated')
            ->whereNull('admin_sla_started_at')
            ->update(['admin_sla_started_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('visit_requests', function (Blueprint $table) {
            $table->dropColumn([
                'host_sla_started_at',
                'host_sla_warned_at',
                'admin_sla_started_at',
                'admin_sla_warned_at',
            ]);
        });
    }
};
