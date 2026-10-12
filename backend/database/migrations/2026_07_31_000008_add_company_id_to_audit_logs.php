<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('audit_logs', 'company_id')) {
            Schema::table('audit_logs', function (Blueprint $table) {
                $table->unsignedBigInteger('company_id')->nullable()->index();
            });
        }

        if (Schema::hasColumn('audit_logs', 'company_id')) {
            DB::table('audit_logs')
                ->whereNull('company_id')
                ->where('actor_type', 'user')
                ->update([
                    'company_id' => DB::raw('(SELECT company_id FROM users WHERE users.id = audit_logs.actor_id)'),
                ]);

            DB::table('audit_logs')
                ->whereNull('company_id')
                ->where('actor_type', 'visitor')
                ->update([
                    'company_id' => DB::raw('(SELECT company_id FROM visitors WHERE visitors.id = audit_logs.actor_id)'),
                ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('audit_logs', 'company_id')) {
            Schema::table('audit_logs', function (Blueprint $table) {
                $table->dropIndex(['company_id']);
                $table->dropColumn('company_id');
            });
        }
    }
};
