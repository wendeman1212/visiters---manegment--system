<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_notifications', function (Blueprint $table) {
            $table->dropIndex(['recipient_type', 'recipient_id', 'is_read']);
        });

        Schema::table('system_notifications', function (Blueprint $table) {
            $table->string('recipient_id', 255)->nullable()->change();
        });

        Schema::table('system_notifications', function (Blueprint $table) {
            $table->index(['recipient_type', 'recipient_id', 'is_read']);
        });
    }

    public function down(): void
    {
        Schema::table('system_notifications', function (Blueprint $table) {
            $table->dropIndex(['recipient_type', 'recipient_id', 'is_read']);
        });

        Schema::table('system_notifications', function (Blueprint $table) {
            $table->unsignedBigInteger('recipient_id')->nullable()->change();
        });

        Schema::table('system_notifications', function (Blueprint $table) {
            $table->index(['recipient_type', 'recipient_id', 'is_read']);
        });
    }
};
