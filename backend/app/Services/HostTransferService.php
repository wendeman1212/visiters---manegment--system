<?php

namespace App\Services;

use App\Models\VisitRequest;
use App\Models\User;
use App\Models\HostTransfer;
use App\Models\VisitorBadge;
use App\Models\QRToken;
use App\Models\CheckInOutLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;
use Exception;

class HostTransferService
{
    protected $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * Transfer a visit request and all related host associations.
     *
     * Options:
     *  - validateScope (bool):  When true (default) the new host must belong to the
     *    visit's company/branch. The Admin/CEO escalation transfer uses cascade-
     *    selected recipients from an authorized company, so it passes false and the
     *    controller is responsible for company/branch/department validation.
     *  - moveToHostCompany (bool):  When true, the visit is relocated to the new
     *    host's company/branch/department so the recipient's dashboard scoping
     *    actually shows the visit (cross-company transfers).
     */
    public function transfer(VisitRequest $visitRequest, User $newHost, User $transferredBy, ?string $reason = null, array $options = []): HostTransfer
    {
        if ($visitRequest->host_id == $newHost->id) {
            throw new Exception('The new host must be different from the current host.');
        }

        if (in_array($visitRequest->status, ['completed', 'cancelled', 'no_show', 'checked_out'])) {
            throw new Exception('Cannot transfer a visit that is already completed, cancelled, no-show or checked out.');
        }

        $validateScope = $options['validateScope'] ?? true;
        if ($validateScope && !$this->isSameCompanyOrBranch($visitRequest, $newHost)) {
            throw new Exception('Host transfer is only allowed within the same company or the same branch.');
        }

        $previousHostId = $visitRequest->host_id;

        $transfer = DB::transaction(function () use ($visitRequest, $newHost, $transferredBy, $reason, $previousHostId, $options) {
            // 1. Update the host on the visit request
            $visitRequest->host_id = $newHost->id;
            // The previous host must lose every association so they no longer
            // see the visit (host dashboard queries host_id OR alternate_host_id).
            // The same applies when the new host was already the alternate host:
            // they are now the primary host, so the stale alternate link must go
            // too or the visit could be listed through both paths.
            if (in_array($visitRequest->alternate_host_id, [$previousHostId, $newHost->id], true)) {
                $visitRequest->alternate_host_id = null;
            }

            // Cross-company transfer: move the visit to the new host's company,
            // branch and department so it is visible in their scoped dashboard.
            if (!empty($options['moveToHostCompany'])) {
                $visitRequest->company_id = $newHost->company_id ?: $visitRequest->company_id;
                $visitRequest->branch_id = $newHost->branch_id;
                $visitRequest->department_id = $newHost->department_id;
            }
            $visitRequest->save();

            // SLA clock hand-off: a visit still awaiting a host decision
            // gives the new host a fresh SLA round; any other hand-off
            // simply stops the admin-level clock (responsibility moved).
            $sla = app(SlaService::class);
            if ($visitRequest->status === 'pending') {
                $sla->restartHostSla($visitRequest);
            } else {
                $sla->stopAdminSla($visitRequest);
            }

            // 1b. Responsibility handoff: transferring a visit that was escalated
            //     to a previous owner must hand that responsibility to the new
            //     recipient. Resolve any open escalation records (keeping the
            //     escalation history for the audit trail) and clear the previous
            //     escalation target so it no longer appears in the original
            //     owner's active escalation queue.
            if ($visitRequest->escalated_to && $visitRequest->escalated_to != $newHost->id) {
                $visitRequest->escalations()
                    ->whereNull('resolved_at')
                    ->update(['resolved_at' => now()]);

                $visitRequest->escalated_to = null;
                $visitRequest->save();
            }

            // 2. Update any other table that might store a host reference
            $this->updateRelatedHostReferences($visitRequest, $newHost->id);

            // 3. Create transfer log (record the target company/branch/department
            // when supplied so the full scope of the transfer is preserved)
            $transfer = HostTransfer::create([
                'visit_request_id' => $visitRequest->id,
                'previous_host_id' => $previousHostId,
                'new_host_id' => $newHost->id,
                'transferred_by' => $transferredBy->id,
                'company_id' => $options['company_id'] ?? $visitRequest->company_id,
                'branch_id' => $options['branch_id'] ?? $visitRequest->branch_id,
                'department_id' => $options['department_id'] ?? $visitRequest->department_id,
                'reason' => $reason,
            ]);

            // 4. Record audit log
            AuditLogger::log('host_transferred', 'visit_requests', $visitRequest->id,
                ['host_id' => $previousHostId],
                ['host_id' => $newHost->id, 'reason' => $reason, 'transferred_by' => $transferredBy->id]
            );

            // 5. Clear any cached lists for both hosts
            $this->clearHostCaches($previousHostId, $newHost->id, $visitRequest);

            return $transfer;
        });

        // 6. Send notifications outside the transaction so they can't roll it back.
        //    Callers that run their own richer notification flow (e.g. the host
        //    change request acceptance) pass notify => false to avoid duplicates.
        $notify = $options['notify'] ?? true;
        if ($notify) {
            try {
                $this->sendNotifications($visitRequest, $newHost, $transferredBy, $previousHostId);
            } catch (\Throwable $e) {
                Log::warning("Host transfer notifications failed for visit #{$visitRequest->id}: " . $e->getMessage());
            }
        }

        return $transfer;
    }

