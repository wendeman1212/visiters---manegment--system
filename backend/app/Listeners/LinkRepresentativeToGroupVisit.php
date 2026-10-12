<?php

namespace App\Listeners;

use Illuminate\Auth\Events\Registered;
use App\Services\GroupVisitRegistrationService;
use App\Services\GroupVisitQrService;

class LinkRepresentativeToGroupVisit
{
    protected $registrationService;
    protected $qrService;

    public function __construct(
        GroupVisitRegistrationService $registrationService,
        GroupVisitQrService $qrService
    ) {
        $this->registrationService = $registrationService;
        $this->qrService = $qrService;
    }

    public function handle(Registered $event)
    {
        $user = $event->user;
        $visitRequest = $this->registrationService->linkRepresentative($user);

        if ($visitRequest) {
            // Generate QR codes and passes after successful link
            $this->qrService->generateForRequest($visitRequest);
        }
    }
}
