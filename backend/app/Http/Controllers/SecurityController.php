<?php

namespace App\Http\Controllers;

use App\Models\VisitRequest;
use App\Models\Visitor;
use App\Models\QRToken;
use App\Models\CheckInOutLog;
use App\Models\Blacklist;
use App\Models\Lockdown;
use App\Models\User;
use App\Models\VisitorProperty;
use App\Models\Incident;
use App\Models\AuditLog;
use App\Models\Escalation;
use App\Models\Watchlist;
use App\Models\BadgeTemplate;
use App\Models\Message;
use App\Services\QRTokenService;
use App\Services\SecurityIncidentService;
use App\Services\NotificationService;
use App\Services\AuditLogger;
use App\Services\SmsSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;

class SecurityController extends Controller
{
    /**
     * Validate that the authenticated guard is authorized for this visit's location and branch.
     * Returns null if authorized, or a string error key if not.
     */
    private function authorizeGuardForVisit(VisitRequest $visit): ?string
    {
        $guard = Auth::guard('web')->user();

        if ($guard->location_id && (int)$visit->location_id !== (int)$guard->location_id) {
            return 'location_mismatch';
        }

        if ($guard->branch_id) {
            if ((int)$visit->branch_id !== (int)$guard->branch_id) {
                return 'branch_mismatch';
            }
        } else {
            if ($visit->branch_id) {
                return 'branch_mismatch';
            }
        }

        return null;
    }

    /**
     * Build a VisitRequest query filtered by the authenticated guard's location and branch.
     */
    private function guardVisitQuery()
    {
        $guard = Auth::guard('web')->user();
        $query = VisitRequest::query();

        if ($guard->location_id) {
            $query->where('location_id', $guard->location_id);
        }

        if ($guard->branch_id) {
            $query->where('branch_id', $guard->branch_id);
        } else {
            $query->whereNull('branch_id');
        }

        return $query;
    }

    /**
     * Build an Incident query filtered to the authenticated guard's own area
     * (company, location and branch).
     */
    private function guardIncidentQuery()
    {
        $guard = Auth::guard('web')->user();
        $query = Incident::query();

        if ($guard->location_id) {
            $query->where('location_id', $guard->location_id);
        }

        if ($guard->branch_id) {
            $query->where('branch_id', $guard->branch_id);
        } else {
            $query->whereNull('branch_id');
        }

        return $query;
    }

    /**
     * Security Dashboard — Comprehensive View
     */
    public function dashboard($section = null)
    {
        $guard = Auth::guard('web')->user();
        $guardBranchId = $guard->branch_id;
        $today = today();

        $baseQuery = $this->guardVisitQuery();

        // 1. Today's Arrivals (approved visits for today)
        $todayArrivals = (clone $baseQuery)
            ->where('status', 'approved')
            ->whereDate('requested_time', $today)
            ->with(['visitor', 'host.department', 'department', 'groupMembers'])
            ->orderBy('requested_time', 'asc')
            ->get();

        // 2. Current visitor count (checked in)
        $checkedInCount = (clone $baseQuery)->where('status', 'checked_in')->count();

        // 3. Pending Arrivals count (approved but not yet arrived)
        $pendingArrivalsCount = (clone $baseQuery)
            ->where('status', 'approved')
            ->where('requested_time', '<=', now()->addHours(2))
            ->count();

        // Resolve scoped-out representatives (cross-company linked visitors are
        // hidden by the global CompanyScope) so name/ID render on the dashboard.
        // A single batched query replaces one DB lookup per visit.
        $ids = $todayArrivals->filter(fn($v) => $v->visitor_id)->pluck('visitor_id')->unique()->values();
        $visitorsById = collect();
        if ($ids->isNotEmpty()) {
            $visitorsById = Visitor::withoutGlobalScopes()->whereIn('id', $ids)->get()->keyBy('id');
        }
        $todayArrivals->each(function ($v) use ($visitorsById) {
            $resolved = $v->visitor_id ? $visitorsById->get($v->visitor_id) : null;
            if (!$resolved) {
                $resolved = $v->resolveVisitor();
            }
            if ($resolved) {
                $v->setRelation('visitor', $resolved);
            }
        });

        // 4. Today's check-ins (filtered by guard's branch/location)
        $todayCheckinsCount = CheckInOutLog::whereDate('checkin_time', $today)
            ->whereHas('visitRequest', function ($q) use ($guard) {
                if ($guard->location_id) {
                    $q->where('location_id', $guard->location_id);
                }
                if ($guard->branch_id) {
                    $q->where('branch_id', $guard->branch_id);
                } else {
                    $q->whereNull('branch_id');
                }
            })
            ->count();

        // 5. Today's check-outs (filtered by guard's branch/location)
        $todayCheckoutsCount = CheckInOutLog::whereDate('checkout_time', $today)
            ->whereHas('visitRequest', function ($q) use ($guard) {
                if ($guard->location_id) {
                    $q->where('location_id', $guard->location_id);
                }
                if ($guard->branch_id) {
                    $q->where('branch_id', $guard->branch_id);
                } else {
                    $q->whereNull('branch_id');
                }
            })
            ->count();

        // 6. Denied entries count (filtered by guard's branch)
        $deniedEntriesCount = AuditLog::where('action', 'visit_request_failed_validation')
            ->whereDate('created_at', $today)
            ->where(function ($q) use ($guard) {
                if ($guard->branch_id) {
                    $q->where('branch_id', $guard->branch_id);
                } else {
                    $q->whereNull('branch_id');
                }
            })
            ->count();

        // 7. Active alerts / incidents (scoped to the guard's company/location/branch)
        $activeIncidents = $this->guardIncidentQuery()
            ->whereIn('status', ['open', 'investigating'])
            ->with('reportedBy')
            ->orderByRaw("CASE WHEN severity = 'critical' THEN 0 WHEN severity = 'high' THEN 1 WHEN severity = 'medium' THEN 2 ELSE 3 END")
            ->get();

        // 8. Blacklist
        $blacklist = Blacklist::select('name', 'id_document_no', 'reason')->get();

        // 9. Live visitor count
        $liveCount = $checkedInCount;

        // 10. Security alerts from audit log (filtered by guard's branch)
        $securityAlerts = AuditLog::whereIn('action', [
            'blacklist_warning', 'blacklist_match',
            'entry_denied', 'checkin', 'checkout',
            'incident_created', 'fake_qr_detected',
        ])->whereDate('created_at', $today)
            ->where(function ($q) use ($guard) {
                if ($guard->branch_id) {
                    $q->where('branch_id', $guard->branch_id);
                } else {
                    $q->whereNull('branch_id');
                }
            })
            ->latest()->take(50)->get();

        // 13. Escalated visits (pending admin decision)
        $escalations = (clone $baseQuery)
            ->where('status', 'escalated')
            ->with('visitor', 'host.department', 'qrToken')
            ->orderBy('updated_at', 'desc')
            ->get()
            ->map(fn($v) => [
                'id'              => $v->id,
                'visitor_name'    => $v->visitor?->name ?? $v->company_name ?? '',
                'visitor_email'   => $v->visitor?->email ?? $v->company_email ?? '',
                'id_document_no'  => $v->visitor?->id_document_no ?? '',
                'host_name'       => $v->host->name ?? '',
                'department'      => $v->host->department->name ?? '',
                'purpose'         => $v->purpose,
                'requested_time'  => $v->requested_time->toDateTimeString(),
                'duration'        => $v->duration,
                'is_vip'          => $v->is_vip,
                'priority'        => $v->priority,
                'escalated_at'    => $v->updated_at->toDateTimeString(),
                'qr_token'        => $v->qrToken?->token,
                'qr_status'       => $v->qrToken?->status,
            ]);

        // 14. Watchlist entries
        $watchlist = Watchlist::select('name', 'id_document_no', 'reason', 'level')->get();

        // 15. Risk summary
        $riskSummary = [
            'escalated_count'   => $escalations->count(),
            'watchlist_count'   => $watchlist->count(),
            'blacklist_count'   => $blacklist->count(),
            'active_incidents'  => $activeIncidents->count(),
            'denied_today'      => $deniedEntriesCount,
        ];

        // Daily summary stats
        $stats = [
            'total_today'      => (clone $baseQuery)->whereDate('created_at', $today)->count(),
            'checked_in'       => $checkedInCount,
            'checked_out_today'=> $todayCheckoutsCount,
            'denied'           => $deniedEntriesCount,
            'incidents'        => $activeIncidents->count(),
            'pending_arrivals' => $pendingArrivalsCount,
            'total_checkins'   => $todayCheckinsCount,
            'total_checkouts'  => $todayCheckoutsCount,
        ];

        return Inertia::render('Dashboard/Security', [
            'stats'              => $stats,
            'today_arrivals'     => $todayArrivals,
            'active_incidents'   => $activeIncidents,
            'blacklist'          => $blacklist,
            'live_count'         => $liveCount,
            'security_alerts'    => $securityAlerts,
            'escalations'        => $escalations,
            'watchlist'          => $watchlist,
            'risk_summary'       => $riskSummary,
            'section'            => $section,
            'host_change_requests' => \App\Models\HostChangeRequest::with('visitRequest.visitor', 'previousHost', 'newHost', 'newDepartment')
                ->whereIn('status', ['pending', 'accepted'])
                ->latest()
                ->take(20)
                ->get(),
            'messages_inbox'     => \App\Http\Controllers\MessageController::inboxList('user', $guard->id),
            'messages_sent'      => \App\Http\Controllers\MessageController::sentList('user', $guard->id),
            'messages_recipients' => \App\Http\Controllers\MessageController::recipientsFor('user', $guard),
            'messages_unread_count' => Message::unreadCount('user', $guard->id),
        ]);
    }

