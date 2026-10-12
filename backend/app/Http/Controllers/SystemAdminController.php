<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Blacklist;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Incident;
use App\Models\Lockdown;
use App\Models\Message;
use App\Models\User;
use App\Models\Visitor;
use App\Models\VisitRequest;
use App\Services\AuditLogger;
use App\Services\CompanyService;
use App\Services\IncidentReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class SystemAdminController extends Controller
{
    public function __construct(private readonly CompanyService $companyService) {}

    /**
     * Redirect an authenticated user who is not authorized for the System Admin
     * area to their own dashboard, instead of showing a 403 error page.
     */
    private function redirectToOwnDashboard(?User $user)
    {
        if (! $user) {
            return redirect(route('login'));
        }

        return redirect(match ($user->role) {
            'system_admin' => '/system-admin/dashboard',
            'admin' => '/admin/dashboard',
            'ceo' => '/ceo/dashboard',
            'host' => '/host/dashboard',
            'security_guard' => '/security/dashboard',
            default => '/',
        });
    }

    /**
     * Render the System Admin dashboard — platform owner overview.
     *
     * Provides enterprise-wide oversight: platform KPIs, per-company summary,
     * recent activity timeline, security monitoring and attention-required alerts.
     */
    public function dashboard($section = null)
    {
        $user = Auth::guard('web')->user();
        if (! $user || ! in_array($user->role, ['system_admin', 'admin'])) {
            return $this->redirectToOwnDashboard($user);
        }

        $today = today();

        // ── Platform KPIs ──
        // Grouped status counts replace one COUNT query per status.
        $companyStatusCounts = Company::selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status');
        $userStatusCounts = User::selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status');
        $globalVisitStatusCounts = VisitRequest::selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status');

        $stats = [
            'total_companies' => (int) $companyStatusCounts->sum(),
            'active_companies' => (int) ($companyStatusCounts['active'] ?? 0),
            'inactive_companies' => (int) ($companyStatusCounts['inactive'] ?? 0),
            'total_users' => (int) $userStatusCounts->sum(),
            'active_users' => (int) ($userStatusCounts['active'] ?? 0),
            'pending_users' => (int) ($userStatusCounts['pending'] ?? 0),
            'suspended_users' => (int) ($userStatusCounts['suspended'] ?? 0),
            'total_visitors' => Visitor::count(),
            'visits_today' => VisitRequest::whereDate('created_at', $today)->count(),
            'active_sessions' => (int) ($globalVisitStatusCounts['checked_in'] ?? 0),
            'pending_approvals' => (int) ($globalVisitStatusCounts['pending'] ?? 0),
            'escalations' => (int) ($globalVisitStatusCounts['escalated'] ?? 0),
            'open_incidents' => Incident::whereIn('status', ['open', 'investigating'])->count(),
            'blacklist_entries' => Blacklist::count(),
        ];

        // ── Per-company summary (oversight) ──
        $companies = Company::withCount(['users', 'departments', 'visitors', 'visitRequests'])->get();

        $companyIds = $companies->pluck('company_id');

        // Batch all active CEOs/admins per company (replaces per-company lookups).
        $leaders = User::whereIn('company_id', $companyIds)
            ->whereIn('role', ['ceo', 'admin'])
            ->where('status', 'active')
            ->get()
            ->groupBy(fn ($u) => $u->role.'|'.$u->company_id);

        // Batch per-company visit counts (replaces 4 COUNT queries per company).
        $visitStatusCounts = VisitRequest::whereIn('company_id', $companyIds)
            ->selectRaw('company_id, status, COUNT(*) as c')
            ->groupBy('company_id', 'status')
            ->get()
            ->groupBy('company_id')
            ->mapWithKeys(fn ($rows, $cid) => [$cid => $rows->pluck('c', 'status')]);

        $visitTodayCounts = VisitRequest::whereIn('company_id', $companyIds)
            ->where('created_at', '>=', $today->copy()->startOfDay())
            ->where('created_at', '<', $today->copy()->endOfDay())
            ->selectRaw('company_id, COUNT(*) as c')
            ->groupBy('company_id')
            ->pluck('c', 'company_id');

        $companies = $companies->map(function ($company) use ($leaders, $visitStatusCounts, $visitTodayCounts) {
            $ceo = $leaders->get('ceo|'.$company->company_id)?->first();
            $admin = $leaders->get('admin|'.$company->company_id)?->first();
            $statuses = $visitStatusCounts->get($company->company_id, collect());

            return [
                'company_id' => $company->company_id,
                'company_name' => $company->company_name,
                'code' => $company->code,
                'description' => $company->description,
                'industry' => $company->industry,
                'status' => $company->status,
                'is_active' => (bool) $company->is_active,
                'users_count' => $company->users_count,
                'departments_count' => $company->departments_count,
                'visitors_count' => $company->visitors_count,
                'visits_count' => $company->visit_requests_count,
                'visits_today' => (int) ($visitTodayCounts[$company->company_id] ?? 0),
                'active_sessions' => (int) ($statuses['checked_in'] ?? 0),
                'pending_approvals' => (int) ($statuses['pending'] ?? 0),
                'escalations' => (int) ($statuses['escalated'] ?? 0),
                'ceo_id' => $ceo?->id,
                'ceo_name' => $ceo?->name,
                'ceo_email' => $ceo?->email,
                'admin_id' => $admin?->id,
                'admin_name' => $admin?->name,
                'admin_email' => $admin?->email,
            ];
        });

        // ── Activity log (across all companies / branches) ──
        $activityService = new IncidentReportService;
        [$activityStart, $activityEnd] = $activityService->dateRange('daily');
        $activityRows = AuditLog::withoutGlobalScopes()
            ->with(['company', 'branch'])
            ->latest()
            ->take(100)
            ->get()
            ->map(fn ($log) => $this->mapActivityRow($log));

        $activityLog = [
            'period' => 'daily',
            'period_label' => $activityService->periodLabel('daily', $activityStart, $activityEnd),
            'range' => [
                'start' => $activityStart->format('Y-m-d'),
                'end' => $activityEnd->format('Y-m-d'),
            ],
            'selected_company' => null,
            'selected_branch' => null,
            'companies' => $this->incidentCompanyTree(),
            'logs' => $activityRows,
            'count' => $activityRows->count(),
        ];

        // ── System Admin activity monitoring (actions performed by system admins) ──
        $systemAdminIds = User::withoutGlobalScopes()->where('role', 'system_admin')->pluck('id');

        $systemAdminActivity = AuditLog::withoutGlobalScopes()
            ->where('actor_type', 'user')
            ->whereIn('actor_id', $systemAdminIds)
            ->latest()
            ->take(50)
            ->get();

        // Batch-load actors and company names instead of one query per row.
        $actorIds = $systemAdminActivity->pluck('actor_id')->filter()->unique()->values();
        $actors = $actorIds->isEmpty()
            ? collect()
            : User::withoutGlobalScopes()->whereIn('id', $actorIds)->get()->keyBy('id');

        $activityCompanyIds = $systemAdminActivity->pluck('company_id')->filter()->unique()->values();
        $activityCompanyNames = $activityCompanyIds->isEmpty()
            ? collect()
            : Company::withoutGlobalScopes()->whereIn('company_id', $activityCompanyIds)->pluck('company_name', 'company_id');

        $systemAdminActivity = $systemAdminActivity
            ->map(function ($log) use ($actors, $activityCompanyNames) {
                $actor = $actors->get($log->actor_id);

                return [
                    'id' => $log->id,
                    'actor_id' => $log->actor_id,
                    'actor_name' => $actor?->name ?? 'System Admin',
                    'actor_email' => $actor?->email,
                    'actor_role' => $actor?->role ?? 'system_admin',
                    'action' => $log->action,
                    'entity' => $log->entity,
                    'entity_id' => $log->entity_id,
                    'company_id' => $log->company_id,
                    'company_name' => $activityCompanyNames[$log->company_id] ?? null,
                    'created_at' => $log->created_at?->toDateTimeString(),
                    'ip_address' => $log->ip_address,
                    'before_value' => $log->before_value,
                    'after_value' => $log->after_value,
                    'status' => 'successful', // Default status, can be enhanced based on action
                    'description' => $this->getActivityDescription($log->action, $log->entity, $log->entity_id),
                ];
            });

        // ── Security monitoring ──
        $incidentService = new IncidentReportService;
        [$incidentStart, $incidentEnd] = $incidentService->dateRange('daily');
        $incidentLog = $incidentService->report(null, 'daily', null);

        $securityIncidentStatusCounts = Incident::selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status');
        $securityCriticalIncidents = Incident::where('severity', 'critical')->count();

        $security = [
            'blacklist_hits' => AuditLog::where('action', 'like', '%blacklist%')->count(),
            'failed_validations' => AuditLog::where('action', 'visit_request_failed_validation')->count(),
            'sla_overdue' => AuditLog::where('action', 'sla_admin_overdue')->count(),
            'lockdown_active' => (
                ((string) optional(
                    DB::table('cache')->where('key', 'emergency_lockdown')->first()
                )->value ?? '') === 'true'
            ) || Lockdown::query()->where('status', 'active')->exists(),
            'incident_stats' => [
                'total' => (int) $securityIncidentStatusCounts->sum(),
                'open' => (int) ($securityIncidentStatusCounts['open'] ?? 0),
                'investigating' => (int) ($securityIncidentStatusCounts['investigating'] ?? 0),
                'resolved' => (int) ($securityIncidentStatusCounts['resolved'] ?? 0),
                'escalated' => (int) ($securityIncidentStatusCounts['escalated'] ?? 0),
                'critical' => $securityCriticalIncidents,
            ],
            'incident_log' => [
                'period' => 'daily',
                'period_label' => $incidentService->periodLabel('daily', $incidentStart, $incidentEnd),
                'range' => [
                    'start' => $incidentStart->format('Y-m-d'),
                    'end' => $incidentEnd->format('Y-m-d'),
                ],
                'selected_company' => null,
                'selected_branch' => null,
                'companies' => $this->incidentCompanyTree(),
                'incidents' => $incidentLog['rows'],
                'count' => $incidentLog['count'],
                'stats' => $incidentLog['stats'],
            ],
        ];

        return Inertia::render('Dashboard/SystemAdmin', [
            'stats' => $stats,
            'companies' => $companies,
            'users' => User::with('company')
                ->orderBy('name')
                ->get(['id', 'name', 'first_name', 'last_name', 'email', 'phone', 'role', 'company_id', 'position', 'status']),
            'system_admin_activity' => $systemAdminActivity,
            'activity_log' => $activityLog,
            'security' => $security,
            'messages_inbox' => MessageController::inboxList('user', $user->id),
            'messages_sent' => MessageController::sentList('user', $user->id),
            'messages_recipients' => MessageController::recipientsFor('user', $user),
            'messages_unread_count' => Message::unreadCount('user', $user->id),
            'sections' => ['overview', 'companies', 'users', 'activity', 'sys_activity', 'security', 'messages'],
            'section' => $section,
        ]);
    }

    public function storeCompany(Request $request)
    {
        $validated = $request->validate([
            'company_name' => 'required|string|max:200|unique:companies,company_name',
            'code' => 'nullable|string|max:50|unique:companies,code',
            'industry' => 'nullable|string|max:150',
            'description' => 'nullable|string',
            'phone' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:150',
            'website' => 'nullable|url|max:255',
            'is_active' => 'boolean',
            'ceo_id' => 'nullable|integer|exists:users,id',
            'admin_id' => 'nullable|integer|exists:users,id',
            'ceo_first_name' => 'nullable|string|max:100',
            'ceo_last_name' => 'nullable|string|max:100',
            'ceo_gender' => 'nullable|in:male,female,other',
            'ceo_age' => 'nullable|integer|min:18|max:120',
            'ceo_national_id' => 'nullable|string|max:20',
            'ceo_position' => 'nullable|string|max:100',
            'ceo_email' => 'nullable|email|max:150',
            'ceo_phone' => 'nullable|string|max:20',
            'ceo_password' => 'nullable|string|min:8|confirmed',
            'admin_first_name' => 'nullable|string|max:100',
            'admin_last_name' => 'nullable|string|max:100',
            'admin_gender' => 'nullable|in:male,female,other',
            'admin_age' => 'nullable|integer|min:18|max:120',
            'admin_national_id' => 'nullable|string|max:20',
            'admin_position' => 'nullable|string|max:100',
            'admin_email' => 'nullable|email|max:150',
            'admin_phone' => 'nullable|string|max:20',
            'admin_password' => 'nullable|string|min:8|confirmed',
        ]);

        $this->companyService->createCompany($validated);

        return redirect()->route('system-admin.section', 'companies')->with('success', 'Company created.');
    }

    public function updateCompany(Request $request, Company $company)
    {
        $validated = $request->validate([
            'company_name' => 'required|string|max:200|unique:companies,company_name,'.$company->company_id.',company_id',
            'code' => 'nullable|string|max:50|unique:companies,code,'.$company->company_id.',company_id',
            'industry' => 'nullable|string|max:150',
            'description' => 'nullable|string',
            'phone' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:150',
            'website' => 'nullable|url|max:255',
            'is_active' => 'boolean',
            'ceo_id' => 'nullable|integer|exists:users,id',
            'admin_id' => 'nullable|integer|exists:users,id',
            'ceo_first_name' => 'nullable|string|max:100',
            'ceo_last_name' => 'nullable|string|max:100',
            'ceo_gender' => 'nullable|in:male,female,other',
            'ceo_age' => 'nullable|integer|min:18|max:120',
            'ceo_national_id' => 'nullable|string|max:20',
            'ceo_position' => 'nullable|string|max:100',
            'ceo_email' => 'nullable|email|max:150',
            'ceo_phone' => 'nullable|string|max:20',
            'ceo_password' => 'nullable|string|min:8|confirmed',
            'admin_first_name' => 'nullable|string|max:100',
            'admin_last_name' => 'nullable|string|max:100',
            'admin_gender' => 'nullable|in:male,female,other',
            'admin_age' => 'nullable|integer|min:18|max:120',
            'admin_national_id' => 'nullable|string|max:20',
            'admin_position' => 'nullable|string|max:100',
            'admin_email' => 'nullable|email|max:150',
            'admin_phone' => 'nullable|string|max:20',
            'admin_password' => 'nullable|string|min:8|confirmed',
        ]);

        $this->companyService->updateCompany($company, $validated);

        return redirect()->route('system-admin.section', 'companies')->with('success', 'Company updated.');
    }

    public function destroyCompany(Company $company)
    {
        if ($company->visitRequests()->exists() || $company->visitors()->exists()) {
            return redirect()->route('system-admin.section', 'companies')
                ->with('error', 'Cannot delete company with existing visits.');
        }

        $this->companyService->deleteCompany($company);

        return redirect()->route('system-admin.section', 'companies')->with('success', 'Company deleted.');
    }

    public function toggleCompanyStatus(Company $company)
    {
        $this->companyService->setActive($company, ! $company->is_active);

        return redirect()->route('system-admin.section', 'companies')
            ->with('success', 'Company '.($company->status === 'active' ? 'activated' : 'deactivated').'.');
    }

    public function storeUser(Request $request)
    {
        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'first_name' => 'required_without:name|string|max:100',
            'last_name' => 'nullable|string|max:100',
            'email' => 'required|email|unique:users',
            'phone' => 'required|string|max:30|regex:/^\+?[0-9][0-9\s\-\.\(\)]{7,16}$/',
            'password' => 'required|min:8',
            'role' => 'required|in:system_admin,admin,manager,host,security_guard,ceo',
            'company_id' => 'nullable|exists:companies,company_id',
            'department_id' => 'nullable|exists:departments,id',
            'branch_id' => 'nullable|exists:branches,id',
            'location_id' => 'nullable|exists:locations,id',
            'position' => 'nullable|string|max:150',
            'nationality' => 'nullable|string|max:100',
            'sex' => 'nullable|in:male,female,other',
            'age' => 'nullable|integer|min:18|max:120',
            'national_id' => 'nullable|string|max:30',
            'status' => 'required|in:active,pending,suspended',
        ]);

        $name = trim(($validated['first_name'] ?? '').' '.($validated['last_name'] ?? ''));
        if ($name === '') {
            $name = $validated['name'] ?? $validated['email'];
        }

        $user = User::create([
            'name' => $name ?: $validated['email'],
            'first_name' => $validated['first_name'] ?? null,
            'last_name' => $validated['last_name'] ?? null,
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'password' => bcrypt($validated['password']),
            'role' => $validated['role'],
            'company_id' => $validated['company_id'] ?? null,
            'department_id' => $validated['department_id'] ?? null,
            'branch_id' => $validated['branch_id'] ?? null,
            'location_id' => $validated['location_id'] ?? null,
            'position' => $validated['position'] ?? null,
            'nationality' => $validated['nationality'] ?? null,
            'sex' => $validated['sex'] ?? null,
            'age' => $validated['age'] ?? null,
            'national_id' => $validated['national_id'] ?? null,
            'status' => $validated['status'],
        ]);

        AuditLogger::log('system_admin_user_created', 'users', $user->id, null, $validated, $user->company_id ?? null);

        return redirect()->route('system-admin.section', 'users')
            ->with('success', 'System user created.');
    }

    public function updateUser(Request $request, User $user)
    {
        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'first_name' => 'required_without:name|string|max:100',
            'last_name' => 'nullable|string|max:100',
            'email' => 'required|email|unique:users,email,'.$user->id,
            'phone' => 'nullable|string|max:30',
            'password' => 'nullable|min:8',
            'role' => 'required|in:system_admin,admin,manager,host,security_guard,ceo',
            'company_id' => 'nullable|exists:companies,company_id',
            'department_id' => 'nullable|exists:departments,id',
            'branch_id' => 'nullable|exists:branches,id',
            'location_id' => 'nullable|exists:locations,id',
            'position' => 'nullable|string|max:150',
            'nationality' => 'nullable|string|max:100',
            'sex' => 'nullable|in:male,female,other',
            'age' => 'nullable|integer|min:18|max:120',
            'national_id' => 'nullable|string|max:30',
            'status' => 'required|in:active,pending,suspended',
        ]);

        $name = trim(($validated['first_name'] ?? '').' '.($validated['last_name'] ?? ''));
        if ($name === '') {
            $name = $validated['name'] ?? $user->name;
        }

        $data = [
            'name' => $name,
            'first_name' => $validated['first_name'] ?? null,
            'last_name' => $validated['last_name'] ?? null,
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'role' => $validated['role'],
            'company_id' => $validated['company_id'] ?? null,
            'department_id' => $validated['department_id'] ?? null,
            'branch_id' => $validated['branch_id'] ?? null,
            'location_id' => $validated['location_id'] ?? null,
            'position' => $validated['position'] ?? null,
            'nationality' => $validated['nationality'] ?? null,
            'sex' => $validated['sex'] ?? null,
            'age' => $validated['age'] ?? null,
            'national_id' => $validated['national_id'] ?? null,
            'status' => $validated['status'],
        ];

        if ($validated['password']) {
            $data['password'] = bcrypt($validated['password']);
        }

        $before = $user->toArray();
        $user->update($data);

        AuditLogger::log('system_admin_user_updated', 'users', $user->id, $before, $user->toArray(), $user->company_id ?? null);

        return redirect()->route('system-admin.section', 'users')
            ->with('success', 'System user updated.');
    }

    public function toggleUserStatus(User $user)
    {
        $user->status = $user->status === 'active' ? 'suspended' : 'active';
        $user->save();

        AuditLogger::log('system_admin_user_status_updated', 'users', $user->id, null, ['status' => $user->status], $user->company_id ?? null);

        return redirect()->route('system-admin.section', 'users')
            ->with('success', 'System user '.($user->status === 'active' ? 'activated' : 'suspended').'.');
    }

    public function destroyUser(User $user)
    {
        $current = Auth::guard('web')->user();
        if ($current && $current->id === $user->id) {
            return redirect()->route('system-admin.section', 'users')
                ->with('error', 'You cannot delete your own account.');
        }

        $before = $user->toArray();
        $companyId = $user->company_id;

        DB::table('company_user')->where('user_id', $user->id)->delete();
        $user->delete();

        AuditLogger::log('system_admin_user_deleted', 'users', $user->id, $before, null, $companyId);

        return redirect()->route('system-admin.section', 'users')
            ->with('success', 'System user deleted.');
    }

    /**
     * Get filtered System Admin activities for AJAX requests
     */
    public function getFilteredActivities(Request $request)
    {
        $user = Auth::guard('web')->user();
        if (! $user || ! in_array($user->role, ['system_admin', 'admin'])) {
            abort(403);
        }

        $systemAdminIds = User::withoutGlobalScopes()->where('role', 'system_admin')->pluck('id');

        $query = AuditLog::withoutGlobalScopes()
            ->where('actor_type', 'user')
            ->whereIn('actor_id', $systemAdminIds);

        // Filter by date range
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        // Filter by company
        if ($request->filled('company_id')) {
            $query->where('company_id', $request->company_id);
        }

        // Filter by activity type (action)
        if ($request->filled('action')) {
            $query->where('action', $request->action);
        }

        // Filter by specific System Admin
        if ($request->filled('admin_id')) {
            $query->where('actor_id', $request->admin_id);
        }

        // Filter by today's activities
        if ($request->boolean('today_only')) {
            $query->whereDate('created_at', today());
        }

        $activities = $query->latest()->take(100)->get()->map(function ($log) {
            $actor = User::withoutGlobalScopes()->find($log->actor_id);

            return [
                'id' => $log->id,
                'actor_id' => $log->actor_id,
                'actor_name' => $actor?->name ?? 'System Admin',
                'actor_email' => $actor?->email,
                'actor_role' => $actor?->role ?? 'system_admin',
                'action' => $log->action,
                'entity' => $log->entity,
                'entity_id' => $log->entity_id,
                'company_id' => $log->company_id,
                'company_name' => $log->company_id
                    ? Company::withoutGlobalScopes()->where('company_id', $log->company_id)->value('company_name')
                    : null,
                'created_at' => $log->created_at?->toDateTimeString(),
                'ip_address' => $log->ip_address,
                'before_value' => $log->before_value,
                'after_value' => $log->after_value,
                'status' => 'successful',
                'description' => $this->getActivityDescription($log->action, $log->entity, $log->entity_id),
            ];
        });

        return response()->json([
            'activities' => $activities,
            'total' => $query->count(),
        ]);
    }

    /**
     * JSON payload for the System Admin Security Incident log.
     *
     * Supports cascading company → branch filtering and time-framed reports.
     */
    public function getIncidentData(Request $request)
    {
        $user = Auth::guard('web')->user();
        if (! $user || ! in_array($user->role, ['system_admin', 'admin'])) {
            abort(403);
        }

        $period = in_array($request->input('period'), IncidentReportService::PERIODS, true)
            ? $request->input('period') : 'daily';
        $companyId = $request->filled('company_id') ? (int) $request->input('company_id') : null;
        $branchId = $request->filled('branch_id') ? (int) $request->input('branch_id') : null;

        $selectedCompany = null;
        if ($companyId) {
            $selectedCompany = Company::find($companyId);
            if (! $selectedCompany) {
                return response()->json(['error' => 'Company not found.'], 404);
            }
        }

        $selectedBranch = null;
        if ($branchId) {
            $selectedBranch = Branch::find($branchId);
            if (! $selectedBranch) {
                return response()->json(['error' => 'Branch not found.'], 404);
            }
            if ($companyId && $selectedBranch->company_id !== $companyId) {
                return response()->json(['error' => 'Branch does not belong to the selected company.'], 422);
            }
        }

        $service = new IncidentReportService;
        [$start, $end] = $service->dateRange($period);
        $report = $service->report($branchId, $period, $companyId);

        return response()->json([
            'period' => $period,
            'period_label' => $service->periodLabel($period, $start, $end),
            'range' => [
                'start' => $start->format('Y-m-d'),
                'end' => $end->format('Y-m-d'),
            ],
            'selected_company' => $selectedCompany
                ? ['id' => $selectedCompany->company_id, 'name' => $selectedCompany->company_name]
                : null,
            'selected_branch' => $selectedBranch
                ? ['id' => $selectedBranch->id, 'name' => $selectedBranch->name]
                : null,
            'companies' => $this->incidentCompanyTree(),
            'incidents' => $report['rows'],
            'count' => $report['count'],
            'stats' => $report['stats'],
        ]);
    }

    /**
     * JSON payload for the System Admin global Activity Log.
     *
     * Supports cascading company → branch filtering plus Daily / Weekly /
     * Monthly / Yearly or a custom date range.
     */
    public function getActivityData(Request $request)
    {
        $user = Auth::guard('web')->user();
        if (! $user || ! in_array($user->role, ['system_admin', 'admin'])) {
            abort(403);
        }

        $service = new IncidentReportService;
        $period = $request->input('period');
        $isCustom = $period === 'custom';

        if ($isCustom && (! $request->filled('date_from') || ! $request->filled('date_to'))) {
            $isCustom = false;
            $period = 'daily';
        }
        if (! $isCustom && ! in_array($period, IncidentReportService::PERIODS, true)) {
            $period = 'daily';
        }

        $companyId = $request->filled('company_id') ? (int) $request->input('company_id') : null;
        $branchId = $request->filled('branch_id') ? (int) $request->input('branch_id') : null;

        $selectedCompany = null;
        if ($companyId) {
            $selectedCompany = Company::find($companyId);
            if (! $selectedCompany) {
                return response()->json(['error' => 'Company not found.'], 404);
            }
        }

        $selectedBranch = null;
        if ($branchId) {
            $selectedBranch = Branch::find($branchId);
            if (! $selectedBranch) {
                return response()->json(['error' => 'Branch not found.'], 404);
            }
            if ($companyId && $selectedBranch->company_id !== $companyId) {
                return response()->json(['error' => 'Branch does not belong to the selected company.'], 422);
            }
        }

        if ($isCustom) {
            [$start, $end] = [
                Carbon::parse($request->date_from)->startOfDay(),
                Carbon::parse($request->date_to)->endOfDay(),
            ];
        } else {
            [$start, $end] = $service->dateRange($period);
        }

        $query = AuditLog::withoutGlobalScopes()
            ->with(['company', 'branch'])
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereBetween('created_at', [$start, $end]);

        $logs = $query->latest()->take(200)->get()->map(fn ($log) => $this->mapActivityRow($log))->values();

        $label = $isCustom
            ? "Custom Range — {$request->date_from} to {$request->date_to}"
            : $service->periodLabel($period, $start, $end);

        return response()->json([
            'period' => $isCustom ? 'custom' : $period,
            'period_label' => $label,
            'range' => [
                'start' => $start->format('Y-m-d'),
                'end' => $end->format('Y-m-d'),
            ],
            'selected_company' => $selectedCompany
                ? ['id' => $selectedCompany->company_id, 'name' => $selectedCompany->company_name]
                : null,
            'selected_branch' => $selectedBranch
                ? ['id' => $selectedBranch->id, 'name' => $selectedBranch->name]
                : null,
            'companies' => $this->incidentCompanyTree(),
            'logs' => $logs,
            'count' => $logs->count(),
        ]);
    }

    /**
     * Map an audit log row for the System Admin Activity Log view.
     */
    private function mapActivityRow(AuditLog $log): array
    {
        $actorName = 'System';
        $actorRole = 'system';
        $actorPhone = null;

        if ($log->actor_type === 'user' && $log->actor) {
            $actorName = $log->actor->name;
            $actorRole = $log->actor->role ?? 'user';
            $actorPhone = $log->actor->phone;
        } elseif ($log->actor_type === 'visitor' && $log->actor) {
            $actorName = $log->actor->name;
            $actorRole = 'visitor';
            $actorPhone = $log->actor->phone;
        }

        return [
            'id' => $log->id,
            'actor_name' => $actorName,
            'actor_type' => $log->actor_type,
            'actor_role' => $actorRole,
            'actor_phone' => $actorPhone,
            'action' => $log->action,
            'entity' => $log->entity,
            'entity_id' => $log->entity_id,
            'company_id' => $log->company_id,
            'company_name' => $log->company?->company_name,
            'branch_id' => $log->branch_id,
            'branch_name' => $log->branch?->name,
            'ip_address' => $log->ip_address,
            'created_at' => $log->created_at?->toDateTimeString(),
            'status' => $this->inferActivityStatus($log->action),
        ];
    }

    /**
     * Derive the action outcome (success / failed / unauthorized) from the action name.
     */
    private function inferActivityStatus(string $action): string
    {
        $failedActions = [
            'visit_request_failed_validation',
            'auto_expired_visit',
            'rejected_visit',
            'reject_user',
            'reject_admin',
            'visitor_no_show',
            'force_checkout_missing_property',
            'property_owner_mismatch',
        ];

        if (in_array($action, $failedActions, true)) {
            return 'failed';
        }

        $unauthorizedActions = [
            'entry_denied',
            'blacklist_match',
            'blacklist_warning',
            'identity_mismatch',
            'fake_qr_detected',
            'fake_qr',
        ];

        if (in_array($action, $unauthorizedActions, true)) {
            return 'unauthorized';
        }

        return 'success';
    }

    /**
     * Cascading company → branch tree for the Security Incident log filters.
     */
    private function incidentCompanyTree(): Collection
    {
        $branches = Branch::with('location')->orderBy('name')->get(['id', 'name', 'company_id', 'location_id'])
            ->map(fn ($b) => [
                'id' => $b->id,
                'name' => $b->name,
                'company_id' => $b->company_id,
                'location_name' => $b->location?->name,
            ]);

        return Company::orderBy('company_name')->get(['company_id', 'company_name'])
            ->map(fn ($company) => [
                'company_id' => $company->company_id,
                'company_name' => $company->company_name,
                'branches' => $branches->where('company_id', $company->company_id)->values(),
            ]);
    }

    /**
     * Get human-readable description for an activity action
     */
    private function getActivityDescription(string $action, ?string $entity, ?int $entityId): string
    {
        $descriptions = [
            'company_created' => 'Created a new company',
            'company_updated' => 'Updated company details',
            'company_status_update' => 'Changed company status',
            'company_deleted' => 'Deleted a company',
            'company_ceo_assigned' => 'Assigned CEO to company',
            'company_admin_assigned' => 'Assigned Company Admin to company',
            'company_create' => 'Created a new company',
            'company_update' => 'Updated company details',
            'company_delete' => 'Deleted a company',
            'company_status_update' => 'Changed company status',
            'company_ceo_assign' => 'Assigned CEO to company',
            'company_admin_assign' => 'Assigned Company Admin to company',
            'system_admin_user_created' => 'Created a new user',
            'system_admin_user_updated' => 'Updated user details',
            'system_admin_user_status_updated' => 'Changed user status',
            'system_admin_user_deleted' => 'Deleted a user',
            'login' => 'Logged into the system',
            'logout' => 'Logged out of the system',
            'password_changed' => 'Changed password',
            'password_reset' => 'Reset password',
            'role_created' => 'Created a new role',
            'role_updated' => 'Updated role details',
            'permission_created' => 'Created a new permission',
            'permission_updated' => 'Updated permission details',
            'permission_assigned' => 'Assigned permission',
            'permission_removed' => 'Removed permission',
            'create_department' => 'Created a new department',
            'update_department' => 'Updated department details',
            'delete_department' => 'Deleted a department',
            'department_created' => 'Created a new department',
            'department_updated' => 'Updated department details',
            'department_deleted' => 'Deleted a department',
            'branch_created' => 'Created a new branch',
            'branch_updated' => 'Updated branch details',
            'branch_deleted' => 'Deleted a branch',
            'branch_status_update' => 'Changed branch status',
            'system_settings_changed' => 'Changed system settings',
            'security_settings_changed' => 'Changed security settings',
            'qr_configuration_changed' => 'Changed QR configuration',
            'notification_settings_changed' => 'Changed notification settings',
            'blacklist_policy_changed' => 'Changed blacklist/security policy',
            'system_configuration_changed' => 'Changed system configuration',
            'ceo_broadcast' => 'Sent a broadcast announcement',
            'ceo_policy_update' => 'Updated system policies',
            'ceo_export_visits' => 'Exported visit requests',
            'ceo_export_audit' => 'Exported audit logs',
            'emergency_lockdown_enabled' => 'Enabled emergency lockdown',
            'emergency_lockdown_disabled' => 'Disabled emergency lockdown',
            'approve_admin' => 'Approved admin account',
            'reject_admin' => 'Rejected admin account',
            'enable_2fa' => 'Enabled two-factor authentication',
            'disable_2fa' => 'Disabled two-factor authentication',
            'profile_update' => 'Updated profile',
            'registration' => 'Registered new account',
            'registration_upgrade' => 'Upgraded visitor account',
            'representative_registration' => 'Registered representative',
            'representative_login' => 'Representative logged in',
        ];

        return $descriptions[$action] ?? "Performed action: {$action}";
    }
}
