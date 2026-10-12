<?php

namespace App\Http\Controllers;

use App\Models\QRToken;
use App\Models\VisitGroupMember;
use App\Models\VisitRequest;
use Inertia\Inertia;

/**
 * Public mobile page opened when a visitor QR pass is scanned with a phone.
 *
 * The QR badge embeds the system-generated visitor number (the QR token) as a
 * link to this page. The scanned token is used to locate the matching visitor
 * record and render its full visitor information. No write operations are
 * performed here and the Security Guard workflow is untouched.
 */
class PublicQrController extends Controller
{
    /**
     * The scanned value must look like a system-generated visitor number.
     * Anything else is rejected before it reaches the database, which keeps
     * junk / malicious input from being used to probe the endpoint.
     */
    protected const TOKEN_PATTERN = '/^[A-Za-z0-9_\-]{4,80}$/';

    public function show(string $token)
    {
        $value = trim($token);

        if (!preg_match(self::TOKEN_PATTERN, $value)) {
            return $this->failed('not_found', $value);
        }

        $result = $this->resolveByToken($value);

        if (!$result['visit']) {
            // Unknown token, or the underlying visit/visitor record was deleted.
            return $this->failed('not_found', $value);
        }

        // Fail closed for passes that must no longer be honored.
        $failure = $this->tokenFailure($result);
        if ($failure !== null) {
            return $this->failed($failure, $value);
        }

        $visit  = $result['visit'];
        $member = $result['member'];

        return Inertia::render('Public/QrScan', [
            'scan_status'    => 'valid',
            'is_exit'        => $result['is_exit'],
            'visitor_number' => $value,
            'visit'          => $this->visitData($visit, $member, $result['is_exit']),
        ]);
    }

    /**
     * Render the public page for a token that could not be honored.
     * Only the scanned value is echoed back — no visitor data is exposed.
     */
    protected function failed(string $status, string $value)
    {
        return Inertia::render('Public/QrScan', [
            'scan_status'    => $status,
            'visitor_number' => $value,
        ]);
    }

    /**
     * Extra validation for a resolved token: revoked or expired passes are
     * rejected with a specific status instead of showing visitor data.
     *
     * Mirrors QRTokenService rules: once a visit is checked in the pass stays
     * valid until check-out so it can be re-verified at the gate.
     *
     * @return ?string 'expired' | 'revoked' | null
     */
    protected function tokenFailure(array $result): ?string
    {
        $visit   = $result['visit'];
        $member  = $result['member'];
        $qrToken = $result['qr_token'] ?? null;
        $isExit  = $result['is_exit'];

        $checkedIn = $visit->status === 'checked_in';

        if ($qrToken) {
            if ($qrToken->isRevoked()) {
                return 'revoked';
            }
            if (!$checkedIn && $qrToken->isExpired()) {
                return 'expired';
            }
        }

        if ($isExit) {
            $expiresAt = $member
                ? $member->exit_qr_expires_at
                : $visit->exit_qr_expires_at;

            if ($expiresAt && $expiresAt->isPast()) {
                return 'expired';
            }
        } elseif ($member && !$checkedIn && $member->qr_expires_at && $member->qr_expires_at->isPast()) {
            return 'expired';
        }

        return null;
    }

    /**
     * Find the visitor record behind a scanned QR token.
     *
     * A scanned value may be any of the existing system-generated visitor
     * numbers: a visit entry token, a group member entry token, a visit exit
     * token, or a group member exit token. Global scopes are bypassed so the
     * matching visit is found regardless of the caller's company/branch.
     *
     * @return array{visit: ?VisitRequest, member: ?VisitGroupMember, is_exit: bool, qr_token: ?QRToken}
     */
    protected function resolveByToken(string $value): array
    {
        // 1) Representative / individual visit EXIT pass
        $visit = VisitRequest::withoutGlobalScopes()
            ->where('exit_qr_token', $value)
            ->first();

        if ($visit) {
            return ['visit' => $visit, 'member' => null, 'is_exit' => true, 'qr_token' => null];
        }

        // 2) Group member EXIT pass
        $member = VisitGroupMember::withoutGlobalScopes()
            ->where('exit_qr_token', $value)
            ->first();

        if ($member) {
            $visit = VisitRequest::withoutGlobalScopes()->find($member->visit_request_id);
            return $visit
                ? ['visit' => $visit, 'member' => $member, 'is_exit' => true, 'qr_token' => null]
                : ['visit' => null, 'member' => null, 'is_exit' => true, 'qr_token' => null];
        }

        // 3) Group member ENTRY pass
        $member = VisitGroupMember::withoutGlobalScopes()
            ->where('qr_token', $value)
            ->first();

        if ($member) {
            $visit = VisitRequest::withoutGlobalScopes()->find($member->visit_request_id);
            return $visit
                ? ['visit' => $visit, 'member' => $member, 'is_exit' => false, 'qr_token' => null]
                : ['visit' => null, 'member' => null, 'is_exit' => false, 'qr_token' => null];
        }

        // 4) Representative / individual visit ENTRY pass (qr_tokens table)
        $qrToken = QRToken::withoutGlobalScopes()
            ->where('token_value', $value)
            ->first();

        if ($qrToken) {
            $visit = $qrToken->visit_request_id
                ? VisitRequest::withoutGlobalScopes()->find($qrToken->visit_request_id)
                : null;
            return $visit
                ? ['visit' => $visit, 'member' => null, 'is_exit' => false, 'qr_token' => $qrToken]
                : ['visit' => null, 'member' => null, 'is_exit' => false, 'qr_token' => $qrToken];
        }

        return ['visit' => null, 'member' => null, 'is_exit' => false, 'qr_token' => null];
    }

