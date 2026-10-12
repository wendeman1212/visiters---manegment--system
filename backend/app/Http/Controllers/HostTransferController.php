<?php

namespace App\Http\Controllers;

use App\Models\VisitRequest;
use App\Models\User;
use App\Services\HostTransferService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class HostTransferController extends Controller
{
    protected $transferService;

    public function __construct(HostTransferService $transferService)
    {
        $this->transferService = $transferService;
    }

    public function store(Request $request)
    {
        $request->validate([
            'visit_request_id' => 'required|exists:visit_requests,id',
            'new_host_id' => 'required|exists:users,id',
            'reason' => 'nullable|string|max:1000',
        ]);

        $visitRequest = VisitRequest::findOrFail($request->visit_request_id);
        $newHost = User::findOrFail($request->new_host_id);

        // Only active Host, Admin or CEO users can receive a transferred visitor.
        if (!in_array($newHost->role, ['host', 'admin', 'ceo'], true) || $newHost->status !== 'active') {
            return back()->with('error', 'The selected user is not eligible to receive a visitor transfer.');
        }

        // Authorization: only admin or current host can transfer
        $user = Auth::user();
        if ($user->role !== 'admin' && $visitRequest->host_id != $user->id) {
            abort(403, 'You are not authorized to transfer this visit.');
        }

        try {
            $this->transferService->transfer(
                $visitRequest,
                $newHost,
                $user,
                $request->reason,
                // Relocate the visit to the new host's company/branch/department
                // so the recipient's scoped dashboard actually returns it. This
                // matches the Admin → Host and escalation → Host routing rules.
                ['moveToHostCompany' => true]
            );

            return back()->with('success', "Visit transferred to {$newHost->name}.");
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
