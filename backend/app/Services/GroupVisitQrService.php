<?php

namespace App\Services;

use App\Models\VisitRequest;
use App\Models\VisitGroupMember;
use App\Models\VisitorBadge;
use App\Services\QRTokenService;
use App\Services\QrBadgeService;
use App\Services\NotificationService;

class GroupVisitQrService
{
    protected $qrTokenService;
    protected $qrBadgeService;
    protected $notificationService;

    public function __construct(
        QRTokenService $qrTokenService,
        QrBadgeService $qrBadgeService,
        NotificationService $notificationService
    ) {
        $this->qrTokenService = $qrTokenService;
        $this->qrBadgeService = $qrBadgeService;
        $this->notificationService = $notificationService;
    }

    /**
     * Generate QR codes and visitor passes for all group members of a visit request.
     *
     * @param VisitRequest $visitRequest
     * @return void
     */
    public function generateForRequest(VisitRequest $visitRequest): void
    {
        foreach ($visitRequest->groupMembers as $member) {
            // Generate QR token if not already present
            if (empty($member->qr_token)) {
                $token = $this->qrTokenService->generateForGroupMember($member);
                $member->qr_token = $token;
                $member->save();
            }

            // Generate visitor pass (badge) if not exists
            if (!$member->badge) {
                $badgePath = $this->qrBadgeService->generateBadgeForGroupMember($member);

                VisitorBadge::create([
                    'visit_request_id'    => $visitRequest->id,
                    'visit_group_member_id' => $member->id,
                    'file_path'           => $badgePath,
                    'visitor_name'        => $member->name ?? '',
                    'visitor_id_document' => $member->id_document_no ?? '',
                    'host_name'           => $visitRequest->host->name ?? '',
                    'department'          => $visitRequest->department->name ?? ($visitRequest->host->department->name ?? ''),
                    'purpose'             => $visitRequest->purpose ?? '',
                    'scheduled_time'      => $visitRequest->requested_time ? $visitRequest->requested_time->format('M d, Y H:i') : '',
                    'duration'            => ($visitRequest->duration ?? 60) . ' min',
                    'priority'            => $visitRequest->priority ?? 'normal',
                    'is_vip'              => $visitRequest->is_vip ?? false,
                    'qr_token'            => $member->qr_token ?? '',
                    'issued_at'           => now(),
                ]);
            }
        }

        // Send notifications to all members and representative
        $this->notificationService->sendGroupMemberPasses($visitRequest);
    }
}
