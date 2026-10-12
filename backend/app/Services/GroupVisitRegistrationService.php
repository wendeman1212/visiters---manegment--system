<?php

namespace App\Services;

use App\Models\VisitRequest;
use App\Models\User;
use App\Models\RepresentativeLink;

class GroupVisitRegistrationService
{
    /**
     * Attempt to link a newly registered user to an approved Company/Group visit request.
     *
     * @param User $user
     * @return VisitRequest|null
     */
    public function linkRepresentative(User $user): ?VisitRequest
    {
        $request = VisitRequest::where('visit_type', 'company')
            ->where('status', 'approved')
            ->whereHas('visitor', function ($query) use ($user) {
                $query->where('email', $user->email)
                      ->orWhere('phone', $user->phone);
            })
            ->first();

        if ($request && !RepresentativeLink::where('visit_request_id', $request->id)->exists()) {
            RepresentativeLink::create([
                'user_id' => $user->id,
                'visit_request_id' => $request->id,
            ]);
            return $request;
        }

        return null;
    }
}