    /**
     * Entry Gate — Scan QR Code and verify visitor.
     */
    public function scanQR(Request $request)
    {
        $tokenValue = $request->input('token') ?? $request->input('token_value');

        if (!$tokenValue) {
            return response()->json([
                'success' => false,
                'message' => 'Please enter or scan a QR code.',
                'visit' => null,
                'qr_status' => null,
            ]);
        }

        // Check if this is an EXIT QR token — redirect to exit flow
        $exitVisit = VisitRequest::with('visitor', 'host.department', 'department')
            ->where('exit_qr_token', strtoupper(trim($tokenValue)))
            ->first();

        if ($exitVisit) {
            $authResult = $this->authorizeGuardForVisit($exitVisit);
            if ($authResult) {
                $msg = $authResult === 'branch_mismatch'
                    ? 'Access denied. This visitor is assigned to another branch. Please scan the visitor at the security gate assigned to this branch.'
                    : 'Access denied. This visitor is assigned to another location.';
                return response()->json(['success' => false, 'message' => $msg], 403);
            }
            if (!in_array($exitVisit->status, ['checked_in', 'checked_out'], true)) {
                return response()->json([
                    'success' => false,
                    'message' => "This is an EXIT QR code, but the visit is currently '{$exitVisit->status}'. Only active visitors can exit.",
                    'visit' => null,
                    'qr_status' => 'exit_wrong_status',
                ]);
            }

            if ($exitVisit->exit_qr_expires_at && now()->gt($exitVisit->exit_qr_expires_at)) {
                return response()->json([
                    'success' => false,
                    'message' => 'This EXIT QR code has expired. Please ask the host to generate a new one.',
                    'visit' => null,
                    'qr_status' => 'exit_expired',
                ]);
            }

            $exitVisitor = $exitVisit->resolveVisitor();
            $entryProperties = $exitVisit->properties()->forExit($exitVisitor?->id)->get();

            return response()->json([
                'success'      => true,
                'message'      => 'EXIT QR code validated. This visitor is ready for checkout.',
                'is_exit_scan' => true,
                'visit_id'     => $exitVisit->id,
                'visitor_name' => $exitVisitor?->name ?? $exitVisit->company_name ?? '',
                'id_document_no' => $exitVisitor?->id_document_no ?? '',
                'visitor_phone' => $exitVisitor?->phone ?? '',
                'host_name'    => $exitVisit->host->name ?? '',
                'department'   => $exitVisit->host->department->name ?? '',
                'purpose'      => $exitVisit->purpose,
                'properties'   => $entryProperties->map(fn($p) => [
                    'id'          => $p->id,
                    'item_name'   => $p->item_name,
                    'quantity'    => $p->quantity,
                    'serial_number' => $p->serial_number,
                    'property_tag' => $p->property_tag,
                    'status'      => $p->status,
                ]),
                'owner_verified' => true,
                'qr_status'    => 'valid_exit',
            ]);
        }

        // Check if this is a GROUP MEMBER EXIT QR token
        if (str_starts_with(strtoupper(trim($tokenValue)), 'VMS-GRPEXIT-')) {
            $exitValidation = QRTokenService::validateGroupMemberExitToken(trim($tokenValue));

            if (!$exitValidation['valid']) {
                return response()->json([
                    'success' => false,
                    'message' => $exitValidation['error'],
                    'visit' => null,
                    'qr_status' => 'exit_invalid',
                ]);
            }

            $visit = $exitValidation['request'];
            $member = $exitValidation['member'];

            $authResult = $this->authorizeGuardForVisit($visit);
            if ($authResult) {
                $msg = $authResult === 'branch_mismatch'
                    ? 'Access denied. This visitor is assigned to another branch. Please scan the visitor at the security gate assigned to this branch.'
                    : 'Access denied. This visitor is assigned to another location.';
                return response()->json(['success' => false, 'message' => $msg, 'visit' => null, 'qr_status' => 'branch_mismatch'], 403);
            }

            $entryProperties = $visit->properties()->forExit(null, $member->id)->get();

            return response()->json([
                'success'         => true,
                'message'         => "Group member EXIT QR code validated: {$member->name}. Ready for checkout.",
                'is_exit_scan'    => true,
                'is_group_member' => true,
                'visit_id'        => $visit->id,
                'group_member_id' => $member->id,
                'visitor_name'    => $member->name,
                'id_document_no'  => $member->id_document_no ?? '',
                'visitor_phone'   => $member->phone ?? '',
                'host_name'       => $visit->host->name ?? '',
                'department'      => $visit->host->department->name ?? '',
                'purpose'         => $visit->purpose,
                'properties'      => $entryProperties->map(fn($p) => [
                    'id'            => $p->id,
                    'item_name'     => $p->item_name,
                    'quantity'      => $p->quantity,
                    'serial_number' => $p->serial_number,
                    'property_tag'  => $p->property_tag,
                    'status'        => $p->status,
                ]),
                'owner_verified'  => true,
                'qr_status'       => 'valid_exit',
            ]);
        }

        // Check if this is a GROUP MEMBER QR token
        if (str_starts_with(strtoupper(trim($tokenValue)), 'VMS-GRP-')) {
            $groupValidation = QRTokenService::validateGroupMemberToken(trim($tokenValue));

            if (!$groupValidation['valid']) {
                $qrStatus  = $groupValidation['qr_status'] ?? 'invalid';
                $guardUser = Auth::guard('web')->user();

                $incidentType = match ($qrStatus) {
                    'not_found' => Incident::TYPE_FAKE_QR,
                    'used'      => Incident::TYPE_USED_QR,
                    'expired'   => Incident::TYPE_EXPIRED_QR,
                    'revoked'   => Incident::TYPE_REVOKED_QR,
                    default     => Incident::TYPE_FAKE_QR,
                };

                $this->createIncident(
                    $incidentType,
                    $qrStatus === 'not_found' ? 'high' : 'medium',
                    Incident::typeLabel($incidentType) . ' — Group Member Entry Attempt',
                    "A {$incidentType} group member QR code was scanned: {$tokenValue}. Error: {$groupValidation['error']}",
                    $groupValidation['request']?->id,
                    null,
                    $guardUser?->id,
                    null,
                    $tokenValue,
                    $groupValidation['member']?->id
                );

                return response()->json([
                    'success' => false,
                    'message' => $groupValidation['error'],
                    'visit' => null,
                    'qr_status' => $qrStatus,
                ]);
            }

            $visit = $groupValidation['request'];
            $member = $groupValidation['member'];
            $visit->load('visitor', 'host.department');

            $authResult = $this->authorizeGuardForVisit($visit);
            if ($authResult) {
                $msg = $authResult === 'branch_mismatch'
                    ? 'Access denied. This visitor is assigned to another branch. Please scan the visitor at the security gate assigned to this branch.'
                    : 'Access denied. This visitor is assigned to another location.';
                return response()->json(['success' => false, 'message' => $msg, 'visit' => null, 'qr_status' => 'branch_mismatch'], 403);
            }

            if ($visit->is_no_show) {
                return response()->json([
                    'success' => false,
                    'message' => 'This visit was marked as a no-show. Entry is not permitted.',
                    'visit' => null,
                    'qr_status' => 'expired',
                ]);
            }

            // Blacklist check for group member
            if ($member->id_document_no) {
                $blacklisted = Blacklist::where('id_document_no', $member->id_document_no)->first();
                if ($blacklisted) {
                    $this->createIncident(
                        Incident::TYPE_BLACKLIST_MATCH,
                        'critical',
                        'Blacklisted Group Member Entry Attempt',
                        "Blacklisted group member '{$member->name}' (ID: {$member->id_document_no}) attempted entry. Reason: {$blacklisted->reason}.",
                        $visit->id,
                        null,
                        Auth::guard('web')->user()->id,
                        null,
                        $tokenValue,
                        $member->id
                    );
                    NotificationService::blacklistAlert($member->name, $member->id_document_no, $blacklisted->reason, $visit->company_id, $visit->branch_id, true);
                    return response()->json(['success' => false, 'message' => "ACCESS DENIED: {$member->name} is on the security blacklist. Reason: {$blacklisted->reason}", 'visit' => null, 'qr_status' => 'blocked']);
                }
            }

            $checkinLog = $visit->checkInOutLogs()->latest()->first();
            $representative = $this->resolveRepresentative($visit);

            return response()->json([
                'success' => true,
                'message' => "Group member QR validated: {$member->name}",
                'visit' => [
                    'id' => $visit->id,
                    'visitor_name' => $member->name,
                    'id_document_no' => $member->id_document_no,
                    'visitor_phone' => $member->phone,
                    'host_name' => $visit->host->name ?? '',
                    'host_department' => $visit->host->department->name ?? '',
                    'purpose' => $visit->purpose,
                    'scheduled' => $visit->requested_time->format('M d, Y H:i'),
                    'duration' => $visit->duration,
                    'is_vip' => $visit->is_vip,
                    'priority' => $visit->priority,
                    'status' => $visit->status,
                    'qr_token' => $member->qr_token ?? '',
                    'is_group_member' => true,
                    'group_member_id' => $member->id,
                    'company_name' => $visit->company_name ?? '',
                    'group_visit_id' => $visit->id,
                    'representative_name' => $representative->name ?? '',
                    'representative_id' => $representative->id_document_no ?? '',
                    'check_in_time' => $checkinLog ? $checkinLog->checkin_time->format('M d, Y H:i') : null,
                    'check_out_time' => $checkinLog?->checkout_time?->format('M d, Y H:i'),
                    'properties' => $visit->properties()->forExit(null, $member->id)->get()->map(fn($p) => [
                        'item_name'      => $p->item_name,
                        'quantity'       => $p->quantity,
                        'serial_number'  => $p->serial_number,
                        'property_tag'   => $p->property_tag,
                        'status'         => $p->status,
                    ]),
                ],
                'qr_status' => 'valid_group_member',
            ]);
        }

        $validation = QRTokenService::validate($tokenValue);

        if (!$validation['valid']) {
            $qrStatus  = $validation['qr_status'] ?? 'invalid';
            $guardUser = Auth::guard('web')->user();
            $visitId   = optional($validation['request'] ?? null)->id;
            $visitorId = optional($validation['request'] ?? null)->visitor_id;

            $incidentType = match ($qrStatus) {
                'not_found' => Incident::TYPE_FAKE_QR,
                'used'      => Incident::TYPE_USED_QR,
                'expired'   => Incident::TYPE_EXPIRED_QR,
                'revoked'   => Incident::TYPE_REVOKED_QR,
                'escalated' => Incident::TYPE_BLACKLIST_MATCH,
                'invalid'   => Incident::TYPE_FAKE_QR,
                default     => Incident::TYPE_FAKE_QR,
            };

            $this->createIncident(
                $incidentType,
                $qrStatus === 'not_found' ? 'high' : 'medium',
                Incident::typeLabel($incidentType) . ' — Entry Attempt',
                "A {$incidentType} QR code was scanned: {$tokenValue}. Error: {$validation['error']}",
                $visitId,
                $visitorId,
                $guardUser?->id,
                null,
                $tokenValue
            );

            return response()->json([
                'success' => false,
                'message' => $validation['error'],
                'visit' => null,
                'qr_status' => $qrStatus,
            ]);
        }

        $visit = $validation['request'];
        $visit->load('visitor', 'host.department');

        $authResult = $this->authorizeGuardForVisit($visit);
        if ($authResult) {
            $msg = $authResult === 'branch_mismatch'
                ? 'Access denied. This visitor is assigned to another branch. Please scan the visitor at the security gate assigned to this branch.'
                : 'Access denied. This visitor is assigned to another location.';
            return response()->json(['success' => false, 'message' => $msg, 'visit' => null, 'qr_status' => 'branch_mismatch'], 403);
        }

        if ($visit->is_no_show) {
            return response()->json([
                'success' => false,
                'message' => 'This visit was marked as a no-show. Entry is not permitted.',
                'visit' => null,
                'qr_status' => 'expired',
            ]);
        }

        // Blacklist check at point of entry
        $scannedVisitor = $this->resolveRepresentative($visit);
        $visitorDocNo = $scannedVisitor?->id_document_no ?? '';
        if ($visitorDocNo) {
            $blacklisted = Blacklist::where('id_document_no', $visitorDocNo)->first();
            if ($blacklisted) {
                $visitorName = $scannedVisitor?->name ?? $visit->company_name ?? 'Visitor';
            $this->createIncident(
                Incident::TYPE_BLACKLIST_MATCH,
                'critical',
                'Blacklisted Visitor Entry Attempt',
                "Blacklisted visitor '{$visitorName}' (ID: {$visitorDocNo}) attempted entry. Reason: {$blacklisted->reason}.",
                $visit->id,
                $visit->visitor_id,
                Auth::guard('web')->user()->id,
                null,
                $tokenValue
            );

                NotificationService::blacklistAlert(
                    $visitorName,
                    $visitorDocNo,
                    "Entry denied: Scan-time blacklist trigger.",
                    $visit->company_id,
                    $visit->branch_id,
                    true
                );

                return response()->json([
                    'success' => false,
                    'message' => "SECURITY DENIAL: Visitor '{$visitorName}' is blacklisted. Entrance forbidden.",
                    'visit' => null,
                    'qr_status' => 'valid_blacklisted',
                ]);
            }
        }

        $checkinLog = $visit->checkInOutLogs()->latest()->first();
        $isRepresentative = $visit->visit_type === 'company';
        $groupMembersCount = $isRepresentative ? $visit->groupMembers()->count() : 0;

        return response()->json([
            'success' => true,
            'message' => 'QR Code validated successfully.',
            'visit' => [
                'id'              => $visit->id,
                'visitor_name'    => $scannedVisitor?->name ?? $visit->company_name ?? '',
                'id_document_no'  => $scannedVisitor?->id_document_no ?? '',
                'visitor_phone'   => $scannedVisitor?->phone ?? '',
                'visitor_photo'   => $scannedVisitor?->photo_url ?? '',
                'host_name'       => $visit->host->name,
                'host_department' => $visit->host->department->name ?? '',
                'purpose'         => $visit->purpose,
                'scheduled'       => $visit->requested_time->format('M d, Y H:i'),
                'duration'        => $visit->duration,
                'is_vip'          => $visit->is_vip,
                'priority'        => $visit->priority,
                'status'          => $visit->status,
                'qr_token'        => $visit->qrToken?->token_value ?? '',
                'is_representative' => $isRepresentative,
                'company_name'    => $visit->company_name ?? '',
                'group_visit_id'  => $isRepresentative ? $visit->id : null,
                'group_members_count' => $groupMembersCount,
                'check_in_time'   => $checkinLog ? $checkinLog->checkin_time->format('M d, Y H:i') : null,
                'check_out_time'  => $checkinLog?->checkout_time?->format('M d, Y H:i'),
                'properties'      => $visit->properties()->forExit($scannedVisitor?->id)->get()->map(fn($p) => [
                    'item_name'      => $p->item_name,
                    'quantity'       => $p->quantity,
                    'serial_number'  => $p->serial_number,
                    'property_tag'   => $p->property_tag,
                    'status'         => $p->status,
                ]),
            ],
            'qr_status' => 'valid',
        ]);
    }

