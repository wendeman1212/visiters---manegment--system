<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Visitor;
use App\Models\VisitRequest;
use App\Models\Department;
use App\Models\Blacklist;
use App\Models\AuditLog;
use App\Models\CompanyPolicy;
use App\Models\Holiday;
use App\Models\Incident;
use App\Models\Location;
use App\Models\Branch;
use App\Models\Message;
use App\Services\AuditLogger;
use App\Services\NotificationService;
use App\Services\SlaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class ManagementController extends Controller
{
    /**
     * Redirect an authenticated non-CEO web user to their own dashboard
     * instead of showing a 403 error page on CEO-only routes.
     */
    private function redirectToOwnDashboard(?User $user)
    {
        if (!$user) {
            return redirect(route('login'));
        }

        return redirect(match ($user->role) {
            'system_admin'   => '/system-admin/dashboard',
            'admin'          => '/admin/dashboard',
            'host'           => '/host/dashboard',
            'security_guard' => '/security/dashboard',
            default          => '/',
        });
    }

    /**
     * Render CEO Dashboard — full enterprise governance module.
     */
    public function ceoDashboard($section = null)
    {
        $ceo = Auth::guard('web')->user();
        if (!$ceo || $ceo->role !== 'ceo') {
            return $this->redirectToOwnDashboard($ceo);
        }

        // ── Lockdown state ── (DB-driven, company/branch scoped)
        $activeLockdowns = \App\Models\Lockdown::query()
            ->where('company_id', $ceo->company_id)
            ->where('status', 'active')
            ->with('branch', 'startedBy')
            ->latest('started_at')
            ->get();
        $lockdown = $activeLockdowns->isNotEmpty();

        // ── Enterprise KPIs ──
        $totalVisitors      = \App\Models\Visitor::count();
        $totalDepartments   = Department::count();

        // Single grouped query for status counts instead of 7 separate counts.
        $statusCounts = VisitRequest::selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        $activeVisitors    = (int) ($statusCounts['checked_in'] ?? 0);
        $totalRequests     = (int) $statusCounts->sum();
        $totalApproved     = (int) ($statusCounts['approved'] ?? 0);
        $totalRejected     = (int) ($statusCounts['rejected'] ?? 0);
        $totalEscalated    = (int) ($statusCounts['escalated'] ?? 0);
        $pendingRequests   = (int) ($statusCounts['pending'] ?? 0);
        $approvalRate      = $totalRequests > 0 ? round(($totalApproved / $totalRequests) * 100, 1) : 0;
        $rejectionRate     = $totalRequests > 0 ? round(($totalRejected / $totalRequests) * 100, 1) : 0;
        $avgDuration       = (float) (VisitRequest::avg('duration') ?? 0);

        // Today's activity — one grouped query for updated_at statuses plus a
        // single created_at count for pending, instead of 4 separate counts.
        $todayStart = now()->startOfDay();
        $todayEnd   = $todayStart->copy()->addDay();
        $todayVisits = VisitRequest::where('updated_at', '>=', $todayStart)
            ->where('updated_at', '<', $todayEnd)
            ->whereIn('status', ['checked_in', 'checked_out', 'rejected'])
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');
        $todayCheckins   = (int) ($todayVisits['checked_in'] ?? 0);
        $todayCheckouts  = (int) ($todayVisits['checked_out'] ?? 0);
        $todayRejections = (int) ($todayVisits['rejected'] ?? 0);
        $todayPending    = (int) VisitRequest::where('status', 'pending')
            ->where('created_at', '>=', $todayStart)
            ->where('created_at', '<', $todayEnd)
            ->count();

        // ── Security Risk ──
        $blacklistHits    = AuditLog::where('action', 'like', '%blacklist%')->count();
        $failedValidations = AuditLog::where('action', 'visit_request_failed_validation')->whereDate('created_at', today())->count();
        $slaOverdue       = AuditLog::where('action', 'sla_admin_overdue')->count();
        $recentSecurityEvents = AuditLog::whereIn('action', [
            'blacklist_warning', 'visit_request_failed_validation',
            'emergency_lockdown_enabled', 'emergency_lockdown_disabled',
            'auto_expired_visit', 'sla_admin_overdue',
        ])->latest()->take(10)->get();

        // ── Incidents (from Security module) ──
        $activeIncidents = Incident::whereIn('status', ['open', 'investigating'])
            ->with('reportedBy', 'branch', 'company')
            ->orderByRaw("CASE WHEN severity = 'critical' THEN 0 WHEN severity = 'high' THEN 1 WHEN severity = 'medium' THEN 2 ELSE 3 END")
            ->latest()
            ->take(50)
            ->get()
            ->each(function ($inc) {
                $inc->scope_type   = $inc->branch_id ? 'branch' : 'company';
                $inc->company_name = $inc->company?->company_name;
                $inc->branch_name  = $inc->branch?->name;
                $inc->creator_name = $inc->reportedBy?->name;
            });
        $incidentStatusCounts = Incident::selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status');
        $activeIncidentSeverity = Incident::whereIn('status', ['open', 'investigating'])
            ->selectRaw('severity, COUNT(*) as c')->groupBy('severity')->pluck('c', 'severity');
        $incidentStats = [
            'total'      => (int) $incidentStatusCounts->sum(),
            'open'       => (int) ($incidentStatusCounts['open'] ?? 0),
            'investigating' => (int) ($incidentStatusCounts['investigating'] ?? 0),
            'resolved'   => (int) ($incidentStatusCounts['resolved'] ?? 0),
            'critical'   => (int) ($activeIncidentSeverity['critical'] ?? 0),
            'high'       => (int) ($activeIncidentSeverity['high'] ?? 0),
        ];

        // ── Compliance ──
        $driver = DB::getDriverName();
        $withinHours = VisitRequest::whereTime('requested_time', '>=', '08:00:00')
            ->whereTime('requested_time', '<', '18:00:00')->count();
        $complianceRate = $totalRequests > 0 ? round(($withinHours / $totalRequests) * 100, 1) : 100;

        $processedWithinSla = VisitRequest::whereNotNull('approved_at')
            ->whereRaw($driver === 'sqlite'
                ? '(julianday(approved_at) - julianday(created_at)) * 1440 <= 5'
                : 'TIMESTAMPDIFF(MINUTE, created_at, approved_at) <= 5')->count();
        $totalProcessed = VisitRequest::whereNotNull('approved_at')->count();
        $slaRate = $totalProcessed > 0 ? round(($processedWithinSla / $totalProcessed) * 100, 1) : 100;

        $weekendAttempts = VisitRequest::whereRaw($driver === 'sqlite'
            ? "strftime('%w', requested_time) IN ('0', '6')"
            : 'DAYOFWEEK(requested_time) IN (1, 7)')->count();
        $activeAdmins    = User::where('role', 'admin')->where('status', 'active')->count();
        $pendingAdmins   = User::where('role', 'admin')->where('status', 'pending')->get();
        $suspendedCount  = User::where('status', 'suspended')->count();

        // ── Department Performance ──
        // Three grouped queries replace the previous per-department N+1
        // (3 COUNT queries for every department). Results are identical.
        $deptVisitsTotal = VisitRequest::join('users as dept_host', 'dept_host.id', '=', 'visit_requests.host_id')
            ->selectRaw('dept_host.department_id, COUNT(*) as c')
            ->groupBy('dept_host.department_id')
            ->pluck('c', 'department_id');
        $deptApproved    = VisitRequest::join('users as dept_host', 'dept_host.id', '=', 'visit_requests.host_id')
            ->where('visit_requests.status', 'approved')
            ->selectRaw('dept_host.department_id, COUNT(*) as c')
            ->groupBy('dept_host.department_id')
            ->pluck('c', 'department_id');
        $deptActiveNow   = VisitRequest::join('users as dept_host', 'dept_host.id', '=', 'visit_requests.host_id')
            ->where('visit_requests.status', 'checked_in')
            ->selectRaw('dept_host.department_id, COUNT(*) as c')
            ->groupBy('dept_host.department_id')
            ->pluck('c', 'department_id');

        $deptStats = Department::withCount(['users' => function($q) {
            $q->where('role', 'host');
        }])->get()->map(function($dept) use ($deptVisitsTotal, $deptApproved, $deptActiveNow) {
            $totalVisits  = (int) ($deptVisitsTotal[$dept->id] ?? 0);
            $totalApproved = (int) ($deptApproved[$dept->id] ?? 0);
            $activeNow    = (int) ($deptActiveNow[$dept->id] ?? 0);
            $utilization  = $dept->capacity_limit > 0
                ? round(($activeNow / $dept->capacity_limit) * 100, 1) : 0;
            return [
                'id'             => $dept->id,
                'name'           => $dept->name,
                'capacity_limit' => $dept->capacity_limit,
                'active_now'     => $activeNow,
                'total_visits'   => $totalVisits,
                'total_approved' => $totalApproved,
                'hosts_count'    => $dept->users_count,
                'utilization'    => $utilization,
            ];
        });

        // ── Visitor Trends — monthly (current year) ──
        $currentYear = now()->year;
        $monthlyCounts = VisitRequest::selectRaw($driver === 'sqlite'
            ? "strftime('%m', created_at) as m, COUNT(*) as c"
            : 'MONTH(created_at) as m, COUNT(*) as c')
            ->whereYear('created_at', $currentYear)
            ->groupBy('m')
            ->pluck('c', 'm');
        $monthlyVisits = [];
        for ($m = 1; $m <= 12; $m++) {
            $monthlyVisits[] = [
                'month' => date('M', mktime(0, 0, 0, $m, 1)),
                'count' => (int) ($monthlyCounts[$m] ?? 0),
            ];
        }

        // Status breakdown
        $statusBreakdown = [
            'pending'     => $pendingRequests,
            'approved'    => $totalApproved,
            'rejected'    => $totalRejected,
            'escalated'   => $totalEscalated,
            'checked_in'  => $activeVisitors,
            'checked_out' => (int) ($statusCounts['checked_out'] ?? 0),
            'archived'    => (int) ($statusCounts['archived'] ?? 0),
        ];

        // Top 5 hosts by visit count
        $topHosts = User::where('role', 'host')->where('status', 'active')
            ->withCount('visitRequestsAsHost')
            ->orderByDesc('visit_requests_as_host_count')
            ->take(5)
            ->get()
            ->map(fn($h) => [
                'name'         => $h->name,
                'visit_count'  => $h->visit_requests_as_host_count,
            ]);

        // ── Approval Performance ──
        $avgApprovalMinutes = VisitRequest::whereNotNull('approved_at')
            ->selectRaw($driver === 'sqlite'
                ? 'AVG((julianday(approved_at) - julianday(created_at)) * 1440) as avg_minutes'
                : 'AVG(TIMESTAMPDIFF(MINUTE, created_at, approved_at)) as avg_minutes')
            ->value('avg_minutes');

        $topApprovers = User::where('role', 'host')
            ->withCount(['visitRequestsAsHost' => fn($q) => $q->where('status', 'approved')])
            ->orderByDesc('visit_requests_as_host_count')
            ->take(5)
            ->get()
            ->map(fn($h) => [
                'name'  => $h->name,
                'count' => $h->visit_requests_as_host_count,
            ]);

        // ── System Policies ──
        $policyWeekend    = DB::table('cache')->where('key', 'policy_allow_weekends')->first();
        $policyBizHours   = DB::table('cache')->where('key', 'policy_enforce_biz_hours')->first();
        $companyPolicy    = CompanyPolicy::forCompany($ceo->company_id);

        $policies = array_merge([
            'allow_weekends'    => ($policyWeekend    && $policyWeekend->value    === 'true'),
            'enforce_biz_hours' => !($policyBizHours  && $policyBizHours->value   === 'false'),
        ], $companyPolicy->toSettingsArray());

        // ── Recent Broadcasts (DB-driven announcements scoped to this company) ──
        $recentBroadcasts = \App\Models\Announcement::where('company_id', $ceo->company_id)
            ->with('creator', 'branch')
            ->latest()->take(20)->get()
            ->map(fn($a) => [
                'id'          => $a->id,
                'message'     => $a->message,
                'priority'    => $a->priority,
                'timestamp'   => $a->created_at?->toDateTimeString(),
                'scope_type'  => $a->scope_type,
                'branch_name' => $a->branch?->name,
                'creator'     => $a->creator?->name,
            ]);

        // ── Audit Logs (paginated — first 50) ──
        // Eager-load visit-request location/branch meta in ONE query instead of
        // one find() per audit log row.
        $auditLogs = AuditLog::latest()->take(50)->get();

        $visitEntityIds = $auditLogs
            ->filter(fn($log) => $log->entity === 'visit_requests' && $log->entity_id)
            ->pluck('entity_id')
            ->unique()
            ->values();
        $visitMeta = $visitEntityIds->isEmpty()
            ? collect()
            : VisitRequest::with('location', 'branch')->whereIn('id', $visitEntityIds)->get()->keyBy('id');

        $auditLogs = $auditLogs->map(function ($log) use ($visitMeta) {
            if ($log->entity === 'visit_requests' && $log->entity_id) {
                $vr = $visitMeta->get($log->entity_id);
                if ($vr) {
                    $log->location_id = $vr->location_id;
                    $log->location_name = $vr->location?->name;
                    $log->branch_id = $vr->branch_id;
                    $log->branch_name = $vr->branch?->name;
                }
            }
            return $log;
        });

        // ── Escalated / assigned visits where this CEO is responsible ──
        // Covers manual escalations (escalated_to), visits the host transferred
        // to this CEO (host_id), and approved visits awaiting check-in.
        $sla = app(SlaService::class);
        $escalations = VisitRequest::whereIn('status', ['escalated', 'checked_in', 'approved'])
            ->where(function ($query) use ($ceo) {
                $query->where('escalated_to', $ceo->id)
                      ->orWhere('host_id', $ceo->id);
            })
            ->with([
                // The visitor may belong to a different company than the visit
                // after a cross-company transfer, so the CompanyScope would
                // otherwise filter them out and null the record on the CEO's
                // dashboard. Unscope it, mirroring the Host and Admin dashboards.
                'visitor' => fn ($q) => $q->withoutGlobalScope(\App\Models\Scopes\CompanyScope::class),
                'host', 'escalatedTo', 'escalations.escalatedBy', 'escalations.escalatedTo',
            ])
            ->orderByRaw("CASE WHEN status = 'escalated' THEN 0 WHEN status = 'checked_in' THEN 1 ELSE 2 END ASC")
            ->orderBy('requested_time', 'asc')
            ->get()
            ->map(fn ($v) => $v->toArray() + $sla->decorate($v));

        // ── Visits this CEO is directly responsible for ──
        // Scoped to the CEO's own assignments (host_id = me or escalated_to =
        // me) so a visit transferred to another user immediately leaves the
        // CEO's Company Visits list. The global CompanyScope keeps it within
        // the CEO's company.
        $allVisits = VisitRequest::whereIn('status', ['pending', 'approved', 'checked_in', 'escalated'])
            ->where(function ($query) use ($ceo) {
                $query->where('host_id', $ceo->id)
                      ->orWhere('escalated_to', $ceo->id);
            })
            ->with([
                // Same cross-company visitor concern as the escalation queue above.
                'visitor' => fn ($q) => $q->withoutGlobalScope(\App\Models\Scopes\CompanyScope::class),
                'host', 'department', 'branch', 'escalatedTo', 'escalations.escalatedBy', 'escalations.escalatedTo',
            ])
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 WHEN status = 'approved' THEN 1 WHEN status = 'escalated' THEN 2 ELSE 3 END ASC")
            ->orderBy('requested_time', 'asc')
            ->get()
            ->map(fn ($v) => $v->toArray() + $sla->decorate($v));

        // Notifications for CEO
        $notifications = \App\Models\SystemNotification::where('recipient_type', 'user')
            ->where('recipient_id', $ceo->id)
            ->latest()
            ->take(30)
            ->get();
        $unreadCount = \App\Models\SystemNotification::where('recipient_type', 'user')
            ->where('recipient_id', $ceo->id)
            ->whereNull('read_at')
            ->count();

        // ── Escalation targets ──
        // Active Admin users of this company who can receive an escalated
        // visit. The CEO escalates to a Company Admin only. The global
        // CompanyScope keeps them in the CEO's company.
        $escalationTargets = User::whereIn('role', ['admin'])
            ->where('status', 'active')
            ->where('id', '!=', $ceo->id)
            ->with('department')
            ->get();

        return Inertia::render('Dashboard/CEO', [
            'escalations' => $escalations,
            'all_visits'  => $allVisits,
            'notifications' => $notifications,
            'unread_count'  => $unreadCount,
            'escalation_targets' => $escalationTargets,
            'stats' => [
                'total_visitors'    => $totalVisitors,
                'active_visitors'   => $activeVisitors,
                'total_requests'    => $totalRequests,
                'total_approved'    => $totalApproved,
                'total_rejected'    => $totalRejected,
                'total_escalated'   => $totalEscalated,
                'pending_requests'  => $pendingRequests,
                'total_departments' => $totalDepartments,
                'approval_rate'     => $approvalRate,
                'rejection_rate'    => $rejectionRate,
                'avg_duration'      => round($avgDuration),
                'lockdown_status'   => $lockdown,
                'today_checkins'    => $todayCheckins,
                'today_checkouts'   => $todayCheckouts,
                'today_pending'     => $todayPending,
                'today_rejections'  => $todayRejections,
            ],
            'security' => [
                'blacklist_hits'       => $blacklistHits,
                'failed_validations'   => $failedValidations,
                'sla_overdue'          => $slaOverdue,
                'recent_events'        => $recentSecurityEvents,
            ],
            'compliance' => [
                'compliance_rate'     => $complianceRate,
                'sla_rate'            => $slaRate,
                'weekend_attempts'    => $weekendAttempts,
                'active_admins'       => $activeAdmins,
                'pending_admins_count'=> count($pendingAdmins),
                'suspended_count'     => $suspendedCount,
            ],
            'department_statistics' => $deptStats,
            'analytics' => [
                'monthly_visits'   => $monthlyVisits,
                'status_breakdown' => $statusBreakdown,
                'top_hosts'        => $topHosts,
            ],
            'approval_performance' => [
                'total_approved'       => $totalApproved,
                'total_rejected'       => $totalRejected,
                'total_escalated'      => $totalEscalated,
                'approval_rate'        => $approvalRate,
                'rejection_rate'       => $rejectionRate,
                'avg_approval_minutes' => $avgApprovalMinutes ? round($avgApprovalMinutes, 1) : null,
                'top_approvers'        => $topApprovers,
            ],
            'pending_admins'    => $pendingAdmins,
            'policies'          => $policies,
            'sla_holidays'      => $this->slaHolidaysFor($ceo->company_id),
            'recent_broadcasts' => $recentBroadcasts,
            'audit_logs'        => $auditLogs,
            'active_incidents'  => $activeIncidents,
            'incident_stats'    => $incidentStats,
            'active_lockdowns'  => $activeLockdowns->map(fn($l) => [
                'id'          => $l->id,
                'scope_type'  => $l->scope_type,
                'branch_id'   => $l->branch_id,
                'branch_name' => $l->branch?->name,
                'reason'      => $l->reason,
                'started_by'  => $l->startedBy?->name,
                'started_at'  => $l->started_at?->toDateTimeString(),
                'ended_at'    => $l->ended_at?->toDateTimeString(),
            ]),
            'branches'          => \App\Models\Branch::where('company_id', $ceo->company_id)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name']),
            'section'           => $section,
            'messages_inbox'    => \App\Http\Controllers\MessageController::inboxList('user', $ceo->id),
            'messages_sent'     => \App\Http\Controllers\MessageController::sentList('user', $ceo->id),
            'messages_recipients' => \App\Http\Controllers\MessageController::recipientsFor('user', $ceo),
            'messages_unread_count' => Message::unreadCount('user', $ceo->id),
        ]);
    }

    /**
     * CEO: Broadcast announcement to all employees — DB-driven and scope-aware
     * (company-wide or one specific branch).
     */
    public function broadcastAnnouncement(Request $request)
    {
        $ceo = Auth::guard('web')->user();
        if (!$ceo || $ceo->role !== 'ceo') {
            return $this->redirectToOwnDashboard($ceo);
        }

        $validator = Validator::make($request->all(), [
            'message'    => 'required|string|max:500',
            'priority'   => 'required|in:info,warning,critical',
            'scope_type' => 'nullable|in:company,branch',
            'branch_id'  => 'nullable|exists:branches,id',
        ]);

        $validator->sometimes('branch_id', 'required|exists:branches,id', function ($input) {
            return ($input->scope_type ?? 'company') === 'branch';
        });

        if ($validator->fails()) {
            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
            }
            return back()->withErrors($validator);
        }

        // Broadcasts are always scoped to the CEO's own company (never another
        // company), and branch_id only applies to branch-scoped broadcasts.
        $data = [
            'company_id' => $ceo->company_id,
            'branch_id'  => ($request->scope_type ?? 'company') === 'branch'
                ? (int) $request->branch_id : null,
            'scope_type' => $request->scope_type ?? 'company',
            'message'    => $request->message,
            'priority'   => $request->priority,
        ];

        \App\Services\SecurityIncidentService::broadcast($data, $ceo);

        return $this->emergencyJson($request, $ceo, 'Broadcast sent to the selected scope.');
    }

    /**
     * CEO: Update system policies.
     */
    public function updatePolicy(Request $request)
    {
        $ceo = Auth::guard('web')->user();
        if (!$ceo || $ceo->role !== 'ceo') {
            return $this->redirectToOwnDashboard($ceo);
        }

        $validator = Validator::make($request->all(), [
            'allow_weekends'         => 'required',
            'enforce_biz_hours'      => 'required',
            'sla_minutes'            => 'required|integer|min:1|max:60',
            'admin_sla_minutes'      => 'nullable|integer|min:1|max:1440',
            'auto_escalation_enabled'=> 'nullable|boolean',
            'sla_warning_minutes'    => 'nullable|integer|min:0|max:1000',
            'sla_pause_off_hours'    => 'nullable|boolean',
            'sla_count_weekends'     => 'nullable|boolean',
            'sla_count_holidays'     => 'nullable|boolean',
            'sla_notify_in_app'      => 'nullable|boolean',
            'sla_notify_email'       => 'nullable|boolean',
            'sla_notify_sms'         => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator);
        }

        // Handle booleans sent as true/false/1/0/"true"/"false" from JS
        $allowWeekends   = filter_var($request->allow_weekends,    FILTER_VALIDATE_BOOLEAN);
        $enforceBizHours = filter_var($request->enforce_biz_hours, FILTER_VALIDATE_BOOLEAN);
        $far = now()->addYears(10)->timestamp;

        DB::table('cache')->updateOrInsert(
            ['key' => 'policy_allow_weekends'],
            ['value' => $allowWeekends ? 'true' : 'false', 'expiration' => $far]
        );
        DB::table('cache')->updateOrInsert(
            ['key' => 'policy_enforce_biz_hours'],
            ['value' => $enforceBizHours ? 'true' : 'false', 'expiration' => $far]
        );

        // SLA timeout is stored per company so each company's policy is
        // enforced independently across all of its branches.
        $policyPayload = [];
        if ($ceo->company_id) {
            $policyPayload = $this->slaPolicyPayload($request, CompanyPolicy::forCompany($ceo->company_id));
            CompanyPolicy::updateOrCreate(
                ['company_id' => $ceo->company_id],
                $policyPayload
            );
            app(SlaService::class)->flushMemo();
        }

        AuditLogger::log('ceo_policy_update', 'cache', null, null, [
            'allow_weekends'    => $allowWeekends,
            'enforce_biz_hours' => $enforceBizHours,
            'sla_minutes'       => (int)$request->sla_minutes,
        ] + $policyPayload);

        return back()->with('success', 'System policies updated and applied immediately.');
    }

    /**
     * CEO: Export visit requests as CSV.
     */
    public function exportVisits()
    {
        $ceo = Auth::guard('web')->user();
        if (!$ceo || $ceo->role !== 'ceo') {
            return $this->redirectToOwnDashboard($ceo);
        }

        $visits = VisitRequest::with('visitor', 'host.department', 'department', 'company', 'branch')->orderBy('id')->get();

        $filename = 'vms-visits-' . now()->format('Y-m-d') . '.csv';
        $headers  = [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = function () use ($visits) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['ID', 'Visitor Name', 'Visitor Email', 'Host Name', 'Department', 'Purpose', 'Requested Time', 'Duration', 'Status', 'Approved At', 'Rejected At', 'Comments', 'Company', 'Branch']);
            foreach ($visits as $v) {
                fputcsv($handle, [
                    $v->id,
                    $v->visitor->name ?? '',
                    $v->visitor->email ?? '',
                    $v->host->name ?? '',
                    $v->department->name ?? $v->host?->department?->name ?? '',
                    $v->purpose,
                    $v->requested_time ? $v->requested_time->format('Y-m-d H:i') : '',
                    $v->duration,
                    $v->status,
                    $v->approved_at ? $v->approved_at->format('Y-m-d H:i') : '',
                    $v->rejected_at ? $v->rejected_at->format('Y-m-d H:i') : '',
                    $v->comments ?? '',
                    $v->company?->company_name ?? '',
                    $v->branch?->name ?? '',
                ]);
            }
            fclose($handle);
        };

        AuditLogger::log('ceo_export_visits', 'visit_requests', null);

        return response()->stream($callback, 200, $headers);
    }

    /**
     * CEO: Export audit logs as CSV.
     */
    public function exportAuditLogs()
    {
        $ceo = Auth::guard('web')->user();
        if (!$ceo || $ceo->role !== 'ceo') {
            return $this->redirectToOwnDashboard($ceo);
        }

        $logs = AuditLog::orderBy('id')->get();

        $filename = 'vms-audit-' . now()->format('Y-m-d') . '.csv';
        $headers  = [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = function () use ($logs) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Timestamp', 'Actor Type', 'Actor ID', 'Action', 'Entity', 'Entity ID', 'IP Address']);
            foreach ($logs as $log) {
                fputcsv($handle, [
                    $log->created_at->format('Y-m-d H:i:s'),
                    $log->actor_type ?? '',
                    $log->actor_id ?? '',
                    $log->action,
                    $log->entity ?? '',
                    $log->entity_id ?? '',
                    $log->ip_address ?? '',
                ]);
            }
            fclose($handle);
        };

        AuditLogger::log('ceo_export_audit', 'audit_logs', null);

        return response()->stream($callback, 200, $headers);
    }

    /**
     * CEO action to Toggle a scoped Lockdown (company-wide or one branch).
     */
    public function toggleLockdown(Request $request)
    {
        $ceo = Auth::guard('web')->user();
        if (!$ceo || $ceo->role !== 'ceo') {
            return $this->redirectToOwnDashboard($ceo);
        }

        $scope = \App\Services\SecurityIncidentService::resolveScope($request->all(), $ceo);

        $existing = \App\Models\Lockdown::query()
            ->where('company_id', $scope['company_id'])
            ->where('branch_id', $scope['branch_id'])
            ->where('status', 'active')
            ->latest('started_at')
            ->first();

        if ($existing) {
            \App\Services\SecurityIncidentService::liftLockdown($existing->id, $ceo);
            return $this->emergencyJson($request, $ceo, 'Lockdown lifted for the selected scope.');
        }

        \App\Services\SecurityIncidentService::declareLockdownOnly($request->all(), $ceo);

        return $this->emergencyJson($request, $ceo, 'Lockdown activated for the selected scope.');
    }

    /**
     * CEO: Declare a scoped security incident with optional lockdown + broadcast.
     */
    public function createIncident(Request $request)
    {
        $ceo = Auth::guard('web')->user();
        if (!$ceo || $ceo->role !== 'ceo') {
            return $this->redirectToOwnDashboard($ceo);
        }

        $validator = Validator::make($request->all(), [
            'title'       => 'required|string|max:255',
            'scope_type'  => 'required|in:company,branch',
            'branch_id'   => 'nullable|exists:branches,id',
            'severity'    => 'required|in:low,medium,high,critical',
            'type'        => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'reason'      => 'nullable|string',
            'message'     => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
            }
            return back()->withErrors($validator);
        }

        try {
            $result = \App\Services\SecurityIncidentService::declareIncident($request->all(), $ceo);
        } catch (\InvalidArgumentException $e) {
            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
            }
            return back()->with('error', $e->getMessage());
        }

        $msg = 'Security incident #' . $result['incident']->id . ' declared.';
        if ($result['lockdown']) {
            $msg .= ' Lockdown activated.';
        }
        if ($result['announcement']) {
            $msg .= ' Announcement broadcast.';
        }

        return $this->emergencyJson($request, $ceo, $msg);
    }

    /**
     * CEO: Lift an active scoped lockdown.
     */
    public function liftIncidentLockdown(Request $request, $id)
    {
        $ceo = Auth::guard('web')->user();
        if (!$ceo || $ceo->role !== 'ceo') {
            return $this->redirectToOwnDashboard($ceo);
        }

        $lockdown = \App\Services\SecurityIncidentService::liftLockdown((int) $id, $ceo);

        return $this->emergencyJson($request, $ceo, $lockdown ? 'Lockdown lifted.' : 'Lockdown not found or already lifted.');
    }

    /**
     * Respond to an emergency action. AJAX/JSON clients get a lightweight
     * snapshot of the emergency state so they can update instantly without a
     * full CEO dashboard re-render; regular Inertia requests keep the original
     * redirect-with-flash behaviour.
     */
    private function emergencyJson(Request $request, \App\Models\User $ceo, string $message)
    {
        if (!$request->wantsJson()) {
            return back()->with('success', $message);
        }

        return response()->json(array_merge(
            ['success' => true, 'message' => $message],
            $this->emergencyState($ceo)
        ));
    }

    /**
     * Lightweight, company-scoped snapshot of the CEO Emergency module state.
     */
    private function emergencyState(\App\Models\User $ceo): array
    {
        $activeLockdowns = \App\Models\Lockdown::query()
            ->where('company_id', $ceo->company_id)
            ->where('status', 'active')
            ->with('branch', 'startedBy')
            ->latest('started_at')
            ->get();

        $recentBroadcasts = \App\Models\Announcement::where('company_id', $ceo->company_id)
            ->with('creator', 'branch')
            ->latest()->take(20)->get()
            ->map(fn($a) => [
                'id'          => $a->id,
                'message'     => $a->message,
                'priority'    => $a->priority,
                'timestamp'   => $a->created_at?->toDateTimeString(),
                'scope_type'  => $a->scope_type,
                'branch_name' => $a->branch?->name,
                'creator'     => $a->creator?->name,
            ]);

        return [
            'active_lockdowns' => $activeLockdowns->map(fn($l) => [
                'id'          => $l->id,
                'scope_type'  => $l->scope_type,
                'branch_id'   => $l->branch_id,
                'branch_name' => $l->branch?->name,
                'reason'      => $l->reason,
                'started_by'  => $l->startedBy?->name,
                'started_at'  => $l->started_at?->toDateTimeString(),
                'ended_at'    => $l->ended_at?->toDateTimeString(),
            ]),
            'lockdown_status'  => $activeLockdowns->isNotEmpty(),
            'recent_broadcasts' => $recentBroadcasts,
        ];
    }

    /**
     * CEO action to Approve Admin account.
     */
    public function approveAdmin(Request $request, $id)
    {
        $ceo = Auth::guard('web')->user();
        $admin = User::findOrFail($id);
        $action = $request->input('action'); // approve, reject

        if ($action === 'approve') {
            $admin->update([
                'status' => 'active',
                'approved_by' => $ceo->id,
            ]);
            NotificationService::send('Registration Approved / Rejected', $admin, "Your Admin registration has been approved by the CEO.");
            AuditLogger::log('approve_admin', 'users', $admin->id);
            return back()->with('success', "Admin account approved.");
        } else {
            $admin->update([
                'status' => 'suspended'
            ]);
            NotificationService::send('Registration Approved / Rejected', $admin, "Your Admin registration has been declined.");
            AuditLogger::log('reject_admin', 'users', $admin->id);
            return back()->with('success', "Admin account rejected.");
        }
    }

    /**
     * Render Admin Dashboard — full enterprise admin module.
     */
    public function adminDashboard($section = null)
    {
        // Load new module data
        $companySetting   = \App\Models\CompanySetting::first();
        $workingHours     = \App\Models\WorkingHour::orderBy('day_of_week')->get();
        $visitorCategories= \App\Models\VisitorCategory::all();
        $watchlist        = \App\Models\Watchlist::with('addedBy')->get();
        $badgeTemplates   = \App\Models\BadgeTemplate::all();
        $notifTemplates   = \App\Models\NotificationTemplate::all();
        $integrations     = \App\Models\SystemIntegration::all();

        // Seed default working hours if none exist
        if ($workingHours->isEmpty()) {
            for ($d = 0; $d <= 6; $d++) {
                \App\Models\WorkingHour::create([
                    'day_of_week'    => $d,
                    'is_working_day' => in_array($d, [1,2,3,4,5]),
                    'open_time'      => '08:00:00',
                    'close_time'     => '19:00:00',
                ]);
            }
            $workingHours = \App\Models\WorkingHour::orderBy('day_of_week')->get();
        }

        // Seed default visitor categories if none exist
        if ($visitorCategories->isEmpty()) {
            foreach ([
                ['name'=>'Regular Visitor',  'color'=>'#6366f1','requires_approval'=>true, 'auto_approve'=>false],
                ['name'=>'VIP Guest',        'color'=>'#ec4899','requires_approval'=>true, 'auto_approve'=>false],
                ['name'=>'Contractor',       'color'=>'#f59e0b','requires_approval'=>true, 'auto_approve'=>false],
                ['name'=>'Supplier/Vendor',  'color'=>'#06b6d4','requires_approval'=>false,'auto_approve'=>true],
                ['name'=>'Job Applicant',    'color'=>'#8b5cf6','requires_approval'=>true, 'auto_approve'=>false],
                ['name'=>'Government Official','color'=>'#ef4444','requires_approval'=>true,'auto_approve'=>false],
            ] as $cat) {
                \App\Models\VisitorCategory::create($cat);
            }
            $visitorCategories = \App\Models\VisitorCategory::all();
        }

        // Seed default integrations if none
        if ($integrations->isEmpty()) {
            foreach ([
                ['name'=>'Email (SMTP)','type'=>'email','status'=>'inactive'],
                ['name'=>'SMS Gateway', 'type'=>'sms',  'status'=>'inactive'],
                ['name'=>'Webhook',     'type'=>'webhook','status'=>'inactive'],
                ['name'=>'LDAP/AD',     'type'=>'ldap', 'status'=>'inactive'],
            ] as $int) {
                \App\Models\SystemIntegration::create($int);
            }
            $integrations = \App\Models\SystemIntegration::all();
        }

        $integrations = $this->sanitizeIntegrations($integrations);

        $pendingUsers = User::where('status', 'pending')
            ->whereIn('role', ['host'])
            ->with('department')
            ->get();

        $departments = Department::with('manager', 'parent', 'children', 'location', 'branch')->get();
        $locations   = Location::with('departments')->get();
        $branches    = \App\Models\Branch::with('location', 'company')->get();
        $companies   = \App\Models\Company::orderBy('company_name')
            ->when(Auth::guard('web')->user()->company_id, fn($q) => $q->where('company_id', Auth::guard('web')->user()->company_id))
            ->get(['company_id', 'company_name']);
        $blacklist   = Blacklist::with('addedBy')->get();
        $auditLogs   = $this->adminAuditLogsWithMeta();

        $slaMinutes  = \App\Models\CompanyPolicy::slaMinutesForCompany(Auth::guard('web')->user()?->company_id);
        $companyPolicy = CompanyPolicy::forCompany(Auth::guard('web')->user()?->company_id);
        $sla = app(SlaService::class);

        // Fetch the three cached policy flags in a single query.
        $policyCache = DB::table('cache')->whereIn('key', [
            'policy_allow_weekends', 'policy_enforce_biz_hours', 'host_assignment_rule',
        ])->pluck('value', 'key');
        $allowWeekends       = ($policyCache['policy_allow_weekends'] ?? null) === 'true';
        $enforceBizHours     = ($policyCache['policy_enforce_biz_hours'] ?? null) !== 'false';
        $assignmentRule      = $policyCache['host_assignment_rule'] ?? 'least_busy';
        $hostAssignmentRule  = in_array($assignmentRule, ['least_busy', 'round_robin']) ? $assignmentRule : 'least_busy';

        // Rich visit stats (single grouped query instead of 8 separate counts)
        $visitStats = $this->adminVisitStats();

        // All active hosts
        $hosts = User::where('role', 'host')->where('status', 'active')->get();

        // All staff (for user management)
        $allStaff = User::whereIn('role', ['host', 'admin', 'manager', 'security_guard'])
            ->with('department', 'approver', 'branch', 'location')
            ->orderBy('status')->orderBy('name')
            ->get();

        // Active visits this Company Admin is directly responsible for: their
        // own escalations plus any visit assigned to them as host (e.g. a host
        // transferred the visitor to this Admin). Scoping to direct ownership
        // keeps a transferred visit from lingering on the original Admin's
        // queue or being duplicated across every Admin/CEO of the company.
        // Company scoping is applied by the global CompanyScope on VisitRequest.
        $escalationsActor = Auth::guard('web')->user();

        $escalations = $this->scopeToOwnedVisits(
            VisitRequest::whereIn('status', ['escalated', 'checked_in', 'approved']),
            $escalationsActor->id
        )
            ->with([
                // Unscope the visitor: after a cross-company transfer the visitor
                // belongs to a different company than the visit and would
                // otherwise be nulled by the global CompanyScope.
                'visitor' => fn ($q) => $q->withoutGlobalScope(\App\Models\Scopes\CompanyScope::class),
                'host', 'escalatedTo', 'escalations.escalatedBy', 'escalations.escalatedTo',
            ])
            ->orderByRaw("CASE WHEN status = 'escalated' THEN 0 WHEN status = 'checked_in' THEN 1 ELSE 2 END ASC")
            ->orderBy('requested_time', 'asc')
            ->get()
            ->map(fn ($v) => $v->toArray() + $sla->decorate($v));

        // Department statistics
        $deptStats = $this->adminDeptStats();

        // Total visitors & active users counts
        $systemStats = [
            'total_visitors'  => \App\Models\Visitor::count(),
            'active_hosts'    => $hosts->count(),
            'pending_staff'   => $pendingUsers->count(),
            'blacklist_count' => $blacklist->count(),
            'active_sessions' => $this->scopeToOwnedVisits(VisitRequest::where('status', 'checked_in'), $escalationsActor->id)->count(),
            'escalation_count'=> $escalations->count(),
        ];

        // ── Incidents (synced from Security module) ──
        $activeIncidents = Incident::whereIn('status', ['open', 'investigating'])
            ->with('reportedBy')
            ->orderByRaw("CASE WHEN severity = 'critical' THEN 0 WHEN severity = 'high' THEN 1 WHEN severity = 'medium' THEN 2 ELSE 3 END")
            ->latest()
            ->take(50)
            ->get();
        // Single grouped query derives all incident stats (replaces 6 full scans).
        $incidentRows = Incident::selectRaw('status, severity, COUNT(*) as c')
            ->groupBy('status', 'severity')
            ->get();
        $incidentStats = [
            'total'        => $incidentRows->sum('c'),
            'open'         => (int) $incidentRows->where('status', 'open')->sum('c'),
            'investigating'=> (int) $incidentRows->where('status', 'investigating')->sum('c'),
            'resolved'     => (int) $incidentRows->where('status', 'resolved')->sum('c'),
            'critical'     => (int) $incidentRows->where('severity', 'critical')->whereIn('status', ['open', 'investigating'])->sum('c'),
            'high'         => (int) $incidentRows->where('severity', 'high')->whereIn('status', ['open', 'investigating'])->sum('c'),
        ];

        // Visitor categories with visit counts
        $visitorCategoriesWithStats = $this->adminVisitorCategoryStats($visitorCategories);

        // ── Admin Personal Notifications (handled by NotificationPanel API) ──
        $adminUser = Auth::guard('web')->user();

        // Reschedule requests (all visits for admin)
        $allRescheduleRequests = \App\Models\RescheduleRequest::with('visitRequest.visitor', 'visitRequest.host', 'requestedByUser', 'requestedByVisitor')
            ->latest()->get();

        // Host change requests for admin view
        $allHostChangeRequests = \App\Models\HostChangeRequest::with('visitRequest.visitor', 'previousHost', 'newHost', 'newDepartment', 'requestedByUser', 'reviewedByUser')
            ->latest()
            ->get();

        $hostChangeCounts = \App\Models\HostChangeRequest::selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');
        $hostChangeStats = [
            'total'     => (int) $hostChangeCounts->sum(),
            'pending'   => (int) ($hostChangeCounts['pending'] ?? 0),
            'accepted'  => (int) ($hostChangeCounts['accepted'] ?? 0),
            'rejected'  => (int) ($hostChangeCounts['rejected'] ?? 0),
            'cancelled' => (int) ($hostChangeCounts['cancelled'] ?? 0),
        ];

        // ── All Visits (Admin's active/pending appointments) ──
        // Scoped to the Admin's own assignments (host_id = me or escalated_to
        // = me) so a visit transferred to another user immediately leaves the
        // original Admin's Appointments list and Live Monitor. The global
        // CompanyScope keeps it within the Admin's company.
        $adminVisitRows = VisitRequest::with([
                'visitor.company',
                'host.department', 'host.company', 'host.branch',
                'department', 'branch', 'location', 'company',
                'escalatedTo.department', 'escalatedTo.company', 'escalatedTo.branch',
                'checkInOutLogs', 'escalations.escalatedBy', 'escalations.escalatedTo',
            ])
            ->whereIn('status', ['pending', 'approved', 'escalated', 'checked_in'])
            ->where(function ($q) use ($adminUser) {
                $q->where('host_id', $adminUser->id)
                  ->orWhere('escalated_to', $adminUser->id);
            })
            ->orderBy('requested_time')
            ->get()
            ->map(fn($v) => $this->mapVisitForAdmin($v))
            ->values();

        $allVisits    = $adminVisitRows;
        // Live = currently on premises AND the meeting has not ended. Visits that
        // are only pending, approved or escalated stay out of Live and surface in
        // Appointments instead.
        $liveVisitors = $adminVisitRows
            ->filter(fn ($v) => $v['status'] === 'checked_in' && empty($v['meeting_ended_at']))
            ->values();

        return Inertia::render('Dashboard/Admin', [
            'pending_users'         => $pendingUsers,
            'all_staff'             => $allStaff,
            'departments'           => $departments,
            'blacklist'             => $blacklist,
            'audit_logs'            => $auditLogs,
            'visit_stats'           => $visitStats,
            'hosts'                 => $hosts,
            'escalations'           => $escalations,
            'department_statistics' => $deptStats,
            'system_stats'          => $systemStats,
            // New module data
            'company_setting'       => $companySetting,
            'working_hours'         => $workingHours,
            'visitor_categories'    => $visitorCategories,
            'visitor_categories_with_stats' => $visitorCategoriesWithStats,
            'watchlist'             => $watchlist,
            'badge_templates'       => $badgeTemplates,
            'notif_templates'       => $notifTemplates,
            'integrations'          => $integrations,
            'active_incidents'      => $activeIncidents,
            'incident_stats'        => $incidentStats,
            'locations'             => $locations,
            'branches'              => $branches,
            'companies'             => $companies,
            'sla_minutes'           => $slaMinutes,
            'sla_policy'            => $companyPolicy->toSettingsArray(),
            'sla_holidays'          => $this->slaHolidaysFor(Auth::guard('web')->user()?->company_id),
            'allow_weekends'        => $allowWeekends,
            'enforce_biz_hours'     => $enforceBizHours,
            'host_assignment_rule'  => $hostAssignmentRule,
            'all_visits'             => $allVisits,
            'live_visitors'          => $liveVisitors,
            'all_reschedule_requests' => $allRescheduleRequests,
            'all_host_change_requests' => $allHostChangeRequests,
            'host_change_stats' => $hostChangeStats,
            'section'               => $section,
            'messages_inbox'        => \App\Http\Controllers\MessageController::inboxList('user', $adminUser->id),
            'messages_sent'         => \App\Http\Controllers\MessageController::sentList('user', $adminUser->id),
            'messages_recipients'   => \App\Http\Controllers\MessageController::recipientsFor('user', $adminUser),
            'messages_unread_count' => Message::unreadCount('user', $adminUser->id),
        ]);
    }

    /**
     * Lightweight Admin Monitor poll — returns only the live-monitor props
     * (live visitors, visit stats, escalations) so the Monitor tab refreshes
     * without re-executing the full dashboard controller every 30s.
     */
    public function adminMonitorData()
    {
        $admin = Auth::guard('web')->user();
        if (!$admin) abort(403);

        $visitStats = $this->adminVisitStats();
        $sla = app(SlaService::class);

        $escalations = $this->scopeToOwnedVisits(
            VisitRequest::whereIn('status', ['escalated', 'checked_in', 'approved']),
            $admin->id
        )
            ->with([
                // Unscope the visitor (see adminDashboard): cross-company visits
                // must still resolve the full visitor record on the Monitor poll.
                'visitor' => fn ($q) => $q->withoutGlobalScope(\App\Models\Scopes\CompanyScope::class),
                'host', 'escalatedTo', 'escalations.escalatedBy', 'escalations.escalatedTo',
            ])
            ->orderByRaw("CASE WHEN status = 'escalated' THEN 0 WHEN status = 'checked_in' THEN 1 ELSE 2 END ASC")
            ->orderBy('requested_time', 'asc')
            ->get()
            ->map(fn ($v) => $v->toArray() + $sla->decorate($v));

        $adminVisitRows = VisitRequest::with([
                'visitor.company',
                'host.department', 'host.company', 'host.branch',
                'department', 'branch', 'location', 'company',
                'escalatedTo.department', 'escalatedTo.company', 'escalatedTo.branch',
                'checkInOutLogs', 'escalations.escalatedBy', 'escalations.escalatedTo',
            ])
            ->whereIn('status', ['pending', 'approved', 'escalated', 'checked_in'])
            ->where(function ($q) use ($admin) {
                $q->where('host_id', $admin->id)
                  ->orWhere('escalated_to', $admin->id);
            })
            ->orderBy('requested_time')
            ->get()
            ->map(fn($v) => $this->mapVisitForAdmin($v))
            ->values();

        // Live = currently on premises AND the meeting has not ended. Visits that
        // are only pending, approved or escalated stay out of Live and surface in
        // Appointments instead.
        $liveVisitors = $adminVisitRows
            ->filter(fn ($v) => $v['status'] === 'checked_in' && empty($v['meeting_ended_at']))
            ->values();

        return response()->json([
            'live_visitors' => $liveVisitors,
            'visit_stats'   => $visitStats,
            'escalations'   => $escalations,
        ]);
    }

    /**
     * Admin Section Page — renders a single section full-screen.
     */
    public function adminSectionPage($section)
    {
        // Must cover every key in the admin sidebar TABS list (Admin.jsx) or
        // clicking that tab returns a 404 and drops the whole dashboard.
        $validSections = ['overview','appointments','company','branches','locations','departments','users','categories','monitor','security','incidents','qr_badges','export','notif','reports','audit','system','messages'];
        if (!in_array($section, $validSections)) {
            abort(404);
        }

        // Reuses the optimized shared admin data loader (single code path).
        return $this->adminDashboard($section);
    }

    /**
     * Shared admin data helpers — performance-optimized. Each replaces the
     * per-row / per-dept / per-category N+1 queries with a few grouped queries
     * while returning exactly the same results.
     */

    private function adminAuditLogsWithMeta()
    {
        $auditLogs = AuditLog::latest()->take(50)->get();

        $visitEntityIds = $auditLogs
            ->filter(fn($log) => $log->entity === 'visit_requests' && $log->entity_id)
            ->pluck('entity_id')
            ->unique()
            ->values();
        $visitMeta = $visitEntityIds->isEmpty()
            ? collect()
            : VisitRequest::with('location', 'branch')->whereIn('id', $visitEntityIds)->get()->keyBy('id');

        return $auditLogs->map(function ($log) use ($visitMeta) {
            if ($log->entity === 'visit_requests' && $log->entity_id) {
                $vr = $visitMeta->get($log->entity_id);
                if ($vr) {
                    $log->location_id = $vr->location_id;
                    $log->location_name = $vr->location?->name;
                    $log->branch_id = $vr->branch_id;
                    $log->branch_name = $vr->branch?->name;
                }
            }
            return $log;
        });
    }

    private function adminVisitStats()
    {
        $actorId = Auth::guard('web')->user()?->id;

        $statusCounts = $this->scopeToOwnedVisits(
            VisitRequest::selectRaw('status, COUNT(*) as c')->groupBy('status'),
            $actorId
        )->pluck('c', 'status');
        $totalRequests = (int) $statusCounts->sum();
        $totalApproved = (int) ($statusCounts['approved'] ?? 0);
        $totalRejected = (int) ($statusCounts['rejected'] ?? 0);

        $todayStart = now()->startOfDay();
        $todayTotal = (int) $this->scopeToOwnedVisits(
            VisitRequest::where('created_at', '>=', $todayStart)
                ->where('created_at', '<', $todayStart->copy()->addDay()),
            $actorId
        )->count();

        return [
            'total'          => $totalRequests,
            'approved'       => $totalApproved,
            'checked_in'     => (int) ($statusCounts['checked_in'] ?? 0),
            'checked_out'    => (int) ($statusCounts['checked_out'] ?? 0),
            'rejected'       => $totalRejected,
            'escalated'      => (int) ($statusCounts['escalated'] ?? 0),
            'pending'        => (int) ($statusCounts['pending'] ?? 0),
            'today_total'    => $todayTotal,
            'approval_rate'  => $totalRequests > 0 ? round(($totalApproved / $totalRequests) * 100, 1) : 0,
            'rejection_rate' => $totalRequests > 0 ? round(($totalRejected / $totalRequests) * 100, 1) : 0,
        ];
    }

    /**
     * Restrict a VisitRequest query to visits a user is directly responsible
     * for: assigned to them as host (host_id) or escalated to them
     * (escalated_to). Used to scope the Admin active lists and statistics to
     * their own assignments so a visit transferred to another user stops
     * appearing on the original Admin's dashboard.
     */
    protected function scopeToOwnedVisits($query, ?int $userId)
    {
        return $query->where(function ($q) use ($userId) {
            $q->where('host_id', $userId)
              ->orWhere('escalated_to', $userId);
        });
    }

    private function adminDeptStats()
    {
        $deptVisitsTotal = VisitRequest::join('users as dept_host', 'dept_host.id', '=', 'visit_requests.host_id')
            ->selectRaw('dept_host.department_id, COUNT(*) as c')
            ->groupBy('dept_host.department_id')->pluck('c', 'department_id');
        $deptActiveNow   = VisitRequest::join('users as dept_host', 'dept_host.id', '=', 'visit_requests.host_id')
            ->where('visit_requests.status', 'checked_in')
            ->selectRaw('dept_host.department_id, COUNT(*) as c')
            ->groupBy('dept_host.department_id')->pluck('c', 'department_id');
        $deptSettled     = VisitRequest::join('users as dept_host', 'dept_host.id', '=', 'visit_requests.host_id')
            ->whereIn('visit_requests.status', ['approved', 'checked_in', 'checked_out', 'archived'])
            ->selectRaw('dept_host.department_id, COUNT(*) as c')
            ->groupBy('dept_host.department_id')->pluck('c', 'department_id');

        return Department::withCount(['users' => fn($q) => $q->where('role', 'host')])
            ->get()->map(function ($dept) use ($deptVisitsTotal, $deptActiveNow, $deptSettled) {
                $visitorCount   = (int) ($deptVisitsTotal[$dept->id] ?? 0);
                $activeVisitors = (int) ($deptActiveNow[$dept->id] ?? 0);
                $settled        = (int) ($deptSettled[$dept->id] ?? 0);
                $approvalRate   = $visitorCount > 0 ? round($settled / $visitorCount * 100) : 0;
                return [
                    'id'             => $dept->id,
                    'name'           => $dept->name,
                    'capacity_limit' => $dept->capacity_limit,
                    'hosts_count'    => $dept->users_count,
                    'visitors_count' => $visitorCount,
                    'active_visitors'=> $activeVisitors,
                    'approval_rate'  => $approvalRate,
                ];
            });
    }

    private function adminVisitorCategoryStats($visitorCategories)
    {
        $todayStart = now()->startOfDay();
        $todayEnd   = $todayStart->copy()->addDay();

        $catCounts = VisitRequest::whereNotNull('visitor_category_id')
            ->selectRaw('visitor_category_id, COUNT(*) as c')
            ->groupBy('visitor_category_id')->pluck('c', 'visitor_category_id');
        $catToday  = VisitRequest::whereNotNull('visitor_category_id')
            ->where('created_at', '>=', $todayStart)->where('created_at', '<', $todayEnd)
            ->selectRaw('visitor_category_id, COUNT(*) as c')
            ->groupBy('visitor_category_id')->pluck('c', 'visitor_category_id');
        $catActive = VisitRequest::whereNotNull('visitor_category_id')
            ->where('status', 'checked_in')
            ->selectRaw('visitor_category_id, COUNT(*) as c')
            ->groupBy('visitor_category_id')->pluck('c', 'visitor_category_id');

        // Single query for the latest visit across ALL categories (replaces the
        // previous per-category query inside the map loop below).
        $catIds = $visitorCategories->pluck('id')->filter()->values();
        $recentByCategory = $catIds->isEmpty() ? collect() : VisitRequest::whereIn('visitor_category_id', $catIds)
            ->with('visitor', 'host')
            ->latest()
            ->get()
            ->groupBy('visitor_category_id')
            ->map(fn($visits) => $visits->take(10)->map(fn($v) => [
                'id'             => $v->id,
                'visitor_name'   => $v->visitor->name ?? '',
                'host_name'      => $v->host->name ?? '',
                'purpose'        => $v->purpose,
                'requested_time' => $v->requested_time->format('M d, Y H:i'),
                'status'         => $v->status,
            ])->values());

        return $visitorCategories->map(function ($cat) use ($catCounts, $catToday, $catActive, $recentByCategory) {
            return [
                'id'            => $cat->id,
                'name'          => $cat->name,
                'color'         => $cat->color,
                'description'   => $cat->description,
                'requires_approval' => $cat->requires_approval,
                'auto_approve'  => $cat->auto_approve,
                'total_visits'  => (int) ($catCounts[$cat->id] ?? 0),
                'today_visits'  => (int) ($catToday[$cat->id] ?? 0),
                'active_visits' => (int) ($catActive[$cat->id] ?? 0),
                'recent_visits' => $recentByCategory->get($cat->id, collect()),
            ];
        });
    }

    /**
     * Admin action to Approve User.
     */
    public function approveUser(Request $request, $id)
    {
        $admin = Auth::guard('web')->user();
        $user = User::findOrFail($id);
        $action = $request->input('action'); // approve, reject

        if ($action === 'approve') {
            $user->update([
                'status' => 'active',
                'approved_by' => $admin->id,
            ]);
            NotificationService::send('Registration Approved / Rejected', $user, "Your registration has been approved. You can now log in.");
            AuditLogger::log('approve_user', 'users', $user->id);
            return back()->with('success', "User account active.");
        } else {
            $user->update([
                'status' => 'suspended'
            ]);
            NotificationService::send('Registration Approved / Rejected', $user, "Your registration has been rejected.");
            AuditLogger::log('reject_user', 'users', $user->id);
            return back()->with('success', "User account declined.");
        }
    }

    /**
     * Admin: Create a new user account.
     */
    public function storeUser(Request $request)
    {
        $admin = Auth::guard('web')->user();
        $validator = Validator::make($request->all(), [
            'name'          => 'required|string|max:255',
            'email'         => 'required|string|email|max:255|unique:users',
            'phone'         => 'required|string|regex:/^\+?[0-9][0-9\s\-\.\(\)]{7,16}$/',
            'role'          => 'required|in:admin,manager,host,security_guard',
            'department_id' => 'nullable',
            'branch_id'     => 'nullable|exists:branches,id',
            'status'        => 'required|in:active,pending',
            'password'      => 'required|string|min:8',
            'company_id'    => 'nullable|exists:companies,company_id',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        $companyId = $admin->hasRole('system_admin')
            ? ($request->input('company_id') ?? null)
            : $admin->company_id;

        $user = User::create([
            'name'          => $request->name,
            'email'         => $request->email,
            'phone'         => $request->phone,
            'role'          => $request->role,
            'department_id' => $request->department_id ?: null,
            'branch_id'     => $request->branch_id ?: null,
            'status'        => $request->status,
            'approved_by'   => $request->status === 'active' ? $admin->id : null,
            'password'      => $request->password,
            'company_id'    => $companyId,
        ]);

        AuditLogger::log('admin_create_user', 'users', $user->id, null, $user->toArray());
        NotificationService::send('Account Created', $user, "Your account has been created by an administrator. You can now log in.");

        return back()->with('success', "User account created successfully.");
    }

    /**
     * Admin: Update an existing user account.
     */
    public function updateUser(Request $request, $id)
    {
        $user = User::findOrFail($id);
        $validator = Validator::make($request->all(), [
            'name'          => 'required|string|max:255',
            'email'         => 'required|string|email|max:255|unique:users,email,' . $user->id,
            'phone'         => 'nullable|string',
            'role'          => 'required|in:admin,manager,host,security_guard',
            'department_id' => 'nullable',
            'branch_id'     => 'nullable|exists:branches,id',
            'status'        => 'required|in:active,pending,suspended',
            'password'      => 'nullable|string|min:8',
            'company_id'    => 'nullable|exists:companies,company_id',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        $before = $user->toArray();
        $data = $request->only('name', 'email', 'phone', 'role', 'department_id', 'branch_id', 'status');
        $admin = Auth::guard('web')->user();
        if ($admin->hasRole('system_admin')) {
            $data['company_id'] = $request->input('company_id') ?? $user->company_id;
        }

        if ($request->filled('password')) {
            $data['password'] = $request->password;
        }

        $user->update($data);
        AuditLogger::log('admin_update_user', 'users', $user->id, $before, $user->fresh()->toArray());

        return back()->with('success', "User account updated successfully.");
    }

    /**
     * Admin: Delete a user account.
     */
    public function deleteUser($id)
    {
        $user = User::findOrFail($id);

        if ($user->role === 'ceo') {
            return back()->with('error', 'Cannot delete a CEO account.');
        }

        $before = $user->toArray();
        $user->delete();
        AuditLogger::log('admin_delete_user', 'users', $id, $before, null);

        return back()->with('success', "User account deleted.");
    }

    // ══════════════════════════════════════════════════════
    // NEW ADMIN MODULE METHODS
    // ══════════════════════════════════════════════════════

    /** Admin: Save company settings */
    public function saveCompanySettings(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'company_name' => 'required|string|max:255',
            'branch_name'  => 'nullable|string',
            'address'      => 'nullable|string',
            'phone'        => 'nullable|string',
            'email'        => 'nullable|email',
            'website'      => 'nullable|string|max:255',
            'timezone'     => 'nullable|string|max:64',
            'date_format'  => 'nullable|string|max:32',
        ]);
        if ($validator->fails()) return back()->withErrors($validator);

        $setting = \App\Models\CompanySetting::firstOrNew([]);
        $setting->fill($request->only('company_name','branch_name','address','phone','email','website','timezone','date_format'));
        $setting->save();

        // Invalidate the cached timezone resolved by ApplyCompanyTimezone so
        // the new value takes effect on the next request.
        \Illuminate\Support\Facades\Cache::forget(\App\Http\Middleware\ApplyCompanyTimezone::TIMEZONE_CACHE_KEY);

        // Apply timezone immediately for the current request
        $tz = $setting->timezone ?? 'Africa/Addis_Ababa';
        try {
            date_default_timezone_set($tz);
            \Carbon\Carbon::setTimezone($tz);
        } catch (\Exception $e) {}

        AuditLogger::log('company_settings_updated', 'company_settings', $setting->id);
        return back()->with('success', 'Company settings saved. Timezone applied: ' . $tz);
    }

    /**
     * Strip stored secrets from integration records before they are sent to
     * the frontend so the Twilio Auth Token is never exposed to the browser.
     */
    private function sanitizeIntegrations($integrations)
    {
        return $integrations->map(function ($integration) {
            $item = is_array($integration) ? $integration : $integration->toArray();

            if (isset($item['config'])) {
                $config = is_string($item['config']) ? json_decode($item['config'], true) : $item['config'];
                if (is_array($config)) {
                    unset($config['auth_token']);
                    unset($config['api_key']);
                    $item['config'] = $config;
                }
            }

            return $item;
        });
    }

    /** Admin: Save SMS gateway integration settings */
    public function saveSmsIntegration(Request $request)
    {
        $provider = $request->input('provider', 'webhook');

        $validator = Validator::make($request->all(), [
            'provider'     => 'required|in:twilio,webhook,smsethiopia',
            'account_sid'  => 'nullable|string|max:255',
            'auth_token'   => 'nullable|string|max:255',
            'from_number'  => 'nullable|string|max:64',
            'webhook_url'  => 'nullable|url|max:500',
            'api_key'      => 'nullable|string|max:255',
            'status'       => 'boolean',
        ]);
        if ($validator->fails()) return back()->withErrors($validator);

        $integration = \App\Models\SystemIntegration::where('type', 'sms')->first();
        $existingConfig = [];
        if ($integration) {
            $decoded = is_string($integration->config) ? json_decode($integration->config, true) : $integration->config;
            if (is_array($decoded)) {
                $existingConfig = $decoded;
            }
        }

        $config = [
            'provider'    => $provider,
            'account_sid' => trim($request->input('account_sid') ?? ''),
            'auth_token'  => trim($request->input('auth_token') ?? ''),
            'from_number' => trim($request->input('from_number') ?? ''),
            'webhook_url' => trim($request->input('webhook_url') ?? ''),
            'api_key'     => trim($request->input('api_key') ?? ''),
        ];

        if ($provider === 'twilio') {
            // The Auth Token is never sent back to the browser after saving, so
            // preserve the stored value when the admin saves without a new one.
            if ($config['auth_token'] === '' && !empty($existingConfig['auth_token'])) {
                $config['auth_token'] = $existingConfig['auth_token'];
            }
            if ($config['account_sid'] === '' && !empty($existingConfig['account_sid'])) {
                $config['account_sid'] = $existingConfig['account_sid'];
            }

            if ($config['account_sid'] === '' || $config['auth_token'] === '') {
                return back()->withErrors(['provider' => 'Twilio requires both Account SID and Auth Token. Enter the Auth Token to replace the saved one.'])->withInput();
            }
            if ($config['from_number'] === '') {
                return back()->withErrors(['from_number' => 'Twilio requires a From Number (e.g. +12025550123).'])->withInput();
            }
        } elseif ($provider === 'webhook' && $config['webhook_url'] === '') {
            return back()->withErrors(['provider' => 'Webhook requires a valid webhook URL.'])->withInput();
        } elseif ($provider === 'smsethiopia') {
            // The API Key is never sent back to the browser after saving, so
            // preserve the stored value when the admin saves without a new one.
            if ($config['api_key'] === '' && !empty($existingConfig['api_key'])) {
                $config['api_key'] = $existingConfig['api_key'];
            }

            if ($config['api_key'] === '') {
                return back()->withErrors(['api_key' => 'SMSEthiopia requires an API Key. Enter the API Key to replace the saved one.'])->withInput();
            }
        }

        if (!$integration) {
            $integration = \App\Models\SystemIntegration::create(['name' => 'SMS Gateway', 'type' => 'sms']);
        }
        $integration->config = json_encode($config);
        $integration->status = $request->boolean('status') ? 'active' : 'inactive';
        $integration->save();

        AuditLogger::log('sms_integration_updated', 'system_integrations', $integration->id, null, ['provider' => $provider]);
        return back()->with('success', 'SMS gateway settings saved (' . strtoupper($provider) . ').');
    }

    /** Admin: Send a live SMS test through the configured gateway */
    public function testSmsIntegration(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'test_phone' => 'required|string|max:32',
        ]);
        if ($validator->fails()) return back()->withErrors($validator);

        $phone  = $request->input('test_phone');
        $result = \App\Services\SmsService::sendWithResult($phone, 'VMS test message: SMS gateway is working.');
        $status = $result['status'] ?? 'skipped';
        $reason = trim((string) ($result['reason'] ?? ''));

        if ($status === 'sent') {
            return back()->with('success', 'Test SMS sent successfully.');
        }

        if ($status === 'failed') {
            return back()->with('error', 'Test SMS failed' . ($reason !== '' ? ': ' . $reason : '') . '.');
        }

        return back()->with('error', 'SMS is not configured.');
    }

    /** Admin: Save working hours */
    public function saveWorkingHours(Request $request)
    {
        $timeRule = ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/'];

        $validator = Validator::make($request->all(), [
            'hours'                  => 'required|array',
            'hours.*.day_of_week'    => 'required|integer|between:0,6',
            'hours.*.is_working_day' => 'nullable|boolean',
            'hours.*.open_time'      => $timeRule,
            'hours.*.close_time'     => $timeRule,
        ]);

        if ($validator->fails()) return back()->withErrors($validator);

        $hours = $request->input('hours', []);
        foreach ($hours as $h) {
            $day = $h['day_of_week'] ?? null;
            if ($day === null) continue;
            \App\Models\WorkingHour::where('day_of_week', $day)->update([
                'is_working_day' => (bool)($h['is_working_day'] ?? false),
                'open_time'      => $h['open_time']  ?? '08:00:00',
                'close_time'     => $h['close_time'] ?? '19:00:00',
            ]);
        }
        AuditLogger::log('working_hours_updated', 'working_hours');
        return back()->with('success', 'Working hours updated.');
    }

    /** Admin: Visitor categories */
    public function storeVisitorCategory(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name'              => 'required|string|max:100',
            'color'             => 'nullable|string|max:32',
            'requires_approval' => 'boolean',
            'auto_approve'      => 'boolean',
            'description'       => 'nullable|string|max:255',
        ]);
        if ($validator->fails()) return back()->withErrors($validator);
        $cat = \App\Models\VisitorCategory::create($request->only('name','color','requires_approval','auto_approve','description'));
        AuditLogger::log('visitor_category_created', 'visitor_categories', $cat->id);
        return back()->with('success', 'Visitor category created.');
    }

    public function updateVisitorCategory(Request $request, $id)
    {
        $cat = \App\Models\VisitorCategory::findOrFail($id);
        $validator = Validator::make($request->all(), [
            'name'              => 'required|string|max:100',
            'color'             => 'nullable|string|max:32',
            'requires_approval' => 'boolean',
            'auto_approve'      => 'boolean',
            'description'       => 'nullable|string|max:255',
        ]);
        if ($validator->fails()) return back()->withErrors($validator);
        $cat->update($request->only('name','color','requires_approval','auto_approve','description'));
        AuditLogger::log('visitor_category_updated', 'visitor_categories', $cat->id);
        return back()->with('success', 'Visitor category updated.');
    }

    public function deleteVisitorCategory($id)
    {
        $cat = \App\Models\VisitorCategory::findOrFail($id);
        $cat->delete();
        AuditLogger::log('visitor_category_deleted', 'visitor_categories', $id);
        return back()->with('success', 'Visitor category deleted.');
    }

    /** Admin: Watchlist */
    public function storeWatchlist(Request $request)
    {
        $admin = Auth::guard('web')->user();
        $validator = Validator::make($request->all(), [
            'name'           => 'required|string|max:255',
            'id_document_no' => 'required|digits:6|unique:watchlist,id_document_no',
            'reason'         => 'required|string|max:1000',
            'level'          => 'required|in:caution,high,critical',
        ]);
        if ($validator->fails()) return back()->withErrors($validator);
        $w = \App\Models\Watchlist::create([...$request->only('name','id_document_no','reason','level'), 'added_by' => $admin->id]);
        AuditLogger::log('watchlist_entry_added', 'watchlist', $w->id);
        return back()->with('success', 'Person added to watchlist.');
    }

    public function deleteWatchlist($id)
    {
        \App\Models\Watchlist::findOrFail($id)->delete();
        AuditLogger::log('watchlist_entry_removed', 'watchlist', $id);
        return back()->with('success', 'Person removed from watchlist.');
    }

    /** Admin: Badge templates */
    public function storeBadgeTemplate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name'  => 'required|string|max:100',
            'type'  => 'required|in:visitor,vip,contractor',
            'color' => 'nullable|string|max:32',
            'show_photo' => 'boolean',
            'show_qr'    => 'boolean',
            'is_default' => 'boolean',
        ]);
        if ($validator->fails()) return back()->withErrors($validator);
        $bt = \App\Models\BadgeTemplate::create($request->only('name','type','color','show_photo','show_qr','is_default'));
        AuditLogger::log('badge_template_created', 'badge_templates', $bt->id);
        return back()->with('success', 'Badge template created.');
    }

    public function deleteBadgeTemplate($id)
    {
        \App\Models\BadgeTemplate::findOrFail($id)->delete();
        return back()->with('success', 'Badge template deleted.');
    }

    /** Admin: Notification templates */
    public function saveNotificationTemplate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'event'     => 'required|string|max:100',
            'channel'   => 'required|in:email,sms,in_app',
            'subject'   => 'nullable|string|max:190',
            'body'      => 'required|string|max:5000',
            'is_active' => 'boolean',
        ]);
        if ($validator->fails()) return back()->withErrors($validator);
        $nt = \App\Models\NotificationTemplate::updateOrCreate(
            ['event' => $request->event, 'channel' => $request->channel],
            $request->only('subject','body','is_active')
        );
        AuditLogger::log('notification_template_saved', 'notification_templates', $nt->id);
        return back()->with('success', 'Notification template saved.');
    }

    public function deleteNotificationTemplate($id)
    {
        \App\Models\NotificationTemplate::findOrFail($id)->delete();
        return back()->with('success', 'Notification template deleted.');
    }

    /** Admin: Export visits CSV */
    public function adminExportVisits()
    {
        $admin = Auth::guard('web')->user();
        if (!$admin || $admin->role !== 'admin') abort(403);
        $visits = VisitRequest::with('visitor','host.department','department')->orderBy('id')->get();
        $filename = 'admin-visits-'.now()->format('Y-m-d').'.csv';
        AuditLogger::log('admin_export_visits', 'visit_requests');
        return response()->stream(function() use ($visits) {
            $h = fopen('php://output','w');
            fputcsv($h, ['ID','Visitor','Email','Host','Dept','Purpose','Date','Duration','Status','Comments']);
            foreach ($visits as $v) {
                fputcsv($h, [$v->id, $v->visitor->name??'', $v->visitor->email??'', $v->host->name??'', $v->host->department->name??'', $v->purpose, $v->requested_time?->format('Y-m-d H:i'), $v->duration, $v->status, $v->comments??'']);
            }
            fclose($h);
        }, 200, ['Content-Type'=>'text/csv','Content-Disposition'=>"attachment; filename=\"$filename\""]);
    }

    /** Admin: Export audit CSV */
    public function adminExportAudit()
    {
        $admin = Auth::guard('web')->user();
        if (!$admin || $admin->role !== 'admin') abort(403);
        $logs = \App\Models\AuditLog::orderBy('id')->get();
        $filename = 'admin-audit-'.now()->format('Y-m-d').'.csv';
        AuditLogger::log('admin_export_audit', 'audit_logs');
        return response()->stream(function() use ($logs) {
            $h = fopen('php://output','w');
            fputcsv($h, ['Timestamp','Actor Type','Actor ID','Action','Entity','Entity ID','IP']);
            foreach ($logs as $l) {
                fputcsv($h, [$l->created_at->format('Y-m-d H:i:s'), $l->actor_type??'', $l->actor_id??'', $l->action, $l->entity??'', $l->entity_id??'', $l->ip_address??'']);
            }
            fclose($h);
        }, 200, ['Content-Type'=>'text/csv','Content-Disposition'=>"attachment; filename=\"$filename\""]);
    }

    /** Admin: Export staff CSV */
    public function adminExportStaff()
    {
        $admin = Auth::guard('web')->user();
        if (!$admin || $admin->role !== 'admin') abort(403);
        $staff = User::with('department')->orderBy('id')->get();
        $filename = 'admin-staff-'.now()->format('Y-m-d').'.csv';
        return response()->stream(function() use ($staff) {
            $h = fopen('php://output','w');
            fputcsv($h, ['ID','Name','Email','Phone','Role','Department','Status']);
            foreach ($staff as $s) {
                fputcsv($h, [$s->id, $s->name, $s->email, $s->phone??'', $s->role, $s->department->name??'', $s->status]);
            }
            fclose($h);
        }, 200, ['Content-Type'=>'text/csv','Content-Disposition'=>"attachment; filename=\"$filename\""]);
    }

    /** Admin: Emergency broadcast */
    public function adminBroadcast(Request $request)
    {
        $admin = Auth::guard('web')->user();
        if (!$admin || $admin->role !== 'admin') abort(403);
        $validator = Validator::make($request->all(), [
            'message'  => 'required|string|max:500',
            'priority' => 'required|in:info,warning,critical',
        ]);
        if ($validator->fails()) return back()->withErrors($validator);

        // Broadcast is scoped to the admin's own company (and their branch if one
        // is assigned) so it never reaches other companies or branches.
        \App\Services\SecurityIncidentService::broadcast([
            'company_id' => $admin->company_id,
            'branch_id'  => $admin->branch_id,
            'scope_type' => $admin->branch_id ? 'branch' : 'company',
            'message'    => $request->message,
            'priority'   => $request->priority,
        ], $admin, 'Admin Broadcast [' . strtoupper($request->priority) . ']');

        AuditLogger::log('admin_broadcast','announcements',null,null,['message'=>$request->message,'priority'=>$request->priority]);
        return back()->with('success','Broadcast sent to all active employees.');
    }

    /**
     * Admin: Save SLA Settings.
     */
    public function saveSlaSettings(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'sla_minutes'            => 'required|integer|min:1|max:120',
            'admin_sla_minutes'      => 'nullable|integer|min:1|max:1440',
            'auto_escalation_enabled'=> 'nullable|boolean',
            'sla_warning_minutes'    => 'nullable|integer|min:0|max:1000',
            'sla_pause_off_hours'    => 'nullable|boolean',
            'sla_count_weekends'     => 'nullable|boolean',
            'sla_count_holidays'     => 'nullable|boolean',
            'sla_notify_in_app'      => 'nullable|boolean',
            'sla_notify_email'       => 'nullable|boolean',
            'sla_notify_sms'         => 'nullable|boolean',
            'allow_weekends'         => 'nullable|boolean',
            'enforce_biz_hours'      => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator);
        }

        $far = now()->addYears(10)->timestamp;

        // SLA timeout is stored per company so each company's policy is
        // enforced independently across all of its branches.
        $admin   = Auth::guard('web')->user();
        $payload = $this->slaPolicyPayload($request, CompanyPolicy::forCompany($admin?->company_id));
        if ($admin && $admin->company_id) {
            CompanyPolicy::updateOrCreate(
                ['company_id' => $admin->company_id],
                $payload
            );
        }

        DB::table('cache')->updateOrInsert(
            ['key' => 'policy_allow_weekends'],
            ['value' => filter_var($request->allow_weekends ?? true, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false', 'expiration' => $far]
        );
        DB::table('cache')->updateOrInsert(
            ['key' => 'policy_enforce_biz_hours'],
            ['value' => filter_var($request->enforce_biz_hours ?? true, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false', 'expiration' => $far]
        );

        app(\App\Services\SlaService::class)->flushMemo();

        AuditLogger::log('admin_sla_settings_update', 'cache', null, null, [
            'sla_minutes'       => (int)$request->sla_minutes,
            'allow_weekends'    => filter_var($request->allow_weekends ?? true, FILTER_VALIDATE_BOOLEAN),
            'enforce_biz_hours' => filter_var($request->enforce_biz_hours ?? true, FILTER_VALIDATE_BOOLEAN),
        ] + $payload);

        return back()->with('success', 'SLA settings saved. Auto-escalation will now process every ' . (int)$request->sla_minutes . ' minute(s).');
    }

    /**
     * Store an SLA holiday (CEO / Admin). Holidays make a date non-working
     * for SLA calculations when the company policy counts holidays
     * (sla_count_holidays = false pauses the SLA clock on those dates).
     */
    public function storeHoliday(Request $request)
    {
        $user = Auth::guard('web')->user();
        if (!$user || !in_array($user->role, ['ceo', 'admin', 'system_admin'], true)) {
            return back()->with('error', 'You are not allowed to manage SLA holidays.');
        }

        $validator = Validator::make($request->all(), [
            'name'         => 'required|string|max:190',
            'holiday_date' => 'required|date',
            'company_id'   => 'nullable|exists:companies,company_id',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator);
        }

        // CEOs/Admins manage their own company's holidays; the system admin
        // may scope a holiday to any company or leave it global.
        if ($user->role === 'system_admin') {
            $companyId = $request->input('company_id');
        } else {
            $companyId = $user->company_id;
        }

        $holiday = Holiday::create([
            'name'         => $request->name,
            'holiday_date' => $request->holiday_date,
            'company_id'   => $companyId,
        ]);

        AuditLogger::log('sla_holiday_created', 'holidays', $holiday->id, null, $holiday->toArray());
        app(SlaService::class)->flushMemo();

        return back()->with('success', "Holiday \"{$holiday->name}\" added to the SLA calendar.");
    }

    /** Delete an SLA holiday. */
    public function destroyHoliday(Holiday $holiday)
    {
        $user = Auth::guard('web')->user();
        if (!$user || !in_array($user->role, ['ceo', 'admin', 'system_admin'], true)) {
            return back()->with('error', 'You are not allowed to manage SLA holidays.');
        }
        if ($user->role !== 'system_admin' && $holiday->company_id && $holiday->company_id !== $user->company_id) {
            return back()->with('error', 'This holiday belongs to another company.');
        }

        $before = $holiday->toArray();
        $holiday->delete();

        AuditLogger::log('sla_holiday_deleted', 'holidays', $holiday->id, $before, null);
        app(SlaService::class)->flushMemo();

        return back()->with('success', 'Holiday removed from the SLA calendar.');
    }

    /** Holidays visible to a user: their company's + global entries. */
    private function slaHolidaysFor(?int $companyId)
    {
        return Holiday::with('company')
            ->where(function ($q) use ($companyId) {
                $q->whereNull('company_id');
                if ($companyId) {
                    $q->orWhere('company_id', $companyId);
                }
            })
            ->orderBy('holiday_date')
            ->get()
            ->map(fn ($h) => [
                'id'           => $h->id,
                'name'         => $h->name,
                'holiday_date' => $h->holiday_date->format('Y-m-d'),
                'company_id'   => $h->company_id,
                'scope'        => $h->company_id ? 'company' : 'global',
            ]);
    }

    /**
     * Build the CompanyPolicy column payload from a settings request, keeping
     * the previously stored value for every field the form did not submit.
     */
    private function slaPolicyPayload(Request $request, CompanyPolicy $existing): array
    {
        $bool = function (string $field, bool $current) use ($request): bool {
            return $request->has($field)
                ? filter_var($request->input($field), FILTER_VALIDATE_BOOLEAN)
                : $current;
        };

        return [
            'sla_minutes'             => (int) $request->input('sla_minutes', $existing->sla_minutes ?? CompanyPolicy::DEFAULT_SLA_MINUTES),
            'admin_sla_minutes'       => $request->filled('admin_sla_minutes') ? (int) $request->admin_sla_minutes : $existing->admin_sla_minutes,
            'auto_escalation_enabled' => $bool('auto_escalation_enabled', (bool) ($existing->auto_escalation_enabled ?? true)),
            'sla_warning_minutes'     => $request->filled('sla_warning_minutes') ? (int) $request->sla_warning_minutes : (int) ($existing->sla_warning_minutes ?? 5),
            'sla_pause_off_hours'     => $bool('sla_pause_off_hours', (bool) ($existing->sla_pause_off_hours ?? false)),
            'sla_count_weekends'      => $bool('sla_count_weekends', (bool) ($existing->sla_count_weekends ?? true)),
            'sla_count_holidays'      => $bool('sla_count_holidays', (bool) ($existing->sla_count_holidays ?? true)),
            'sla_notify_in_app'       => $bool('sla_notify_in_app', (bool) ($existing->sla_notify_in_app ?? true)),
            'sla_notify_email'        => $bool('sla_notify_email', (bool) ($existing->sla_notify_email ?? true)),
            'sla_notify_sms'          => $bool('sla_notify_sms', (bool) ($existing->sla_notify_sms ?? false)),
        ];
    }

    /** Admin: Update visit appointment date & time */
    public function adminUpdateVisitTime(Request $request, $id)
    {
        $visitRequest = VisitRequest::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'requested_time' => 'required|date_format:Y-m-d\TH:i',
            'duration'       => 'nullable|integer|min:15|max:480',
        ]);
        if ($validator->fails()) {
            return back()->withErrors($validator);
        }

        $newDuration = (int) ($request->input('duration') ?: $visitRequest->duration ?: 60);

        if ($violation = VisitRequestController::workingHoursViolation($request->input('requested_time'), $newDuration)) {
            return back()->withInput()->withErrors(['requested_time' => $violation])->with('error', $violation);
        }

        $actor       = Auth::guard('web')->user();
        $actorRole   = $actor ? ucfirst($actor->role) : 'Admin';
        $oldState    = $visitRequest->toArray();

        DB::transaction(function () use ($visitRequest, $request, $newDuration, $actor, $actorRole) {
            // Supersede any outstanding visitor/host reschedule request.
            $visitRequest->rescheduleRequests()->where('status', 'pending')->update(['status' => 'cancelled']);

            [$oldTime, $oldDuration] = app(\App\Services\RescheduleService::class)->apply(
                $visitRequest,
                $request->input('requested_time'),
                $newDuration
            );

            \App\Models\RescheduleRequest::create([
                'visit_request_id'   => $visitRequest->id,
                'requested_by'       => $actor->id ?? null,
                'requested_by_role'  => strtolower($actorRole),
                'old_requested_time' => $oldTime,
                'old_duration'       => $oldDuration,
                'new_requested_time' => $request->input('requested_time'),
                'new_duration'       => $newDuration,
                'reason'             => 'Updated by ' . $actorRole,
                'status'             => 'approved',
                'reviewed_by'        => $actor->id ?? null,
                'reviewed_at'        => now(),
            ]);
        });

        $visitRequest->refresh();

        AuditLogger::log('admin_update_time_duration', 'visit_requests', $visitRequest->id, $oldState, $visitRequest->toArray());

        $updatedWhen = \Carbon\Carbon::parse($visitRequest->requested_time)->format('M d, Y H:i');
        $visitor = $visitRequest->resolveVisitor();
        $visitorName = $visitor?->name ?? ($visitRequest->company_name ?: 'Visitor');

        if ($visitor) {
            NotificationService::send(
                'Appointment Updated',
                $visitor,
                "Your visit appointment has been updated by the {$actorRole} to {$updatedWhen} ({$visitRequest->duration} min). Please review."
            );
        }

        if ($visitRequest->host) {
            NotificationService::send(
                'Appointment Updated',
                $visitRequest->host,
                "Visit appointment for {$visitorName} has been updated by the {$actorRole} to {$updatedWhen} ({$visitRequest->duration} min)."
            );
        }

        return back()->with('success', 'Appointment date & time updated successfully.');
    }

    /**
     * Map a visit request to the shared Admin Appointments / Live Monitor row.
     *
     * Escalated/transferred visits resolve to their currently assigned host
     * (escalated_to first — the new responsible person — falling back to host_id),
     * while preserving all original visitor, appointment and location details.
     */
    private function mapVisitForAdmin(VisitRequest $v): array
    {
        $assignedHost   = $v->escalatedTo ?? $v->host;
        $visitor        = $v->resolveVisitor();
        $lastCheckin    = $v->checkInOutLogs->sortByDesc('id')->first();
        $lastEscalation = $v->escalations->sortByDesc('id')->first();

        return [
            'id'                  => $v->id,
            'visitor_name'        => $visitor?->name ?? $v->company_name ?? '',
            'visitor_email'       => $visitor?->email ?? $v->company_email ?? '',
            'visitor_phone'       => $visitor?->phone ?? $v->company_phone ?? '',
            'id_document_no'      => $visitor?->id_document_no ?? '',
            'visitor_photo'       => $visitor?->photo_url ?? '',
            'visitor_company_name'=> $visitor?->company?->company_name ?? $visitor?->company_name ?? $v->company_name ?? '',
            'host_id'             => $v->host_id,
            'assigned_host_id'    => $assignedHost?->id,
            'host_name'           => $assignedHost?->name ?? $v->host?->name ?? '',
            'host_department'     => $assignedHost?->department?->name ?? $v->department?->name ?? '',
            'host_company'        => $assignedHost?->company?->company_name ?? $v->company?->company_name ?? '',
            'host_branch'         => $assignedHost?->branch?->name ?? $v->branch?->name ?? '',
            'original_host_id'    => $v->host_id,
            'original_host_name'  => $v->host?->name ?? '',
            'company_id'          => $v->company_id,
            'company_name'        => $v->company?->company_name ?? '',
            'branch_id'           => $v->branch_id,
            'branch_name'         => $v->branch?->name ?? '',
            'department_name'     => $v->department?->name ?? '',
            'location_id'         => $v->location_id,
            'location_name'       => $v->location?->name ?? '',
            'purpose'             => $v->purpose,
            'requested_time'      => $v->requested_time ? $v->requested_time->format('Y-m-d\TH:i') : '',
            'duration'            => $v->duration,
            'status'              => $v->status,
            'visit_type'          => $v->visit_type ?? 'individual',
            'building'            => $v->building ?? '',
            'floor'               => $v->floor ?? '',
            'meeting_room_id'     => $v->meeting_room_id ?? '',
            'meeting_started_at'  => $v->meeting_started_at ? $v->meeting_started_at->format('Y-m-d\TH:i') : '',
            'meeting_ended_at'    => $v->meeting_ended_at ? $v->meeting_ended_at->format('Y-m-d\TH:i') : '',
            'checkin_time'        => $lastCheckin?->checkin_time ? $lastCheckin->checkin_time->format('Y-m-d\TH:i') : '',
            'escalated_at'        => $lastEscalation?->created_at ? $lastEscalation->created_at->format('Y-m-d\TH:i') : '',
            'escalated_reason'    => $lastEscalation?->reason ?? '',
            'comments'            => $v->comments ?? '',
        ] + app(SlaService::class)->decorate($v);
    }

    /**
     * Admin: Department Management.
     */
    public function storeDepartment(Request $request)
    {
        $admin = Auth::guard('web')->user();
        $companyId = $admin->hasRole('system_admin')
            ? ($request->input('company_id') ?? null)
            : $admin->company_id;

        $validator = Validator::make($request->all(), [
            'name'           => [
                'required', 'string',
                Rule::unique('departments', 'name')->where(function ($q) use ($companyId, $request) {
                    $companyId ? $q->where('company_id', $companyId) : $q->whereNull('company_id');
                    if ($request->filled('branch_id')) {
                        $q->where('branch_id', $request->branch_id);
                    } else {
                        $q->whereNull('branch_id');
                    }
                }),
            ],
            'capacity_limit' => 'required|integer|min:1',
            'parent_id'      => 'nullable|exists:departments,id',
            'branch_id'      => 'nullable|exists:branches,id',
            'company_id'     => 'nullable|exists:companies,company_id',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator);
        }

        $dept = Department::create(array_merge(
            $request->only('name', 'capacity_limit', 'parent_id', 'branch_id'),
            ['company_id' => $companyId]
        ));
        AuditLogger::log('create_department', 'departments', $dept->id, null, $dept->toArray());

        return back()->with('success', 'Department created successfully.');
    }

    public function updateDepartment(Request $request, $id)
    {
        $dept = Department::findOrFail($id);
        $admin = Auth::guard('web')->user();
        $companyId = $admin->hasRole('system_admin')
            ? ($request->input('company_id') ?? $dept->company_id)
            : $admin->company_id;

        $validator = Validator::make($request->all(), [
            'name'           => [
                'required', 'string',
                Rule::unique('departments', 'name')->ignore($dept->id)->where(function ($q) use ($companyId, $request) {
                    $companyId ? $q->where('company_id', $companyId) : $q->whereNull('company_id');
                    if ($request->filled('branch_id')) {
                        $q->where('branch_id', $request->branch_id);
                    } else {
                        $q->whereNull('branch_id');
                    }
                }),
            ],
            'capacity_limit' => 'required|integer|min:1',
            'manager_id'     => 'nullable|exists:users,id',
            'parent_id'      => 'nullable|exists:departments,id',
            'branch_id'      => 'nullable|exists:branches,id',
            'company_id'     => 'nullable|exists:companies,company_id',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator);
        }

        $data = $request->only('name', 'capacity_limit', 'manager_id', 'parent_id', 'branch_id');
        if ($admin->hasRole('system_admin')) {
            $data['company_id'] = $request->input('company_id') ?? $dept->company_id;
        }

        $before = $dept->toArray();
        $dept->update($data);
        
        AuditLogger::log('update_department', 'departments', $dept->id, $before, $dept->toArray());

        return back()->with('success', 'Department updated successfully.');
    }

    public function deleteDepartment($id)
    {
        $dept = Department::findOrFail($id);

        // Detach child departments (move them to top level)
        Department::where('parent_id', $dept->id)->update(['parent_id' => null]);

        // Detach manager assignments on other departments pointing to this one as manager
        Department::where('manager_id', $dept->id)->update(['manager_id' => null]);

        // Remove user department associations
        User::where('department_id', $dept->id)->update(['department_id' => null]);

        $before = $dept->toArray();
        $dept->delete();

        AuditLogger::log('delete_department', 'departments', $id, $before, null);

        return back()->with('success', 'Department deleted successfully.');
    }

    /**
     * Admin: Branch Management.
     */
    public function storeBranch(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'location_id' => 'nullable|exists:locations,id',
            'name'        => 'required|string|unique:branches,name',
            'description' => 'nullable|string',
            'is_active'   => 'nullable|boolean',
            'company_id'  => 'nullable|exists:companies,company_id',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator);
        }

        $admin = Auth::guard('web')->user();
        $companyId = $admin->hasRole('system_admin')
            ? ($request->input('company_id') ?? Location::find($request->location_id)?->company_id)
            : $admin->company_id;

        $branch = Branch::create([
            'company_id'  => $companyId,
            'location_id' => $request->location_id,
            'name'        => $request->name,
            'slug'        => \Illuminate\Support\Str::slug($request->name),
            'description' => $request->description,
            'is_active'   => $request->is_active ?? true,
        ]);

        AuditLogger::log('create_branch', 'branches', $branch->id, null, $branch->toArray());

        return back()->with('success', 'Branch created successfully.');
    }

    public function updateBranch(Request $request, $id)
    {
        $branch = Branch::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'location_id' => 'nullable|exists:locations,id',
            'name'        => 'required|string|unique:branches,name,' . $branch->id,
            'description' => 'nullable|string',
            'is_active'   => 'nullable|boolean',
            'company_id'  => 'nullable|exists:companies,company_id',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator);
        }

        $admin = Auth::guard('web')->user();
        $data = [
            'location_id' => $request->location_id,
            'name'        => $request->name,
            'slug'        => \Illuminate\Support\Str::slug($request->name),
            'description' => $request->description,
            'is_active'   => $request->is_active,
        ];
        if ($admin->hasRole('system_admin')) {
            $data['company_id'] = $request->input('company_id') ?? $branch->company_id;
        }

        $before = $branch->toArray();
        $branch->update($data);

        AuditLogger::log('update_branch', 'branches', $branch->id, $before, $branch->toArray());

        return back()->with('success', 'Branch updated successfully.');
    }

    public function deleteBranch($id)
    {
        $branch = Branch::findOrFail($id);

        // Detach related departments, users, visit requests
        Department::where('branch_id', $branch->id)->update(['branch_id' => null]);
        User::where('branch_id', $branch->id)->update(['branch_id' => null]);
        VisitRequest::where('branch_id', $branch->id)->update(['branch_id' => null]);

        $before = $branch->toArray();
        $branch->delete();

        AuditLogger::log('delete_branch', 'branches', $id, $before, null);

        return back()->with('success', 'Branch deleted successfully.');
    }

    /**
     * Admin: Location Management.
     */
    public function storeLocation(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name'         => 'required|string|unique:locations,name',
            'company_name' => 'nullable|string|max:255',
            'address'      => 'nullable|string|max:255',
            'floor'        => 'nullable|string|max:50',
            'phone'        => 'nullable|string|max:50',
            'is_active'    => 'nullable|boolean',
            'company_id'   => 'nullable|exists:companies,company_id',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator);
        }

        $admin = Auth::guard('web')->user();
        $companyId = $admin->hasRole('system_admin')
            ? ($request->input('company_id') ?? null)
            : $admin->company_id;

        $location = Location::create([
            'name'         => $request->name,
            'company_name' => $request->company_name,
            'address'      => $request->address,
            'floor'        => $request->floor,
            'phone'        => $request->phone,
            'is_active'    => $request->boolean('is_active', true),
            'company_id'   => $companyId,
        ]);

        AuditLogger::log('create_location', 'locations', $location->id, null, $location->toArray());

        return back()->with('success', 'Location created successfully.');
    }

    public function updateLocation(Request $request, $id)
    {
        $location = Location::findOrFail($id);
        $validator = Validator::make($request->all(), [
            'name'         => 'required|string|unique:locations,name,' . $location->id,
            'company_name' => 'nullable|string|max:255',
            'address'      => 'nullable|string|max:255',
            'floor'        => 'nullable|string|max:50',
            'phone'        => 'nullable|string|max:50',
            'is_active'    => 'nullable|boolean',
            'company_id'   => 'nullable|exists:companies,company_id',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator);
        }

        $admin = Auth::guard('web')->user();
        $data = [
            'name'         => $request->name,
            'company_name' => $request->company_name,
            'address'      => $request->address,
            'floor'        => $request->floor,
            'phone'        => $request->phone,
            'is_active'    => $request->boolean('is_active', true),
        ];
        if ($admin->hasRole('system_admin')) {
            $data['company_id'] = $request->input('company_id') ?? $location->company_id;
        }

        $before = $location->toArray();
        $location->update($data);

        AuditLogger::log('update_location', 'locations', $location->id, $before, $location->toArray());

        return back()->with('success', 'Location updated successfully.');
    }

    public function deleteLocation($id)
    {
        $location = Location::findOrFail($id);

        // Move departments under this location to no location
        Department::where('location_id', $id)->update(['location_id' => null]);

        $before = $location->toArray();
        $location->delete();

        AuditLogger::log('delete_location', 'locations', $id, $before, null);

        return back()->with('success', 'Location deleted successfully.');
    }

    /**
     * Admin: Blacklist Management.
     */
    public function storeBlacklist(Request $request)
    {
        $admin = Auth::guard('web')->user();
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'id_document_no' => 'required|digits:6|unique:blacklist,id_document_no',
            'reason' => 'required|string|max:1000',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator);
        }

        $blacklist = Blacklist::create([
            'name' => $request->name,
            'id_document_no' => $request->id_document_no,
            'reason' => $request->reason,
            'added_by' => $admin->id,
        ]);

        AuditLogger::log('add_blacklist', 'blacklist', $blacklist->id, null, $blacklist->toArray());

        NotificationService::send('Blacklist Alert', 'Security Guard',
            "SECURITY ALERT: A new blacklist entry has been added by {$admin->name}. Name: {$request->name}, ID: {$request->id_document_no}. Reason: {$request->reason}. All pending visits for this ID have been auto-rejected.",
            null, false, $admin->company_id);

        // Re-check pending requests and auto-reject if matching
        $pendingMatches = VisitRequest::where('status', 'pending')
            ->whereHas('visitor', function($q) use ($blacklist) {
                $q->where('id_document_no', $blacklist->id_document_no);
            })->get();

        foreach ($pendingMatches as $req) {
            $req->update([
                'status' => 'rejected',
                'comments' => 'Automatically rejected: ID added to blacklist.'
            ]);
            NotificationService::send('Registration Approved / Rejected', $req->visitor, 'Your visit request was cancelled as your document number matches a blacklisted entry.');
        }

        return back()->with('success', 'Blacklist record added successfully. All matching pending requests auto-declined.');
    }

    public function deleteBlacklist($id)
    {
        $record = Blacklist::findOrFail($id);
        $before = $record->toArray();
        $record->delete();

        AuditLogger::log('delete_blacklist', 'blacklist', $id, $before, null);

        NotificationService::send('Blacklist Alert', 'Security Guard',
            "A blacklist entry has been removed. Name: {$before['name']}, ID: {$before['id_document_no']}. This visitor is no longer blocked from the premises.",
            null, false, Auth::guard('web')->user()->company_id ?? null);

        return back()->with('success', 'Visitor removed from blacklist. Security team notified.');
    }

    public function deleteAuditLog($id)
    {
        $log = AuditLog::findOrFail($id);
        $log->delete();

        AuditLogger::log('audit_log_deleted', 'audit_logs', $id, null, null);

        return back()->with('success', 'Audit record deleted.');
    }

    public function deleteAllAuditLogs()
    {
        $count = AuditLog::count();
        AuditLog::query()->delete();

        AuditLogger::log('audit_logs_cleared', 'audit_logs', null, null, ['deleted' => $count]);

        return back()->with('success', $count . ' audit record(s) deleted.');
    }

    public function deleteIncident($id)
    {
        $ceo = Auth::guard('web')->user();
        if (!$ceo || $ceo->role !== 'ceo') {
            return $this->redirectToOwnDashboard($ceo);
        }

        $incident = Incident::findOrFail($id);
        $before = $incident->toArray();
        $incident->delete();

        AuditLogger::log('incident_deleted', 'incidents', $id, $before, null);

        return back()->with('success', 'Incident #' . $id . ' deleted.');
    }

    public function deleteAllIncidents()
    {
        $ceo = Auth::guard('web')->user();
        if (!$ceo || $ceo->role !== 'ceo') {
            return $this->redirectToOwnDashboard($ceo);
        }

        $count = Incident::whereIn('status', ['open', 'investigating'])->delete();

        AuditLogger::log('incidents_cleared', 'incidents', null, null, ['deleted' => $count]);

        return back()->with('success', $count . ' active incident(s) deleted.');
    }

    /**
     * CEO: Delete all broadcasts for the CEO's company.
     */
    public function deleteAllBroadcasts()
    {
        $ceo = Auth::guard('web')->user();
        if (!$ceo || $ceo->role !== 'ceo') {
            return $this->redirectToOwnDashboard($ceo);
        }

        $count = \App\Models\Announcement::where('company_id', $ceo->company_id)->delete();

        AuditLogger::log('broadcasts_cleared', 'announcements', null, null, ['deleted' => $count]);

        return back()->with('success', $count . ' broadcast(s) deleted.');
    }

    /**
     * Render Manager Dashboard.
     */
    public function managerDashboard()
    {
        $manager = Auth::guard('web')->user();
        $dept = Department::where('manager_id', $manager->id)->first();

        if (!$dept) {
            return Inertia::render('Dashboard/Manager', [
                'error' => 'You are not assigned as manager of any department yet.'
            ]);
        }

        // 1. Get department visit requests and escalations
        $escalations = VisitRequest::where('status', 'escalated')
            ->where('escalated_to', $manager->id)
            ->with('visitor', 'host')
            ->get();

        $departmentVisits = VisitRequest::whereHas('host', function($q) use ($dept) {
            $q->where('department_id', $dept->id);
        })->with('visitor', 'host')->get();

        // 2. Department specific statistics
        $activeVisitors = VisitRequest::where('status', 'checked_in')
            ->whereHas('host', function($q) use ($dept) {
                $q->where('department_id', $dept->id);
            })->count();

        // 3. Hosts list in department with their stats
        $hosts = User::where('department_id', $dept->id)
            ->where('role', 'host')
            ->get();

        $hostIds = $hosts->pluck('id');
        $hostTotals = VisitRequest::whereIn('host_id', $hostIds)
            ->selectRaw('host_id, COUNT(*) as c')->groupBy('host_id')->pluck('c', 'host_id');
        $hostApproved = VisitRequest::whereIn('host_id', $hostIds)
            ->where('status', 'approved')
            ->selectRaw('host_id, COUNT(*) as c')->groupBy('host_id')->pluck('c', 'host_id');

        $hosts = $hosts->map(function($host) use ($hostTotals, $hostApproved) {
            return [
                'id' => $host->id,
                'name' => $host->name,
                'email' => $host->email,
                'status' => $host->status,
                'total_visits' => (int) ($hostTotals[$host->id] ?? 0),
                'approved_visits' => (int) ($hostApproved[$host->id] ?? 0),
            ];
        });

        return Inertia::render('Dashboard/Manager', [
            'department_name' => $dept->name,
            'capacity_limit' => $dept->capacity_limit,
            'active_visitors_count' => $activeVisitors,
            'escalations' => $escalations,
            'department_visits' => $departmentVisits,
            'hosts' => $hosts,
        ]);
    }

    /**
     * Render Host Dashboard — full enterprise host module.
     */
    public function hostDashboard($section = null)
    {
        $host = Auth::guard('web')->user();
        $sla  = app(SlaService::class);

        // All my visits ordered by priority
        $visits = VisitRequest::where(function($q) use ($host) {
                $q->where('host_id', $host->id)->orWhere('alternate_host_id', $host->id);
            })
            ->with([
                // A host must see the visitor's identity even when the visitor's account
                // belongs to a different company (external/cross-company visitors). The
                // CompanyScope would otherwise filter the visitor out and null it out.
                'visitor' => fn($q) => $q->withoutGlobalScope(\App\Models\Scopes\CompanyScope::class),
                'qrToken', 'feedback', 'alternateHost', 'groupMembers', 'documents',
                'host.department', 'department',
                'category', 'escalatedTo',
                'escalations.escalatedBy', 'escalations.escalatedTo',
            ])
            ->with(['groupMembers' => function($q) { $q->with('properties'); }])
            ->orderByRaw("CASE WHEN status IN ('pending','escalated') THEN 0
                               WHEN status = 'approved' THEN 1
                               WHEN status = 'checked_in' THEN 2
                               ELSE 3 END ASC")
            ->orderByRaw("CASE WHEN priority = 'vip' THEN 0
                               WHEN priority = 'high' THEN 1
                               WHEN priority = 'normal' THEN 2
                               ELSE 3 END ASC")
            ->orderBy('requested_time', 'asc')
            ->get()
            ->map(function($visit) use ($sla) {
                // Format company/group visit data for Host Dashboard
                $visitArray = $visit->toArray();
                
                if ($visit->visit_type === 'company') {
                    $visitArray['company_info'] = [
                        'name' => $visit->company_name,
                        'email' => $visit->company_email,
                        'phone' => $visit->company_phone,
                        'address' => $visit->company_address,
                        'website' => $visit->company_website,
                    ];
                    
                    $visitArray['representative_info'] = [
                        'name' => $visit->visitor->name ?? '',
                        'email' => $visit->visitor->email ?? '',
                        'phone' => $visit->visitor->phone ?? '',
                        'id_document_no' => $visit->visitor->id_document_no ?? '',
                        'gender' => $visit->representative_gender,
                        'nationality' => $visit->representative_nationality,
                        'position' => $visit->representative_position,
                    ];
                    
                    $visitArray['groupMembers'] = $visit->groupMembers->map(function($member) {
                        return [
                            'id' => $member->id,
                            'name' => $member->name,
                            'id_document_no' => $member->id_document_no,
                            'email' => $member->email,
                            'phone' => $member->phone,
                            'gender' => $member->gender,
                            'nationality' => $member->nationality,
                            'position' => $member->position,
                            'qr_token' => $member->qr_token,
                            'qr_expires_at' => $member->qr_expires_at ? $member->qr_expires_at->toDateTimeString() : null,
                            'checked_in_at' => $member->checked_in_at ? $member->checked_in_at->toDateTimeString() : null,
                            'checked_out_at' => $member->checked_out_at ? $member->checked_out_at->toDateTimeString() : null,
                            'badge_status' => $member->badge_status,
                            'properties_count' => $member->properties->count(),
                            'is_checked_in' => $member->isCheckedIn(),
                            'is_checked_out' => $member->isFullyCheckedOut(),
                        ];
                    })->toArray();
                    
                    $visitArray['total_group_members'] = $visit->groupMembers->count();
                    $visitArray['checked_in_members'] = $visit->groupMembers->whereNotNull('checked_in_at')->whereNull('checked_out_at')->count();
                    $visitArray['checked_out_members'] = $visit->groupMembers->whereNotNull('checked_out_at')->count();
                    
                    $visitArray['documents'] = $visit->documents->map(function($doc) {
                        return [
                            'id' => $doc->id,
                            'type' => $doc->doc_type,
                            'file_path' => $doc->file_path,
                            'original_name' => $doc->original_name,
                        ];
                    })->toArray();
                }
                
                return $sla->decorateArray($visit, $visitArray);
            });

        // Today's stats
        $today = today();
        $todayVisits    = $visits->filter(fn($v) => \Carbon\Carbon::parse($v['requested_time'])->isToday());
        $todayPending   = $todayVisits->where('status', 'pending')->count();
        $todayApproved  = $todayVisits->where('status', 'approved')->count();
        $todayCheckedIn = $todayVisits->where('status', 'checked_in')->count();
        $todayCompleted = $todayVisits->whereIn('status', ['checked_out', 'archived'])->count();
        $todayRejected  = $todayVisits->where('status', 'rejected')->count();
        $todayArchived  = $todayVisits->where('status', 'archived')->count();
        $todayNoShows   = $visits->filter(fn($v) => ($v['is_no_show'] ?? false) && \Carbon\Carbon::parse($v['requested_time'])->isToday())->count();
        $upcomingToday  = $visits->filter(fn($v) =>
            $v['status'] === 'approved' &&
            \Carbon\Carbon::parse($v['requested_time'])->isToday() &&
            \Carbon\Carbon::parse($v['requested_time'])->isFuture()
        )->count();

        // Active checked-in (my visitors only) — derived from the already-loaded
        // visits collection instead of re-querying the DB.
        $blacklist = Blacklist::select('name', 'id_document_no')->get();
        $liveCount = $visits->where('status', 'checked_in')->count();

        // VIP visitors for host
        $vipVisitors = $visits->where('is_vip', true)->pluck('visitor_id')->unique();

        // Repeat visitors (visited more than once)
        $repeatVisitorIds = VisitRequest::where('host_id', $host->id)
            ->select('visitor_id', DB::raw('count(*) as cnt'))
            ->groupBy('visitor_id')
            ->having('cnt', '>', 1)
            ->pluck('visitor_id');

        // Recent notifications (SystemNotification for this host)
        // Handled by NotificationPanel's own API poll — not needed server-side.

        // Analytics
        $totalMyVisits     = $visits->count();
        $totalApprovedAll  = $visits->where('status', 'approved')->count() + $visits->whereIn('status', ['checked_in','checked_out','archived'])->count();
        $approvalRate      = $totalMyVisits > 0 ? round(($totalApprovedAll / $totalMyVisits) * 100) : 0;
        $missedVisits      = $visits->where('is_no_show', true)->count();
        $rejectedCount     = $visits->where('status', 'rejected')->count();
        $archivedCount     = $visits->where('status', 'archived')->count();

        // Monthly stats (this year) — one grouped query instead of 12 counts
        $currentYear = now()->year;
        $monthlyCounts = VisitRequest::where('host_id', $host->id)
            ->whereYear('created_at', $currentYear)
            ->selectRaw(DB::getDriverName() === 'sqlite'
                ? "strftime('%m', created_at) as m, COUNT(*) as c"
                : 'MONTH(created_at) as m, COUNT(*) as c')
            ->groupBy('m')
            ->pluck('c', 'm');
        $monthlyStats = [];
        for ($m = 1; $m <= 12; $m++) {
            $monthlyStats[] = [
                'month' => date('M', mktime(0, 0, 0, $m, 1)),
                'count' => (int) ($monthlyCounts[$m] ?? 0),
            ];
        }

        // All active Host/Admin/CEO users eligible to receive a transferred
        // visitor (transfer feature). Filtered to the same company by the
        // global CompanyScope; per-visit branch/company matching happens on
        // the frontend exactly like eligibleTransferHosts.
        $transferRecipients = User::whereIn('role', ['host', 'admin', 'ceo'])
            ->where('status', 'active')
            ->where('id', '!=', $host->id)
            ->with('department')
            ->get();

        // All active hosts (for host change feature) — subset of transferRecipients.
        $allHosts = $transferRecipients->filter(fn($u) => $u->role === 'host')->values();

        // Host change requests for this host (pending incoming + my outgoing)
        $pendingHostChangeRequests = \App\Models\HostChangeRequest::where('new_host_id', $host->id)
            ->where('status', 'pending')
            ->with('visitRequest.visitor', 'previousHost', 'newDepartment', 'requestedByUser')
            ->latest()
            ->get();

        $myHostChangeRequests = \App\Models\HostChangeRequest::where('requested_by', $host->id)
            ->with('visitRequest.visitor', 'newHost', 'previousHost', 'reviewedByUser')
            ->latest()
            ->take(50)
            ->get();

        // Reschedule requests for this host's visits
        $rescheduleRequests = \App\Models\RescheduleRequest::with('visitRequest.visitor', 'visitRequest.host', 'requestedByUser', 'requestedByVisitor')
            ->whereHas('visitRequest', fn($q) => $q->where('host_id', $host->id))
            ->latest()->get();

        // ── Security Alerts for this host's visits ──
        $hostVisitIds = $visits->pluck('id')->toArray();
        $securityAlerts = Incident::whereIn('related_visit_id', $hostVisitIds)
            ->orWhereIn('related_visitor_id', $visits->pluck('visitor_id')->unique()->toArray())
            ->with('reportedBy')
            ->latest()
            ->take(20)
            ->get();
        $securityAlertStats = [
            'total'    => $securityAlerts->count(),
            'open'     => $securityAlerts->whereIn('status', ['open', 'investigating'])->count(),
            'critical' => $securityAlerts->where('severity', 'critical')->count(),
        ];

        return Inertia::render('Dashboard/Host', [
            'visits'           => $visits,
            'sla_policy'       => CompanyPolicy::forCompany($host->company_id)->toSettingsArray(),
            'live_count'       => $liveCount,
            'blacklist'        => $blacklist,
            'all_hosts'        => $allHosts,
            'transfer_recipients' => $transferRecipients,
            'pending_host_change_requests' => $pendingHostChangeRequests,
            'my_host_change_requests' => $myHostChangeRequests,
            'reschedule_requests' => $rescheduleRequests,
            'security_alerts'  => $securityAlerts,
            'security_alert_stats' => $securityAlertStats,
            'today_stats' => [
                'pending'    => $todayPending,
                'approved'   => $todayApproved,
                'checked_in' => $todayCheckedIn,
                'completed'  => $todayCompleted,
                'no_shows'   => $todayNoShows,
                'rejected'   => $todayRejected,
                'archived'   => $todayArchived,
                'upcoming'   => $upcomingToday,
                'live_count' => $liveCount,
            ],
            'analytics' => [
                'total'         => $totalMyVisits,
                'approval_rate' => $approvalRate,
                'missed'        => $missedVisits,
                'rejected'      => $rejectedCount,
                'archived'      => $archivedCount,
                'monthly'       => $monthlyStats,
                'vip_count'     => $vipVisitors->count(),
                'repeat_count'  => $repeatVisitorIds->count(),
            ],
            'section'       => $section,
            'facilities'    => \App\Models\Facility::orderBy('building')->orderBy('floor')->orderBy('room')->get(),
            'messages_inbox'    => \App\Http\Controllers\MessageController::inboxList('user', $host->id),
            'messages_sent'     => \App\Http\Controllers\MessageController::sentList('user', $host->id),
            'messages_recipients' => \App\Http\Controllers\MessageController::recipientsFor('user', $host),
            'messages_unread_count' => Message::unreadCount('user', $host->id),
        ]);
    }

    /**
     */
    public function securityDashboard()
    {
        $todayCheckedIn = VisitRequest::where('status', 'checked_in')
            ->with('visitor', 'host')
            ->get();

        $todayExited = VisitRequest::where('status', 'checked_out')
            ->with('visitor', 'host')
            ->get();

        $blacklist = Blacklist::select('name', 'id_document_no')->get();

        // Total active in building count
        $liveCount = $todayCheckedIn->count();

        return Inertia::render('Dashboard/Security', [
            'live_count' => $liveCount,
            'checked_in_list' => $todayCheckedIn,
            'checked_out_list' => $todayExited,
            'blacklist' => $blacklist,
        ]);
    }

    /**
     * Render Visitor Dashboard.
     */
    public function visitorDashboard($section = null)
    {
        $visitor = Auth::guard('visitor')->user();

        $requests = VisitRequest::where('visitor_id', $visitor->id)
            ->with('host.department', 'department', 'qrToken', 'feedback', 'badge', 'groupMembers.properties', 'documents')
            ->orderByRaw('CASE WHEN requested_time >= ? THEN 0 ELSE 1 END ASC', [now()])
            ->orderBy('requested_time', 'asc')
            ->get();

        // The visitor always sees the host they originally scheduled with.
        // Transfers and escalations stay internal to Admin/CEO/Host dashboards,
        // so for each visit resolve the ORIGINAL host from the first HostTransfer
        // record (previous_host_id); a never-transferred visit keeps its host.
        $originalHostIds = collect();
        if ($requests->isNotEmpty()) {
            $originalHostIds = \App\Models\HostTransfer::whereIn('visit_request_id', $requests->pluck('id'))
                ->orderBy('id')
                ->get()
                ->groupBy('visit_request_id')
                ->map(fn ($group) => $group->first()->previous_host_id);
        }
        $originalHostUsers = \App\Models\User::whereIn('id', $originalHostIds->values()->all())
            ->with('department:id,name')
            ->get()
            ->keyBy('id');

        $requests->each(function ($req) use ($originalHostIds, $originalHostUsers) {
            $origId = $originalHostIds->get($req->id) ?? $req->host_id;
            $orig   = $originalHostUsers->get($origId) ?? $req->host;
            if ($orig) {
                $req->original_host = [
                    'id'              => $orig->id,
                    'name'            => $orig->name,
                    'department_name' => $orig->department?->name
                        ?? $req->department?->name
                        ?? $req->host?->department?->name
                        ?? '',
                ];
            }

            // Internal escalation notes are appended to the visit comments
            // ("Escalated to X by Y on ..."). They stay stored in the database
            // but must never surface on the visitor dashboard — the visitor only
            // ever sees the non-escalation part of the comment trail.
            $req->comments = NotificationService::sanitizeCommentsForVisitor($req->comments);
        });

        // Pre-fetch existing member badges for all approved company visits in one
        // query (replaces the per-member SELECT inside the safety-net loop below).
        $existingMemberBadges = collect();
        $badgeVisits = $requests->where('visit_type', 'company')->where('status', 'approved');
        if ($badgeVisits->isNotEmpty()) {
            $existingMemberBadges = \App\Models\VisitorBadge::whereIn('visit_request_id', $badgeVisits->pluck('id'))
                ->get()
                ->keyBy(fn($b) => $b->visit_request_id . '|' . $b->visitor_name);
        }

        // Safety net: auto-generate any missing QR tokens for approved visits
        foreach ($requests->where('status', 'approved') as $req) {
            // A visitor who deliberately deleted their (expired) QR pass should NOT
            // get it silently regenerated on the next page load.
            if ($req->qr_deleted_at) {
                continue;
            }

            if (!$req->qrToken) {
                \Illuminate\Support\Facades\Log::info("visitorDashboard SAFETY-NET: Visit #{$req->id} is approved but has no QR token. Attempting auto-generate...");
                $generated = false;
                for ($attempt = 1; $attempt <= 3; $attempt++) {
                    try {
                        \App\Services\QRTokenService::generate($req);
                        $req->load('qrToken');
                        if ($req->qrToken) {
                            \Illuminate\Support\Facades\Log::info("visitorDashboard SAFETY-NET: QR token auto-generated for visit #{$req->id} on attempt {$attempt}");
                            $generated = true;
                            break;
                        }
                    } catch (\Exception $e) {
                        \Illuminate\Support\Facades\Log::error("visitorDashboard SAFETY-NET: Attempt {$attempt} failed for visit #{$req->id}: " . $e->getMessage(), [
                            'exception' => $e,
                        ]);
                        if ($attempt < 3) usleep(100000);
                    }
                }
                if (!$generated) {
                    \Illuminate\Support\Facades\Log::error("visitorDashboard SAFETY-NET: QR token generation FAILED after 3 attempts for visit #{$req->id}");
                }
            }
            // Safety net: auto-generate missing group member tokens for company visits
            if ($req->visit_type === 'company' && $req->groupMembers->isNotEmpty()) {
                $hasMissing = $req->groupMembers->contains('qr_token', null);
                if ($hasMissing) {
                    try {
                        \App\Services\QRTokenService::generateGroupMemberTokens($req);
                        $req->load('groupMembers');
                    } catch (\Exception $e) {
                        \Illuminate\Support\Facades\Log::error("visitorDashboard group QR safety-net failed for visit #{$req->id}: " . $e->getMessage(), [
                            'exception' => $e,
                        ]);
                    }
                }
            }

            // Safety net: auto-generate missing EXIT QR tokens for checked-in group members
            // so the visitor dashboard always has an exit QR to display for the group.
            if ($req->visit_type === 'company' && $req->status === 'checked_in' && $req->groupMembers->isNotEmpty()) {
                foreach ($req->groupMembers as $member) {
                    if (empty($member->exit_qr_token) && $member->checked_in_at && !$member->checked_out_at) {
                        try {
                            \App\Services\QRTokenService::generateGroupMemberExitToken($req, $member);
                            $member->refresh();
                        } catch (\Exception $e) {
                            \Illuminate\Support\Facades\Log::error("visitorDashboard group EXIT QR safety-net failed for member #{$member->id}: " . $e->getMessage(), [
                                'exception' => $e,
                            ]);
                        }
                    }
                }
            }

            // Safety net: auto-generate missing EXIT QR token for the representative (you)
            if ($req->status === 'checked_in' && empty($req->exit_qr_token)) {
                try {
                    $req->update([
                        'exit_qr_token'      => 'VMS-EXIT-' . strtoupper(\Illuminate\Support\Str::random(16)),
                        'exit_qr_expires_at' => now()->addHours(4),
                    ]);
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::error("visitorDashboard rep EXIT QR safety-net failed for visit #{$req->id}: " . $e->getMessage(), [
                        'exception' => $e,
                    ]);
                }
            }

            // Ensure VisitorBadge records exist for company/group visit members
            if ($req->visit_type === 'company' && $req->status === 'approved') {
                foreach ($req->groupMembers as $member) {
                    $existingMemberBadge = $existingMemberBadges->get($req->id . '|' . $member->name);
                    if (!$existingMemberBadge) {
                        try {
                            \App\Models\VisitorBadge::create([
                                'visit_request_id'   => $req->id,
                                'visitor_name'       => $member->name,
                                'visitor_id_document'=> $member->id_document_no ?? '',
                                'visitor_photo_url'  => null,
                                'host_name'          => $req->host->name ?? '',
                                'department'         => $req->department->name ?? ($req->host->department->name ?? ''),
                                'purpose'            => $req->purpose ?? '',
                                'scheduled_time'     => $req->requested_time ? $req->requested_time->format('M d, Y H:i') : '',
                                'duration'           => $req->duration . ' min',
                                'priority'           => $req->priority ?? 'normal',
                                'is_vip'             => $req->is_vip ?? false,
                                'qr_token'           => $member->qr_token ?? '',
                                'issued_at'          => now(),
                            ]);
                        } catch (\Exception $e) {
                            // Badge may already exist, ignore
                        }
                    }
                }
            }
        }

        $hosts = User::where('role', 'host')->where('status', 'active')
            ->with('department')->get();

        $departments = Department::withCount(['users as host_count' => fn($q) => $q->where('role','host')->where('status','active')])->get();

        $stats = [
            'total'      => $requests->count(),
            'pending'    => $requests->where('status', 'pending')->count(),
            'approved'   => $requests->where('status', 'approved')->count(),
            'completed'  => $requests->whereIn('status', ['checked_out','archived'])->count(),
            'upcoming'   => $requests->filter(fn($r) => $r->status === 'approved' && \Carbon\Carbon::parse($r->requested_time)->isFuture())->count(),
            'company'    => $requests->where('visit_type', 'company')->count(),
            'individual' => $requests->where('visit_type', '!=', 'company')->count(),
        ];

        // Visitor notifications are handled by NotificationPanel's own API poll.

        // Reschedule requests for this visitor
        $rescheduleRequests = \App\Models\RescheduleRequest::with('visitRequest.visitor', 'visitRequest.host', 'requestedByUser', 'requestedByVisitor')
            ->whereHas('visitRequest', fn($q) => $q->where('visitor_id', $visitor->id))
            ->latest()->get();

        // Determine if this visitor is a company/group representative (derived from
        // the already-loaded requests to avoid a redundant DB query).
        $isRepresentative = $requests->contains('visit_type', 'company');

        // Get company/group visits where this visitor is the representative
        $companyVisits = $requests->where('visit_type', 'company')->map(function($visit) {
            $visitArray = $visit->toArray();
            
            // Add detailed company information for Representative Dashboard
            $visitArray['company_info'] = [
                'name' => $visit->company_name,
                'email' => $visit->company_email,
                'phone' => $visit->company_phone,
                'address' => $visit->company_address,
                'website' => $visit->company_website,
            ];
            
            // Add representative information
            $visitArray['representative_info'] = [
                'name' => $visit->visitor->name ?? '',
                'email' => $visit->visitor->email ?? '',
                'phone' => $visit->visitor->phone ?? '',
                'id_document_no' => $visit->visitor->id_document_no ?? '',
                'gender' => $visit->representative_gender,
                'nationality' => $visit->representative_nationality,
                'position' => $visit->representative_position,
            ];
            
            // Add detailed group members list with QR codes and status
            $visitArray['groupMembers'] = $visit->groupMembers->map(function($member) {
                return [
                    'id' => $member->id,
                    'name' => $member->name,
                    'id_document_no' => $member->id_document_no,
                    'email' => $member->email,
                    'phone' => $member->phone,
                    'gender' => $member->gender,
                    'nationality' => $member->nationality,
                    'position' => $member->position,
                    'qr_token' => $member->qr_token,
                    'qr_expires_at' => $member->qr_expires_at ? $member->qr_expires_at->toDateTimeString() : null,
                    'checked_in_at' => $member->checked_in_at ? $member->checked_in_at->toDateTimeString() : null,
                    'checked_out_at' => $member->checked_out_at ? $member->checked_out_at->toDateTimeString() : null,
                    'exit_qr_token' => $member->exit_qr_token,
                    'exit_qr_expires_at' => $member->exit_qr_expires_at ? $member->exit_qr_expires_at->toDateTimeString() : null,
                    'badge_status' => $member->badge_status,
                    'properties_count' => $member->properties->count(),
                    'properties' => $member->properties->map(fn($p) => [
                        'id' => $p->id,
                        'item_name' => $p->item_name,
                        'quantity' => $p->quantity,
                        'serial_number' => $p->serial_number,
                        'property_tag' => $p->property_tag,
                        'status' => $p->status,
                    ]),
                    'is_checked_in' => $member->isCheckedIn(),
                    'is_checked_out' => $member->isFullyCheckedOut(),
                ];
            })->toArray();
            
            // Add group statistics
            $visitArray['total_group_members'] = $visit->groupMembers->count();
            $visitArray['checked_in_members'] = $visit->groupMembers->whereNotNull('checked_in_at')->whereNull('checked_out_at')->count();
            $visitArray['checked_out_members'] = $visit->groupMembers->whereNotNull('checked_out_at')->count();
            $visitArray['pending_members'] = $visit->groupMembers->whereNull('checked_in_at')->count();
            
            // Add documents
            $visitArray['documents'] = $visit->documents->map(function($doc) {
                return [
                    'id' => $doc->id,
                    'type' => $doc->doc_type,
                    'file_path' => $doc->file_path,
                    'original_name' => $doc->original_name,
                ];
            })->toArray();
            
            return $visitArray;
        })->values();

        return Inertia::render('Dashboard/Visitor', [
            'requests'       => $requests,
            'categories'     => \App\Models\VisitorCategory::orderBy('name')->get(),
            'companies'      => \App\Models\Company::where('is_active', true)
                ->with(['locations.branches'])
                ->get(['company_id', 'company_name']),
            'branches'       => \App\Models\Branch::with('location', 'departments')->where('is_active', true)->get(),
            'hosts'          => $hosts,
            'departments'    => $departments,
            'stats'          => $stats,
            'reschedule_requests' => $rescheduleRequests,
            'section'        => $section,
            'is_representative' => $isRepresentative,
            'company_visits' => $companyVisits,
            'messages_inbox'    => \App\Http\Controllers\MessageController::inboxList('visitor', $visitor->id),
            'messages_sent'     => \App\Http\Controllers\MessageController::sentList('visitor', $visitor->id),
            'messages_recipients' => \App\Http\Controllers\MessageController::recipientsFor('visitor', $visitor),
            'messages_unread_count' => Message::unreadCount('visitor', $visitor->id),
        ]);
    }

    /**
     * Visitor: Update profile (name, phone, company, etc.)
     */
    public function updateVisitorProfile(\Illuminate\Http\Request $request)
    {
        $visitor = Auth::guard('visitor')->user();
        if (!$visitor) abort(403);

        $validator = Validator::make($request->all(), [
            'name'             => 'required|string|max:255',
            'first_name'       => 'nullable|string|max:255',
            'last_name'        => 'nullable|string|max:255',
            'email'            => 'nullable|email|max:255|unique:visitors,email,' . $visitor->id,
            'id_document_no'   => 'nullable|string|max:255|unique:visitors,id_document_no,' . $visitor->id,
            'phone'            => 'nullable|string|max:50',
            'age'              => 'nullable|integer|min:16|max:120',
            'sex'              => 'nullable|in:male,female',
            'company_position' => 'nullable|string|max:255',
            'password'         => 'nullable|string|min:8|confirmed',
            'profile_photo'    => 'nullable|image|max:2048',
        ]);
        if ($validator->fails()) return back()->withErrors($validator);

        $fields = $request->only('name', 'first_name', 'last_name', 'email', 'id_document_no', 'phone', 'age', 'sex', 'company_position');

        if ($request->filled('password')) {
            $fields['password'] = $request->password;
        }

        if ($request->hasFile('profile_photo')) {
            $fields['photo_url'] = $request->file('profile_photo')->store('profile-photos', 'public');
        }

        $visitor->update($fields);
        AuditLogger::log('visitor_profile_updated', 'visitors', $visitor->id);

        return back()->with('success', 'Profile updated successfully.');
    }

    // ─── Visitor QR Code Access Methods ───

    /**
     * Render QR badge image for the representative's own visit.
     */
    public function visitorQrImage($id)
    {
        $visitor = Auth::guard('visitor')->user();
        $visit = VisitRequest::with('qrToken', 'visitor', 'host.department', 'department')->where('id', $id)
            ->where('visitor_id', $visitor->id)->firstOrFail();

        // Safety net: generate token on-the-fly if missing for approved visit.
        // Skip when the visitor deliberately deleted the expired QR pass.
        if (!$visit->qrToken && $visit->status === 'approved' && !$visit->qr_deleted_at) {
        try {
            // Explicit regeneration clears the "deleted" marker so the QR stays.
            $visit->qr_deleted_at = null;
            $visit->save();

            \App\Services\QRTokenService::generate($visit);
                $visit->load('qrToken');
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::warning("visitorQrImage auto-generate failed for visit #{$id}: " . $e->getMessage());
            }
        }

        if (!$visit->qrToken) abort(404, 'No QR token found.');

        $isExit = $visit->status === 'checked_in' && !empty($visit->exit_qr_token);
        $svg = $isExit
            ? \App\Services\QrBadgeService::generateVisitExitBadge($visit)
            : \App\Services\QrBadgeService::generateVisitBadge($visit);
        return response($svg)
            ->header('Content-Type', 'image/svg+xml')
            ->header('Cache-Control', 'no-store, no-cache');
    }

    /**
     * Render QR badge image for a specific group member.
     */
    public function visitorGroupMemberQrImage($id, $memberId)
    {
        $visitor = Auth::guard('visitor')->user();
        $visit = VisitRequest::with('host.department', 'department')->where('id', $id)
            ->where('visitor_id', $visitor->id)->firstOrFail();

        $member = $visit->groupMembers()->where('id', $memberId)->firstOrFail();

        // Safety net: generate member token on-the-fly if missing.
        // Skip when the visitor deliberately deleted the expired QR pass.
        if (!$member->qr_token && $visit->status === 'approved' && !$visit->qr_deleted_at) {
            try {
                \App\Services\QRTokenService::generateGroupMemberTokens($visit);
                $member->refresh();
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::warning("visitorGroupMemberQrImage auto-generate failed for member #{$memberId}: " . $e->getMessage());
            }
        }

        if (!$member->qr_token) abort(404, 'No QR token for this member.');

        $svg = \App\Services\QrBadgeService::generateMemberBadge($visit, $member);
        return response($svg)
            ->header('Content-Type', 'image/svg+xml')
            ->header('Cache-Control', 'no-store, no-cache');
    }

    /**
     * Render EXIT QR badge image for a specific checked-in group member.
     */
    public function visitorGroupMemberExitQrImage($id, $memberId)
    {
        $visitor = Auth::guard('visitor')->user();
        $visit = VisitRequest::with('host.department', 'department')->where('id', $id)
            ->where('visitor_id', $visitor->id)->firstOrFail();

        $member = $visit->groupMembers()->where('id', $memberId)->firstOrFail();

        // Safety net: generate the member exit token on-the-fly if missing
        if (!$member->exit_qr_token && $visit->status === 'checked_in' && $member->checked_in_at && !$member->checked_out_at) {
            try {
                \App\Services\QRTokenService::generateGroupMemberExitToken($visit, $member);
                $member->refresh();
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::warning("visitorGroupMemberExitQrImage auto-generate failed for member #{$memberId}: " . $e->getMessage());
            }
        }

        if (!$member->exit_qr_token) abort(404, 'No exit QR token for this member.');

        $svg = \App\Services\QrBadgeService::generateMemberExitBadge($visit, $member);
        return response($svg)
            ->header('Content-Type', 'image/svg+xml')
            ->header('Cache-Control', 'no-store, no-cache');
    }

    /**
     * Download the EXIT QR badge for a single group member as SVG.
     */
    public function visitorGroupMemberExitQrDownload($id, $memberId)
    {
        $visitor = Auth::guard('visitor')->user();
        $visit = VisitRequest::with('host.department', 'department')->where('id', $id)
            ->where('visitor_id', $visitor->id)->firstOrFail();

        $member = $visit->groupMembers()->where('id', $memberId)->firstOrFail();
        if (!$member->exit_qr_token) return back()->with('error', 'No exit QR token for this member.');

        $svg = \App\Services\QrBadgeService::generateMemberExitBadge($visit, $member);
        $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $member->name);
        return response($svg)
            ->header('Content-Type', 'image/svg+xml')
            ->header('Content-Disposition', 'attachment; filename="exit-qr-badge-' . $safeName . '-member-' . $memberId . '.svg"');
    }

    /**
     * Download the representative's own QR badge as SVG.
     */
    public function visitorQrDownload($id)
    {
        $visitor = Auth::guard('visitor')->user();
        $visit = VisitRequest::with('qrToken', 'visitor', 'host.department', 'department')->where('id', $id)
            ->where('visitor_id', $visitor->id)->firstOrFail();

        if (!$visit->qrToken) return back()->with('error', 'No QR token found.');

        $isExit = $visit->status === 'checked_in' && !empty($visit->exit_qr_token);
        $svg = $isExit
            ? \App\Services\QrBadgeService::generateVisitExitBadge($visit)
            : \App\Services\QrBadgeService::generateVisitBadge($visit);
        $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $visit->resolveVisitor()?->name ?? $visit->company_name ?? 'visitor');
        return response($svg)
            ->header('Content-Type', 'image/svg+xml')
            ->header('Content-Disposition', 'attachment; filename="' . ($isExit ? 'exit-' : '') . 'qr-badge-' . $safeName . '-visit-' . $id . '.svg"');
    }

    /**
     * Download QR badge for a single group member as SVG.
     */
    public function visitorGroupMemberQrDownload($id, $memberId)
    {
        $visitor = Auth::guard('visitor')->user();
        $visit = VisitRequest::with('host.department', 'department')->where('id', $id)
            ->where('visitor_id', $visitor->id)->firstOrFail();

        $member = $visit->groupMembers()->where('id', $memberId)->firstOrFail();
        if (!$member->qr_token) return back()->with('error', 'No QR token for this member.');

        $svg = \App\Services\QrBadgeService::generateMemberBadge($visit, $member);
        $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $member->name);
        return response($svg)
            ->header('Content-Type', 'image/svg+xml')
            ->header('Content-Disposition', 'attachment; filename="qr-badge-' . $safeName . '-member-' . $memberId . '.svg"');
    }

    /**
     * Download representative + all group member QR badges as SVGs in a ZIP.
     */
    public function visitorQrAllDownload($id)
    {
        $visitor = Auth::guard('visitor')->user();
        $visit = VisitRequest::with('qrToken', 'groupMembers', 'visitor', 'host.department', 'department')->where('id', $id)
            ->where('visitor_id', $visitor->id)->firstOrFail();

        $tempFile = tempnam(sys_get_temp_dir(), 'qr_');
        $zip = new \ZipArchive();
        $zip->open($tempFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        // Representative badge
        if ($visit->qrToken) {
            $svg = \App\Services\QrBadgeService::generateVisitBadge($visit);
            $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $visit->resolveVisitor()?->name ?? $visit->company_name ?? 'visitor');
            $zip->addFromString("representative-badge-{$safeName}.svg", $svg);
        }

        // Group member badges
        if ($visit->visit_type === 'company') {
            foreach ($visit->groupMembers as $member) {
                if ($member->qr_token) {
                    $svg = \App\Services\QrBadgeService::generateMemberBadge($visit, $member);
                    $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $member->name);
                    $zip->addFromString("member-badge-{$safeName}-{$member->id}.svg", $svg);
                }
            }
        }

        $zip->close();
        $filename = 'qr-badges-visit-' . $id . '.zip';
        return response()->download($tempFile, $filename)->deleteFileAfterSend(true);
    }

    /**
     * Download all group member QR badges as SVGs in a ZIP.
     */
    public function visitorGroupQrPackage($id)
    {
        $visitor = Auth::guard('visitor')->user();
        $visit = VisitRequest::with('groupMembers', 'host.department', 'department')->where('id', $id)
            ->where('visitor_id', $visitor->id)->firstOrFail();

        if ($visit->visit_type !== 'company' || $visit->groupMembers->isEmpty()) {
            return back()->with('error', 'No group members found for this visit.');
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'qr_');
        $zip = new \ZipArchive();
        $zip->open($tempFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        foreach ($visit->groupMembers as $member) {
            if ($member->qr_token) {
                $svg = \App\Services\QrBadgeService::generateMemberBadge($visit, $member);
                $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $member->name);
                $zip->addFromString("badge-{$safeName}-{$member->id}.svg", $svg);
            }
        }

        $zip->close();
        $filename = 'group-qr-badges-visit-' . $id . '.zip';
        return response()->download($tempFile, $filename)->deleteFileAfterSend(true);
    }

    /**
     * Regenerate QR token for an approved visit that is missing one.
     * Allows retry when initial QR generation failed.
     */
    public function regenerateQrToken($id)
    {
        $visitor = Auth::guard('visitor')->user();
        $visit = VisitRequest::with('qrToken', 'groupMembers', 'visitor', 'host.department', 'department')
            ->where('id', $id)
            ->where('visitor_id', $visitor->id)
            ->firstOrFail();

        if ($visit->status !== 'approved') {
            return back()->with('error', 'QR tokens can only be generated for approved visits.');
        }

        try {
            \App\Services\QRTokenService::generate($visit);

            if ($visit->visit_type === 'company' && $visit->groupMembers->count() > 0) {
                \App\Services\QRTokenService::generateGroupMemberTokens($visit);
            }

            $visit->refresh();

            \Illuminate\Support\Facades\Log::info("QR token regenerated for visit #{$visit->id} by visitor #{$visitor->id}");

            return back()->with('success', 'QR token(s) regenerated successfully.');
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("QR token regeneration failed for visit #{$visit->id}: " . $e->getMessage(), [
                'visit_request_id' => $visit->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return back()->with('error', 'QR token regeneration failed. Please try again or contact support.');
        }
    }

    /**
     * Visitor: Delete an expired, unused QR token for their own visit.
     * Expired group-member tokens are cleared together for company visits.
     */
    public function deleteExpiredQr($id)
    {
        $visitor = Auth::guard('visitor')->user();
        $visit = VisitRequest::with('qrToken', 'groupMembers', 'visitor', 'host.department', 'department')
            ->where('id', $id)
            ->where('visitor_id', $visitor->id)
            ->firstOrFail();

        $qrToken = $visit->qrToken;

        if (!$qrToken) {
            return back()->with('error', 'No QR code found for this visit.');
        }

        if ($qrToken->used_at) {
            return back()->with('error', 'A used QR code cannot be deleted.');
        }

        if (!$qrToken->expires_at || !$qrToken->expires_at->isPast()) {
            return back()->with('error', 'Only expired QR codes can be deleted.');
        }

        try {
            DB::transaction(function () use ($visit, $qrToken) {
                $qrToken->delete();

                if ($visit->visit_type === 'company' && $visit->groupMembers->count() > 0) {
                    foreach ($visit->groupMembers as $member) {
                        if ($member->qr_token && !$member->qr_used_at && $member->qr_expires_at && $member->qr_expires_at->isPast()) {
                            $member->qr_token = null;
                            $member->qr_expires_at = null;
                            $member->save();
                        }
                    }
                }

                // Mark the visit so the dashboard safety-net does NOT regenerate
                // a new token on the next page load (the QR was deliberately deleted).
                $visit->qr_deleted_at = now();
                $visit->save();
            });

            \App\Services\AuditLogger::log(
                'visitor_qr_deleted',
                'visit_requests',
                $visit->id,
                null,
                ['visitor_id' => $visitor->id, 'token' => $qrToken->token_value]
            );

            \Illuminate\Support\Facades\Log::info("Expired QR token deleted for visit #{$visit->id} by visitor #{$visitor->id}");

            return back()->with('success', 'Expired QR code deleted successfully.');
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("QR token deletion failed for visit #{$visit->id}: " . $e->getMessage(), [
                'visit_request_id' => $visit->id,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Failed to delete the expired QR code. Please try again.');
        }
    }

    /**
     * Download all visitor passes (representative + group members) as SVGs in a ZIP.
     */
    public function visitorAllPassesDownload($id)
    {
        $visitor = Auth::guard('visitor')->user();
        $visit = VisitRequest::with('qrToken', 'groupMembers', 'visitor', 'host.department', 'department')->where('id', $id)
            ->where('visitor_id', $visitor->id)->firstOrFail();

        $tempFile = tempnam(sys_get_temp_dir(), 'passes_');
        $zip = new \ZipArchive();
        $zip->open($tempFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        // Representative badge
        if ($visit->qrToken) {
            $svg = \App\Services\QrBadgeService::generateVisitBadge($visit);
            $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $visit->resolveVisitor()?->name ?? $visit->company_name ?? 'visitor');
            $zip->addFromString("visitor-pass-{$safeName}-representative.svg", $svg);
        }

        // Group member badges
        if ($visit->visit_type === 'company') {
            foreach ($visit->groupMembers as $member) {
                if ($member->qr_token) {
                    $svg = \App\Services\QrBadgeService::generateMemberBadge($visit, $member);
                    $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $member->name);
                    $zip->addFromString("visitor-pass-{$safeName}-{$member->id}.svg", $svg);
                }
            }
        }

        $zip->close();
        $filename = 'visitor-passes-visit-' . $id . '.zip';
        return response()->download($tempFile, $filename)->deleteFileAfterSend(true);
    }

    /**
     * Host: Regenerate QR token for an approved visit that is missing one.
     */
    public function hostRegenerateQrToken($id)
    {
        $host = Auth::guard('web')->user();
        $visit = VisitRequest::with('qrToken', 'groupMembers', 'visitor', 'host.department', 'department')
            ->where('id', $id)
            ->where('host_id', $host->id)
            ->firstOrFail();

        if ($visit->status !== 'approved') {
            return back()->with('error', 'QR tokens can only be generated for approved visits.');
        }

        try {
            // Explicit regeneration clears the "deleted" marker so the QR stays.
            $visit->qr_deleted_at = null;
            $visit->save();

            \App\Services\QRTokenService::generate($visit);

            if ($visit->visit_type === 'company' && $visit->groupMembers->count() > 0) {
                \App\Services\QRTokenService::generateGroupMemberTokens($visit);
            }

            $visit->refresh();

            $token = $visit->qrToken ? $visit->qrToken->token_value : 'N/A';
            \App\Services\NotificationService::send(
                'QR Code Generated',
                $visit->visitor,
                "Your visit request has been approved! Your entry QR token is: {$token}. Valid on " . $visit->requested_time->format('M d, Y H:i')
            );

            if ($visit->visit_type === 'company' && $visit->groupMembers->count() > 0) {
                foreach ($visit->groupMembers as $member) {
                    if ($member->email) {
                        $fakeVisitor = new \App\Models\Visitor([
                            'name' => $member->name, 'email' => $member->email, 'phone' => $member->phone,
                        ]);
                        \App\Services\NotificationService::send('QR Code Generated', $fakeVisitor,
                            "Dear {$member->name}, your group visit has been approved. Your personal entry QR token is: {$member->qr_token}. Present this at the security gate on " . $visit->requested_time->format('M d, Y H:i'));
                    }
                }
            }

            \Illuminate\Support\Facades\Log::info("QR token regenerated by host #{$host->id} for visit #{$visit->id}");

            return back()->with('success', 'QR token(s) regenerated and visitor notified.');
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Host QR token regeneration failed for visit #{$visit->id}: " . $e->getMessage(), [
                'visit_request_id' => $visit->id,
                'host_id' => $host->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return back()->with('error', 'QR token regeneration failed. Please try again.');
        }
    }
}
