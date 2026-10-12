<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('host_transfers')) {
            return;
        }

        Schema::table('host_transfers', function (Blueprint $table) {
            if (!Schema::hasColumn('host_transfers', 'company_id')) {
                $table->unsignedBigInteger('company_id')->nullable()->after('transferred_by');
            }
            if (!Schema::hasColumn('host_transfers', 'branch_id')) {
                $table->foreignId('branch_id')->nullable()->after('company_id');
            }
            if (!Schema::hasColumn('host_transfers', 'department_id')) {
                $table->foreignId('department_id')->nullable()->after('branch_id');
            }
        });

        Schema::table('host_transfers', function (Blueprint $table) {
            $table->foreign('company_id')->references('company_id')->on('companies')->onDelete('cascade');
            $table->foreign('branch_id')->references('id')->on('branches')->onDelete('cascade');
            $table->foreign('department_id')->references('id')->on('departments')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('host_transfers')) {
            return;
        }

        Schema::table('host_transfers', function (Blueprint $table) {
            if (Schema::hasColumn('host_transfers', 'company_id')) {
                $table->dropForeign(['company_id']);
            }
            if (Schema::hasColumn('host_transfers', 'branch_id')) {
                $table->dropForeign(['branch_id']);
            }
            if (Schema::hasColumn('host_transfers', 'department_id')) {
                $table->dropForeign(['department_id']);
            }

            $table->dropColumn(['company_id', 'branch_id', 'department_id']);
        });
    }
};