    /**
     * Determine whether the new host belongs to the same company or branch
     * as the visit request (or the current host). Transfers across companies
     * and branches are not allowed.
     *
     * Admin and CEO users are company-level: they have no branch, so a visit
     * from any branch of their company can be transferred to them. Regular
     * hosts are branch-level and must belong to the visit's branch.
     */
    protected function isSameCompanyOrBranch(VisitRequest $visitRequest, User $newHost): bool
    {
        if (in_array($newHost->role, ['admin', 'ceo'], true)) {
            $companyId = $visitRequest->company_id ?? $newHost->company_id;
            if (!$companyId) {
                return true;
            }

            return $newHost->company_id == $companyId
                || $newHost->companies()->where('company_id', $companyId)->exists();
        }

        if ($visitRequest->branch_id) {
            return $newHost->branch_id == $visitRequest->branch_id;
        }

        $previousHost = User::find($visitRequest->host_id);
        $companyId = $visitRequest->company_id ?? $previousHost?->company_id;
        if (!$companyId) {
            return true;
        }

        return $newHost->company_id == $companyId
            || $newHost->companies()->where('company_id', $companyId)->exists();
    }

    /**
     * Dynamically update any related model that has a `host_id` column.
     */
    protected function updateRelatedHostReferences(VisitRequest $visitRequest, int $newHostId): void
    {
        $modelsToCheck = [
            VisitorBadge::class,
            QRToken::class,
            CheckInOutLog::class,
        ];

        foreach ($modelsToCheck as $modelClass) {
            if (class_exists($modelClass)) {
                $table = (new $modelClass)->getTable();
                if (Schema::hasColumn($table, 'host_id')) {
                    $modelClass::where('visit_request_id', $visitRequest->id)
                        ->update(['host_id' => $newHostId]);
                }
            }
        }

        if (Schema::hasTable('visitors') && Schema::hasColumn('visitors', 'host_id')) {
            \App\Models\Visitor::where('id', $visitRequest->visitor_id)
                ->update(['host_id' => $newHostId]);
        }
    }

    /**
     * Clear any cached data for old and new hosts.
     */
    protected function clearHostCaches(int $oldHostId, int $newHostId, VisitRequest $visitRequest): void
    {
        Cache::forget("host_visits_{$oldHostId}");
        Cache::forget("host_visits_{$newHostId}");
        Cache::forget("visit_{$visitRequest->id}");
    }

    /**
     * Notify the new host, previous host and visitor regarding the transfer.
     */
    protected function sendNotifications(VisitRequest $visitRequest, User $newHost, User $transferredBy, ?int $previousHostId = null): void
    {
        $visitorName = $visitRequest->resolveVisitor()?->name ?? $visitRequest->company_name ?? 'Visitor';

        // 1. Notify the new host / CEO
        NotificationService::sendDeferred('New Visit Assigned',
            $newHost,
            "Visit #{$visitRequest->id} ({$visitorName}) has been transferred to you by {$transferredBy->name}."
        );

        // 2. Notify previous host if present and different
        if ($previousHostId) {
            $previousHost = User::find($previousHostId);
            if ($previousHost && $previousHost->id !== $newHost->id) {
                NotificationService::sendDeferred('Host Transfer',
                    $previousHost,
                    "Visit #{$visitRequest->id} ({$visitorName}) has been transferred to {$newHost->name} by {$transferredBy->name}."
                );
            }
        }

        // 3. Notify visitor if present
        $visitor = $visitRequest->resolveVisitor();
        if ($visitor) {
            NotificationService::sendDeferred('Host Changed',
                $visitor,
                "Your visit #{$visitRequest->id} host has been changed to {$newHost->name}."
            );
        }
    }
}
