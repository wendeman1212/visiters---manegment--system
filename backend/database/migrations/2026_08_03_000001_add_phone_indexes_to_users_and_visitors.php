<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'phone')) {
            Schema::table('users', function (Blueprint $table) {
                $table->index('phone');
            });
        }

        if (Schema::hasTable('visitors') && Schema::hasColumn('visitors', 'phone')) {
            Schema::table('visitors', function (Blueprint $table) {
                $table->index('phone');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'phone')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropIndex(['phone']);
            });
        }

        if (Schema::hasTable('visitors') && Schema::hasColumn('visitors', 'phone')) {
            Schema::table('visitors', function (Blueprint $table) {
                $table->dropIndex(['phone']);
            });
        }
    }
};
