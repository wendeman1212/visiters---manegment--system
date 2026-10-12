<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_notifications', function (Blueprint $table) {
            $table->string('status')->default('active')->after('is_read');
            $table->timestamp('cancelled_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('system_notifications', function (Blueprint $table) {
            $table->dropColumn(['status', 'cancelled_at']);
        });
    }
};
