<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('companies')) {
            return;
        }

        $defaultCompany = DB::table('companies')->where('company_name', 'Default Organization')->first();

        if (!$defaultCompany) {
            $defaultCompanyId = DB::table('companies')->insertGetId([
                'company_name' => 'Default Organization',
                'status'       => 'active',
            ]);
        } else {
            $defaultCompanyId = $defaultCompany->company_id;
        }

        $tables = ['users', 'departments', 'locations', 'visitors', 'visit_requests'];

        foreach ($tables as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'company_id')) {
                DB::table($table)->whereNull('company_id')->update(['company_id' => $defaultCompanyId]);
            }
        }
    }

    public function down(): void
    {
        $defaultCompanyId = DB::table('companies')->where('company_name', 'Default Organization')->value('company_id');

        if (!$defaultCompanyId) {
            return;
        }

        $tables = ['users', 'departments', 'locations', 'visitors', 'visit_requests'];

        foreach ($tables as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'company_id')) {
                DB::table($table)->where('company_id', $defaultCompanyId)->update(['company_id' => null]);
            }
        }

        DB::table('companies')->where('company_id', $defaultCompanyId)->delete();
    }
};
