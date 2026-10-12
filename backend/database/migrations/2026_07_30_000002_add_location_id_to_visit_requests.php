<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('visit_requests', function (Blueprint $table) {
            $table->foreignId('location_id')->nullable()->constrained('locations')->onDelete('set null');
        });
    }

    public function down()
    {
        Schema::table('visit_requests', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
            $table->dropColumn('location_id');
        });
    }
};
