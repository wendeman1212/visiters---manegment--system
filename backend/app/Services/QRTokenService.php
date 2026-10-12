<?php

namespace App\Services;

use App\Models\VisitRequest;
use App\Models\VisitGroupMember;
use App\Models\QRToken;
use Illuminate\Support\Str;

class QRTokenService
{
    public static function generate(VisitRequest $request): void
    {
        $existing = QRToken::where('visit_request_id', $request->id)->first();
        if ($existing) {
            $existing->delete();
        }

        // Unpredictable, opaque token: no database IDs are embedded so the
        // value cannot be used to enumerate visit records.
        $tokenValue = 'VMS-' . Str::random(16);

        $expiresAt = $request->requested_time
            ? $request->requested_time->copy()->addMinutes($request->duration ?? 60)->addHours(2)
            : now()->addHours(4);

        QRToken::create([
            'visit_request_id' => $request->id,
            'token_value' => $tokenValue,
            'type' => 'visit',
            'expires_at' => $expiresAt,
            'status' => 'active',
        ]);
    }

    public static function generateGroupMemberTokens(VisitRequest $request): void
    {
        foreach ($request->groupMembers as $member) {
            if (empty($member->qr_token)) {
                $tokenValue = 'VMS-GRP-' . Str::random(16);
                $member->qr_token = $tokenValue;
                $member->qr_expires_at = now()->addHours(4);
                $member->save();
            }
        }
    }

    public static function generateGroupMemberExitToken(VisitRequest $visit, VisitGroupMember $member): string
    {
        $tokenValue = 'VMS-GRPEXIT-' . Str::random(16);
        $member->exit_qr_token = $tokenValue;
        $member->exit_qr_expires_at = now()->addHours(4);
        $member->save();

        return $tokenValue;
    }

    public static function validate(string $tokenValue): array
    {
        $qrToken = QRToken::where('token_value', $tokenValue)->first();

        if (!$qrToken) {
            return ['valid' => false, 'error' => 'Invalid QR code.', 'qr_status' => 'not_found', 'request' => null];
        }

        $visit = $qrToken->visitRequest;

        if ($qrToken->isRevoked()) {
            return ['valid' => false, 'error' => 'QR code has been revoked.', 'qr_status' => 'revoked', 'request' => $visit];
        }

        if ($qrToken->isUsed()) {
            return ['valid' => false, 'error' => 'QR code has already been used.', 'qr_status' => 'used', 'request' => $visit];
        }

        if ($qrToken->isExpired()) {
            return ['valid' => false, 'error' => 'QR code has expired.', 'qr_status' => 'expired', 'request' => $visit];
        }

        if (!$visit) {
            return ['valid' => false, 'error' => 'Visit request not found.', 'qr_status' => 'not_found', 'request' => null];
        }

        // Security validation: QR code can only be used for approved visits (not escalated)
        if ($visit->isEscalated()) {
            return ['valid' => false, 'error' => 'This visit is escalated and requires Admin/CEO approval before QR code can be used.', 'qr_status' => 'escalated', 'request' => null];
        }

        if (!in_array($visit->status, ['approved', 'checked_in'])) {
            return ['valid' => false, 'error' => 'Visit is not approved.', 'qr_status' => 'invalid', 'request' => null];
        }

        // The QR must not be used before the scheduled appointment time.
        // Entry is only allowed once the appointment time is reached.
        if ($visit->status !== 'checked_in' && $visit->requested_time && $visit->requested_time->isFuture()) {
            return ['valid' => false, 'error' => 'QR code is not active yet. Entry is only allowed at the scheduled appointment time.', 'qr_status' => 'expired', 'request' => null];
        }

        // The QR must stay valid for the whole visit so the visitor can be
        // re-verified at the gate (e.g. re-entry). Expiry is only enforced
        // before entry; once checked in the QR lives until check-out.
        if ($visit->status !== 'checked_in' && $qrToken->expires_at && $qrToken->expires_at->isPast()) {
            return ['valid' => false, 'error' => 'QR code has expired.', 'qr_status' => 'expired', 'request' => null];
        }

        return ['valid' => true, 'qr_status' => 'valid', 'request' => $visit];
    }

    public static function validateGroupMemberToken(string $tokenValue): array
    {
        $member = VisitGroupMember::where('qr_token', $tokenValue)->first();

        if (!$member) {
            return ['valid' => false, 'error' => 'Invalid group member QR code.', 'qr_status' => 'not_found', 'request' => null, 'member' => null];
        }

        if ($member->qr_used_at) {
            return ['valid' => false, 'error' => 'Group member QR code has already been used.', 'qr_status' => 'used', 'request' => null, 'member' => null];
        }

        $visit = $member->visitRequest;
        if (!$visit || !in_array($visit->status, ['approved', 'checked_in'])) {
            return ['valid' => false, 'error' => 'Visit is not approved.', 'qr_status' => 'invalid', 'request' => null, 'member' => null];
        }

        // The QR must not be used before the scheduled appointment time.
        if ($visit->status !== 'checked_in' && $visit->requested_time && $visit->requested_time->isFuture()) {
            return ['valid' => false, 'error' => 'Group member QR code is not active yet. Entry is only allowed at the scheduled appointment time.', 'qr_status' => 'expired', 'request' => null, 'member' => null];
        }

        // Same rule as the representative pass: expiry only applies before entry;
        // once the member is checked in the QR stays valid until check-out.
        if ($visit->status !== 'checked_in' && $member->qr_expires_at && $member->qr_expires_at->isPast()) {
            return ['valid' => false, 'error' => 'Group member QR code has expired.', 'qr_status' => 'expired', 'request' => null, 'member' => null];
        }

        return ['valid' => true, 'qr_status' => 'valid', 'request' => $visit, 'member' => $member];
    }

    public static function validateGroupMemberExitToken(string $tokenValue): array
    {
        $member = VisitGroupMember::where('exit_qr_token', $tokenValue)->first();

        if (!$member) {
            return ['valid' => false, 'error' => 'Invalid exit QR code.', 'request' => null, 'member' => null];
        }

        if ($member->exit_qr_expires_at && $member->exit_qr_expires_at->isPast()) {
            return ['valid' => false, 'error' => 'Exit QR code has expired.', 'request' => null, 'member' => null];
        }

        $visit = $member->visitRequest;

        return ['valid' => true, 'request' => $visit, 'member' => $member];
    }

    public static function markGroupMemberTokenUsed(string $qrToken): void
    {
        VisitGroupMember::where('qr_token', $qrToken)->update(['qr_used_at' => now()]);
    }

    public static function markGroupMemberExitTokenUsed(int $memberId): void
    {
        VisitGroupMember::where('id', $memberId)->update([
            'exit_qr_token' => null,
            'exit_qr_expires_at' => null,
        ]);
    }

    public static function allGroupMembersCheckedOut(VisitRequest $visit): bool
    {
        $total = $visit->groupMembers()->count();
        $checkedOut = $visit->groupMembers()->whereNotNull('checked_out_at')->count();
        return $total > 0 && $checkedOut >= $total;
    }

    public function generateForGroupMember(VisitGroupMember $member): string
    {
        $tokenValue = 'VMS-GRP-' . Str::random(16);

        QRToken::create([
            'token_value' => $tokenValue,
            'model_type' => get_class($member),
            'model_id' => $member->id,
            'type' => 'group_member',
            'expires_at' => now()->addHours(4),
            'status' => 'active',
        ]);

        return $tokenValue;
    }
}
