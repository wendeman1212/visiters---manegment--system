<?php

namespace App\Services;

use App\Models\HostChangeRequest;
use App\Models\VisitRequest;
use App\Models\User;
use App\Models\Department;
use Illuminate\Support\Facades\DB;

class HostChangeService
{
    const ALLOWED_REQUESTER_ROLES = ['admin', 'host', 'security_guard', 'receptionist'];
    const APPROVED_STATUSES = ['approved', 'checked_in'];
    const BLOCKED_STATUSES = ['cancelled', 'rejected', 'expired', 'checked_out', 'archived'];

    /**
     * Determine if a visit is in pre-approval (pending) state.
     */
    public static function isPendingVisit(VisitRequest $visit): bool
    {
        return $visit->status === 'pending';
    }

    /**
     * Determine if a visit is in post-approval active state.
     */
    public static function isActiveVisit(VisitRequest $visit): bool
    {
        return in_array($visit->status, self::APPROVED_STATUSES);
    }

    /**
     * Check if the visit is in a transferable state.
     */
    public static function isTransferable(VisitRequest $visit): bool
    {
        return !in_array($visit->status, self::BLOCKED_STATUSES);
    }

    /**
     * Validate the new host for the transfer.
     * Returns ['valid' => bool, 'errors' => array].
     */
    public static function validateNewHost(VisitRequest $visit, int $newHostId, ?int $departmentId = null): array
    {
        $errors = [];

        $newHost = User::withoutGlobalScopes()
            ->where('id', $newHostId)
            ->where('role', 'host')
            ->where('status', 'active')
            ->first();

        if (!$newHost) {
            $errors[] = 'Selected host account does not exist or is not active.';
            return ['valid' => false, 'errors' => $errors];
        }

        if ($visit->branch_id) {
            if ($newHost->branch_id != $visit->branch_id) {
                $errors[] = 'Host change is only allowed to a host within the same branch.';
            }
        } else {
            $sameCompany = !$visit->company_id || $newHost->company_id == $visit->company_id
                || ($visit->company_id && $newHost->companies()->where('company_id', $visit->company_id)->exists());
            if (!$sameCompany) {
                $errors[] = 'Host change is only allowed to a host within the same company.';
            }
        }

        if ($newHost->id === $visit->host_id) {
            $errors[] = 'New host cannot be the same as the current host.';
        }

        $targetDeptId = $departmentId ?? $newHost->department_id;
        if ($targetDeptId && $newHost->department_id != $targetDeptId) {
            $dept = Department::find($targetDeptId);
            $errors[] = "Host '{$newHost->name}' does not belong to the selected department" . ($dept ? " ({$dept->name})" : '') . '.';
        }

        if (!$newHost->isActive()) {
            $errors[] = 'New host account is not active.';
        }

        // Check if host has permission to receive visitors
        if (!$newHost->canReceiveVisitors()) {
            $errors[] = 'Selected host does not have permission to receive visitors.';
        }

        return empty($errors) ? ['valid' => true, 'errors' => []] : ['valid' => false, 'errors' => $errors];
    }

    /**
     * Validate that there are no appointment conflicts for the new host.
     */
    public static function validateAvailability(VisitRequest $visit, int $newHostId): array
    {
        $errors = [];

        $conflictingVisit = VisitRequest::where('host_id', $newHostId)
            ->whereIn('status', ['pending', 'approved', 'checked_in'])
            ->where('id', '!=', $visit->id)
            ->where(function ($q) use ($visit) {
                $visitStart = $visit->requested_time;
                $visitEnd = $visit->requested_time->copy()->addMinutes($visit->duration);
                $q->where(function ($q2) use ($visitStart, $visitEnd) {
                    $q2->where('requested_time', '<', $visitEnd)
                        ->whereRaw('DATE_ADD(requested_time, INTERVAL duration MINUTE) > ?', [$visitStart]);
                });
            })
            ->first();

        if ($conflictingVisit) {
            $errors[] = 'Selected host has an overlapping appointment during the requested visit time.';
        }

        // Check working hours (company-wide schedule; working_hours rows are keyed
        // by numeric day_of_week 0=Sun..6=Sat and store open_time/close_time).
        $newHost = User::find($newHostId);
        $visitDay = (int) $visit->requested_time->dayOfWeek; // 0 = Sunday, 6 = Saturday
        $visitTime = $visit->requested_time->format('H:i:s');

        $workingHour = \App\Models\WorkingHour::where('day_of_week', $visitDay)->first();
        if ($workingHour && !$workingHour->is_working_day) {
            $errors[] = 'Selected host is not available on the requested day (non-working day).';
        } elseif ($workingHour && ($visitTime < $workingHour->open_time || $visitTime > $workingHour->close_time)) {
            $errors[] = 'Selected host is not available during the requested visit time (outside working hours).';
        }

        return empty($errors) ? ['valid' => true, 'errors' => []] : ['valid' => false, 'errors' => $errors];
    }

