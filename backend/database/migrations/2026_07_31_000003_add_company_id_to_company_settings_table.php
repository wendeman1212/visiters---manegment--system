<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('company_settings', 'company_id')) {
            Schema::table('company_settings', function (Blueprint $table) {
                $table->foreignId('company_id')->nullable()->after('id')
                    ->constrained('companies', 'company_id')
                    ->onDelete('set null');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('company_settings', 'company_id')) {
            Schema::table('company_settings', function (Blueprint $table) {
                $table->dropForeign(['company_id']);
                $table->dropColumn('company_id');
            });
        }
    }
};
