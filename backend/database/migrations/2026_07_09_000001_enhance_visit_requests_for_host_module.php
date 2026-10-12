<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visit_requests', function (Blueprint $table) {
            $table->string('priority')->default('normal')->after('status');       // low, normal, high, vip
            $table->text('meeting_agenda')->nullable()->after('comments');
            $table->text('meeting_notes')->nullable()->after('meeting_agenda');
            $table->text('meeting_outcome')->nullable()->after('meeting_notes');
            $table->text('visitor_notes')->nullable()->after('meeting_outcome');  // host notes about visitor
            $table->boolean('is_vip')->default(false)->after('visitor_notes');
            $table->boolean('is_no_show')->default(false)->after('is_vip');
            $table->string('parking_space')->nullable()->after('is_no_show');
            $table->string('wifi_credentials')->nullable()->after('parking_space');
            $table->timestamp('meeting_started_at')->nullable()->after('wifi_credentials');
            $table->timestamp('meeting_ended_at')->nullable()->after('meeting_started_at');
            $table->timestamp('no_show_at')->nullable()->after('meeting_ended_at');
            $table->unsignedBigInteger('alternate_host_id')->nullable()->after('no_show_at');
            $table->foreign('alternate_host_id')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('visit_requests', function (Blueprint $table) {
            $table->dropForeign(['alternate_host_id']);
            $table->dropColumn([
                'priority', 'meeting_agenda', 'meeting_notes', 'meeting_outcome',
                'visitor_notes', 'is_vip', 'is_no_show', 'parking_space',
                'wifi_credentials', 'meeting_started_at', 'meeting_ended_at',
                'no_show_at', 'alternate_host_id',
            ]);
        });
    }
};
