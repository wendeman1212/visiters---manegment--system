<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

class AuditLogger
{
    /**
     * Log a state-changing action.
     *
     * @param string $action The action description (e.g. login, checkin, approve)
     * @param string|null $entity The target table/entity name (e.g. users, visit_requests)
     * @param int|null $entityId The primary key of the target entity
     * @param array|null $before The state before modification
     * @param array|null $after The state after modification
     * @param int|null $companyId Override the company attribution (used by system admin actions)
     * @param int|null $branchId Override the branch attribution (defaults to the acting user's branch)
     * @return AuditLog
     */
    public static function log(string $action, ?string $entity = null, ?int $entityId = null, ?array $before = null, ?array $after = null, ?int $companyId = null, ?int $branchId = null): AuditLog
    {
        $actorId = null;
        $actorType = 'system';
        $companyId = $companyId ?? null;
        $branchId = $branchId ?? null;

        if (Auth::guard('web')->check()) {
            $actor = Auth::guard('web')->user();
            $actorId = $actor->id;
            $actorType = 'user';
            $companyId = $companyId ?? $actor->company_id;
            $branchId = $branchId ?? $actor->branch_id;
        } elseif (Auth::guard('visitor')->check()) {
            $actor = Auth::guard('visitor')->user();
            $actorId = $actor->id;
            $actorType = 'visitor';
            $companyId = $companyId ?? $actor->company_id;
        }

        return AuditLog::create([
            'actor_id' => $actorId,
            'actor_type' => $actorType,
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'action' => $action,
            'entity' => $entity,
            'entity_id' => $entityId,
            'before_value' => $before,
            'after_value' => $after,
            'ip_address' => Request::ip(),
            'user_agent' => Request::header('User-Agent'),
        ]);
    }
}
