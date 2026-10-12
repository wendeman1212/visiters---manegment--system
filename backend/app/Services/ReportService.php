<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\VisitRequest;
use App\Models\Visitor;
use Illuminate\Support\Carbon;

class ReportService
{
    public const PERIODS = ['daily', 'weekly', 'monthly', 'yearly'];

    /**
     * Resolve a timeframe option into an inclusive [start, end] range.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function dateRange(string $period): array
    {
        $now = now();
        switch ($period) {
            case 'weekly':
                return [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()];
            case 'monthly':
                return [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()];
            case 'yearly':
                return [$now->copy()->startOfYear(), $now->copy()->endOfYear()];
            case 'daily':
            default:
                return [$now->copy()->startOfDay(), $now->copy()->endOfDay()];
        }
    }

    public function periodLabel(string $period, Carbon $start, Carbon $end): string
    {
        switch ($period) {
            case 'weekly':
                return 'This Week — ' . $start->format('M d') . ' to ' . $end->format('M d, Y');
            case 'monthly':
                return 'This Month — ' . $start->format('F Y');
            case 'yearly':
                return 'This Year — ' . $start->format('Y');
            case 'daily':
            default:
                return 'Today — ' . $start->format('M d, Y');
        }
    }

    /**
     * Build the Visit Requests report.
     *
     * @return array{rows: array, count: int, stats: array}
     */
    public function visitsReport(?int $branchId, string $period): array
    {
        [$start, $end] = $this->dateRange($period);

        $visits = VisitRequest::query()
            ->with(['visitor', 'host.department', 'department', 'branch'])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereBetween('created_at', [$start, $end])
            ->orderByDesc('created_at')
            ->get();

        $rows = $visits->map(fn ($v) => [
            'id'          => $v->id,
            'visitor_name' => $v->visitor->name ?? ($v->visitor ? trim(($v->visitor->first_name ?? '') . ' ' . ($v->visitor->last_name ?? '')) : ''),
            'host_name'   => $v->host->name ?? '',
            'department'  => $v->department?->name ?? $v->host?->department?->name ?? '',
            'purpose'     => $v->purpose,
            'status'      => $v->status,
            'timestamp'   => $v->created_at?->format('Y-m-d H:i:s') ?? '',
        ])->values()->all();

        return [
            'rows'  => $rows,
            'count' => count($rows),
            'stats' => [
                'total'       => $visits->count(),
                'approved'    => $visits->where('status', 'approved')->count(),
                'rejected'    => $visits->whereIn('status', ['rejected'])->count(),
                'checked_in'  => $visits->where('status', 'checked_in')->count(),
                'checked_out' => $visits->where('status', 'checked_out')->count(),
                'pending'     => $visits->where('status', 'pending')->count(),
                'escalated'   => $visits->where('status', 'escalated')->count(),
            ],
        ];
    }

    /**
     * Build the Audit Logs report.
     *
     * @return array{rows: array, count: int}
     */
    public function auditReport(?int $branchId, string $period): array
    {
        [$start, $end] = $this->dateRange($period);

        $logs = AuditLog::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereBetween('created_at', [$start, $end])
            ->orderByDesc('created_at')
            ->get();

        $userIds    = $logs->where('actor_type', 'user')->pluck('actor_id')->filter()->unique()->values();
        $visitorIds = $logs->where('actor_type', 'visitor')->pluck('actor_id')->filter()->unique()->values();

        $users    = User::whereIn('id', $userIds)->get(['id', 'name'])->pluck('name', 'id');
        $visitors = Visitor::whereIn('id', $visitorIds)->get(['id', 'name'])->pluck('name', 'id');

        $rows = $logs->map(fn ($log) => [
            'id'         => $log->id,
            'actor'      => $this->actorName($log, $users, $visitors),
            'action'     => $log->action,
            'ip_address' => $log->ip_address ?? '',
            'timestamp'  => $log->created_at?->format('Y-m-d H:i:s') ?? '',
        ])->values()->all();

        return [
            'rows'  => $rows,
            'count' => count($rows),
        ];
    }

    /**
     * Build the Staff report (filtered by account creation within the period).
     *
     * @return array{rows: array, count: int}
     */
    public function staffReport(?int $branchId, string $period): array
    {
        [$start, $end] = $this->dateRange($period);

        $staff = User::query()
            ->with(['department', 'branch'])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereBetween('created_at', [$start, $end])
            ->orderBy('name')
            ->get();

        $rows = $staff->map(fn ($u) => [
            'id'         => $u->id,
            'name'       => $u->name,
            'role'       => $u->role,
            'department' => $u->department?->name ?? '',
            'status'     => $u->status,
            'branch'     => $u->branch?->name ?? '',
        ])->values()->all();

        return [
            'rows'  => $rows,
            'count' => count($rows),
        ];
    }

    protected function actorName(AuditLog $log, $users, $visitors): string
    {
        if ($log->actor_type === 'user' && $log->actor_id) {
            return $users[$log->actor_id] ?? ('User #' . $log->actor_id);
        }
        if ($log->actor_type === 'visitor' && $log->actor_id) {
            return $visitors[$log->actor_id] ?? ('Visitor #' . $log->actor_id);
        }
        return 'System';
    }
}
