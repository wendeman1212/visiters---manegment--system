<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('visitors')) {
            return;
        }

        Schema::table('visitors', function (Blueprint $table) {
            if (!Schema::hasColumn('visitors', 'email_verified_at')) {
                $table->timestamp('email_verified_at')->nullable()->after('password');
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('visitors') && Schema::hasColumn('visitors', 'email_verified_at')) {
            Schema::table('visitors', function (Blueprint $table) {
                $table->dropColumn('email_verified_at');
            });
        }
    }
};
