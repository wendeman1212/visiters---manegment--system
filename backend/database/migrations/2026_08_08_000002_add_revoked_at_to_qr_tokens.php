<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('qr_tokens', function (Blueprint $table) {
            $table->timestamp('revoked_at')->nullable()->after('expires_at')
                ->comment('When the QR token was revoked (visit cancelled, visitor blacklisted, etc.)');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('qr_tokens', 'revoked_at')) {
            Schema::table('qr_tokens', function (Blueprint $table) {
                $table->dropColumn('revoked_at');
            });
        }
    }
};
