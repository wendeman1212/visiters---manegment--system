<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitor_badges', function (Blueprint $table) {
            if (!Schema::hasColumn('visitor_badges', 'visit_group_member_id')) {
                $table->unsignedBigInteger('visit_group_member_id')->nullable()->after('visit_request_id');
                $table->foreign('visit_group_member_id')->references('id')->on('visit_group_members')->onDelete('cascade');
            }

            if (!Schema::hasColumn('visitor_badges', 'file_path')) {
                $table->string('file_path')->nullable()->after('visit_group_member_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('visitor_badges', function (Blueprint $table) {
            if (Schema::hasColumn('visitor_badges', 'visit_group_member_id')) {
                $table->dropForeign(['visit_group_member_id']);
                $table->dropColumn('visit_group_member_id');
            }

            if (Schema::hasColumn('visitor_badges', 'file_path')) {
                $table->dropColumn('file_path');
            }
        });
    }
};
