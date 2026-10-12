<?php

namespace App\Services;

use App\Models\VisitRequest;
use App\Models\Escalation;
use App\Models\User;
use App\Services\SmsSyncService;
use Illuminate\Support\Facades\Log;

class ApprovalService
{
    /**
     * Approve a visit request.
     * Escalated visits are only approved via makeDecision, which enforces that the
     * responsible Admin/CEO owns the visit before the QR workflow may proceed.
     */
    public static function approve(VisitRequest $request, ?string $comments = ''): VisitRequest
    {
        $oldState = $request->toArray();
        
        $request->status = 'approved';
        $request->approved_at = now();
        $request->comments = $comments ?? '';
        $request->save();

        // Generate QR code token — retry up to 3 times
        $qrGenerated = false;
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                QRTokenService::generate($request);
                $qrGenerated = true;
                break;
            } catch (\Exception $e) {
                Log::warning("QR token generation attempt {$attempt} failed for visit #{$request->id}: " . $e->getMessage());
            }
        }
        if (!$qrGenerated) {
            Log::error("QR token generation FAILED after 3 attempts for visit #{$request->id}");
        }

        // Generate QR tokens for all group members if this is a company/group visit
        if ($request->visit_type === 'company') {
            for ($attempt = 1; $attempt <= 3; $attempt++) {
                try {
                    QRTokenService::generateGroupMemberTokens($request);
                    break;
                } catch (\Exception $e) {
                    Log::warning("Group member QR token attempt {$attempt} failed for visit #{$request->id}: " . $e->getMessage());
                }
            }

            // Send notifications to all group members with their QR codes
            $request->load('groupMembers');
            foreach ($request->groupMembers as $member) {
                if ($member->qr_token) {
                    $memberMessage = "Your company visit has been approved. Your personal QR Code: {$member->qr_token}. Please use this QR code for check-in at the security gate. Visit details: {$request->purpose} on {$request->requested_time->format('M d, Y H:i')}.";
                    NotificationService::sendToGroupMemberDeferred('Visit Approved - Group Member', $member, $memberMessage);
                }
            }
        }

        AuditLogger::log(
            'approved_visit',
            'visit_requests',
            $request->id,
            $oldState,
            $request->fresh()->toArray()
        );

        SmsSyncService::syncVisitDeferred($request, SmsSyncService::EVENT_APPROVED);

        return $request;
    }

    /**
     * Reject a visit request.
     */
    public static function reject(VisitRequest $request, ?string $comments = ''): VisitRequest
    {
        $oldState = $request->toArray();

        $request->status = 'rejected';
        $request->rejected_at = now();
        $request->comments = $comments ?? '';
        $request->save();

        // Invalidate token if exists
        if ($request->qrToken) {
            $request->qrToken->update(['status' => 'expired']);
        }

        AuditLogger::log(
            'rejected_visit',
            'visit_requests',
            $request->id,
            $oldState,
            $request->fresh()->toArray()
        );

        return $request;
    }

    /**
     * Escalate a visit request.
     *
     * $meta optionally stamps SLA context onto the escalation row:
     *   trigger_type ('manual'|'sla'), level, sla_minutes, deadline_at.
     */
    public static function escalate(VisitRequest $request, int $escalatedByUserId, string $reason, array $meta = []): VisitRequest
    {
        $oldState = $request->toArray();
        $escalator = User::findOrFail($escalatedByUserId);

        $escalatedToUserId = null;
        // With only 4 roles (ceo, admin, host, visitor), hosts escalate directly to Admin.
        // The admin is picked from the same company as the visit so escalations never
        // land in another company's admin dashboard.
        $admin = User::withoutGlobalScopes()
            ->where('role', 'admin')
            ->where('status', 'active')
            ->where('company_id', $request->company_id)
            ->first();

        if (!$admin) {
            $admin = User::withoutGlobalScopes()->where('role', 'admin')->where('status', 'active')->first();
        }
        $escalatedToUserId = $admin ? $admin->id : null;

        if (!$escalatedToUserId) {
            throw new \Exception("No appropriate user found to escalate request ID {$request->id} to.");
        }

        $request->status = 'escalated';
        $request->escalated_to = $escalatedToUserId;
        $request->save();

        Escalation::create(array_merge([
            'visit_request_id' => $request->id,
            'escalated_by' => $escalatedByUserId,
            'escalated_to' => $escalatedToUserId,
            'reason' => $reason,
        ], array_intersect_key($meta, array_flip([
            'trigger_type', 'level', 'sla_minutes', 'deadline_at',
        ]))));

        AuditLogger::log(
            'escalated_visit',
            'visit_requests',
            $request->id,
            $oldState,
            $request->fresh()->toArray()
        );

        return $request;
    }

    /**
     * Run automatic check of unaddressed visit requests and auto-escalate them.
     *
     * Thin delegation: all SLA maths, warnings and escalation rules live in
     * SlaService so the scheduler, the cron endpoint and manual triggers all
     * share one idempotent implementation. Kept as a static entry point so
     * existing callers and tests do not change.
     */
    public static function processAutoEscalations(): int
    {
        return app(SlaService::class)->process();
    }
}
