<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The reschedule_requests.status enum originally allowed only
     * pending/approved/rejected, but the cancel-visit flow writes 'cancelled'
     * for any in-flight request. On MySQL strict mode that write is rejected
     * (data truncated), aborting the whole cancellation. Widening the column to
     * a plain string keeps every status the application writes.
     */
    public function up(): void
    {
        Schema::table('reschedule_requests', function (Blueprint $table) {
            $table->string('status', 20)->default('pending')->change();
        });
    }

    public function down(): void
    {
        Schema::table('reschedule_requests', function (Blueprint $table) {
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending')->change();
        });
    }
};
