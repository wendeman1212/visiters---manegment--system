<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\VisitRequest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\HostTransferService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Visitor Transfer for Company Admin / CEO.
 *
 * Both roles are company-level users and may transfer a visitor to an eligible
 * active Host — or to a company Admin/CEO — within any company they are
 * authorized for. The modal follows a Company -> Branch -> Department cascade.
 * When only a company is selected every eligible user of the company is
 * offered. Once a branch is selected the recipients are strictly limited to
 * users assigned to that branch — Hosts, Admins and CEOs bound to the branch —
 * and company-level users (branch_id NULL) are no longer offered. Only eligible
 * Hosts (role 'host', status 'active', assigned to the selected branch and
 * department) are offered as Host recipients.
 *
 * Everything is validated against the live database relationships; no users or
 * companies are hardcoded. The transfer is an Admin/CEO company-level action so
 * it works independently of the Host-side transfer and the escalation flow.
 */
class VisitorTransferController extends Controller
{
    /**
     * JSON cascade for the Admin/CEO visitor-transfer modal.
     *
     * Query params (all optional): company_id, branch_id, department_id.
     * - companies:  only the companies the actor is authorized to manage.
     * - branches:   active branches belonging to the selected company.
     * - departments:departments strictly scoped to the selection: branch chosen
     *               -> only that branch's departments; otherwise -> only the
     *               company-level departments (branch_id NULL).
     * - hosts:      eligible active Hosts of the selected company, narrowed to
     *               the selected branch and department when supplied,
     *               excluding the current responsible host.
     */
    public function options(Request $request, $id)
    {
        $actor = $this->actorOrAbort();

        $visit = VisitRequest::withoutGlobalScopes()->findOrFail($id);

        if (!$actor->hasCompanyAccess($visit->company_id)) {
            abort(403, 'You do not have access to this visit\'s company.');
        }

        $payload = [
            'companies' => $this->authorizedCompanies($actor),
            'visit'     => [
                'id'            => $visit->id,
                'visitor_name'  => $visit->resolveVisitor()?->name ?? $visit->company_name ?? 'Visitor',
                'company_id'    => $visit->company_id,
                'branch_id'     => $visit->branch_id,
                'department_id' => $visit->department_id,
                'host_id'       => $visit->host_id,
                'status'        => $visit->status,
            ],
            'branches'    => [],
            'departments' => [],
            'hosts'       => [],
            'managers'    => [],
        ];

        if (!$request->filled('company_id')) {
            return response()->json($payload);
        }

        $companyId = (int) $request->input('company_id');
        if (!$actor->hasCompanyAccess($companyId)) {
            abort(403, 'You are not authorized for the selected company.');
        }

        $payload['branches'] = Branch::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        $branchId = $request->filled('branch_id') ? (int) $request->input('branch_id') : null;
        if ($branchId !== null && !Branch::withoutGlobalScopes()
                ->where('id', $branchId)
                ->where('company_id', $companyId)
                ->exists()) {
            abort(422, 'The selected branch does not belong to this company.');
        }

        // Departments of the selected company. When a branch is selected only
        // that branch's departments are returned (recipients are branch-scoped,
        // so company-level departments are unreachable). When no branch is
        // selected only the company-level departments (branch_id NULL) are
        // returned.
        if ($branchId !== null) {
            $payload['departments'] = Department::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->orderBy('name')
                ->get(['id', 'name']);
        } else {
            $payload['departments'] = Department::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->whereNull('branch_id')
                ->orderBy('name')
                ->get(['id', 'name']);
        }

        $deptId = $request->filled('department_id') ? (int) $request->input('department_id') : null;
        if ($deptId !== null && !Department::withoutGlobalScopes()
                ->where('id', $deptId)
                ->where('company_id', $companyId)
                ->when($branchId !== null, function ($q) use ($branchId) {
                    $q->where('branch_id', $branchId);
                }, function ($q) {
                    $q->whereNull('branch_id');
                })
                ->exists()) {
            abort(422, 'The selected department does not belong to this branch/company.');
        }

        // Eligible Hosts load as soon as a company is selected (all eligible
        // active Hosts of the company). Selecting a branch narrows the list to
        // Hosts assigned to that branch, and selecting a department narrows it
        // further to that department. A department is never required before
        // Hosts are shown. The current responsible host is excluded.
        // Company Admins/CEOs are offered separately in the 'managers' list.
        $payload['hosts'] = $this->eligibleHostsFor($companyId, $branchId, $deptId, $visit, $actor);

        // Company Admins/CEOs are offered as transfer recipients as soon as a
        // company is selected. Once a branch is selected only managers bound to
        // that branch remain eligible (company-level Admins/CEOs are excluded),
        // because recipients are strictly branch-scoped. The current responsible
        // host and the acting user are excluded.
        $payload['managers'] = $this->eligibleManagersFor($companyId, $branchId, $visit, $actor);

        return response()->json($payload);
    }

