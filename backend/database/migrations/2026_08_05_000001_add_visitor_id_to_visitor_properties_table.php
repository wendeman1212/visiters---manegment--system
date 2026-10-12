<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitor_properties', function (Blueprint $table) {
            $table->unsignedBigInteger('visitor_id')->nullable()->after('visit_request_id');
            $table->foreign('visitor_id')->references('id')->on('visitors')->onDelete('cascade');
        });

        // Backfill explicit ownership: any property that has no group member owner
        // belongs to the visit's representative/individual visitor. This makes
        // ownership explicit so checkout never infers it from visit membership.
        // Iterate row-by-row (portable across MySQL and SQLite, which cannot
        // execute an UPDATE ... JOIN).
        $orphaned = DB::table('visitor_properties as vp')
            ->join('visit_requests as vr', 'vr.id', '=', 'vp.visit_request_id')
            ->whereNull('vp.visit_group_member_id')
            ->whereNull('vp.visitor_id')
            ->whereNotNull('vr.visitor_id')
            ->select('vp.id', 'vr.visitor_id')
            ->get();

        foreach ($orphaned as $row) {
            DB::table('visitor_properties')
                ->where('id', $row->id)
                ->update(['visitor_id' => $row->visitor_id]);
        }
    }

    public function down(): void
    {
        Schema::table('visitor_properties', function (Blueprint $table) {
            $table->dropForeign(['visitor_id']);
            $table->dropColumn('visitor_id');
        });
    }
};
