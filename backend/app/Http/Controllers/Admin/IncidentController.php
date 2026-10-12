<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ExportsReports;
use App\Models\Branch;
use App\Models\Incident;
use App\Services\AuditLogger;
use App\Services\IncidentReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class IncidentController extends Controller
{
    use ExportsReports;

    protected const STATUSES = ['open', 'investigating', 'resolved', 'closed', 'escalated'];

    /**
     * JSON payload for the Admin "Branch Security Incidents" tab.
     */
    public function data(Request $request)
    {
        $user = auth('web')->user();
        if (!$user || !$user->isAdmin()) {
            abort(403);
        }

        $period = in_array($request->input('period'), IncidentReportService::PERIODS, true)
            ? $request->input('period') : 'daily';
        $branchId = $request->filled('branch_id') ? (int) $request->input('branch_id') : null;

        if ($branchId && !Branch::find($branchId)) {
            return response()->json(['error' => 'Branch not found.'], 404);
        }

        $service = new IncidentReportService();
        [$start, $end] = $service->dateRange($period);

        $branches = Branch::with('location')->orderBy('name')->get(['id', 'name', 'location_id'])
            ->map(fn ($b) => [
                'id'            => $b->id,
                'name'          => $b->name,
                'location_name' => $b->location?->name,
            ]);

        $report = $service->report($branchId, $period);

        return response()->json([
            'period'          => $period,
            'period_label'    => $service->periodLabel($period, $start, $end),
            'range'           => [
                'start' => $start->format('Y-m-d'),
                'end'   => $end->format('Y-m-d'),
            ],
            'selected_branch' => $branchId ? ['id' => $branchId, 'name' => Branch::find($branchId)->name] : null,
            'branches'        => $branches,
            'incidents'       => $report['rows'],
            'count'           => $report['count'],
            'stats'           => $report['stats'],
        ]);
    }

    /**
     * Stream the incident report in CSV, Excel or PDF.
     */
    public function export(Request $request)
    {
        $user = auth('web')->user();
        if (!$user || !$user->isAdmin()) {
            abort(403);
        }

        $format = in_array($request->input('format'), static::FORMATS, true) ? $request->input('format') : 'csv';
        $period = in_array($request->input('period'), IncidentReportService::PERIODS, true)
            ? $request->input('period') : 'daily';
        $branchId = $request->filled('branch_id') ? (int) $request->input('branch_id') : null;

        $branch = null;
        if ($branchId) {
            $branch = Branch::find($branchId);
            if (!$branch) {
                abort(404);
            }
        }

        $service = new IncidentReportService();
        $data = $service->report($branchId, $period);

        [$start, $end] = $service->dateRange($period);
        $periodLabel = $service->periodLabel($period, $start, $end);
        $branchLabel = $branch ? $branch->name : 'All Branches';
        $subtitle = $periodLabel . '  |  Branch: ' . $branchLabel;

        [$title, $headers, $cols] = $this->definition();

        AuditLogger::log('admin_report_export_incidents', 'reports', null, null, [
            'format'    => $format,
            'period'    => $period,
            'branch_id' => $branchId,
        ]);

        $base = 'incidents-report-' . now()->format('Y-m-d') . ($branch ? '-' . \Illuminate\Support\Str::slug($branch->name) : '');

        return $this->exportReport($format, $title, $subtitle, $headers, $cols, $data['rows'], $base);
    }

    /**
     * Update status / action taken / resolution for an incident.
     */
    public function update(Request $request, int $id)
    {
        $user = auth('web')->user();
        if (!$user || !$user->isAdmin()) {
            abort(403);
        }

        $incident = Incident::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'status'       => 'nullable|string|in:' . implode(',', static::STATUSES),
            'action_taken' => 'nullable|string|max:2000',
            'description'  => 'nullable|string|max:2000',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator);
        }

        $data = $request->only(['status', 'action_taken', 'description']);

        $oldState = $incident->toArray();

        if (array_key_exists('status', $data) && $data['status'] && $data['status'] !== $incident->status) {
            $incident->status = $data['status'];
            if (in_array($data['status'], ['resolved', 'closed'], true)) {
                $incident->resolved_at = now();
            }
        }
        if (array_key_exists('action_taken', $data) && $data['action_taken']) {
            $incident->action_taken = $data['action_taken'];
        }
        if (array_key_exists('description', $data) && $data['description']) {
            $incident->description = $data['description'];
        }
        $incident->save();

        AuditLogger::log('incident_updated', 'incidents', $incident->id, $oldState, $incident->toArray());

        return back()->with('success', 'Incident #' . $incident->id . ' updated.');
    }

    protected function definition(): array
    {
        return [
            'Branch Security Incident Report',
            [
                'Incident ID',
                'Date & Time',
                'Branch Location',
                'Category',
                'Severity',
                'Reported Visitor/Person',
                'Host Staff Member',
                'Reporting Security Officer',
                'Status',
                'Description',
                'Action Taken',
                'Resolved At',
            ],
            [
                'id',
                'created_at',
                'branch_name',
                'category',
                'severity',
                'visitor_name',
                'host_name',
                'reported_by_name',
                'status',
                'description',
                'action_taken',
                'resolved_at',
            ],
        ];
    }
}