    /**
     * Resolve the actual visitor identity for a scan.
     * For company/group visits the representative may not yet be linked via visitor_id,
     * so fall back to matching the company_email / company_phone against the visitors table.
     * Uses withoutGlobalScopes so the linked visitor is found even when their Visitor
     * account belongs to a different company than the visit (CompanyScope would hide it).
     */
    private function resolveRepresentative(VisitRequest $visit)
    {
        if ($visit->visitor_id) {
            $visitor = \App\Models\Visitor::withoutGlobalScopes()
                ->where('id', $visit->visitor_id)
                ->first();
            if ($visitor) {
                return $visitor;
            }
        }

        if ($visit->visit_type === 'company') {
            if (!empty($visit->company_email) || !empty($visit->company_phone)) {
                return \App\Models\Visitor::withoutGlobalScopes()
                    ->where('company_id', $visit->company_id)
                    ->where(function ($q) use ($visit) {
                        if (!empty($visit->company_email)) {
                            $q->where('email', $visit->company_email);
                        }
                        if (!empty($visit->company_phone)) {
                            $q->orWhere('phone', $visit->company_phone);
                        }
                    })->first();
            }
        }

        return null;
    }

    /**
     * Identity Verification — Compare QR data with ID card.
     */
    public function verifyIdentity(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'visit_id'        => 'required|exists:visit_requests,id',
            'id_document_no'  => 'required|string',
            'visitor_name'    => 'required|string',
            'group_member_id' => 'nullable|exists:visit_group_members,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Invalid verification data.'], 422);
        }

        $visit = VisitRequest::with('visitor')->findOrFail($request->visit_id);

        $authResult = $this->authorizeGuardForVisit($visit);
        if ($authResult) {
            $msg = $authResult === 'branch_mismatch'
                ? 'Access denied. This visitor is assigned to another branch. Please scan the visitor at the security gate assigned to this branch.'
                : 'Access denied. This visitor is assigned to another location.';
            return response()->json(['success' => false, 'message' => $msg], 403);
        }

        // Group member identity verification
        if ($request->group_member_id) {
            $member = \App\Models\VisitGroupMember::where('id', $request->group_member_id)
                ->where('visit_request_id', $visit->id)->first();

            if (!$member) {
                return response()->json(['success' => false, 'message' => 'Group member not found for this visit.'], 404);
            }

            $nameMatch = strtolower(trim($member->name)) === strtolower(trim($request->visitor_name));
            $idMatch = strtolower(trim($member->id_document_no ?? '')) === strtolower(trim($request->id_document_no));

            if ($nameMatch && $idMatch) {
                AuditLogger::log('identity_verified_group_member', 'visit_requests', $visit->id, null, [
                    'member_name' => $member->name,
                    'id_number' => $member->id_document_no,
                    'group_member_id' => $member->id,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => "Identity verified for group member {$member->name}. Name and ID match QR data.",
                    'match' => true,
                ]);
            }

            $mismatches = [];
            if (!$nameMatch) $mismatches[] = 'Name';
            if (!$idMatch) $mismatches[] = 'ID Number';

            $this->createIncident(
                'identity_mismatch',
                'high',
                'Group Member Identity Verification Failed',
                "Identity mismatch for group member '{$member->name}'. Mismatched fields: " . implode(', ', $mismatches) . ".",
                $visit->id,
                null,
                Auth::guard('web')->user()->id
            );

            return response()->json([
                'success' => false,
                'message' => 'Entry Denied: Identity does not match QR data. Mismatched: ' . implode(', ', $mismatches),
                'match' => false,
            ]);
        }

        $visitor = $this->resolveRepresentative($visit);
        if (!$visitor) {
            // Company/group visit whose representative has not yet registered/linked a visitor
            // account, so there is no stored name/ID to compare against. The guard's manual
            // entry is accepted as a manual verification and the flow continues to property
            // registration / check-in instead of being blocked.
            AuditLogger::log('identity_verified_manual', 'visit_requests', $visit->id, null, [
                'visitor_name' => $request->visitor_name,
                'id_number'    => $request->id_document_no,
                'note'         => 'No linked visitor record - manual verification for company representative',
            ]);
            return response()->json([
                'success' => true,
                'message' => 'Identity verified (manual). No linked visitor record exists for this company representative - confirm the name and ID against the physical ID card before allowing entry.',
                'match'   => true,
                'manual'  => true,
            ]);
        }

        $nameMatch = strtolower(trim($visitor->name)) === strtolower(trim($request->visitor_name));
        $idMatch = strtolower(trim($visitor->id_document_no)) === strtolower(trim($request->id_document_no));

        if ($nameMatch && $idMatch) {
            AuditLogger::log('identity_verified', 'visit_requests', $visit->id, null, [
                'visitor_name' => $visitor->name,
                'id_number' => $visitor->id_document_no,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Identity verified successfully. Name and ID match QR data.',
                'match' => true,
            ]);
        }

        $mismatches = [];
        if (!$nameMatch) $mismatches[] = 'Name';
        if (!$idMatch) $mismatches[] = 'ID Number';

        $this->createIncident(
            'identity_mismatch',
            'high',
            'Identity Verification Failed',
            "Identity mismatch for visitor '{$visitor->name}'. Mismatched fields: " . implode(', ', $mismatches) . ".",
            $visit->id,
            $visit->visitor_id,
            Auth::guard('web')->user()->id
        );

        return response()->json([
            'success' => false,
            'message' => 'Entry Denied: Identity does not match QR data. Mismatched: ' . implode(', ', $mismatches),
            'match' => false,
        ]);
    }

    /**
     * Register visitor property (item inspection at entry).
     */
    public function registerProperty(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'visit_request_id'      => 'required|exists:visit_requests,id',
            'visit_group_member_id' => 'nullable|exists:visit_group_members,id',
            'items'                 => 'required|array|min:1',
            'items.*.item_name'     => 'required|string|max:255',
            'items.*.quantity'      => 'nullable|integer|min:1|max:9999',
            'items.*.serial_number' => 'nullable|string|max:255',
            'items.*.property_tag'  => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $guard = Auth::guard('web')->user();
        $visit = VisitRequest::findOrFail($request->visit_request_id);

        $authResult = $this->authorizeGuardForVisit($visit);
        if ($authResult) {
            $msg = $authResult === 'branch_mismatch'
                ? 'Access denied. This visitor is assigned to another branch. Please scan the visitor at the security gate assigned to this branch.'
                : 'Access denied. This visitor is assigned to another location.';
            return response()->json(['success' => false, 'message' => $msg], 403);
        }

        $groupMemberId = $request->input('visit_group_member_id');

        // Strict individual ownership: a property is registered to exactly one
        // owner — the group member (visit_group_member_id) or the representative /
        // individual visitor (visitor_id). Never infer ownership from visit
        // membership, so check-out can always scope by the person's own id.
        if ($groupMemberId) {
            $memberBelongsToVisit = \App\Models\VisitGroupMember::where('id', $groupMemberId)
                ->where('visit_request_id', $visit->id)
                ->exists();
            if (!$memberBelongsToVisit) {
                return response()->json([
                    'success' => false,
                    'message' => 'Property owner does not belong to this visit.',
                ], 403);
            }
        }

        $visitorId = null;
        if (!$groupMemberId) {
            $visitorId = $visit->resolveVisitor()?->id;
        }

        foreach ($request->items as $item) {
            VisitorProperty::create([
                'visit_request_id'       => $visit->id,
                'visitor_id'             => $visitorId,
                'visit_group_member_id'  => $groupMemberId ?: null,
                'item_name'              => $item['item_name'],
                'quantity'               => $item['quantity'] ?? 1,
                'serial_number'          => $item['serial_number'] ?? null,
                'property_tag'           => $item['property_tag'] ?? null,
                'status'                 => 'checked_in',
                'checked_in_at'          => now(),
                'registered_by'          => $guard->id,
            ]);
        }

        $query = VisitorProperty::where('visit_request_id', $visit->id);
        if ($groupMemberId) {
            $query->where('visit_group_member_id', $groupMemberId);
        } else {
            $query->where('visitor_id', $visitorId)
                ->orWhere(function ($legacy) {
                    $legacy->whereNull('visitor_id')->whereNull('visit_group_member_id');
                });
        }

        $registeredProperties = $query->get()->map(fn($p) => [
            'id' => $p->id, 'item_name' => $p->item_name, 'quantity' => $p->quantity,
            'serial_number' => $p->serial_number, 'property_tag' => $p->property_tag,
            'status' => $p->status, 'visit_group_member_id' => $p->visit_group_member_id,
        ]);

        AuditLogger::log('property_registered', 'visitor_properties', null, null, [
            'visit_id' => $visit->id, 'items_count' => count($request->items),
            'guard' => $guard->name, 'group_member_id' => $groupMemberId,
        ]);

        return response()->json([
            'success' => true,
            'message' => count($request->items) . ' item(s) registered for visitor.',
            'properties' => $registeredProperties,
        ]);
    }

    /**
     * Check-in visitor after all verifications pass.
     */
    public function checkIn(Request $request, $id)
    {
        $visit = VisitRequest::with('visitor', 'host.department', 'department', 'qrToken')->findOrFail($id);
        $guard = Auth::guard('web')->user();

        $authResult = $this->authorizeGuardForVisit($visit);
        if ($authResult) {
            $msg = $authResult === 'branch_mismatch'
                ? 'Access denied. This visitor is assigned to another branch. Please scan the visitor at the security gate assigned to this branch.'
                : 'Access denied. This visitor is assigned to another location.';
            return back()->with('error', $msg);
        }

        if ($visit->branch && !$visit->branch->is_active) {
            return back()->with('error', 'Check-in is currently suspended: this branch has been deactivated.');
        }

        if (Lockdown::isLocked($visit->company_id, $visit->branch_id)) {
            return back()->with('error', 'Check-in is currently blocked: this location is under an active security lockdown.');
        }

        $groupMemberId = $request->input('group_member_id');

        // Group members check in independently from the representative, so a
        // member may still check in after the representative has already arrived.
        $allowedStatuses = $groupMemberId ? ['approved', 'checked_in'] : ['approved'];
        if (!in_array($visit->status, $allowedStatuses)) {
            return back()->with('error', 'Only approved visit requests can be checked in.');
        }

        $oldState = $visit->toArray();

        CheckInOutLog::create([
            'visit_request_id'       => $visit->id,
            'visit_group_member_id'  => $groupMemberId ?: null,
            'checkin_time'           => now(),
            'guard_id'               => $guard->id,
        ]);

        if ($groupMemberId) {
            $groupMember = \App\Models\VisitGroupMember::where('id', $groupMemberId)
                ->where('visit_request_id', $visit->id)->first();
            if ($groupMember) {
                $groupMember->update([
                    'checked_in_at' => now(),
                    'badge_status'  => 'checked_in',
                ]);
                // QR stays valid during the visit so the member can be re-verified
                // at the gate (e.g. re-entry). It is consumed at check-out.
            }
        }

        $isGroupVisit = $visit->visit_type === 'company' && $visit->groupMembers()->count() > 0;

        if ($isGroupVisit && !$groupMemberId) {
            $visit->status = 'checked_in';
            $visit->save();
        } elseif (!$isGroupVisit) {
            $visit->status = 'checked_in';
            $visit->save();
        }

        $resolvedVisitor = $visit->resolveVisitor();
        $visitorName = $groupMemberId
            ? (($groupMember ?? null)?->name ?? 'Group Member')
            : ($resolvedVisitor?->name ?? $visit->company_name ?? 'Visitor');

        // Use updateOrCreate to handle pre-existing badge (created at approval time)
        $badgeQuery = \App\Models\VisitorBadge::where('visit_request_id', $visit->id);
        if ($groupMemberId) {
            $badgeQuery->where('visitor_name', $visitorName);
        }
        $existingBadge = $badgeQuery->first();

        if ($existingBadge) {
            $existingBadge->update([
                'visitor_id_document' => $groupMemberId ? (($groupMember ?? null)?->id_document_no ?? '') : ($resolvedVisitor?->id_document_no ?? ''),
                'visitor_photo_url'   => $resolvedVisitor?->photo_url ?? $existingBadge->visitor_photo_url,
                'qr_token'            => $groupMemberId ? (($groupMember ?? null)?->qr_token ?? '') : ($visit->qrToken?->token_value ?? $existingBadge->qr_token),
                'issued_at'           => now(),
            ]);
        } else {
            \App\Models\VisitorBadge::create([
                'visit_request_id'   => $visit->id,
                'visitor_name'       => $visitorName,
                'visitor_id_document'=> $groupMemberId ? (($groupMember ?? null)?->id_document_no ?? '') : ($resolvedVisitor?->id_document_no ?? ''),
                'visitor_photo_url'  => $resolvedVisitor?->photo_url ?? null,
                'host_name'          => $visit->host->name ?? '',
                'department'         => $visit->host->department->name ?? '',
                'purpose'            => $visit->purpose ?? '',
                'scheduled_time'     => $visit->requested_time ? $visit->requested_time->format('M d, Y H:i') : '',
                'duration'           => $visit->duration . ' min',
                'priority'           => $visit->priority ?? 'normal',
                'is_vip'             => $visit->is_vip ?? false,
                'qr_token'           => $groupMemberId ? (($groupMember ?? null)?->qr_token ?? '') : ($visit->qrToken?->token_value ?? ''),
                'issued_at'          => now(),
            ]);
        }

        AuditLogger::log('security_checkin', 'visit_requests', $visit->id, $oldState, $visit->toArray());

        // Email/SMS delivery is deferred until after the HTTP response is sent —
        // SMTP/SMS round-trips (which can take seconds per recipient) must never
        // block the guard's check-in request. Notifications still arrive.
        SmsSyncService::syncVisitDeferred($visit, SmsSyncService::EVENT_CHECKED_IN);

        NotificationService::sendDeferred('Visitor Arrived', $visit->host,
            "Visitor {$visitorName} has arrived at the entry gate and been checked in by security.");

        NotificationService::sendDeferred('Visitor Arrived', 'Reception',
            "Visitor {$visitorName} has been checked in. Please prepare visitor badge.",
            null, false, $visit->company_id, $visit->branch_id);

        NotificationService::sendToCompanyAdminsAndCeoDeferred('Visitor Arrived',
            "Visitor {$visitorName} has been checked in at the entry gate by security guard {$guard->name}.",
            $visit->company_id, $visit->branch_id);

        if (!$groupMemberId && $visit->visitor) {
            NotificationService::sendDeferred('Visitor Arrived', $visit->visitor,
                "You have been checked in at the security gate by guard {$guard->name}. Please proceed to {$visit->host->name}'s office.");
        }

        return back()->with('success', "Check-in successful. Host, reception, and visitor notified.");
    }

    /**
     * Check-out visitor at exit gate.
     */
    public function checkOut(Request $request, $id)
    {
        $visit = VisitRequest::findOrFail($id);
        $guard = Auth::guard('web')->user();
        $groupMemberId = $request->input('group_member_id');
        $force = $request->boolean('force', false);

        $authResult = $this->authorizeGuardForVisit($visit);
        if ($authResult) {
            $msg = $authResult === 'branch_mismatch'
                ? 'Access denied. This visitor is assigned to another branch. Please scan the visitor at the security gate assigned to this branch.'
                : 'Access denied. This visitor is assigned to another location.';
            return response()->json(['success' => false, 'message' => $msg], 403);
        }

        if (Lockdown::isLocked($visit->company_id, $visit->branch_id)) {
            return response()->json([
                'success' => false,
                'message' => 'Check-out is currently blocked: this location is under an active security lockdown.',
            ], 423);
        }

        // Group members check out independently from the representative, so a
        // member may still check out even if the representative has not arrived.
        $allowedStatuses = $groupMemberId ? ['checked_in', 'approved'] : ['checked_in'];

        // A visit already ended by a Company Admin/CEO (`checked_out`) can still
        // be finalized at the exit gate when the guard presents the visitor's
        // valid Exit QR — the QR is validated against the visit below so only
        // the intended visitor completes the checkout record.
        if ($visit->status === 'checked_out' && !$groupMemberId) {
            $exitToken = (string) $request->input('qr_token', '');
            $tokenMatches = $visit->exit_qr_token
                && $exitToken !== ''
                && strtoupper(trim($exitToken)) === strtoupper(trim($visit->exit_qr_token));
            if (!$tokenMatches) {
                return response()->json(['success' => false, 'message' => 'This visit is already checked out. A valid Exit QR is required to complete the checkout.'], 422);
            }
            $allowedStatuses[] = 'checked_out';
        }

        if (!in_array($visit->status, $allowedStatuses)) {
            return response()->json(['success' => false, 'message' => 'Only checked-in visitors can be checked out.'], 422);
        }

        // Representative of a group visit may only complete their own check-out
        // once every group member has already departed, so no member is left
        // stranded on premises while the visit is marked complete. A FORCED
        // checkout is an explicit security override and skips this dependency,
        // but the visit is kept open below so remaining members can still leave.
        if (!$groupMemberId && $visit->visit_type === 'company' && !$force) {
            $membersStillInside = \App\Models\VisitGroupMember::where('visit_request_id', $visit->id)
                ->whereNotNull('checked_in_at')
                ->whereNull('checked_out_at')
                ->count();
            if ($membersStillInside > 0) {
                return response()->json([
                    'success' => false,
                    'message' => "Checkout blocked: {$membersStillInside} group member(s) are still on premises. All group members must check out before the representative can depart.",
                ], 422);
            }
        }

        // Group member checkout
        if ($groupMemberId) {
            $member = \App\Models\VisitGroupMember::where('id', $groupMemberId)
                ->where('visit_request_id', $visit->id)->first();

            if (!$member || !$member->isCheckedIn()) {
                return response()->json(['success' => false, 'message' => 'Group member is not currently checked in.'], 422);
            }

            // Verify exit QR if provided
            $exitToken = $request->input('exit_qr_token');
            if ($exitToken && $member->exit_qr_token) {
                if (strtoupper(trim($exitToken)) !== strtoupper(trim($member->exit_qr_token))) {
                    $this->createIncident(
                        Incident::TYPE_FAKE_QR,
                        'critical',
                        'Group Member Exit QR Mismatch',
                        "Group member '{$member->name}' presented an exit QR that does not match their record.",
                        $visit->id, null, $guard->id,
                        null, $tokenValue, $member->id
                    );
                    return response()->json(['success' => false, 'message' => 'SECURITY DENIAL: Exit QR does not match this group member.'], 403);
                }
            }

            // Property verification for this member (only this member's own items)
            $memberProperties = $visit->properties()->forExit(null, $groupMemberId)->get();
            if ($memberProperties->count() > 0) {
                $returnedItems = $request->input('returned_items', []);
                $force = $request->boolean('force', false);

                if (!$force) {
                    $missingItems = [];
                    foreach ($memberProperties as $prop) {
                        $found = false;
                        foreach ($returnedItems as $rItem) {
                            if (($rItem['item_name'] ?? '') === $prop->item_name && ($rItem['serial_number'] ?? '') === ($prop->serial_number ?? '')) {
                                $found = true;
                                break;
                            }
                        }
                        if (!$found) {
                            $missingItems[] = $prop->item_name . ($prop->serial_number ? " (SN: {$prop->serial_number})" : '');
                        }
                    }
                    if (!empty($missingItems)) {
                        $this->createIncident(
                            Incident::TYPE_MISSING_PROPERTY,
                            'high',
                            'Missing Property at Group Member Checkout',
                            "Group member '{$member->name}' is missing: " . implode(', ', $missingItems) . ".",
                            $visit->id, null, $guard->id,
                            null, null, $member->id
                        );
                        return response()->json(['success' => false, 'message' => 'ALARM: Missing property! Items not returned: ' . implode(', ', $missingItems) . '.']);
                    }
                    foreach ($memberProperties as $prop) {
                        $prop->update(['status' => 'returned', 'returned_at' => now()]);
                    }
                } else {
                    foreach ($memberProperties as $prop) {
                        $prop->update(['status' => 'returned', 'returned_at' => now()]);
                    }
                }
            }

            $member->update(['checked_out_at' => now(), 'badge_status' => 'checked_out']);
            QRTokenService::markGroupMemberExitTokenUsed($member->id);
            if ($member->qr_token) {
                QRTokenService::markGroupMemberTokenUsed($member->qr_token);
            }

            CheckInOutLog::where('visit_request_id', $visit->id)
                ->where('visit_group_member_id', $groupMemberId)
                ->whereNull('checkout_time')
                ->update(['checkout_time' => now()]);

            $allCheckedOut = QRTokenService::allGroupMembersCheckedOut($visit);

            AuditLogger::log('security_checkout_group_member', 'visit_requests', $visit->id, null, [
                'member_id' => $groupMemberId, 'member_name' => $member->name, 'all_checked_out' => $allCheckedOut,
            ]);

            NotificationService::sendDeferred('Visitor Checked Out', $visit->host,
                "Group member {$member->name} has checked out." . ($allCheckedOut ? ' All group members have now departed.' : ' Some group members are still on premises.'));

            if ($allCheckedOut) {
                // Only mark the visit as fully checked out once the representative
                // is no longer on premises. Group members depart independently; if
                // the representative is still checked in, keep the visit open so
                // they can still complete their own check-out step and return their
                // own property.
                $representativeStillInside = CheckInOutLog::where('visit_request_id', $visit->id)
                    ->whereNull('visit_group_member_id')
                    ->whereNull('checkout_time')
                    ->exists();

                if (!$representativeStillInside) {
                    $visit->status = 'checked_out';
                    $visit->save();
                    NotificationService::sendDeferred('Group Visit Completed', $visit->host,
                        "All group members for visit #{$visit->id} (" . ($visit->company_name ?? 'Company') . ") have checked out. Visit marked as completed.");
                }
            }

            return response()->json([
                'success' => true,
                'message' => "Group member {$member->name} checked out successfully." . ($allCheckedOut ? ' All group members departed. Visit completed.' : ''),
                'all_checked_out' => $allCheckedOut,
            ]);
        }

        // Individual visitor checkout (existing logic)
        $tokenValue = $request->input('qr_token');
        if ($tokenValue) {
            $qrToken = QRToken::where('token_value', strtoupper(trim($tokenValue)))
                ->where('visit_request_id', $visit->id)->first();

            // Also accept exit QR token stored on the visit record
            $isExitToken = $visit->exit_qr_token
                && strtoupper(trim($tokenValue)) === strtoupper(trim($visit->exit_qr_token));

            if (!$qrToken && !$isExitToken) {
                $scannedVisitor = $this->resolveRepresentative($visit);
                $visitLabel = $scannedVisitor?->name ?? $visit->company_name ?? 'Visitor';
                $visitDoc = $scannedVisitor?->id_document_no ?? $visit->visitor?->id_document_no ?? 'N/A';
                $this->createIncident(
                    Incident::TYPE_FAKE_QR,
                    'critical',
                    'Checkout QR Mismatch',
                    "Visitor '{$visitLabel}' (ID: {$visitDoc}) presented a QR code that does not match their visit at checkout.",
                    $visit->id, $visit->visitor_id, $guard->id,
                    null, $tokenValue
                );
                return response()->json(['success' => false, 'message' => 'SECURITY DENIAL: QR code does not match this visit. Property owner verification failed.'], 403);
            }
        }

        // Individual/representative visitor checkout — scope to this person's own
        // property only, never to other group members' registered items.
        $entryProperties = $visit->properties()->forExit($visit->resolveVisitor()?->id)->get();

        if ($entryProperties->count() > 0) {
            $returnedItems = $request->input('returned_items', []);
            $force = $request->boolean('force', false);

            if (!$force) {
                $allReturned = true;
                $missingItems = [];
                foreach ($entryProperties as $prop) {
                    if ($prop->visit_request_id !== $visit->id) {
                        $this->createIncident(
                            Incident::TYPE_PROPERTY_MISMATCH,
                            'critical',
                            'Property Owner Mismatch at Checkout',
                            "Property '{$prop->item_name}' is registered under visit #{$prop->visit_request_id}, not visit #{$visit->id}.",
                            $visit->id, $visit->visitor_id, $guard->id
                        );
                        return response()->json(['success' => false, 'message' => "SECURITY ALERT: Property '{$prop->item_name}' belongs to visit #{$prop->visit_request_id}."], 403);
                    }
                    $found = false;
                    foreach ($returnedItems as $rItem) {
                        if (($rItem['item_name'] ?? '') === $prop->item_name && ($rItem['serial_number'] ?? '') === ($prop->serial_number ?? '')) {
                            $found = true;
                            break;
                        }
                    }
                    if (!$found) {
                        $allReturned = false;
                        $missingItems[] = $prop->item_name . ($prop->serial_number ? " (SN: {$prop->serial_number})" : '');
                    }
                }
                if (!$allReturned) {
                    $scannedVisitor = $this->resolveRepresentative($visit);
                    $visitLabel = $scannedVisitor?->name ?? $visit->company_name ?? 'Visitor';
                    $this->createIncident(
                        Incident::TYPE_MISSING_PROPERTY,
                        'high',
                        'Missing Property at Checkout',
                        "Visitor '{$visitLabel}' (Visit #{$visit->id}) is missing: " . implode(', ', $missingItems) . ".",
                        $visit->id, $visit->visitor_id, $guard->id
                    );
                    return response()->json(['success' => false, 'message' => 'ALARM: Missing property detected! Items not returned: ' . implode(', ', $missingItems) . '. Checkout blocked.']);
                }
                foreach ($entryProperties as $prop) {
                    $prop->update(['status' => 'returned', 'returned_at' => now()]);
                }
            } else {
                foreach ($entryProperties as $prop) {
                    $prop->update(['status' => 'returned', 'returned_at' => now()]);
                }
                AuditLogger::log('force_checkout_missing_property', 'visitor_properties', null, null, [
                    'visit_id' => $visit->id, 'guard' => $guard->name,
                ]);
            }
        }

        $oldState = $visit->toArray();

        $log = CheckInOutLog::where('visit_request_id', $visit->id)->whereNull('checkout_time')->first();
        if ($log) {
            $log->update(['checkout_time' => now()]);
        }

        // The representative/individual visitor has left — consume their entry QR
        // now, even if the visit stays open for remaining group members.
        if ($visit->qrToken && !$visit->qrToken->used_at) {
            $visit->qrToken->update(['status' => 'used', 'used_at' => now()]);
        }

        // If the representative force-departed while group members are still on
        // premises, keep the visit open (status stays 'checked_in') so those
        // members can still complete their own check-out afterwards. The visit
        // is only closed once no members remain inside.
        $membersStillInside = \App\Models\VisitGroupMember::where('visit_request_id', $visit->id)
            ->whereNotNull('checked_in_at')
            ->whereNull('checked_out_at')
            ->count();

        if ($membersStillInside === 0) {
            $visit->status = 'checked_out';
            $visit->save();
        }

        AuditLogger::log('security_checkout', 'visit_requests', $visit->id, $oldState, $visit->toArray());

        $checkoutVisitor = $this->resolveRepresentative($visit) ?? $visit->visitor;
        $visitLabel = $checkoutVisitor?->name ?? $visit->company_name ?? 'Visitor';
        NotificationService::sendDeferred('Visitor Checked Out', $visit->host,
            "Visitor {$visitLabel} has checked out and departed the premises.");

        if ($checkoutVisitor) {
            NotificationService::sendDeferred('Visitor Checked Out', $visit->visitor,
                "You have been checked out from the building. Thank you for visiting {$visit->host->name}. Have a great day!");
        }

        return response()->json(['success' => true, 'message' => 'Visitor checked out successfully. All property verified.']);
    }

    /**
     * Verify and return visitor property at exit.
     */
    public function verifyPropertyReturn(Request $request, $id)
    {
        $visit = VisitRequest::findOrFail($id);
        $guard = Auth::guard('web')->user();

        $authResult = $this->authorizeGuardForVisit($visit);
        if ($authResult) {
            $msg = $authResult === 'branch_mismatch'
                ? 'Access denied. This visitor is assigned to another branch. Please scan the visitor at the security gate assigned to this branch.'
                : 'Access denied. This visitor is assigned to another location.';
            return response()->json(['success' => false, 'message' => $msg], 403);
        }

        $groupMemberId = $request->input('group_member_id');

        // Strict property isolation: when a group member is checking out, the
        // verification must only consider that member's own registered items.
        // If a member id is supplied it must belong to this visit, otherwise the
        // scope would silently fall back to the representative's property.
        if ($groupMemberId) {
            $member = \App\Models\VisitGroupMember::where('id', $groupMemberId)
                ->where('visit_request_id', $visit->id)
                ->first();
            if (!$member) {
                return response()->json([
                    'success' => false,
                    'all_returned' => false,
                    'missing_items' => [],
                    'message' => 'Group member does not belong to this visit.',
                ], 422);
            }
        }

        // Verify return status ONLY for the current person's own property:
        // the group member's items, or the representative/individual visitor's
        // own items — never another member's or the representative's.
        $entryProperties = $visit->properties()
            ->forExit($groupMemberId ? null : $visit->resolveVisitor()?->id, $groupMemberId)
            ->get();

        $allReturned = true;
        $missingItems = [];
        $returned = $request->input('returned_items', []);

        foreach ($entryProperties as $prop) {
            $found = false;

            foreach ($returned as $rItem) {
                if (($rItem['item_name'] ?? '') === $prop->item_name &&
                    ($rItem['serial_number'] ?? '') === ($prop->serial_number ?? '')) {
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                $allReturned = false;
                $missingItems[] = $prop->item_name . ($prop->serial_number ? " (SN: {$prop->serial_number})" : '');
            }
        }

        if (!$allReturned) {
            $verifyVisitor = $this->resolveRepresentative($visit) ?? $visit->visitor;
            $visitLabel = $verifyVisitor?->name ?? $visit->company_name ?? 'Visitor';
            $this->createIncident(
                Incident::TYPE_MISSING_PROPERTY,
                'high',
                'Missing Property at Checkout',
                "Visitor '{$visitLabel}' (Visit #{$visit->id}) is missing: " . implode(', ', $missingItems) . ".",
                $visit->id,
                $visit->visitor_id,
                $guard->id
            );

            return response()->json([
                'success' => false,
                'all_returned' => false,
                'missing_items' => $missingItems,
                'message' => 'ALARM: Missing property detected! Items not returned: ' . implode(', ', $missingItems),
            ]);
        }

        AuditLogger::log('property_verified_return', 'visitor_properties', null, null, [
            'visit_id' => $visit->id,
            'guard'    => $guard->name,
        ]);

        $verifyVisitor = $this->resolveRepresentative($visit) ?? $visit->visitor;
        $visitLabel = $verifyVisitor?->name ?? $visit->company_name ?? 'Visitor';
        NotificationService::sendDeferred('Visitor Checked Out', $visit->host,
            "All registered property for visitor {$visitLabel} (Visit #{$visit->id}) has been verified as returned by guard {$guard->name}. Exit cleared.");

        return response()->json([
            'success' => true,
            'all_returned' => true,
            'missing_items' => [],
            'message' => 'All property verified. Exit allowed.',
        ]);
    }

    /**
     * Create a security incident (form-based).
     */
    public function createIncidentFromRequest(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'type'        => 'required|string|max:50',
            'severity'    => 'required|in:low,medium,high,critical',
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'visit_id'    => 'nullable|exists:visit_requests,id',
            'visitor_id'  => 'nullable|exists:visitors,id',
            'scan_token'  => 'nullable|string|max:80',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator);
        }

        $guard = Auth::guard('web')->user();

        $incident = $this->createIncident(
            $request->type,
            $request->severity,
            $request->title,
            $request->description ?? '',
            $request->visit_id ?? null,
            $request->visitor_id ?? null,
            $guard->id,
            null,
            $request->scan_token
        );

        return back()->with('success', 'Incident report created. ID: #' . $incident->id);
    }

    /**
     * Update incident status / action taken (legacy form-based).
     * Delegates to SecurityIncidentService for proper audit trail.
     */
    public function updateIncident(Request $request, $id)
    {
        $incident = Incident::findOrFail($id);
        $guard = Auth::guard('web')->user();

        $validator = Validator::make($request->all(), [
            'status'            => 'nullable|in:open,investigating,resolved,dismissed',
            'action_taken'      => 'nullable|string|max:2000',
            'resolution_reason' => 'nullable|string|max:255',
            'resolution_notes'  => 'nullable|string|max:2000',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator);
        }

        if ($request->status === Incident::STATUS_INVESTIGATING) {
            SecurityIncidentService::investigate($incident, $guard, $request->action_taken);
            return back()->with('success', 'Incident #' . $incident->id . ' is now under investigation.');
        }

        if (in_array($request->status, [Incident::STATUS_RESOLVED, Incident::STATUS_DISMISSED])) {
            $resolution = [
                'resolution_reason'  => $request->resolution_reason ?? Incident::RESOLUTION_REASON_CLOSED,
                'resolution_notes'   => $request->action_taken ?? $request->resolution_notes,
                'resolution_action'  => $request->action_taken,
            ];

            if ($request->status === Incident::STATUS_RESOLVED) {
                SecurityIncidentService::resolve($incident, $resolution, $guard);
            } else {
                SecurityIncidentService::dismiss($incident, $request->resolution_notes ?? $request->action_taken, $guard);
            }

            return back()->with('success', 'Incident #' . $incident->id . ' marked as ' . ($request->status === Incident::STATUS_RESOLVED ? 'resolved' : 'dismissed') . '.');
        }

        if ($request->action_taken !== null) {
            $incident->action_taken = $request->action_taken;
            $incident->save();
            AuditLogger::log('incident_updated', 'incidents', $incident->id, null, $incident->toArray());
        }

        return back()->with('success', 'Incident #' . $incident->id . ' updated.');
    }

    /*
    |----------------------------------------------------------------------
    | Security Page — Incident API Endpoints (JSON)
    |----------------------------------------------------------------------
    */

    /**
     * GET /security/incidents/data
     * Paginated, filterable list of incidents for the guard's scope.
     */
    public function incidentData(Request $request)
    {
        $guard = Auth::guard('web')->user();
        $companyId = $guard->company_id;
        $branchId  = $guard->branch_id;

        $filters = [
            'status'    => $request->input('status'),
            'severity'  => $request->input('severity'),
            'type'      => $request->input('type'),
            'search'    => $request->input('search'),
            'date_from' => $request->input('date_from'),
            'date_to'   => $request->input('date_to'),
        ];

        $query = SecurityIncidentService::getIncidentsQuery($filters, $companyId, $branchId);

        $perPage = min($request->input('per_page', 20), 100);
        $paginated = $query->paginate($perPage);

        $data = $paginated->items();
        $rows = array_map([SecurityIncidentService::class, 'serializeIncident'], $data);

        return response()->json([
            'incidents' => $rows,
            'pagination' => [
                'current_page' => $paginated->currentPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
                'last_page'    => $paginated->lastPage(),
                'from'         => $paginated->firstItem(),
                'to'           => $paginated->lastItem(),
            ],
            'total'     => $paginated->total(),
            'open_total'=> $paginated->whereIn('status', ['open', 'investigating'])->count(),
        ]);
    }

    /**
     * GET /security/incidents/counts
     * Summary counts by status, severity, and type for the guard's scope.
     */
    public function incidentCounts(Request $request)
    {
        $guard = Auth::guard('web')->user();

        $counts = SecurityIncidentService::getIncidentCounts(
            $guard->company_id,
            $guard->branch_id
        );

        return response()->json($counts);
    }

    /**
     * GET /security/visits/recent
     * Return recent visits (with visitor info) for the guard's scope.
     * Used to populate the "Related Visit" dropdown in the incident form.
     */
    public function recentVisits(Request $request)
    {
        $guard = Auth::guard('web')->user();

        $query = VisitRequest::with('visitor', 'host')
            ->where('company_id', $guard->company_id);

        if ($guard->location_id) {
            $query->where('location_id', $guard->location_id);
        }
        if ($guard->branch_id) {
            $query->where('branch_id', $guard->branch_id);
        } else {
            $query->whereNull('branch_id');
        }

        $perPage = min($request->input('per_page', 30), 100);

        $visits = $query->whereIn('status', ['approved', 'checked_in', 'checked_out'])
            ->latest('requested_time')
            ->take($perPage)
            ->get()
            ->map(fn ($v) => [
                'id'           => $v->id,
                'visitor_id'   => $v->visitor_id ?? $v->visitor_id_number ?? null,
                'visitor_name' => $v->visitor?->name ?? $v->company_name ?? '—',
                'visitor_doc'  => $v->visitor?->id_document_no ?? null,
                'host_name'    => $v->host?->name ?? '—',
                'purpose'      => $v->purpose ?? '',
                'status'       => $v->status,
                'requested_time'=> $v->requested_time?->format('Y-m-d H:i') ?? '',
            ]);

        return response()->json(['visits' => $visits]);
    }

    /**
     * GET /security/incidents/{id}
     * Full incident detail with audit trail.
     */
    public function incidentDetail(Request $request, $id)
    {
        $guard = Auth::guard('web')->user();
        $incident = Incident::withoutGlobalScopes()
            ->where('company_id', $guard->company_id)
            ->where(function ($q) use ($guard) {
                $q->whereNull('branch_id')
                  ->orWhere('branch_id', $guard->branch_id);
            })
            ->findOrFail($id);

        return response()->json(
            SecurityIncidentService::serializeIncident($incident)
        );
    }

    /**
     * POST /security/incidents/{id}/investigate
     * Assign an incident to investigation.
     */
    public function investigateIncident(Request $request, $id)
    {
        $incident = Incident::findOrFail($id);
        $guard = Auth::guard('web')->user();

        $validator = Validator::make($request->all(), [
            'notes' => 'nullable|string|max:2000',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        try {
            $updated = SecurityIncidentService::investigate($incident, $guard, $request->input('notes'));
            return response()->json([
                'success' => true,
                'message' => "Incident #{$incident->id} is now under investigation.",
                'incident' => SecurityIncidentService::serializeIncident($updated),
            ]);
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 403);
        }
    }

    /**
     * POST /security/incidents/{id}/resolve
     * Resolve an incident with full resolution details.
     */
    public function resolveIncident(Request $request, $id)
    {
        $incident = Incident::findOrFail($id);
        $guard = Auth::guard('web')->user();

        $validator = Validator::make($request->all(), [
            'resolution_reason'  => 'required|string|max:255',
            'resolution_action'  => 'nullable|string|max:255',
            'resolution_notes'   => 'nullable|string|max:2000',
            'evidence'           => 'nullable|array',
            'evidence.*'         => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        try {
            $updated = SecurityIncidentService::resolve($incident, $request->only([
                'resolution_reason', 'resolution_action', 'resolution_notes', 'evidence',
            ]), $guard);

            return response()->json([
                'success' => true,
                'message' => "Incident #{$incident->id} resolved.",
                'incident' => SecurityIncidentService::serializeIncident($updated),
            ]);
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 403);
        }
    }

    /**
     * POST /security/incidents/{id}/dismiss
     * Dismiss an incident (false-positive or not actionable).
     */
    public function dismissIncident(Request $request, $id)
    {
        $incident = Incident::findOrFail($id);
        $guard = Auth::guard('web')->user();

        $validator = Validator::make($request->all(), [
            'notes' => 'nullable|string|max:2000',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        try {
            $updated = SecurityIncidentService::dismiss($incident, $request->input('notes'), $guard);
            return response()->json([
                'success' => true,
                'message' => "Incident #{$incident->id} dismissed.",
                'incident' => SecurityIncidentService::serializeIncident($updated),
            ]);
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 403);
        }
    }

    /**
     * Generate a security report for the chosen period:
     * daily, weekly, monthly, yearly or a custom date range.
     */
    public function dailyReport(Request $request)
    {
        $guard = Auth::guard('web')->user();
        $guardBranchId = $guard->branch_id;
        $today = today();

        $period = $request->input('period', 'daily');
        $fromInput = $request->input('from');
        $toInput = $request->input('to');

        $validator = Validator::make($request->all(), [
            'period' => 'nullable|in:daily,weekly,monthly,yearly,range',
            'from'   => 'nullable|date',
            'to'     => 'nullable|date',
        ]);
        if ($validator->fails()) {
            return back()->withErrors($validator);
        }

        $start = $today->copy()->startOfDay();
        $end = $today->copy()->endOfDay();

        switch ($period) {
            case 'weekly':
                $start = $today->copy()->startOfWeek();
                $end = $today->copy()->endOfWeek();
                break;
            case 'monthly':
                $start = $today->copy()->startOfMonth();
                $end = $today->copy()->endOfMonth();
                break;
            case 'yearly':
                $start = $today->copy()->startOfYear();
                $end = $today->copy()->endOfYear();
                break;
            case 'range':
                $start = $fromInput ? \Carbon\Carbon::parse($fromInput)->startOfDay() : $today->copy()->startOfWeek();
                $end = $toInput ? \Carbon\Carbon::parse($toInput)->endOfDay() : $today->copy()->endOfDay();
                break;
        }

        if ($start->gt($end)) {
            $tmp = $start;
            $start = $end;
            $end = $tmp;
        }

        $guardAuditScope = function ($query) use ($guard) {
            return $query->where(function ($q) use ($guard) {
                if ($guard->location_id) $q->where('location_id', $guard->location_id);
                if ($guard->branch_id) $q->where('branch_id', $guard->branch_id);
                else $q->whereNull('branch_id');
            });
        };

        $baseQuery = $this->guardVisitQuery();

        $stats = [
            'date'              => $start->format('Y-m-d') . ' / ' . $end->format('Y-m-d'),
            'total_visitors'    => (clone $baseQuery)->whereBetween('created_at', [$start, $end])->count(),
            'checked_in'        => (clone $baseQuery)->where('status', 'checked_in')->whereBetween('updated_at', [$start, $end])->count(),
            'checked_out'       => (clone $baseQuery)->where('status', 'checked_out')->whereBetween('updated_at', [$start, $end])->count(),
            'denied_entries'    => $guardAuditScope(AuditLog::where('action', 'visit_request_failed_validation'))->whereBetween('created_at', [$start, $end])->count(),
            'incidents'         => $this->guardIncidentQuery()->whereBetween('created_at', [$start, $end])->count(),
            'critical_incidents'=> $this->guardIncidentQuery()->where('severity', 'critical')->whereBetween('created_at', [$start, $end])->count(),
            'blacklist_attempts'=> $guardAuditScope(AuditLog::where('action', 'like', '%blacklist%'))->whereBetween('created_at', [$start, $end])->count(),
            'property_items'    => VisitorProperty::whereBetween('checked_in_at', [$start, $end])->count(),
            'missing_property'  => VisitorProperty::where('status', 'missing')->whereBetween('created_at', [$start, $end])->count(),
        ];

        $timeFormat = $period === 'daily' ? 'H:i' : 'M d, Y H:i';

        $reportVisitors = (clone $baseQuery)
            ->whereBetween('requested_time', [$start, $end])
            ->with('visitor', 'host', 'department')
            ->get()
            ->map(fn($v) => [
                'id'             => $v->id,
                'visitor'        => $v->visitor?->name ?? $v->company_name ?? '',
                'id_document_no' => $v->visitor?->id_document_no ?? '',
                'host'           => $v->host->name ?? '',
                'purpose'        => $v->purpose,
                'time'           => $v->requested_time->format($timeFormat),
                'status'         => $v->status,
            ]);

        $incidents = $this->guardIncidentQuery()
            ->whereBetween('created_at', [$start, $end])
            ->with('reportedBy')
            ->get()
            ->map(fn($i) => [
                'id'          => $i->id,
                'type'        => $i->type,
                'title'       => $i->title,
                'severity'    => $i->severity,
                'status'      => $i->status,
                'reported_by' => $i->reportedBy->name ?? '',
                'time'        => $i->created_at->format($timeFormat),
            ]);

        $recentAlerts = AuditLog::whereBetween('created_at', [$start, $end])
            ->whereIn('action', [
                'security_checkin', 'security_checkout',
                'identity_verified', 'identity_mismatch',
                'property_registered', 'property_verified_return',
                'incident_created', 'blacklist_match',
                'visit_request_failed_validation',
            ])
            ->where(function ($q) use ($guard) {
                if ($guard->location_id) $q->where('location_id', $guard->location_id);
                if ($guard->branch_id) $q->where('branch_id', $guard->branch_id);
                else $q->whereNull('branch_id');
            })
            ->latest()->take(50)->get();

        return Inertia::render('Dashboard/Security', [
            'page'            => 'daily_report',
            'report_period'   => $period,
            'report_range'    => [
                'from'  => $start->format('Y-m-d'),
                'to'    => $end->format('Y-m-d'),
                'label' => $start->format('M d, Y') . ' — ' . $end->format('M d, Y'),
            ],
            'report_stats'    => $stats,
            'report_visitors' => $reportVisitors,
            'report_incidents'=> $incidents,
            'report_alerts'   => $recentAlerts,
            'escalations'     => [],
            'watchlist'       => Watchlist::select('name', 'id_document_no', 'reason', 'level')->get(),
            'risk_summary'    => [
                'escalated_count'  => 0,
                'watchlist_count'  => Watchlist::count(),
                'blacklist_count'  => Blacklist::count(),
                'active_incidents' => $this->guardIncidentQuery()->whereIn('status', ['open', 'investigating'])->count(),
                'denied_today'     => $guardAuditScope(AuditLog::where('action', 'visit_request_failed_validation'))->whereBetween('created_at', [$start, $end])->count(),
            ],
        ]);
    }

    /**
     * Get live visitor list (for real-time polling).
     */
    public function liveVisitors()
    {
        $guard = Auth::guard('web')->user();
        $current = $this->guardVisitQuery()
            ->where('status', 'checked_in')
            ->with([
                'visitor',
                'host.department',
                'properties',
                'checkInOutLogs' => fn ($q) => $q->whereNull('checkout_time')->latest(),
            ])
            ->get()
            ->map(function ($v) {
                $checkinLog = $v->checkInOutLogs->first();
                $checkinTime = $checkinLog ? $checkinLog->checkin_time : $v->updated_at;
                $durationMinutes = $checkinTime->diffInMinutes(now());
                $overstay = $durationMinutes > $v->duration;

                return [
                    'id'              => $v->id,
                    'visitor_name'    => $v->visitor?->name ?? $v->company_name ?? '',
                    'id_document_no'  => $v->visitor?->id_document_no ?? '',
                    'host_name'       => $v->host->name ?? '',
                    'department'      => $v->host->department->name ?? '',
                    'building'        => $v->building ?? '',
                    'floor'           => $v->floor ?? '',
                    'meeting_room_id' => $v->meeting_room_id ?? '',
                    'checkin_time'    => $checkinTime->format('H:i'),
                    'duration'        => $v->duration,
                    'elapsed'         => $durationMinutes,
                    'overstay'        => $overstay,
                    'is_vip'          => $v->is_vip,
                    'properties_count'=> $v->properties->count(),
                ];
            });

        return response()->json([
            'count'    => $current->count(),
            'visitors' => $current,
        ]);
    }

    /**
     * Get visitor's entry property list for comparison at exit.
     */
    public function getVisitProperties(Request $request, $visitId)
    {
        $visit = VisitRequest::with('visitor')->findOrFail($visitId);

        $authResult = $this->authorizeGuardForVisit($visit);
        if ($authResult) {
            $msg = $authResult === 'branch_mismatch'
                ? 'Access denied. This visitor is assigned to another branch. Please scan the visitor at the security gate assigned to this branch.'
                : 'Access denied. This visitor is assigned to another location.';
            return response()->json(['success' => false, 'message' => $msg], 403);
        }

        $groupMemberId = $request->input('group_member_id');

        // Strict individual ownership: the property list for an exit may only be
        // scoped to the representative/individual visitor (visitor_id) or a group
        // member that belongs to THIS visit. Never return a different owner's
        // property by tampering with group_member_id.
        if ($groupMemberId) {
            $memberBelongsToVisit = \App\Models\VisitGroupMember::where('id', $groupMemberId)
                ->where('visit_request_id', $visit->id)
                ->exists();
            if (!$memberBelongsToVisit) {
                return response()->json([
                    'success' => false,
                    'message' => 'Property owner does not belong to this visit.',
                ], 403);
            }
        }

        $properties = $visit->properties()
            ->forExit($groupMemberId ? null : $visit->resolveVisitor()?->id, $groupMemberId)
            ->get();

        return response()->json([
            'visitor_name' => $visit->visitor?->name ?? $visit->company_name ?? '',
            'properties'   => $properties->map(fn($p) => [
                'id'             => $p->id,
                'item_name'      => $p->item_name,
                'quantity'       => $p->quantity,
                'serial_number'  => $p->serial_number,
                'property_tag'   => $p->property_tag,
                'status'         => $p->status,
                'checked_in_at'  => $p->checked_in_at ? $p->checked_in_at->format('Y-m-d H:i') : null,
            ]),
        ]);
    }

    /**
     * Exit Gate: Scan exit QR code for checkout. Looks up visit by exit_qr_token on visit_requests or group members.
     */
    public function exitScan(Request $request)
    {
        $request->validate(['token' => 'required|string']);

        $tokenValue = strtoupper(trim($request->input('token')));

        // First check if this is a group member exit QR
        if (str_starts_with($tokenValue, 'VMS-GRPEXIT-')) {
            $validation = QRTokenService::validateGroupMemberExitToken($tokenValue);
            if (!$validation['valid']) {
                return response()->json(['success' => false, 'message' => $validation['error']]);
            }

            $visit = $validation['request'];
            $member = $validation['member'];
            $visit->load('visitor', 'host.department');

            $authResult = $this->authorizeGuardForVisit($visit);
            if ($authResult) {
                $msg = $authResult === 'branch_mismatch'
                    ? 'Access denied. This visitor is assigned to another branch. Please scan the visitor at the security gate assigned to this branch.'
                    : 'Access denied. This visitor is assigned to another location.';
                return response()->json(['success' => false, 'message' => $msg], 403);
            }

            $memberProperties = $visit->properties()->forExit(null, $member->id)->get();

            AuditLogger::log('exit_scan_group_member', 'visit_requests', $visit->id, null, [
                'member_id' => $member->id, 'member_name' => $member->name,
            ]);

            return response()->json([
                'success'         => true,
                'is_exit_scan'    => true,
                'is_group_member' => true,
                'visit_id'        => $visit->id,
                'group_member_id' => $member->id,
                'visitor_name'    => $member->name,
                'id_document_no'  => $member->id_document_no ?? '',
                'host_name'       => $visit->host->name ?? '',
                'department'      => $visit->host->department->name ?? '',
                'properties'      => $memberProperties->map(fn($p) => [
                    'id' => $p->id, 'item_name' => $p->item_name, 'quantity' => $p->quantity,
                    'serial_number' => $p->serial_number, 'property_tag' => $p->property_tag, 'status' => $p->status,
                ]),
                'owner_verified'  => true,
            ]);
        }

        // First try exit QR token stored on the visit
        $visit = VisitRequest::with('properties', 'visitor', 'host.department', 'department')
            ->where('exit_qr_token', $tokenValue)
            ->first();

        if (!$visit) {
            $qrToken = \App\Models\QRToken::where('token_value', $tokenValue)->first();
            if ($qrToken) {
                $visit = VisitRequest::with('properties', 'visitor', 'host.department', 'department')
                    ->where('id', $qrToken->visit_request_id)
                    ->first();
            }
        }

        if (!$visit) {
            return response()->json(['success' => false, 'message' => 'QR code not recognized. Please ask the visitor for a valid exit QR code.']);
        }

        $visitor = $visit->resolveVisitor();

        $authResult = $this->authorizeGuardForVisit($visit);
        if ($authResult) {
            $msg = $authResult === 'branch_mismatch'
                ? 'Access denied. This visitor is assigned to another branch. Please scan the visitor at the security gate assigned to this branch.'
                : 'Access denied. This visitor is assigned to another location.';
            return response()->json(['success' => false, 'message' => $msg], 403);
        }

        if ($visit->exit_qr_expires_at && now()->gt($visit->exit_qr_expires_at)) {
            return response()->json(['success' => false, 'message' => 'Exit QR code has expired. Please ask the host to generate a new one.']);
        }

        if (!in_array($visit->status, ['checked_in', 'checked_out'], true)) {
            return response()->json(['success' => false, 'message' => "Visit is currently '{$visit->status}'. Only active visitors can be checked out."]);
        }

        // Individual/representative visitor exit scan — verify only this person's
        // own registered property, never group members' items.
        $entryProperties = $visit->properties()->forExit($visitor?->id)->get();

        $ownerVerified = true;
        $ownerMismatch = [];

        foreach ($entryProperties as $prop) {
            if ($prop->visit_request_id !== $visit->id) {
                $ownerVerified = false;
                $ownerMismatch[] = "{$prop->item_name} (registered under visit #{$prop->visit_request_id})";
            }
        }

        if (!$ownerVerified) {
            $visitLabel = $visitor?->name ?? $visit->company_name ?? 'Visitor';
            $visitDoc = $visitor?->id_document_no ?? 'N/A';
            $this->createIncident(
                Incident::TYPE_PROPERTY_MISMATCH,
                'critical',
                'Property Owner Mismatch Detected',
                "Visitor '{$visitLabel}' (ID: {$visitDoc}) attempted exit with properties registered under different visit(s): " . implode(', ', $ownerMismatch) . ".",
                $visit->id, $visit->visitor_id, Auth::guard('web')->user()->id
            );
            return response()->json(['success' => false, 'message' => 'SECURITY ALERT: Property ownership mismatch detected. Properties do not belong to this visitor.']);
        }

        AuditLogger::log('exit_scan_property_owner_verified', 'visitor_properties', null, null, [
            'visit_id' => $visit->id, 'visitor_name' => $visitor?->name ?? $visit->company_name ?? '',
            'id_document_no' => $visitor?->id_document_no ?? '', 'properties_count' => $entryProperties->count(),
        ]);

        return response()->json([
            'success'      => true,
            'is_exit_scan' => true,
            'visit_id'     => $visit->id,
            'visitor_name' => $visitor?->name ?? $visit->company_name ?? '',
            'id_document_no' => $visitor?->id_document_no ?? '',
            'visitor_phone' => $visitor?->phone ?? '',
            'host_name'    => $visit->host->name ?? '',
            'department'   => $visit->host->department->name ?? '',
            'purpose'      => $visit->purpose ?? '',
            'scheduled'    => $visit->requested_time ? $visit->requested_time->format('Y-m-d H:i') : '',
            'owner_verified' => true,
            'properties'   => $entryProperties->map(fn($p) => [
                'id' => $p->id, 'item_name' => $p->item_name, 'quantity' => $p->quantity,
                'serial_number' => $p->serial_number, 'property_tag' => $p->property_tag, 'status' => $p->status,
            ]),
        ]);
    }

    /**
     * Get group members for a group/company visit.
     */
    public function getGroupMembers($visitId)
    {
        $visit = VisitRequest::with(['groupMembers' => fn($q) => $q->withCount('properties'), 'visitor'])->findOrFail($visitId);

        $authResult = $this->authorizeGuardForVisit($visit);
        if ($authResult) {
            $msg = $authResult === 'branch_mismatch'
                ? 'Access denied. This visitor is assigned to another branch. Please scan the visitor at the security gate assigned to this branch.'
                : 'Access denied. This visitor is assigned to another location.';
            return response()->json(['success' => false, 'message' => $msg], 403);
        }

        if ($visit->visit_type !== 'company') {
            return response()->json(['success' => false, 'message' => 'This is not a group/company visit.'], 422);
        }

        $members = $visit->groupMembers->map(fn($m) => [
            'id'               => $m->id,
            'name'             => $m->name,
            'id_document_no'   => $m->id_document_no,
            'email'            => $m->email,
            'phone'            => $m->phone,
            'gender'           => $m->gender,
            'nationality'      => $m->nationality,
            'position'         => $m->position,
            'qr_token'         => $m->qr_token,
            'exit_qr_token'    => $m->exit_qr_token,
            'checked_in_at'    => $m->checked_in_at ? $m->checked_in_at->format('M d, Y H:i') : null,
            'checked_out_at'   => $m->checked_out_at ? $m->checked_out_at->format('M d, Y H:i') : null,
            'badge_status'     => $m->badge_status,
            'properties_count' => $m->properties_count ?? 0,
        ]);

        return response()->json([
            'success'          => true,
            'representative'   => $visit->visitor?->name ?? $visit->company_name ?? '',
            'company_name'     => $visit->company_name ?? '',
            'total_members'    => $members->count(),
            'checked_in_count' => $members->where('checked_in_at', '!=', null)->where('checked_out_at', null)->count(),
            'checked_out_count'=> $members->where('checked_out_at', '!=', null)->count(),
            'members'          => $members,
        ]);
    }

    /**
     * Download QR code for a single visit (representative).
     */
    public function downloadQr($visitId)
    {
        $visit = VisitRequest::with('qrToken', 'visitor')->findOrFail($visitId);

        if (!$visit->qrToken) {
            return back()->with('error', 'No QR token found for this visit.');
        }

        $qrContent = \App\Services\QrBadgeService::scanPayload($visit->qrToken->token_value);
        $qrImage = (new \chillerlan\QRCode\QRCode())->render($qrContent);

        return response($qrImage)
            ->header('Content-Type', 'image/png')
            ->header('Content-Disposition', 'attachment; filename="qr-visit-' . $visitId . '.png"');
    }

    /**
     * Download QR codes for representative + all group members as individual PNGs in a ZIP.
     */
    public function downloadAllQr($visitId)
    {
        $visit = VisitRequest::with('qrToken', 'groupMembers', 'visitor')->findOrFail($visitId);
        $qr = new \chillerlan\QRCode\QRCode();

        $tempFile = tempnam(sys_get_temp_dir(), 'qr_');
        $zip = new \ZipArchive();
        $zip->open($tempFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        // Representative QR
        if ($visit->qrToken) {
            $qrImage = $qr->render(\App\Services\QrBadgeService::scanPayload($visit->qrToken->token_value));
            $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $visit->visitor?->name ?? $visit->company_name ?? 'representative');
            $zip->addFromString("representative-{$safeName}.png", $qrImage);
        }

        // Group member QRs
        if ($visit->visit_type === 'company') {
            foreach ($visit->groupMembers as $member) {
                if ($member->qr_token) {
                    $qrImage = $qr->render(\App\Services\QrBadgeService::scanPayload($member->qr_token));
                    $zip->addFromString("member-{$member->name}.png", $qrImage);
                }
            }
        }

        $zip->close();

        $filename = 'qr-codes-visit-' . $visitId . '.zip';
        return response()->download($tempFile, $filename)->deleteFileAfterSend(true);
    }

    /**
     * Download group QR package — all QR codes for group members as individual PNGs.
     */
    public function downloadGroupQrPackage($visitId)
    {
        $visit = VisitRequest::with('groupMembers')->findOrFail($visitId);

        if ($visit->visit_type !== 'company' || $visit->groupMembers->isEmpty()) {
            return back()->with('error', 'No group members found for this visit.');
        }

        $qr = new \chillerlan\QRCode\QRCode();
        $tempFile = tempnam(sys_get_temp_dir(), 'qr_');
        $zip = new \ZipArchive();
        $zip->open($tempFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        foreach ($visit->groupMembers as $member) {
            if ($member->qr_token) {
                $qrImage = $qr->render(\App\Services\QrBadgeService::scanPayload($member->qr_token));
                $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $member->name);
                $zip->addFromString("{$safeName}-{$member->id}.png", $qrImage);
            }
        }

        $zip->close();

        $filename = 'group-qr-package-visit-' . $visitId . '.zip';
        return response()->download($tempFile, $filename)->deleteFileAfterSend(true);
    }

    /**
     * Helper: create or de-duplicate an incident record via SecurityIncidentService.
     *
     * @param string   $type         Incident type (use Incident::TYPE_* constants)
     * @param string   $severity     low|medium|high|critical
     * @param string   $title        Human-readable summary
     * @param string   $description  Detailed narrative
     * @param int|null $visitId      Related visit request
     * @param int|null $visitorId    Related visitor
     * @param int|null $reportedBy   Reporting user (defaults to current guard)
     * @param int|null $branchId     Override branch (defaults to guard's)
     * @param string|null $scanToken Raw QR token that triggered the incident (for dedup)
     * @param int|null $groupMemberId Related group member
     * @param array|null $evidence   Optional evidence payloads
     */
    private function createIncident(
        string $type,
        string $severity,
        string $title,
        string $description,
        ?int $visitId = null,
        ?int $visitorId = null,
        ?int $reportedBy = null,
        ?int $branchId = null,
        ?string $scanToken = null,
        ?int $groupMemberId = null,
        ?array $evidence = null
    ): Incident {
        $guard = Auth::guard('web')->user();

        $payload = [
            'type'                   => $type,
            'severity'               => $severity,
            'title'                  => $title,
            'description'            => $description,
            'scan_token'             => $scanToken,
            'related_visit_id'       => $visitId,
            'related_visitor_id'     => $visitorId,
            'related_group_member_id'=> $groupMemberId,
            'reported_by'            => $reportedBy ?? $guard?->id,
            'evidence'               => $evidence,
        ];

        if ($branchId !== null) {
            $payload['branch_id'] = $branchId;
        }

        $visit = $visitId ? VisitRequest::withoutGlobalScopes()->find($visitId) : null;

        $payload['company_id']  = $visit?->company_id  ?? $guard?->company_id;
        $payload['location_id'] = $visit?->location_id ?? $guard?->location_id;
        $payload['checkpoint_id'] = $guard?->location_id;

        return SecurityIncidentService::recordIncident($payload, $guard ?? User::findOrFail($reportedBy));
    }
}
