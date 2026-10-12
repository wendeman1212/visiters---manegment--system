<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visit_requests', function (Blueprint $table) {
            $table->foreignId('visitor_category_id')->nullable()->after('host_id')->constrained('visitor_categories')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('visit_requests', function (Blueprint $table) {
            $table->dropForeign(['visitor_category_id']);
            $table->dropColumn('visitor_category_id');
        });
    }
};
