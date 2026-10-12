<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('visit_group_members', function (Blueprint $table) {
            $table->string('qr_token', 100)->nullable()->unique()->after('position');
            $table->timestamp('qr_expires_at')->nullable()->after('qr_token');
            $table->timestamp('qr_used_at')->nullable()->after('qr_expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('visit_group_members', function (Blueprint $table) {
            $table->dropColumn(['qr_token', 'qr_expires_at', 'qr_used_at']);
        });
    }
};
