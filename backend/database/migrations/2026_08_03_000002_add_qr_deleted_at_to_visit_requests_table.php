<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visit_requests', function (Blueprint $table) {
            if (!Schema::hasColumn('visit_requests', 'qr_deleted_at')) {
                $table->timestamp('qr_deleted_at')->nullable()->after('exit_qr_expires_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('visit_requests', function (Blueprint $table) {
            if (Schema::hasColumn('visit_requests', 'qr_deleted_at')) {
                $table->dropColumn('qr_deleted_at');
            }
        });
    }
};
