<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->foreignId('location_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('branches')->whereNull('location_id')->delete();

        Schema::table('branches', function (Blueprint $table) {
            $table->foreignId('location_id')->nullable(false)->change();
        });
    }
};