    /**
     * Create a host change request.
     */
    public static function createRequest(array $data): HostChangeRequest
    {
        $visit = VisitRequest::findOrFail($data['visit_request_id']);

        $changeRequest = HostChangeRequest::create([
            'visit_request_id'  => $visit->id,
            'previous_host_id'  => $visit->host_id,
            'new_host_id'       => $data['new_host_id'],
            'new_department_id' => User::find($data['new_host_id'])->department_id ?? null,
            'requested_by'      => $data['requested_by'],
            'requested_by_role' => $data['requested_by_role'],
            'reason'            => $data['reason'],
            'additional_notes'  => $data['additional_notes'] ?? null,
            'meeting_room'      => $data['meeting_room'] ?? null,
            'previous_location' => $data['previous_location'] ?? null,
            'new_location'      => $data['new_location'] ?? null,
            'status'            => 'pending',
        ]);

        AuditLogger::log(
            'host_change_requested',
            'host_change_requests',
            $changeRequest->id,
            null,
            [
                'visit_id'        => $visit->id,
                'previous_host'   => $visit->host->name ?? 'Unknown',
                'new_host_id'     => $data['new_host_id'],
                'requested_by'    => $data['requested_by_role'],
                'reason'          => $data['reason'],
                'visit_status'    => $visit->status,
            ]
        );

        return $changeRequest->load('visitRequest.visitor', 'previousHost', 'newHost', 'newDepartment', 'requestedByUser');
    }

    /**
     * Accept a host change request — transfer the visit.
     */
    public static function acceptRequest(HostChangeRequest $changeRequest, int $reviewedByUserId, ?string $reviewNotes = ''): HostChangeRequest
    {
        return DB::transaction(function () use ($changeRequest, $reviewedByUserId, $reviewNotes) {
            $visit    = $changeRequest->visitRequest;
            $newHost  = User::withoutGlobalScopes()->find($changeRequest->new_host_id);
            $reviewer = User::withoutGlobalScopes()->find($reviewedByUserId);

            $oldState = $visit->toArray();

            $changeRequest->update([
                'status'        => 'accepted',
                'reviewed_by'   => $reviewedByUserId,
                'review_notes'  => $reviewNotes,
                'reviewed_at'   => now(),
                'transferred_at'=> now(),
            ]);

            // Run the exact same complete, atomic hand-off as every other
            // transfer path through the shared engine: host_id + department
            // move, alternate host cleared, open escalations resolved, SLA
            // clock handed off, transfer history recorded and caches cleared.
            // Scope is already validated by validateNewHost(), and the
            // host-change flow sends its own richer notifications.
            if ($newHost && $visit->host_id !== $newHost->id) {
                app(HostTransferService::class)->transfer(
                    $visit,
                    $newHost,
                    $reviewer ?? $newHost,
                    $changeRequest->reason,
                    [
                        'validateScope'     => false,
                        'moveToHostCompany' => true,
                        'notify'            => false,
                    ]
                );
            }

            // Preserve the meeting-room/location change captured on the request.
            if ($changeRequest->meeting_room && $visit->meeting_room_id !== $changeRequest->meeting_room) {
                $visit->meeting_room_id = $changeRequest->meeting_room;
                $visit->save();
            }

            AuditLogger::log(
                'host_change_accepted',
                'host_change_requests',
                $changeRequest->id,
                $oldState,
                [
                    'visit_id'        => $visit->id,
                    'previous_host'   => $changeRequest->previousHost->name ?? 'Unknown',
                    'new_host'        => $newHost->name ?? 'Unknown',
                    'transferred_by'  => $reviewedByUserId,
                ]
            );

            return $changeRequest->fresh()->load('visitRequest.visitor', 'previousHost', 'newHost', 'newDepartment');
        });
    }

    /**
     * Reject a host change request.
     */
    public static function rejectRequest(HostChangeRequest $changeRequest, int $reviewedByUserId, ?string $reviewNotes = ''): HostChangeRequest
    {
        $changeRequest->update([
            'status'       => 'rejected',
            'reviewed_by'  => $reviewedByUserId,
            'review_notes' => $reviewNotes,
            'reviewed_at'  => now(),
        ]);

        AuditLogger::log(
            'host_change_rejected',
            'host_change_requests',
            $changeRequest->id,
            null,
            [
                'visit_id'     => $changeRequest->visit_request_id,
                'rejected_by'  => $reviewedByUserId,
                'reason'       => $reviewNotes,
            ]
        );

        return $changeRequest->fresh()->load('visitRequest.visitor', 'previousHost', 'newHost', 'newDepartment');
    }

