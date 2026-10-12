<?php

namespace App\Services;

use App\Models\Announcement;
use App\Models\Branch;
use App\Models\Incident;
use App\Models\IncidentAuditTrail;
use App\Models\Lockdown;
use App\Models\User;
use App\Models\VisitRequest;
use Illuminate\Support\Facades\DB;

/**
 * DB-driven, scoped Security Incident / Lockdown / Broadcast orchestration.
 *
 * Scope model:
 *   - Company scope: company_id set, branch_id = null  -> covers every branch.
 *   - Branch scope:  company_id set, branch_id set     -> covers one branch only.
 *
 * Nothing written here can ever leak across companies because every record is
 * explicitly keyed by company_id (and optionally branch_id).
 */
class SecurityIncidentService
{
    /**
     * Resolve + validate the target scope from raw request data.
     *
     * @return array{company_id:int, branch_id:?int, scope_type:string}
     */
    public static function resolveScope(array $data, User $actor): array
    {
        $companyId = (int) ($data['company_id'] ?? $actor->company_id);
        $scopeType = ($data['scope_type'] ?? 'company') === 'branch' ? 'branch' : 'company';
        $branchId  = null;

        if (!$companyId) {
            throw new \InvalidArgumentException('A company scope is required.');
        }

        if ($scopeType === 'branch') {
            $branchId = (int) ($data['branch_id'] ?? 0);
            if (!$branchId) {
                throw new \InvalidArgumentException('Please select a branch for the branch-level scope.');
            }
            $branch = Branch::withoutGlobalScopes()->find($branchId);
            if (!$branch || (int) $branch->company_id !== (int) $companyId) {
                throw new \InvalidArgumentException('The selected branch does not belong to the selected company.');
            }
        }

        return [
            'company_id' => $companyId,
            'branch_id'  => $branchId,
            'scope_type' => $scopeType,
        ];
    }

