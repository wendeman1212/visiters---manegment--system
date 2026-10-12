<?php

namespace App\Services;

use App\Models\Incident;
use Illuminate\Support\Carbon;

class IncidentReportService
{
    public const PERIODS = ['daily', 'weekly', 'monthly', 'yearly'];

    public const CATEGORY_LABELS = [
        'security_alert'          => 'Security Alert',
        'blacklist_match'         => 'Blacklist Match',
        'fake_qr'                 => 'Fake QR Code',
        'expired_qr'              => 'Expired QR Code',
        'unauthorized_area'       => 'Unauthorized Access',
        'unauthorized_access'     => 'Unauthorized Access',
        'contraband'              => 'Contraband Detected',
        'visitor_disruption'      => 'Visitor Disruption',
        'physical_breach'         => 'Physical Security Breach',
        'physical_security_breach'=> 'Physical Security Breach',
        'policy_violation'        => 'Policy Violation',
        'lost_property'           => 'Lost Property',
        'emergency'               => 'Emergency',
        'panic_alarm'             => 'Panic Alarm',
        'identity_mismatch'       => 'Identity Mismatch',
        'missing_property'        => 'Missing Property',
        'property_owner_mismatch' => 'Property Owner Mismatch',
    ];

    public function categoryLabel(string $type): string
    {
        return static::CATEGORY_LABELS[$type] ?? str_replace('_', ' ', ucwords($type, '_'));
    }

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
     * Build the Branch Security Incident report.
     *
     * @return array{rows: array, count: int, stats: array}
     */
    public function report(?int $branchId, string $period, ?int $companyId = null): array
    {
        [$start, $end] = $this->dateRange($period);

        $incidents = Incident::query()
            ->with(['visitor', 'reportedBy', 'branch', 'location', 'company', 'visitRequest.visitor', 'visitRequest.host'])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->whereBetween('created_at', [$start, $end])
            ->orderByDesc('created_at')
            ->get();

        $rows = $incidents->map(fn ($inc) => [
            'id'                => $inc->id,
            'created_at'        => $inc->created_at?->format('Y-m-d H:i:s') ?? '',
            'company_name'      => $inc->company?->company_name ?? '—',
            'branch_name'       => $inc->branch?->name ?? '—',
            'location_name'     => $inc->location?->name ?? '',
            'category'          => $inc->type,
            'category_label'    => $this->categoryLabel($inc->type),
            'severity'          => $inc->severity,
            'visitor_name'      => $inc->visitor?->name
                ?? $inc->visitRequest?->visitor?->name
                ?? $inc->visitRequest?->visitor?->first_name,
            'host_name'         => $inc->visitRequest?->host?->name ?? '',
            'reported_by_name'  => $inc->reportedBy?->name ?? ($inc->reported_by ? 'User #' . $inc->reported_by : '—'),
            'status'            => $inc->status,
            'description'       => $inc->description ?? '',
            'action_taken'      => $inc->action_taken ?? '',
            'resolved_at'       => $inc->resolved_at?->format('Y-m-d H:i:s') ?? '',
            'related_visit_id'  => $inc->related_visit_id,
        ])->values()->all();

        return [
            'rows'  => $rows,
            'count' => count($rows),
            'stats' => [
                'total'          => $incidents->count(),
                'open'           => $incidents->where('status', 'open')->count(),
                'investigating'  => $incidents->where('status', 'investigating')->count(),
                'resolved'       => $incidents->whereIn('status', ['resolved', 'closed'])->count(),
                'escalated'      => $incidents->where('status', 'escalated')->count(),
                'critical'       => $incidents->where('severity', 'critical')->count(),
                'high'           => $incidents->where('severity', 'high')->count(),
            ],
        ];
    }
}
