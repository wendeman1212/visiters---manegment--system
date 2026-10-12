<?php

namespace App\Http\Controllers;

use App\Models\RepresentativeLink;
use Inertia\Inertia;

class RepresentativeDashboardController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        $link = RepresentativeLink::where('user_id', $user->id)->first();

        if (!$link) {
            abort(403, 'You are not linked to any group visit.');
        }

        $visitRequest = $link->visitRequest;
        $members = $visitRequest->groupMembers()->with('badge')->get();

        return Inertia::render('Representative/Dashboard', [
            'company' => $visitRequest->company_name,
            'representative' => $visitRequest->representative_name,
            'appointment' => $visitRequest->requested_time,
            'status' => $visitRequest->status,
            'members' => $members->map(function ($member) {
                return [
                    'name' => $member->name,
                    'email' => $member->email,
                    'phone' => $member->phone,
                    'qr_code' => $member->qr_token ? route('representative.qr.download', $member->qr_token) : null,
                    'pass_url' => $member->badge ? asset($member->badge->file_path) : null,
                ];
            }),
        ]);
    }

    // Additional download methods (individual QR, all QR, all passes) can be added here
}