    /**
     * Build the visitor-information payload shown on the mobile page.
     * Uses only fields already stored in the existing visitor/visit records.
     */
    protected function visitData(VisitRequest $visit, ?VisitGroupMember $member, bool $isExit): array
    {
        $visit->loadMissing(['host.department', 'department', 'branch', 'location', 'qrToken']);

        $visitor   = $visit->resolveVisitor();
        $host      = $visit->host;
        $department = $visit->department->name ?? ($host?->department->name ?? '');

        $checkinLog = $visit->checkInOutLogs()->latest()->first();

        $visitorNumber = $member
            ? ($isExit ? $member->exit_qr_token : $member->qr_token)
            : ($isExit ? $visit->exit_qr_token : ($visit->qrToken->token_value ?? ''));

        $name = $member?->name ?? $visitor?->name ?? $visit->company_name ?? '';

        // Same group/representative context the Security Guard scan returns.
        $isRepresentative = $visit->visit_type === 'company';
        $groupMembersCount = $isRepresentative ? $visit->groupMembers()->count() : 0;

        // Same registered-properties data the Security Guard scan returns,
        // scoped to the owner of the scanned pass (member or representative).
        $properties = ($member
                ? $visit->properties()->forExit(null, $member->id)
                : $visit->properties()->forExit($visitor?->id)
            )->get()
            ->map(fn ($p) => [
                'item_name'     => $p->item_name,
                'quantity'      => $p->quantity,
                'serial_number' => $p->serial_number,
                'property_tag'  => $p->property_tag,
                'status'        => $p->status,
            ]);

        return [
            'id'                 => $visit->id,
            'visit_type'         => $visit->visit_type,
            'visitor_number'     => $visitorNumber,
            'qr_token'           => $visitorNumber,
            'name'               => $name,
            'visitor_name'       => $name,
            'photo_url'          => $visitor?->photo_url ? '/storage/' . $visitor->photo_url : null,
            'gender'             => $member?->gender ?? $visitor?->sex ?? null,
            'age'                => $visitor?->age ?? null,
            'phone'              => $member?->phone ?? $visitor?->phone ?? $visit->company_phone ?? null,
            'email'              => $member?->email ?? $visitor?->email ?? $visit->company_email ?? null,
            'id_document_no'     => $member?->id_document_no ?? $visitor?->id_document_no ?? null,
            'nationality'        => $member?->nationality ?? $visitor?->nationality ?? $visit->representative_nationality ?? null,
            'company_name'       => $visitor?->company_name ?? $visit->company_name ?? null,
            'company_position'   => $member?->position ?? $visitor?->company_position ?? $visit->representative_position ?? null,
            'host_name'          => $host?->name ?? '',
            'host_department'    => $department,
            'department'         => $department,
            'branch'             => $visit->branch->name ?? $visit->location->name ?? null,
            'purpose'            => $visit->purpose,
            'appointment_date'   => $visit->requested_time ? $visit->requested_time->format('D, M d Y') : null,
            'appointment_time'   => $visit->requested_time ? $visit->requested_time->format('h:i A') : null,
            'scheduled'          => $visit->requested_time ? $visit->requested_time->format('M d, Y H:i') : null,
            'duration'           => $visit->duration,
            'status'             => $visit->status,
            'priority'           => $visit->priority,
            'is_vip'             => $visit->is_vip,
            'is_group_member'    => $member ? true : false,
            'is_representative'  => $member ? false : $isRepresentative,
            'group_member_id'    => $member?->id,
            'group_visit_id'     => ($isRepresentative || $member) ? $visit->id : null,
            'group_members_count' => $groupMembersCount,
            'representative_name' => $member ? ($visitor?->name ?? '') : null,
            'representative_id'   => $member ? ($visitor?->id_document_no ?? '') : null,
            'properties'         => $properties,
            'meeting_room'       => $visit->meeting_room_id,
            'building'           => $visit->building,
            'floor'              => $visit->floor ? 'Floor ' . $visit->floor : null,
            'check_in_time'      => $checkinLog?->checkin_time?->format('M d, Y H:i'),
            'check_out_time'     => $checkinLog?->checkout_time?->format('M d, Y H:i'),
        ];
    }
}