<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'national_id')) {
                $table->string('national_id')->nullable()->after('nationality');
            }
            if (!Schema::hasColumn('users', 'position')) {
                $table->string('position')->nullable()->after('national_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'position')) {
                $table->dropColumn('position');
            }
            if (Schema::hasColumn('users', 'national_id')) {
                $table->dropColumn('national_id');
            }
        });
    }
};