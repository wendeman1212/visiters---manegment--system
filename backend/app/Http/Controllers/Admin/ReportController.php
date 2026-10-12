<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ExportsReports;
use App\Models\Branch;
use App\Services\AuditLogger;
use App\Services\ReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ReportController extends Controller
{
    use ExportsReports;

    protected const TYPES = ['visits', 'audit', 'staff'];

    /**
     * JSON payload used by the Admin "Reports & Export" tab.
     */
    public function data(Request $request)
    {
        $user = auth('web')->user();
        if (!$user || !$user->isAdmin()) {
            abort(403);
        }

        $period = in_array($request->input('period'), ReportService::PERIODS, true)
            ? $request->input('period') : 'daily';
        $branchId = $request->filled('branch_id') ? (int) $request->input('branch_id') : null;

        if ($branchId && !Branch::find($branchId)) {
            return response()->json(['error' => 'Branch not found.'], 404);
        }

        $service = new ReportService();
        [$start, $end] = $service->dateRange($period);

        $branches = Branch::with('location')->orderBy('name')->get(['id', 'name', 'location_id'])
            ->map(fn ($b) => [
                'id'            => $b->id,
                'name'          => $b->name,
                'location_name' => $b->location?->name,
            ]);

        return response()->json([
            'period'          => $period,
            'period_label'    => $service->periodLabel($period, $start, $end),
            'range'           => [
                'start' => $start->format('Y-m-d'),
                'end'   => $end->format('Y-m-d'),
            ],
            'selected_branch' => $branchId ? ['id' => $branchId, 'name' => Branch::find($branchId)->name] : null,
            'branches'        => $branches,
            'visits'          => $service->visitsReport($branchId, $period),
            'audit'           => $service->auditReport($branchId, $period),
            'staff'           => $service->staffReport($branchId, $period),
        ]);
    }

    /**
     * Stream a report file in CSV, Excel or PDF format.
     */
    public function export(Request $request, string $type)
    {
        $user = auth('web')->user();
        if (!$user || !$user->isAdmin()) {
            abort(403);
        }

        $type = in_array($type, static::TYPES, true) ? $type : 'visits';
        $format = in_array($request->input('format'), static::FORMATS, true) ? $request->input('format') : 'csv';
        $period = in_array($request->input('period'), ReportService::PERIODS, true)
            ? $request->input('period') : 'daily';
        $branchId = $request->filled('branch_id') ? (int) $request->input('branch_id') : null;

        $branch = null;
        if ($branchId) {
            $branch = Branch::find($branchId);
            if (!$branch) {
                abort(404);
            }
        }

        $service = new ReportService();
        $data = match ($type) {
            'audit'  => $service->auditReport($branchId, $period),
            'staff'  => $service->staffReport($branchId, $period),
            default  => $service->visitsReport($branchId, $period),
        };

        [$start, $end] = $service->dateRange($period);
        $periodLabel = $service->periodLabel($period, $start, $end);
        $branchLabel = $branch ? $branch->name : 'All Branches';
        $subtitle = $periodLabel . '  |  Branch: ' . $branchLabel;

        [$title, $headers, $cols] = $this->definition($type);

        AuditLogger::log("admin_report_export_{$type}", 'reports', null, null, [
            'format'    => $format,
            'period'    => $period,
            'branch_id' => $branchId,
        ]);

        $base = $type . '-report-' . now()->format('Y-m-d') . ($branch ? '-' . Str::slug($branch->name) : '');

        return $this->exportReport($format, $title, $subtitle, $headers, $cols, $data['rows'], $base);
    }

    protected function definition(string $type): array
    {
        switch ($type) {
            case 'audit':
                return [
                    'Audit Logs Report',
                    ['Actor', 'Action Taken', 'IP Address', 'Timestamp'],
                    ['actor', 'action', 'ip_address', 'timestamp'],
                ];
            case 'staff':
                return [
                    'Staff Report',
                    ['Staff Member Name', 'Role', 'Department', 'Status', 'Branch'],
                    ['name', 'role', 'department', 'status', 'branch'],
                ];
            case 'visits':
            default:
                return [
                    'Visit Requests Report',
                    ['Visitor Name', 'Host', 'Department', 'Purpose of Visit', 'Status', 'Timestamp'],
                    ['visitor_name', 'host_name', 'department', 'purpose', 'status', 'timestamp'],
                ];
        }
    }
}