    /**
     * Cancel a host change request (by the requester).
     */
    public static function cancelRequest(HostChangeRequest $changeRequest, int $cancelledByUserId): HostChangeRequest
    {
        $changeRequest->update([
            'status'  => 'cancelled',
            'reviewed_by' => $cancelledByUserId,
            'reviewed_at' => now(),
        ]);

        AuditLogger::log(
            'host_change_cancelled',
            'host_change_requests',
            $changeRequest->id,
            null,
            ['cancelled_by' => $cancelledByUserId]
        );

        return $changeRequest->fresh()->load('visitRequest.visitor', 'previousHost', 'newHost');
    }

    /**
     * Send notifications for a host change request.
     */
    public static function sendRequestNotifications(HostChangeRequest $changeRequest): void
    {
        $visit = $changeRequest->visitRequest;
        $visitor = $visit->visitor;
        $newHost = $changeRequest->newHost;
        $previousHost = $changeRequest->previousHost;

        $phase = self::isPendingVisit($visit) ? 'Pending Visit' : 'Active Visit';
        $baseMsg = "Visitor Host Change Request ({$phase})\n";
        $baseMsg .= "Visit ID: #{$visit->id}\n";
        $baseMsg .= "Visitor: {$visitor->name}\n";
        $baseMsg .= "Visitor Phone: " . ($visitor->phone ?: 'N/A') . "\n";
        $baseMsg .= "Purpose: {$visit->purpose}\n";
        $baseMsg .= "Visit Time: {$visit->requested_time->format('M d, Y H:i')}\n";
        $baseMsg .= "Previous Host: {$previousHost->name}\n";
        $baseMsg .= "New Host: {$newHost->name} (" . ($changeRequest->newDepartment->name ?? 'N/A') . ")\n";
        $baseMsg .= "Meeting Location: " . ($changeRequest->meeting_room ?: ($visit->building . ($visit->floor ? " Floor {$visit->floor}" : '') ?: 'N/A')) . "\n";
        $baseMsg .= "Reason: {$changeRequest->reason}\n";

        NotificationService::send('Host Change Request', $newHost, $baseMsg . "\nPlease accept or reject this transfer request.");

        NotificationService::send('Host Change Request', $previousHost, "A host change request has been submitted for Visit #{$visit->id} (visitor {$visitor->name}). New proposed host: {$newHost->name}. Reason: {$changeRequest->reason}");

        $admins = User::withoutGlobalScopes()
            ->where('role', 'admin')
            ->where('status', 'active')
            ->where('company_id', $visit->company_id)
            ->get();
        if ($admins->isEmpty()) {
            $admins = User::withoutGlobalScopes()->where('role', 'admin')->where('status', 'active')->get();
        }
        foreach ($admins as $admin) {
            NotificationService::send('Host Change Request', $admin, "Host change request submitted for Visit #{$visit->id}. Visitor: {$visitor->name}. From: {$previousHost->name} to {$newHost->name}.");
        }
    }

