<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (!Schema::hasColumn('companies', 'code')) {
                $table->string('code', 50)->nullable()->unique()->after('company_name');
            }
            if (!Schema::hasColumn('companies', 'description')) {
                $table->text('description')->nullable()->after('industry');
            }
            if (!Schema::hasColumn('companies', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('status');
            }
        });

        DB::table('companies')->where('status', 'active')->update(['is_active' => true]);
        DB::table('companies')->where('status', 'inactive')->update(['is_active' => false]);
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['code', 'description', 'is_active']);
        });
    }
};
