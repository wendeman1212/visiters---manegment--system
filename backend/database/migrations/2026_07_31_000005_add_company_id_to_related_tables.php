<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tables = [
            'departments'    => 'cascade',
            'locations'      => 'cascade',
            'visitors'       => 'cascade',
            'visit_requests' => 'set null',
        ];

        foreach ($tables as $table => $onDelete) {
            if (!Schema::hasColumn($table, 'company_id')) {
                Schema::table($table, function (Blueprint $table) use ($onDelete) {
                    $table->foreignId('company_id')->nullable()
                        ->constrained('companies', 'company_id')
                        ->onDelete($onDelete);
                });
            }
        }
    }

    public function down(): void
    {
        $tables = ['departments', 'locations', 'visitors', 'visit_requests'];

        foreach ($tables as $table) {
            if (Schema::hasColumn($table, 'company_id')) {
                Schema::table($table, function (Blueprint $table) {
                    $table->dropForeign(['company_id']);
                    $table->dropColumn('company_id');
                });
            }
        }
    }
};
