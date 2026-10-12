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
        Schema::table('visit_requests', function (Blueprint $table) {
            $table->string('exit_qr_token')->nullable()->unique()->after('wifi_credentials');
            $table->timestamp('exit_qr_expires_at')->nullable()->after('exit_qr_token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('visit_requests', function (Blueprint $table) {
            $table->dropColumn(['exit_qr_token', 'exit_qr_expires_at']);
        });
    }
};