    /**
     * Declare a security incident and, optionally, a scoped lockdown + broadcast.
     *
     * @return array{incident:Incident, lockdown:?Lockdown, announcement:?Announcement}
     */
    public static function declareIncident(array $data, User $actor): array
    {
        $scope = self::resolveScope($data, $actor);

        $incident = Incident::create([
            'type'          => $data['type'] ?? 'security_alert',
            'severity'      => $data['severity'] ?? 'medium',
            'status'        => 'open',
            'title'         => $data['title'],
            'description'   => $data['description'] ?? null,
            'reported_by'   => $actor->id,
            'action_taken'  => $data['action_taken'] ?? null,
            'company_id'    => $scope['company_id'],
            'branch_id'     => $scope['branch_id'],
            'location_id'   => $data['location_id'] ?? null,
        ]);

        AuditLogger::log(
            'incident_created',
            'incidents',
            $incident->id,
            null,
            $incident->toArray(),
            $scope['company_id'],
            $scope['branch_id']
        );

        $lockdown = null;
        $announcement = null;

        $lockdownEnabled = filter_var($data['lockdown'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $broadcastEnabled = filter_var($data['broadcast'] ?? true, FILTER_VALIDATE_BOOLEAN);

        if ($lockdownEnabled) {
            $lockdown = self::startLockdown($scope, $data['reason'] ?? $data['title'], $actor);
        }

        if ($broadcastEnabled) {
            $message = $data['message']
                ?? 'SECURITY INCIDENT: ' . $data['title']
                . ($data['description'] ? ' — ' . $data['description'] : '');
            $announcement = self::createAnnouncement([
                'company_id' => $scope['company_id'],
                'branch_id'  => $scope['branch_id'],
                'scope_type' => $scope['scope_type'],
                'message'    => $message,
                'priority'   => 'critical',
                'created_by' => $actor->id,
            ], $actor);

            NotificationService::emergencyAlert(
                'SECURITY INCIDENT / LOCKDOWN',
                $message,
                $scope['company_id'],
                $scope['branch_id']
            );
        }

        return [
            'incident'     => $incident,
            'lockdown'     => $lockdown,
            'announcement' => $announcement,
        ];
    }

    /**
     * Declare a lockdown without creating a separate incident record
     * (used by the CEO's standalone "Activate Lockdown" control).
     */
    public static function declareLockdownOnly(array $data, User $actor): Lockdown
    {
        $scope = self::resolveScope($data, $actor);

        $lockdown = self::startLockdown(
            $scope,
            $data['reason'] ?? 'Emergency lockdown declared by the Executive Office.',
            $actor
        );

        $message = 'EMERGENCY LOCKDOWN: ' . $lockdown->reason;
        self::createAnnouncement([
            'company_id' => $scope['company_id'],
            'branch_id'  => $scope['branch_id'],
            'scope_type' => $scope['scope_type'],
            'message'    => $message,
            'priority'   => 'critical',
            'created_by' => $actor->id,
        ], $actor);

        NotificationService::emergencyAlert(
            'SYSTEM LOCKDOWN',
            $message,
            $scope['company_id'],
            $scope['branch_id']
        );

        return $lockdown;
    }

    /**
     * Lift an active lockdown and broadcast the unlock to its scope.
     */
    public static function liftLockdown(int $lockdownId, User $actor): ?Lockdown
    {
        $lockdown = Lockdown::activeById($lockdownId);
        if (!$lockdown) {
            return null;
        }

        $before = $lockdown->toArray();
        $lockdown->end($actor->id);

        AuditLogger::log(
            'lockdown_lifted',
            'security_lockdowns',
            $lockdown->id,
            $before,
            $lockdown->fresh()->toArray(),
            $lockdown->company_id,
            $lockdown->branch_id
        );

        $message = 'The ' . ($lockdown->scope_type === 'branch'
            ? 'branch (' . ($lockdown->branch->name ?? '#' . $lockdown->branch_id) . ')'
            : 'company') . ' lockdown has been lifted. Standard access guidelines apply.';

        self::createAnnouncement([
            'company_id' => $lockdown->company_id,
            'branch_id'  => $lockdown->branch_id,
            'scope_type' => $lockdown->scope_type,
            'message'    => $message,
            'priority'   => 'warning',
            'created_by' => $actor->id,
        ], $actor);

        NotificationService::emergencyAlert(
            'LOCKDOWN LIFTED',
            $message,
            $lockdown->company_id,
            $lockdown->branch_id
        );

        return $lockdown;
    }

    /**
     * Create a scoped broadcast announcement (DB-driven + auditable).
     */
    public static function broadcast(array $data, User $actor, ?string $title = null): Announcement
    {
        $scope = self::resolveScope($data, $actor);

        return self::createAnnouncement([
            'company_id' => $scope['company_id'],
            'branch_id'  => $scope['branch_id'],
            'scope_type' => $scope['scope_type'],
            'message'    => $data['message'],
            'priority'   => in_array($data['priority'] ?? null, ['info', 'warning', 'critical'])
                ? $data['priority'] : 'info',
            'created_by' => $actor->id,
        ], $actor, $title);
    }

    /**
     * Store an announcement and audit it. Delivers the in-app/email alert to the
     * scoped company leadership via the NotificationService and creates a
     * per-user in-app notification for every active user in the broadcast scope
     * (company-wide or the selected branch only).
     */
    private static function createAnnouncement(array $data, User $actor, ?string $title = null): Announcement
    {
        $announcement = Announcement::create($data);

        $notifTitle = $title ?? 'CEO Broadcast [' . strtoupper($data['priority']) . ']';

        AuditLogger::log(
            'ceo_broadcast',
            'announcements',
            $announcement->id,
            null,
            $announcement->toArray(),
            $data['company_id'],
            $data['branch_id']
        );

        NotificationService::broadcastToScope(
            $notifTitle,
            $data['message'],
            $data['company_id'],
            $data['branch_id'],
            ['priority' => $data['priority'], 'scope_type' => $data['scope_type']],
            $actor->id
        );

        NotificationService::emergencyAlert(
            $notifTitle,
            $data['message'],
            $data['company_id'],
            $data['branch_id']
        );

        return $announcement;
    }

    /**
     * Create the lockdown record, reject pending requests in scope, and audit it.
     */
    private static function startLockdown(array $scope, string $reason, User $actor): Lockdown
    {
        $lockdown = Lockdown::create([
            'company_id' => $scope['company_id'],
            'branch_id'  => $scope['branch_id'],
            'scope_type' => $scope['scope_type'],
            'reason'     => $reason,
            'started_by' => $actor->id,
            'started_at' => now(),
            'status'     => 'active',
        ]);

        self::rejectPendingRequests($scope['company_id'], $scope['branch_id'], $reason);

        AuditLogger::log(
            'lockdown_declared',
            'security_lockdowns',
            $lockdown->id,
            null,
            $lockdown->toArray(),
            $scope['company_id'],
            $scope['branch_id']
        );

        return $lockdown;
    }

    /**
     * Auto-reject pending visit requests inside the affected scope so no
     * already-scheduled visit can proceed during a lockdown.
     */
    private static function rejectPendingRequests(int $companyId, ?int $branchId, string $reason): void
    {
        $query = VisitRequest::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('status', 'pending');

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        $ids = $query->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        $query->update([
            'status'   => 'rejected',
            'comments' => DB::raw("CONCAT(COALESCE(comments, ''), ' | Automatically rejected due to security lockdown: ', " . DB::getPdo()->quote($reason) . ")"),
        ]);

        foreach ($ids as $id) {
            AuditLogger::log(
                'visit_rejected_lockdown',
                'visit_requests',
                $id,
                null,
                ['reason' => $reason],
                $companyId,
                $branchId
            );
        }
    }

    /**
     * Record an incident, or merge with an existing open/incident within the dedup window.
     *
     * @param array $payload Incident data (type, severity, title, description, scan_token, etc.)
     * @param User  $actor   The user (guard) reporting the incident
     * @param int $dedupWindowMinutes How long to look back for a matching open incident (default 60)
     * @return Incident
     */
    public static function recordIncident(array $payload, User $actor, int $dedupWindowMinutes = 60): Incident
    {
        return DB::transaction(function () use ($payload, $actor, $dedupWindowMinutes) {
            $companyId  = $payload['company_id']  ?? $actor->company_id;
            $branchId   = $payload['branch_id']   ?? $actor->branch_id;
            $scanToken  = $payload['scan_token']  ?? null;
            $visitId    = $payload['related_visit_id'] ?? null;
            $visitorId  = $payload['related_visitor_id'] ?? null;

            $existing = Incident::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('type', $payload['type'])
                ->whereIn('status', [Incident::STATUS_OPEN, Incident::STATUS_INVESTIGATING])
                ->where('created_at', '>=', now()->subMinutes($dedupWindowMinutes))
                ->when($scanToken,  fn ($q) => $q->where('scan_token', $scanToken))
                ->when($visitId,    fn ($q) => $q->where('related_visit_id', $visitId))
                ->when($visitorId,  fn ($q) => $q->where('related_visitor_id', $visitorId))
                ->latest('created_at')
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $existing->increment('attempt_count');
                $existing->update([
                    'last_attempt_at' => now(),
                    'description'     => $payload['description'] ?? $existing->description,
                ]);

                IncidentAuditTrail::create([
                    'incident_id'    => $existing->id,
                    'actor_id'       => $actor->id,
                    'actor_type'     => 'user',
                    'action'         => 'reoccurrence_detected',
                    'from_status'    => $existing->getOriginal('status'),
                    'to_status'      => $existing->status,
                    'notes'          => 'Duplicate detection — attempt #' . ($existing->attempt_count),
                    'evidence'       => $payload['evidence'] ?? null,
                ]);

                AuditLogger::log(
                    'incident_reoccurrence',
                    'incidents',
                    $existing->id,
                    ['attempt_count' => $existing->getOriginal('attempt_count')],
                    ['attempt_count' => $existing->attempt_count],
                    $companyId,
                    $branchId
                );

                return $existing->fresh();
            }

            $incident = Incident::create([
                'type'              => $payload['type'],
                'severity'          => $payload['severity'] ?? Incident::SEVERITY_MEDIUM,
                'status'            => Incident::STATUS_OPEN,
                'title'             => $payload['title'],
                'description'       => $payload['description'] ?? null,
                'scan_token'        => $scanToken,
                'related_visit_id'  => $visitId,
                'related_visitor_id'=> $visitorId,
                'related_group_member_id' => $payload['related_group_member_id'] ?? null,
                'checkpoint_id'     => $payload['checkpoint_id'] ?? $actor->location_id,
                'reported_by'       => $payload['reported_by'] ?? $actor->id,
                'action_taken'      => $payload['action_taken'] ?? null,
                'company_id'        => $companyId,
                'branch_id'         => $branchId,
                'location_id'       => $payload['location_id'] ?? $actor->location_id,
            ]);

            IncidentAuditTrail::create([
                'incident_id'    => $incident->id,
                'actor_id'       => $actor->id,
                'actor_type'     => 'user',
                'action'         => 'incident_created',
                'from_status'    => null,
                'to_status'      => Incident::STATUS_OPEN,
                'notes'          => $payload['description'] ?? null,
                'evidence'       => $payload['evidence'] ?? null,
            ]);

            AuditLogger::log(
                'incident_created',
                'incidents',
                $incident->id,
                null,
                $incident->toArray(),
                $companyId,
                $branchId
            );

            return $incident;
        });
    }

    /**
     * Transition an incident to "investigating" status.
     * Authorized roles: super_admin, admin, security_manager, security_guard, manager.
     */
    public static function investigate(Incident $incident, User $actor, ?string $notes = null): Incident
    {
        self::ensureCanInvestigate($actor);

        $before = $incident->toArray();

        $incident->update([
            'status'       => Incident::STATUS_INVESTIGATING,
            'action_taken' => $notes
                ? ($incident->action_taken ? $incident->action_taken . ' | ' : '') . $notes
                : $incident->action_taken,
        ]);

        IncidentAuditTrail::create([
            'incident_id' => $incident->id,
            'actor_id'    => $actor->id,
            'actor_type'  => 'user',
            'action'      => 'investigate',
            'from_status' => $before['status'],
            'to_status'   => Incident::STATUS_INVESTIGATING,
            'notes'       => $notes,
        ]);

        AuditLogger::log(
            'incident_status_changed',
            'incidents',
            $incident->id,
            $before,
            $incident->fresh()->toArray(),
            $incident->company_id,
            $incident->branch_id
        );

        return $incident->fresh();
    }

    /**
     * Resolve an incident with required resolution details.
     * Authorized roles: super_admin, admin, security_manager.
     *
     * @param array $resolution {resolution_reason, resolution_action, resolution_notes, evidence}
     */
    public static function resolve(Incident $incident, array $resolution, User $actor): Incident
    {
        self::ensureCanResolve($actor);

        $before = $incident->toArray();

        $incident->update([
            'status'            => Incident::STATUS_RESOLVED,
            'resolved_at'       => now(),
            'resolved_by'       => $actor->id,
            'resolution_reason' => $resolution['resolution_reason'],
            'resolution_action' => $resolution['resolution_action'] ?? null,
            'resolution_notes'  => $resolution['resolution_notes'] ?? null,
            'evidence'     => $resolution['evidence'] ?? $incident->evidence ?? [],
        ]);

        IncidentAuditTrail::create([
            'incident_id' => $incident->id,
            'actor_id'    => $actor->id,
            'actor_type'  => 'user',
            'action'      => 'resolve',
            'from_status' => $before['status'],
            'to_status'   => Incident::STATUS_RESOLVED,
            'notes'       => $resolution['resolution_notes'] ?? null,
            'evidence'    => $resolution['evidence'] ?? $incident->evidence ?? null,
        ]);

        AuditLogger::log(
            'incident_resolved',
            'incidents',
            $incident->id,
            $before,
            $incident->fresh()->toArray(),
            $incident->company_id,
            $incident->branch_id
        );

        return $incident->fresh();
    }

    /**
     * Dismiss an incident (alternative to resolving).
     * Authorized roles: super_admin, admin, security_manager.
     */
    public static function dismiss(Incident $incident, ?string $notes, User $actor): Incident
    {
        self::ensureCanDismiss($actor);

        $before = $incident->toArray();

        $incident->update([
            'status'            => Incident::STATUS_DISMISSED,
            'resolved_at'       => now(),
            'resolved_by'       => $actor->id,
            'resolution_reason' => Incident::RESOLUTION_REASON_FALSE_POSITIVE,
            'resolution_notes'  => $notes,
        ]);

        IncidentAuditTrail::create([
            'incident_id' => $incident->id,
            'actor_id'    => $actor->id,
            'actor_type'  => 'user',
            'action'      => 'dismiss',
            'from_status' => $before['status'],
            'to_status'   => Incident::STATUS_DISMISSED,
            'notes'       => $notes,
        ]);

        AuditLogger::log(
            'incident_dismissed',
            'incidents',
            $incident->id,
            $before,
            $incident->fresh()->toArray(),
            $incident->company_id,
            $incident->branch_id
        );

        return $incident->fresh();
    }

    /**
     * Fetch incidents for the Security page with filtering + pagination.
     *
     * @return array{data: array, pagination: array}
     */
    public static function getIncidentsQuery(array $filters, ?int $companyId = null, ?int $branchId = null)
    {
        $query = Incident::withoutGlobalScopes()
            ->with([
                'visitor', 'visitRequest.visitor', 'visitRequest.host',
                'groupMember', 'reportedBy', 'resolvedBy',
                'checkpoint', 'location', 'branch', 'company',
            ])
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->when($branchId,  fn ($q) => $q->where('branch_id', $branchId));

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (!empty($filters['severity']) && $filters['severity'] !== 'all') {
            $query->where('severity', $filters['severity']);
        }
        if (!empty($filters['type']) && $filters['type'] !== 'all') {
            $query->where('type', $filters['type']);
        }
        if (!empty($filters['search'])) {
            $s = '%' . $filters['search'] . '%';
            $query->where(function ($q) use ($s) {
                $q->where('title', 'like', $s)
                  ->orWhere('description', 'like', $s)
                  ->orWhere('scan_token', 'like', $s);
            });
        }
        if (!empty($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to']);
        }

        return $query->orderByDesc('created_at');
    }

    /**
     * Return counts grouped by status and severity.
     */
    public static function getIncidentCounts(?int $companyId = null, ?int $branchId = null): array
    {
        $base = Incident::withoutGlobalScopes()
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->when($branchId,  fn ($q) => $q->where('branch_id', $branchId));

        $byStatus   = (clone $base)->selectRaw('status, count(*) as count')->groupBy('status')->pluck('count', 'status')->toArray();
        $bySeverity = (clone $base)->selectRaw('severity, count(*) as count')->groupBy('severity')->pluck('count', 'severity')->toArray();
        $byType     = (clone $base)->selectRaw('type, count(*) as count')->groupBy('type')->pluck('count', 'type')->toArray();

        return [
            'by_status'     => array_merge(['open' => 0, 'investigating' => 0, 'resolved' => 0, 'dismissed' => 0], $byStatus),
            'by_severity'   => array_merge(['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0], $bySeverity),
            'by_type'       => $byType,
            'total_open'    => (int) array_sum(array_intersect_key($byStatus, array_flip(['open', 'investigating']))),
        ];
    }

    /**
     * Authorization: only certain roles may assign an incident to investigation.
     */
    public static function ensureCanInvestigate(User $actor): void
    {
        if (!in_array(strtolower($actor->role), Incident::ALLOWED_INVESTIGATORS, true)) {
            throw new \Illuminate\Auth\Access\AuthorizationAccessException(
                'You do not have permission to investigate incidents.'
            );
        }
    }

    /**
     * Authorization: only certain roles may resolve an incident.
     */
    public static function ensureCanResolve(User $actor): void
    {
        if (!in_array(strtolower($actor->role), Incident::ALLOWED_RESOLVERS, true)) {
            throw new \Illuminate\Auth\Access\AuthorizationException(
                'You do not have permission to resolve incidents.'
            );
        }
    }

    public static function ensureCanDismiss(User $actor): void
    {
        if (!in_array(strtolower($actor->role), Incident::ALLOWED_DISMISSERS, true)) {
            throw new \Illuminate\Auth\Access\AuthorizationException(
                'You do not have permission to dismiss incidents.'
            );
        }
    }

    /**
     * Build a single incident's serialized view (used by detail endpoint).
     */
    public static function serializeIncident(Incident $incident): array
    {
        $incident->load([
            'visitor', 'visitRequest.visitor', 'visitRequest.host',
            'groupMember', 'reportedBy', 'resolvedBy',
            'checkpoint', 'location', 'branch', 'company',
            'auditTrail',
        ]);

        $visit = $incident->visitRequest;
        $visitor = $incident->visitor
            ?? $visit?->visitor
            ?? $incident->groupMember;

        return [
            'id'                   => $incident->id,
            'type'                 => $incident->type,
            'type_label'           => $incident->displayTypeLabel(),
            'severity'             => $incident->severity,
            'severity_label'       => $incident->displaySeverityLabel(),
            'severity_color'       => $incident->severityColor(),
            'status'               => $incident->status,
            'status_label'         => $incident->displayStatusLabel(),
            'title'                => $incident->title,
            'description'          => $incident->description,
            'scan_token'           => $incident->scan_token,
            'attempt_count'        => $incident->attempt_count,
            'last_attempt_at'      => $incident->last_attempt_at?->format('Y-m-d H:i:s'),
            'related_visit_id'     => $incident->related_visit_id,
            'visitor_name'         => $visitor?->name
                ?? $visitor?->first_name
                ?? $visit?->company_name
                ?? null,
            'visitor_document'     => $visitor?->id_document_no ?? null,
            'visitor_photo'        => $visitor?->photo_url ?? null,
            'visitor_phone'        => $visitor?->phone ?? null,
            'host_name'            => $visit?->host?->name ?? null,
            'checkpoint_name'      => $incident->checkpoint?->name ?? $incident->location?->name ?? null,
            'reported_by'          => $incident->reported_by,
            'reported_by_name'     => $incident->reportedBy?->name ?? null,
            'action_taken'         => $incident->action_taken,
            'resolved_at'          => $incident->resolved_at?->format('Y-m-d H:i:s'),
            'resolved_by'          => $incident->resolved_by,
            'resolved_by_name'     => $incident->resolvedBy?->name ?? null,
            'resolution_reason'    => $incident->resolution_reason,
            'resolution_reason_label' => $incident->resolution_reason
                ? Incident::resolutionReasonLabel($incident->resolution_reason)
                : null,
            'resolution_action'    => $incident->resolution_action,
            'resolution_notes'     => $incident->resolution_notes,
            'evidence'             => $incident->evidence,
            'created_at'           => $incident->created_at?->format('Y-m-d H:i:s'),
            'updated_at'           => $incident->updated_at?->format('Y-m-d H:i:s'),
            'audit_trail'          => $incident->auditTrail->map(fn ($a) => [
                'id'          => $a->id,
                'actor_id'    => $a->actor_id,
                'actor_name'  => $a->actor?->name ?? null,
                'actor_type'  => $a->actor_type,
                'action'      => $a->action,
                'from_status' => $a->from_status,
                'to_status'   => $a->to_status,
                'notes'       => $a->notes,
                'evidence'    => $a->evidence,
                'created_at'  => $a->created_at?->format('Y-m-d H:i:s'),
            ])->values()->all(),
        ];
    }
}
