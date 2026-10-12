<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropUnique('departments_name_unique');
            $table->unique(['company_id', 'branch_id', 'name'], 'departments_company_branch_name_unique');
        });
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropUnique('departments_company_branch_name_unique');
            $table->unique('name');
        });
    }
};
