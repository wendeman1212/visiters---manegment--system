<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitors', function (Blueprint $table) {
            $table->string('company_name')->nullable()->after('phone');
            $table->string('company_position')->nullable()->after('company_name');
            $table->text('id_document_url')->nullable()->after('photo_url'); // uploaded ID doc
            $table->boolean('profile_complete')->default(false)->after('id_document_url');
        });
    }

    public function down(): void
    {
        Schema::table('visitors', function (Blueprint $table) {
            $table->dropColumn(['company_name', 'company_position', 'id_document_url', 'profile_complete']);
        });
    }
};
