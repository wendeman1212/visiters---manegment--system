<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('branches', 'company_id')) {
            Schema::table('branches', function (Blueprint $table) {
                $table->foreignId('company_id')->nullable()
                    ->after('location_id')
                    ->constrained('companies', 'company_id')
                    ->onDelete('cascade');
            });
        }

        // Backfill existing branches from the company of their location.
        // Implemented with a portable loop instead of a joined UPDATE because
        // the UPDATE ... FROM syntax is not preparable on all SQLite versions.
        if (Schema::hasColumn('locations', 'company_id')) {
            $branches = DB::table('branches')
                ->join('locations', 'locations.id', '=', 'branches.location_id')
                ->whereNull('branches.company_id')
                ->select('branches.id', 'locations.company_id as loc_company_id')
                ->get();

            foreach ($branches as $branch) {
                DB::table('branches')
                    ->where('id', $branch->id)
                    ->update(['company_id' => $branch->loc_company_id]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('branches', 'company_id')) {
            Schema::table('branches', function (Blueprint $table) {
                $table->dropForeign(['company_id']);
                $table->dropColumn('company_id');
            });
        }
    }
};
