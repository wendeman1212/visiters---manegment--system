<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->foreignId('location_id')->nullable()->after('name')->constrained('locations')->nullOnDelete();
            $table->dropColumn('location');
        });
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->string('location')->nullable();
            $table->dropForeign(['location_id']);
            $table->dropColumn('location_id');
        });
    }
};
