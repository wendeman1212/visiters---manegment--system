<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('qr_tokens', function (Blueprint $table) {
            if (!Schema::hasColumn('qr_tokens', 'type')) {
                $table->string('type')->nullable()->after('token_value');
            }
            if (!Schema::hasColumn('qr_tokens', 'visit_group_member_id')) {
                $table->unsignedBigInteger('visit_group_member_id')->nullable()->after('visit_request_id');
            }
            if (!Schema::hasColumn('qr_tokens', 'model_type')) {
                $table->string('model_type')->nullable()->after('visit_group_member_id');
            }
            if (!Schema::hasColumn('qr_tokens', 'model_id')) {
                $table->unsignedBigInteger('model_id')->nullable()->after('model_type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('qr_tokens', function (Blueprint $table) {
            $table->dropColumn(['type', 'visit_group_member_id', 'model_type', 'model_id']);
        });
    }
};
