<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visit_requests', function (Blueprint $table) {
            $table->string('company_name')->nullable()->after('visitor_category_id');
            $table->string('company_email')->nullable()->after('company_name');
            $table->string('company_phone')->nullable()->after('company_email');
            $table->text('company_address')->nullable()->after('company_phone');
            $table->string('company_website')->nullable()->after('company_address');
            $table->string('representative_gender')->nullable()->after('company_website');
            $table->string('representative_nationality')->nullable()->after('representative_gender');
            $table->string('representative_position')->nullable()->after('representative_nationality');
            $table->string('visit_type')->default('individual')->after('representative_position');
        });
    }

    public function down(): void
    {
        Schema::table('visit_requests', function (Blueprint $table) {
            $table->dropColumn([
                'company_name', 'company_email', 'company_phone', 'company_address',
                'company_website', 'representative_gender', 'representative_nationality',
                'representative_position', 'visit_type',
            ]);
        });
    }
};
