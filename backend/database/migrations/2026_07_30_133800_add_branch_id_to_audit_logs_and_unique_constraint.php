<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        if (Schema::hasTable('audit_logs') && !Schema::hasColumn('audit_logs', 'branch_id')) {
            Schema::table('audit_logs', function (Blueprint $table) {
                $table->foreignId('branch_id')->nullable()->constrained('branches')->onDelete('set null');
            });
        }

        if (Schema::hasTable('branches') && !Schema::hasIndex('branches', ['location_id', 'name'])) {
            Schema::table('branches', function (Blueprint $table) {
                $table->unique(['location_id', 'name']);
            });
        }
    }

    public function down()
    {
        if (Schema::hasTable('audit_logs') && Schema::hasColumn('audit_logs', 'branch_id')) {
            Schema::table('audit_logs', function (Blueprint $table) {
                $table->dropForeign(['branch_id']);
                $table->dropColumn('branch_id');
            });
        }

        Schema::table('branches', function (Blueprint $table) {
            $table->dropUnique(['location_id', 'name']);
        });
    }
};
