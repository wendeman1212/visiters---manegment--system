<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('incidents', 'company_id')) {
            Schema::table('incidents', function (Blueprint $table) {
                $table->foreignId('company_id')->nullable()
                    ->after('branch_id')
                    ->constrained('companies', 'company_id')
                    ->onDelete('set null');
            });
        }

        if (!Schema::hasColumn('incidents', 'location_id')) {
            Schema::table('incidents', function (Blueprint $table) {
                $table->foreignId('location_id')->nullable()
                    ->after('company_id')
                    ->constrained('locations')
                    ->onDelete('set null');
            });
        }

        // Backfill existing incidents with the area of their related visit,
        // falling back to the reporting guard's own area.
        $incidents = DB::table('incidents as i')
            ->leftJoin('visit_requests as v', 'v.id', '=', 'i.related_visit_id')
            ->leftJoin('users as u', 'u.id', '=', 'i.reported_by')
            ->select(
                'i.id',
                'v.company_id as visit_company',
                'v.branch_id as visit_branch',
                'v.location_id as visit_location',
                'u.company_id as user_company',
                'u.branch_id as user_branch',
                'u.location_id as user_location'
            )
            ->get();

        foreach ($incidents as $incident) {
            DB::table('incidents')
                ->where('id', $incident->id)
                ->update([
                    'company_id'  => $incident->visit_company ?? $incident->user_company,
                    'branch_id'   => $incident->visit_branch ?? $incident->user_branch,
                    'location_id' => $incident->visit_location ?? $incident->user_location,
                ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('incidents', 'location_id')) {
            Schema::table('incidents', function (Blueprint $table) {
                $table->dropForeign(['location_id']);
                $table->dropColumn('location_id');
            });
        }

        if (Schema::hasColumn('incidents', 'company_id')) {
            Schema::table('incidents', function (Blueprint $table) {
                $table->dropForeign(['company_id']);
                $table->dropColumn('company_id');
            });
        }
    }
};
