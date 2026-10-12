<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visit_requests', function (Blueprint $table) {
            $table->string('building')->nullable()->after('meeting_room_id');
            $table->string('floor')->nullable()->after('building');
        });
    }

    public function down(): void
    {
        Schema::table('visit_requests', function (Blueprint $table) {
            $table->dropColumn(['building', 'floor']);
        });
    }
};