    /**
     * Confirm an Admin/CEO visitor transfer.
     *
     * Re-validates the full cascade on the server, transfers responsibility to
     * the new Host (old Host loses it immediately), preserves every visit /
     * approval / property / history record, records a complete transfer log +
     * audit entry and notifies the new Host.
     */
    public function store(Request $request, $id)
    {
        $actor = $this->actorOrAbort();

        $data = $request->validate([
            'new_host_id'   => 'required|integer',
            'company_id'    => 'required|integer',
            'branch_id'     => 'nullable|integer',
            'department_id' => 'nullable|integer',
            'reason'        => 'nullable|string|max:1000',
        ]);

        $visit = VisitRequest::withoutGlobalScopes()->findOrFail($id);

        if (!$actor->hasCompanyAccess($visit->company_id)) {
            abort(403, 'You do not have access to this visit\'s company.');
        }

        if (in_array($visit->status, ['completed', 'cancelled', 'no_show', 'checked_out'], true)) {
            return back()->with('error', 'Cannot transfer a visit that is already completed, cancelled, no-show or checked out.');
        }

        if (!$actor->hasCompanyAccess($data['company_id'])) {
            abort(403, 'You are not authorized for the selected company.');
        }

        $company = Company::withoutGlobalScopes()->find($data['company_id']);
        if (!$company) {
            return back()->with('error', 'The selected company does not exist.');
        }

        $branch = null;
        if (($data['branch_id'] ?? null) !== null) {
            $branch = Branch::withoutGlobalScopes()
                ->where('id', $data['branch_id'])
                ->where('company_id', $company->company_id)
                ->first();
            if (!$branch) {
                return back()->with('error', 'The selected branch does not belong to the selected company.');
            }
        }

        $department = null;
        if (($data['department_id'] ?? null) !== null) {
            $department = Department::withoutGlobalScopes()
                ->where('id', $data['department_id'])
                ->where('company_id', $company->company_id)
                ->when($branch !== null, function ($q) use ($branch) {
                    $q->where('branch_id', $branch->id);
                }, function ($q) {
                    $q->whereNull('branch_id');
                })
                ->first();
            if (!$department) {
                return back()->with('error', 'The selected department does not belong to the selected branch/company.');
            }
        }

        // The recipient must be an active user of the selected company. When a
        // branch is selected the recipient must be assigned to that branch — for
        // Hosts as well as Admins/CEOs — mirroring the exact rule used to build
        // the recipient dropdown so a listed recipient can never be rejected at
        // confirm time. When no branch is selected, Hosts are company-wide and
        // Admins/CEOs are company-level.
        $newHost = User::withoutGlobalScopes()
            ->whereIn('role', ['host', 'admin', 'ceo'])
            ->where('status', 'active')
            ->where('company_id', $company->company_id)
            ->where(function ($q) use ($branch, $department) {
                // Admins/CEOs: branch-bound once a branch is selected, otherwise
                // any active company-level manager is eligible.
                $q->where(function ($q1) use ($branch) {
                    $q1->whereIn('role', ['admin', 'ceo']);
                    if ($branch !== null) {
                        $q1->where('branch_id', $branch->id);
                    }
                });
                // Hosts: strictly branch-scoped when a branch is selected.
                $q->orWhere(function ($q2) use ($branch, $department) {
                    $q2->where('role', 'host');
                    if ($branch !== null) {
                        $q2->where('branch_id', $branch->id);
                    }
                    if ($department !== null) {
                        $q2->where('department_id', $department->id);
                    }
                });
            })
            ->find($data['new_host_id']);

        if (!$newHost) {
            return back()->with('error', 'The selected user is not an active, eligible Host or Admin/CEO for the selected company/branch/department.');
        }
        if ($newHost->id === $visit->host_id) {
            return back()->with('error', 'The visit is already assigned to the selected Host.');
        }
        if ($newHost->id === $actor->id) {
            return back()->with('error', 'You cannot transfer the visit to yourself.');
        }

        // When no branch/department was selected, fall back to the new Host's
        // own branch/department so the transfer record is always complete.
        $effectiveBranchId = $branch?->id ?? $newHost->branch_id;
        $effectiveDeptId = $department?->id ?? $newHost->department_id;

        $previousHostId = $visit->host_id;

        try {
            $oldState = $visit->toArray();

            DB::transaction(function () use ($visit, $newHost, $actor, $data, $previousHostId, $oldState, $company, $effectiveBranchId, $effectiveDeptId) {
                $transfer = app(HostTransferService::class)->transfer($visit, $newHost, $actor, $data['reason'] ?? null, [
                    'validateScope'     => false,
                    'moveToHostCompany' => true,
                    'company_id'        => $company->company_id,
                    'branch_id'         => $effectiveBranchId,
                    'department_id'     => $effectiveDeptId,
                ]);

                // Fully hand over responsibility from the previous owner
                // (Admin/CEO or Host) to the new recipient. An escalated visit
                // must return to 'pending' so the receiving Host approves it and
                // it actually appears in their approvals queue — a Host has no
                // escalation section, so leaving it 'escalated' made the visit
                // invisible on the host page. The previous escalation target is
                // cleared and any open escalation record is resolved. Approved /
                // checked-in visits keep their status so they stay in the
                // recipient's appointments / active sections.
                if ($visit->status === 'escalated') {
                    $visit->status = 'pending';
                }
                $visit->escalated_to = null;
                $visit->save();

                $sla = app(\App\Services\SlaService::class);
                if ($visit->status === 'pending') {
                    $sla->restartHostSla($visit);
                } else {
                    $sla->stopAdminSla($visit);
                }
                $visit->escalations()->whereNull('resolved_at')->update(['resolved_at' => now()]);

                AuditLogger::log(
                    'visitor_transferred',
                    'visit_requests',
                    $visit->id,
                    $oldState,
                    [
                        'previous_host_id' => $previousHostId,
                        'new_host_id'      => $newHost->id,
                        'transferred_by'   => $actor->id,
                        'reason'           => $data['reason'] ?? null,
                        'company_id'       => $company->company_id,
                        'branch_id'        => $effectiveBranchId,
                        'department_id'    => $effectiveDeptId,
                        'transfer_id'      => $transfer->id,
                    ],
                    $company->company_id,
                    $effectiveBranchId
                );
            });

            return back()->with('success', "Visitor transferred to {$newHost->name}.");
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * The acting company-level user (Admin or CEO) or abort.
     */
    protected function actorOrAbort(): User
    {
        $actor = Auth::guard('web')->user();

        if (!$actor || !in_array($actor->role, ['admin', 'ceo'], true)) {
            abort(403, 'Only a Company Admin or CEO can transfer visitors.');
        }

        return $actor;
    }

    /**
     * Companies the actor may transfer INTO (own company + authorized pivots).
     */
    protected function authorizedCompanies(User $actor): array
    {
        if ($actor->hasRole('system_admin')) {
            $companies = Company::withoutGlobalScopes()->orderBy('company_name')->get(['company_id', 'company_name']);
        } else {
            $ids = array_values(array_filter(array_unique([
                $actor->company_id,
                ...$actor->companies()->withoutGlobalScopes()->pluck('companies.company_id')->all(),
            ])));

            $companies = $ids
                ? Company::withoutGlobalScopes()->whereIn('company_id', $ids)->orderBy('company_name')->get(['company_id', 'company_name'])
                : collect();
        }

        return $companies->map(fn ($c) => [
            'company_id'   => $c->company_id,
            'company_name' => $c->company_name,
        ])->all();
    }

    /**
     * Eligible active Hosts of a company, optionally narrowed by branch and/or
     * department.
     *
     * A Host is eligible when they belong to the selected company, are active
     * and have role 'host'. When a branch is supplied only Hosts assigned to
     * that branch are returned. When a department is supplied they must be
     * assigned to it. Admins/CEOs are intentionally never returned here.
     */
    protected function eligibleHostsFor(int $companyId, ?int $branchId, ?int $deptId, VisitRequest $visit, User $actor): array
    {
        return User::withoutGlobalScopes()
            ->where('role', 'host')
            ->where('status', 'active')
            ->where('company_id', $companyId)
            // Branch scoping is strict: once a branch is chosen only Hosts
            // assigned to that branch are eligible. Company-level Hosts
            // (branch_id NULL) are only reachable with a company-only transfer.
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->when($deptId !== null, fn ($q) => $q->where('department_id', $deptId))
            ->whereNotIn('id', array_values(array_unique(array_filter([$visit->host_id, $actor->id]))))
            ->with([
                'department' => fn ($q) => $q->select('id', 'name')->withoutGlobalScopes(),
                'branch'     => fn ($q) => $q->select('id', 'name')->withoutGlobalScopes(),
            ])
            ->orderBy('name')
            ->get(['id', 'name', 'branch_id', 'department_id'])
            ->map(fn ($h) => [
                'id'            => $h->id,
                'name'          => $h->name,
                'branch_id'     => $h->branch_id,
                'department_id' => $h->department_id,
                'department'    => $h->department?->name,
                'branch'        => $h->branch?->name,
            ])
            ->all();
    }

    /**
     * Active Admins/CEOs eligible as transfer recipients.
     *
     * Once a company is selected they are offered. When a branch is selected
     * only managers bound to that branch remain eligible (company-level
     * Admins/CEOs are excluded), keeping recipients strictly branch-scoped. The
     * current responsible host and the acting user are excluded.
     */
    protected function eligibleManagersFor(int $companyId, ?int $branchId, VisitRequest $visit, User $actor): array
    {
        return User::withoutGlobalScopes()
            ->whereIn('role', ['admin', 'ceo'])
            ->where('status', 'active')
            ->where('company_id', $companyId)
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->whereNotIn('id', array_values(array_unique(array_filter([$visit->host_id, $actor->id]))))
            ->with([
                'department' => fn ($q) => $q->select('id', 'name')->withoutGlobalScopes(),
                'branch'     => fn ($q) => $q->select('id', 'name')->withoutGlobalScopes(),
            ])
            ->orderBy('name')
            ->get(['id', 'name', 'role', 'branch_id', 'department_id'])
            ->map(fn ($m) => [
                'id'            => $m->id,
                'name'          => $m->name,
                'role'          => $m->role,
                'branch_id'     => $m->branch_id,
                'department_id' => $m->department_id,
                'department'    => $m->department?->name,
                'branch'        => $m->branch?->name,
            ])->all();
    }
}
