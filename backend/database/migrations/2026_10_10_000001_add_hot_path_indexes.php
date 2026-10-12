<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hot-path indexes identified by the performance audit. Every entry is
     * defensive: the table/columns are checked before an index is created and an
     * existing index with the same name is skipped, so this migration is safe to
     * run on databases that already have some of them.
     *
     * @var array<int, array{0: string, 1: array<int, string>, 2: string}>
     */
    private array $indexes = [
        ['visit_requests', ['status'], 'vr_status_idx'],
        ['visit_requests', ['created_at'], 'vr_created_at_idx'],
        ['incidents', ['scan_token'], 'incidents_scan_token_idx'],
        ['incidents', ['created_at'], 'incidents_created_at_idx'],
        ['escalations', ['resolved_at'], 'escalations_resolved_at_idx'],
        ['escalations', ['trigger_type', 'deadline_at'], 'escalations_trigger_deadline_idx'],
        ['reschedule_requests', ['status'], 'reschedule_status_idx'],
        ['system_notifications', ['status'], 'sys_notifications_status_idx'],
        ['audit_logs', ['company_id', 'created_at'], 'audit_logs_company_created_idx'],
        ['qr_tokens', ['visit_group_member_id'], 'qr_tokens_group_member_idx'],
        ['departments', ['manager_id'], 'departments_manager_idx'],
        ['departments', ['parent_id'], 'departments_parent_idx'],
        ['users', ['status'], 'users_status_idx'],
        ['messages', ['recipient_type', 'recipient_id', 'is_read'], 'messages_recipient_read_idx'],
        ['visitor_badges', ['qr_token'], 'visitor_badges_qr_token_idx'],
    ];

    public function up(): void
    {
        foreach ($this->indexes as [$table, $columns, $name]) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $missingColumn = false;
            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    $missingColumn = true;
                    break;
                }
            }
            if ($missingColumn || $this->hasIndex($table, $name)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($columns, $name) {
                $blueprint->index($columns, $name);
            });
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as [$table, $columns, $name]) {
            if (! Schema::hasTable($table) || ! $this->hasIndex($table, $name)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($name) {
                $blueprint->dropIndex($name);
            });
        }
    }

    private function hasIndex(string $table, string $name): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (($index['name'] ?? null) === $name) {
                return true;
            }
        }

        return false;
    }
};
