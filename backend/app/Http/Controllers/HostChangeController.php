<?php

namespace App\Http\Controllers;

use App\Models\HostChangeRequest;
use App\Models\VisitRequest;
use App\Models\User;
use App\Models\Department;
use App\Services\HostChangeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class HostChangeController extends Controller
{
    /**
     * Create a new host change request.
     */
    public function store(Request $request)
    {
        $actor = Auth::guard('web')->user();

        $validator = Validator::make($request->all(), [
            'visit_request_id'  => 'required|exists:visit_requests,id',
            'new_host_id'       => 'required|exists:users,id',
            'new_department_id' => 'nullable|exists:departments,id',
            'reason'            => 'required|string|max:500',
            'additional_notes'  => 'nullable|string|max:1000',
            'meeting_room'      => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        $visit = VisitRequest::findOrFail($request->visit_request_id);

        if (!HostChangeService::canRequestChange($actor, $visit)) {
            return back()->with('error', 'You are not authorized to request a host change for this visit.');
        }

        if (!HostChangeService::isTransferable($visit)) {
            return back()->with('error', 'This visit cannot be transferred (status: ' . $visit->status . ').');
        }

        $existingPending = HostChangeRequest::where('visit_request_id', $visit->id)
            ->where('status', 'pending')
            ->exists();
        if ($existingPending) {
            return back()->with('error', 'There is already a pending host change request for this visit.');
        }

        $hostValidation = HostChangeService::validateNewHost($visit, $request->new_host_id, $request->new_department_id);
        if (!$hostValidation['valid']) {
            return back()->with('error', implode(' ', $hostValidation['errors']))->withInput();
        }

        $availabilityValidation = HostChangeService::validateAvailability($visit, $request->new_host_id);
        if (!$availabilityValidation['valid']) {
            return back()->with('error', implode(' ', $availabilityValidation['errors']))->withInput();
        }

        $newHost = User::find($request->new_host_id);
        $previousLocation = trim(implode(', ', array_filter([
            $visit->building,
            $visit->floor ? "Floor {$visit->floor}" : null,
            $visit->meeting_room_id,
        ])));

        $changeRequest = HostChangeService::createRequest([
            'visit_request_id'  => $visit->id,
            'new_host_id'       => $request->new_host_id,
            'new_department_id' => $newHost->department_id,
            'requested_by'      => $actor->id,
            'requested_by_role' => $actor->role,
            'reason'            => $request->reason,
            'additional_notes'  => $request->additional_notes,
            'meeting_room'      => $request->meeting_room,
            'previous_location' => $previousLocation,
            'new_location'      => $request->meeting_room ?: null,
        ]);

        HostChangeService::sendRequestNotifications($changeRequest);

        return back()->with('success', 'Host change request submitted. The new host will be notified to accept or reject.');
    }

    /**
     * Accept a host change request.
     */
    public function accept(Request $request, $id)
    {
        $actor = Auth::guard('web')->user();
        $changeRequest = HostChangeRequest::findOrFail($id);

        if ($changeRequest->status !== 'pending') {
            return back()->with('error', 'This host change request has already been processed.');
        }

        if (!HostChangeService::canReviewChange($actor, $changeRequest)) {
            return back()->with('error', 'You are not authorized to review this host change request.');
        }

        $changeRequest = HostChangeService::acceptRequest($changeRequest, $actor->id, $request->input('review_notes', ''));

        HostChangeService::sendAcceptanceNotifications($changeRequest);

        $phase = HostChangeService::isPendingVisit($changeRequest->visitRequest) ? 'Pending Visit' : 'Active Visit';
        return back()->with('success', "Host change accepted ({$phase}). Visitor {$changeRequest->visitRequest->visitor->name} is now assigned to the new host.");
    }

    /**
     * Reject a host change request.
     */
    public function reject(Request $request, $id)
    {
        $actor = Auth::guard('web')->user();
        $changeRequest = HostChangeRequest::findOrFail($id);

        if ($changeRequest->status !== 'pending') {
            return back()->with('error', 'This host change request has already been processed.');
        }

        if (!HostChangeService::canReviewChange($actor, $changeRequest)) {
            return back()->with('error', 'You are not authorized to review this host change request.');
        }

        $changeRequest = HostChangeService::rejectRequest($changeRequest, $actor->id, $request->input('review_notes', ''));

        HostChangeService::sendRejectionNotifications($changeRequest);

        return back()->with('success', 'Host change request rejected. The previous host remains assigned.');
    }

    /**
     * Cancel a host change request (by the requester).
     */
    public function cancel($id)
    {
        $actor = Auth::guard('web')->user();
        $changeRequest = HostChangeRequest::findOrFail($id);

        if ($changeRequest->status !== 'pending') {
            return back()->with('error', 'This host change request has already been processed.');
        }

        if ($changeRequest->requested_by !== $actor->id && $actor->role !== 'admin') {
            return back()->with('error', 'You are not authorized to cancel this request.');
        }

        $changeRequest = HostChangeService::cancelRequest($changeRequest, $actor->id);

        return back()->with('success', 'Host change request cancelled.');
    }

    /**
     * Get pending host change requests for the current user (as new host).
     */
    public function myPendingRequests()
    {
        $user = Auth::guard('web')->user();

        $requests = HostChangeRequest::where('new_host_id', $user->id)
            ->where('status', 'pending')
            ->with('visitRequest.visitor', 'previousHost', 'newDepartment', 'requestedByUser')
            ->latest()
            ->get();

        return response()->json(['requests' => $requests]);
    }

    /**
     * Get all host change requests with filters (for admin/reporting).
     */
    public function index(Request $request)
    {
        $query = HostChangeRequest::with('visitRequest.visitor', 'previousHost', 'newHost', 'newDepartment', 'requestedByUser', 'reviewedByUser');

        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->has('visit_id')) {
            $query->where('visit_request_id', $request->visit_id);
        }

        $requests = $query->latest()->paginate(50);

        return response()->json($requests);
    }

    /**
     * Get host change statistics for reporting.
     */
    public function stats()
    {
        $totalChanges = HostChangeRequest::count();
        $accepted = HostChangeRequest::where('status', 'accepted')->count();
        $rejected = HostChangeRequest::where('status', 'rejected')->count();
        $pending = HostChangeRequest::where('status', 'pending')->count();
        $cancelled = HostChangeRequest::where('status', 'cancelled')->count();

        $topReasons = HostChangeRequest::select('reason', \Illuminate\Support\Facades\DB::raw('count(*) as cnt'))
            ->groupBy('reason')
            ->orderByDesc('cnt')
            ->take(10)
            ->get();

        $transfersByDepartment = Department::withCount(['users' => function ($q) {
            $q->where('role', 'host');
        }])->get()->map(function ($dept) use ($totalChanges) {
            $count = HostChangeRequest::whereHas('newHost', fn($q) => $q->where('department_id', $dept->id))->count();
            return [
                'department' => $dept->name,
                'transfers'  => $count,
            ];
        })->sortByDesc('transfers')->values();

        $recentChanges = HostChangeRequest::with('visitRequest.visitor', 'previousHost', 'newHost')
            ->where('status', 'accepted')
            ->latest()
            ->take(20)
            ->get();

        $avgProcessingTime = HostChangeRequest::where('status', '!=', 'pending')
            ->whereNotNull('reviewed_at')
            ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, created_at, reviewed_at)) as avg_minutes')
            ->value('avg_minutes');

        return response()->json([
            'total_changes'         => $totalChanges,
            'accepted'              => $accepted,
            'rejected'              => $rejected,
            'pending'               => $pending,
            'cancelled'             => $cancelled,
            'top_reasons'           => $topReasons,
            'transfers_by_dept'     => $transfersByDepartment,
            'recent_changes'        => $recentChanges,
            'avg_processing_minutes'=> $avgProcessingTime ? round($avgProcessingTime, 1) : 0,
        ]);
    }
}