    /**
     * Send acceptance notifications.
     */
    public static function sendAcceptanceNotifications(HostChangeRequest $changeRequest): void
    {
        $visit = $changeRequest->visitRequest;
        $visitor = $visit->visitor;
        $newHost = $changeRequest->newHost;
        $previousHost = $changeRequest->previousHost;

        $isPending = self::isPendingVisit($visit);
        $phase = $isPending ? 'Pending Visit' : 'Active Visit';
        $department = $changeRequest->newDepartment->name ?? $newHost->department->name ?? 'N/A';
        $location = $changeRequest->meeting_room ?: ($visit->building . ($visit->floor ? " Floor {$visit->floor}" : '') ?: 'N/A');

        NotificationService::send('Host Change Accepted', $newHost,
            "Visit #{$visit->id} — You have been assigned as host for visitor {$visitor->name} ({$phase}).\n" .
            "Visitor Phone: " . ($visitor->phone ?: 'N/A') . "\n" .
            "Purpose: {$visit->purpose}\n" .
            "Visit Time: {$visit->requested_time->format('M d, Y H:i')}\n" .
            "Department: {$department}\n" .
            "Meeting Location: {$location}\n" .
            "Previous Host: {$previousHost->name}\n" .
            "Reason: " . ($changeRequest->reason ?: 'N/A') . "\n" .
            "Please proceed with your host responsibilities.");

        NotificationService::send('Host Change Accepted', $previousHost,
            "The host change for visitor {$visitor->name} (Visit #{$visit->id}) has been accepted. {$newHost->name} is now the assigned host. This visit has been fully transferred and is no longer assigned to you.");

        NotificationService::send('Host Change Accepted', $visitor,
            "Your visit host has been changed to {$newHost->name} ({$department}).\n" .
            "New Host Phone: " . ($newHost->phone ?: 'N/A') . "\n" .
            "Visit ID: #{$visit->id}\n" .
            "Purpose: {$visit->purpose}\n" .
            "Visit Time: {$visit->requested_time->format('M d, Y H:i')}\n" .
            "Meeting Location: {$location}\n" .
            "Please check with reception for any location changes.");

        $admins = User::withoutGlobalScopes()
            ->where('role', 'admin')
            ->where('status', 'active')
            ->where('company_id', $visit->company_id)
            ->get();
        if ($admins->isEmpty()) {
            $admins = User::withoutGlobalScopes()->where('role', 'admin')->where('status', 'active')->get();
        }
        foreach ($admins as $admin) {
            NotificationService::send('Host Change Accepted', $admin, "Host change for Visit #{$visit->id} has been accepted. New host: {$newHost->name}.");
        }

        // Notify receptionists
        $receptionists = User::withoutGlobalScopes()
            ->where('role', 'receptionist')
            ->where('status', 'active')
            ->where('company_id', $visit->company_id)
            ->get();
        if ($receptionists->isEmpty()) {
            $receptionists = User::withoutGlobalScopes()->where('role', 'receptionist')->where('status', 'active')->get();
        }
        foreach ($receptionists as $receptionist) {
            NotificationService::send('Host Change Accepted', $receptionist, "Host change for Visit #{$visit->id}. Visitor: {$visitor->name}. New host: {$newHost->name}. Department: " . ($changeRequest->newDepartment->name ?? $newHost->department->name ?? 'N/A'));
        }

        if ($visit->status === 'checked_in') {
            NotificationService::send('Visitor Host Updated', 'Security Guard', "SECURITY NOTICE: Visitor {$visitor->name} (Visit #{$visit->id}) host has been updated. Previous: {$previousHost->name}, New: {$newHost->name}. New Department: {$department}. New Location: {$location}. Please inform the visitor and escort if needed to the new meeting area.", null, false, $visit->company_id, $visit->branch_id);
        } elseif ($visit->status === 'approved') {
            NotificationService::send('Visitor Host Updated', 'Security Guard', "SECURITY NOTICE: Visitor {$visitor->name} (Visit #{$visit->id}) has a new host: {$newHost->name}. Department: {$department}. Location: {$location}. Please escort visitor if needed.", null, false, $visit->company_id, $visit->branch_id);
        }
    }

    /**
     * Send rejection notifications.
     */
    public static function sendRejectionNotifications(HostChangeRequest $changeRequest): void
    {
        $visit = $changeRequest->visitRequest;
        $visitor = $visit->visitor;
        $newHost = $changeRequest->newHost;
        $previousHost = $changeRequest->previousHost;

        NotificationService::send('Host Change Rejected', $newHost, "The host change request for visitor {$visitor->name} has been rejected. Reason: " . ($changeRequest->review_notes ?: 'No reason provided.'));

        NotificationService::send('Host Change Rejected', $previousHost, "The host change request for visitor {$visitor->name} has been rejected. You remain the assigned host.");

        $requester = $changeRequest->requestedByUser;
        if ($requester) {
            NotificationService::send('Host Change Rejected', $requester, "Your host change request for Visit #{$visit->id} has been rejected by {$newHost->name}. Reason: " . ($changeRequest->review_notes ?: 'No reason provided.'));
        }
    }

    /**
     * Check authorization: can this user request a host change?
     */
    public static function canRequestChange(User $user, VisitRequest $visit): bool
    {
        if (in_array($user->role, ['admin', 'receptionist'])) {
            return true;
        }

        if ($user->role === 'host' && $visit->host_id === $user->id) {
            return true;
        }

        if ($user->role === 'security_guard') {
            return in_array($visit->status, ['approved', 'checked_in']);
        }

        return false;
    }

    /**
     * Check authorization: can this user review a host change request?
     */
    public static function canReviewChange(User $user, HostChangeRequest $changeRequest): bool
    {
        if ($user->role === 'admin') {
            return true;
        }

        if ($user->role === 'host' && $changeRequest->new_host_id === $user->id) {
            return true;
        }

        return false;
    }
}
