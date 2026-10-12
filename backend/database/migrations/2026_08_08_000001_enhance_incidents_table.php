<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE incidents MODIFY COLUMN status ENUM('open','investigating','resolved','dismissed') DEFAULT 'open'");
        }

        Schema::table('incidents', function (Blueprint $table) {
            $table->string('scan_token')->nullable()->after('description')
                ->comment('The raw QR token value that triggered the incident, used for de-duplication');
            $table->foreignId('related_group_member_id')->nullable()->after('related_visitor_id')
                ->constrained('visit_group_members')->nullOnDelete();
            $table->foreignId('checkpoint_id')->nullable()->after('location_id')
                ->comment('The security checkpoint/gate (location) where the event was detected')
                ->constrained('locations')->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->after('resolved_at')
                ->comment('User who resolved the incident')
                ->constrained('users')->nullOnDelete();
            $table->string('resolution_reason')->nullable()->after('resolved_by');
            $table->text('resolution_action')->nullable()->after('resolution_reason');
            $table->text('resolution_notes')->nullable()->after('resolution_action');
            $table->json('evidence')->nullable()->after('resolution_notes')
                ->comment('Optional evidence: file paths, photo URLs, etc.');
            $table->unsignedInteger('attempt_count')->default(1)->after('evidence')
                ->comment('How many times the same event was detected (de-dup counter)');
            $table->timestamp('last_attempt_at')->nullable()->after('attempt_count');
        });

        Schema::create('incident_audit_trail', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained('incidents')->cascadeOnDelete();
            $table->string('actor_type')->default('user');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action');
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->text('notes')->nullable();
            $table->json('evidence')->nullable();
            $table->timestamps();

            $table->index(['incident_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_audit_trail');

        if (Schema::hasColumns('incidents', [
            'scan_token','related_group_member_id','checkpoint_id',
            'resolved_by','resolution_reason','resolution_action',
            'resolution_notes','evidence','attempt_count','last_attempt_at',
        ])) {
            Schema::table('incidents', function (Blueprint $table) {
                $table->dropForeign(['related_group_member_id']);
                $table->dropForeign(['checkpoint_id']);
                $table->dropForeign(['resolved_by']);
                $table->dropColumn([
                    'scan_token','related_group_member_id','checkpoint_id',
                    'resolved_by','resolution_reason','resolution_action',
                    'resolution_notes','evidence','attempt_count','last_attempt_at',
                ]);
            });
        }
    }
};
