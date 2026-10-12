<?php

namespace App\Http\Controllers;

use App\Models\VisitRequest;
use App\Models\Visitor;
use App\Models\QRToken;
use App\Models\CheckInOutLog;
use App\Models\Feedback;
use App\Models\Lockdown;
use App\Models\User;
use App\Models\Department;
use App\Models\Escalation;
use App\Services\ValidationEngine;
use App\Services\ApprovalService;
use App\Services\QRTokenService;
use App\Services\NotificationService;
use App\Services\AuditLogger;
use App\Services\SmsSyncService;
use App\Services\HostTransferService;
use App\Services\RescheduleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Inertia\Inertia;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use chillerlan\QRCode\Data\QRMatrix;

class VisitRequestController extends Controller
{
    /**
     * Submit a new visit request — visitor must be logged in.
     */
    public function store(Request $request)
    {
        $visitorUser = Auth::guard('visitor')->user();

        $visitType = $request->input('visit_type', 'individual');

        $rules = [
            'visitor_name'   => 'required|string|max:255',
            'id_document_no' => 'required|digits:6|numeric',
            'visitor_email'  => 'required|email',
            'visitor_phone'  => 'required|string',
            'visitor_category_id' => 'nullable|exists:visitor_categories,id',
            'company_id'     => 'nullable|exists:companies,company_id',
            'location_id'    => 'nullable|exists:locations,id',
            'branch_id'      => 'nullable|exists:branches,id',
            'department_id'  => 'nullable|exists:departments,id',
            'host_id'        => 'nullable|exists:users,id',
            'purpose'        => 'required|string|max:255',
            'requested_time' => 'required|date_format:Y-m-d\TH:i',
            'duration'       => 'required|integer|min:15|max:480',
            'building'       => 'nullable|string|max:255',
            'floor'          => 'nullable|string|max:50',
            'meeting_room_id'=> 'nullable|string',
        ];

        if ($visitType === 'company') {
            $rules = array_merge($rules, [
                'company_name'    => 'required|string|max:255',
                'company_email'   => 'required|email',
                'company_phone'   => 'required|string',
                'company_address' => 'required|string',
                'company_website' => 'nullable|string|max:255',
                'representative_gender' => 'nullable|string',
                'representative_nationality' => 'nullable|string',
                'representative_position'   => 'nullable|string',
                'visitors'        => 'required|array|min:1',
                'visitors.*.name' => 'required|string|max:255',
                'visitors.*.id_document_no' => 'required|digits:6|numeric',
                'visitors.*.email' => 'required|email',
                'visitors.*.phone' => 'required|string',
                'visitors.*.gender' => 'nullable|string',
                'visitors.*.nationality' => 'nullable|string',
                'visitors.*.position' => 'nullable|string',
            ]);
        }

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        $input = $request->all();
        $input['duration'] = (int) ($input['duration'] ?? 60);

        if ($violation = self::workingHoursViolation($request->input('requested_time'), $input['duration'])) {
            return back()->withInput()->withErrors(['requested_time' => $violation])->with('error', $violation);
        }

        // ── Assign Company ──
        // Non-admin employees are locked to their own company; system admins may specify one.
        $companyId = $input['company_id'] ?? null;
        if (Auth::guard('web')->check()) {
            $webUser = Auth::guard('web')->user();
            $companyId = $webUser->hasRole('system_admin') ? $companyId : $webUser->company_id;
        }
        $input['company_id'] = $companyId;

        // ── Assign or Auto-assign Department + Host ──
        $resolvedHost = null;
        if (!empty($input['host_id'])) {
            $resolvedHost = User::find($input['host_id']);
            if (!$resolvedHost) {
                return back()->withInput()->with('error', 'Selected host not found.');
            }
            if ($companyId && (int) $resolvedHost->company_id !== (int) $companyId) {
                return back()->withInput()->with('error', 'Selected host does not belong to the selected company.');
            }
            if (!empty($input['branch_id']) && $resolvedHost->branch_id && (int) $resolvedHost->branch_id !== (int) $input['branch_id']) {
                return back()->withInput()->with('error', 'Selected host does not belong to the selected branch.');
            }
            $input['department_id'] = $resolvedHost->department_id;
        } elseif (!empty($input['department_id'])) {
            // The visitor explicitly selected a department — honor it, never
            // re-resolve from the purpose text.
            $department = Department::find($input['department_id']);
            if (!$department) {
                return back()->withInput()->with('error', 'Selected department not found.');
            }
            if ($companyId && (int) $department->company_id !== (int) $companyId) {
                return back()->withInput()->with('error', 'Selected department does not belong to the selected company.');
            }
            if (!empty($input['branch_id']) && $department->branch_id && (int) $department->branch_id !== (int) $input['branch_id']) {
                return back()->withInput()->with('error', 'Selected department does not belong to the selected branch.');
            }
            try {
                $resolvedHost = self::resolveHostFromDepartment(
                    $department,
                    $input['building'] ?? null,
                    $input['floor'] ?? null,
                    $input['requested_time'] ?? null,
                    $companyId,
                    $input['branch_id'] ?? null
                );
                $input['host_id'] = $resolvedHost->id;
                $input['department_id'] = $department->id;
                \Log::info("[HostAssignment] Selected department honored: host='{$resolvedHost->name}'(id={$resolvedHost->id}) dept='{$department->name}'(id={$department->id})");
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException | \Illuminate\Http\Exceptions\HttpResponseException $e) {
                $message = self::assignmentErrorMessage($e);
                \Log::warning("[HostAssignment] Assignment failed: " . $message);
                return back()->withInput()->with('error', $message);
            }
        } else {
            try {
                $purpose = strtolower(trim($input['purpose'] ?? ''));
                $branchId = $input['branch_id'] ?? null;
                \Log::info("[HostAssignment] New request: purpose='{$purpose}' branch_id=" . ($branchId ?: 'null'));
                $assignedDepartment = self::resolveDepartmentFromPurpose($purpose, $branchId, $companyId);
                $resolvedHost = self::resolveHostFromDepartment(
                    $assignedDepartment,
                    $input['building'] ?? null,
                    $input['floor'] ?? null,
                    $input['requested_time'] ?? null,
                    $companyId,
                    $input['branch_id'] ?? null
                );
                $input['host_id'] = $resolvedHost->id;
                $input['department_id'] = $assignedDepartment->id;
                \Log::info("[HostAssignment] Final assignment: host='{$resolvedHost->name}'(id={$resolvedHost->id}) dept='{$assignedDepartment->name}'(id={$assignedDepartment->id})");
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException | \Illuminate\Http\Exceptions\HttpResponseException $e) {
                $message = self::assignmentErrorMessage($e);
                \Log::warning("[HostAssignment] Assignment failed: " . $message);
                return back()->withInput()->with('error', $message);
            }
        }

        // ── Keep the request's branch consistent with the assigned host ──
        // When the visitor's selected branch has no host and the host was resolved
        // from another branch (or a company-wide host), re-tag the request to the
        // host's branch so it is always visible on that host's dashboard.
        if ($resolvedHost && $resolvedHost->branch_id) {
            $selectedBranch = $input['branch_id'] ?? null;
            if ($selectedBranch && (string) $resolvedHost->branch_id !== (string) $selectedBranch) {
                \Log::info("[HostAssignment] Re-tagging request branch from {$selectedBranch} to host's branch {$resolvedHost->branch_id} (host #{$resolvedHost->id} {$resolvedHost->name})");
            }
            $input['branch_id'] = (string) $resolvedHost->branch_id;
        }

        // ── Block visit requests for inactive branches ──
        $targetBranchId = $input['branch_id'] ?? $resolvedHost?->branch_id ?? null;
        if ($targetBranchId) {
            $branch = \App\Models\Branch::find($targetBranchId);
            if ($branch && !$branch->is_active) {
                return back()->withInput()->with('error', 'Visit requests cannot be created for this branch because it is currently inactive.');
            }
        }

        // ── Ensure the request is attached to the host's company ──
        // The VisitRequest model is company-scoped, so a request with a missing or
        // mismatched company_id would be invisible on the host's dashboard.
        if ($resolvedHost && $resolvedHost->company_id && empty($input['company_id'])) {
            $input['company_id'] = $resolvedHost->company_id;
        }
        $companyId = $input['company_id'] ?: null;
        $input['company_id'] = $companyId;

        // ── Duplicate email / national-passport ID protection ──
        // A visitor account must not be created with an email or national/passport
        // ID that already belongs to a different visitor.
        $byEmail = Visitor::withoutGlobalScope(\App\Models\Scopes\CompanyScope::class)->where('email', $input['visitor_email'])->first();
        $byId    = Visitor::withoutGlobalScope(\App\Models\Scopes\CompanyScope::class)->where('id_document_no', $input['id_document_no'])->first();

        if ($byEmail && $byId && $byEmail->id !== $byId->id) {
            return back()->withErrors([
                'visitor_email'   => 'This email address is already registered to another visitor.',
                'id_document_no'  => 'This national ID / passport number is already registered to another visitor.',
            ])->withInput();
        }

        if ($byEmail && !$byId) {
            return back()->withErrors([
                'visitor_email' => 'This email address is already registered to another visitor. Please log in to continue.',
            ])->withInput();
        }

        if ($byId && !$byEmail) {
            return back()->withErrors([
                'id_document_no' => 'This national ID / passport number is already registered to another visitor.',
            ])->withInput();
        }

        // Resolve or create visitor account (same person re-using their account)
        $visitor = $byEmail ?: $byId;
        if (!$visitor) {
            $visitorData = [
                'name'           => $input['visitor_name'],
                'email'          => $input['visitor_email'],
                'phone'          => $input['visitor_phone'],
                'id_document_no' => $input['id_document_no'],
                'password'       => bcrypt('password'),
                'company_id'     => $companyId,
            ];
            if ($visitType === 'company' && !empty($input['company_name'])) {
                $visitorData['company_name'] = $input['company_name'];
            }
            $visitor = Visitor::create($visitorData);
        } elseif ($visitType === 'company' && !empty($input['company_name']) && empty($visitor->company_name)) {
            $visitor->update(['company_name' => $input['company_name']]);
        } elseif ($companyId && empty($visitor->company_id)) {
            $visitor->update(['company_id' => $companyId]);
        }

        // Run Validation Gate
        $validationResult = ValidationEngine::validate($input);

        if (!$validationResult['passed']) {
            $requestData = [
                'visitor_id'      => $visitor->id,
                'host_id'         => $input['host_id'],
                'department_id'   => $input['department_id'],
                'branch_id'       => !empty($input['branch_id']) ? $input['branch_id'] : ($resolvedHost?->branch_id ?? null),
                'location_id'     => $input['location_id'] ?? null,
                'company_id'      => $input['company_id'] ?? null,
                'visitor_category_id' => $input['visitor_category_id'] ?? null,
                'purpose'         => $input['purpose'],
                'requested_time'  => $input['requested_time'],
                'duration'        => $input['duration'],
                'building'        => $input['building'] ?? null,
                'floor'           => $input['floor'] ?? null,
                'meeting_room_id' => $input['meeting_room_id'] ?? null,
                'status'          => 'rejected',
                'comments'        => implode(' | ', $validationResult['errors']),
            ];
            if ($visitType === 'company') {
                $requestData['visit_type'] = 'company';
                $requestData['company_name'] = $input['company_name'] ?? null;
                $requestData['company_email'] = $input['company_email'] ?? null;
                $requestData['company_phone'] = $input['company_phone'] ?? null;
                $requestData['company_address'] = $input['company_address'] ?? null;
                $requestData['company_website'] = $input['company_website'] ?? null;
                $requestData['representative_gender'] = $input['representative_gender'] ?? null;
                $requestData['representative_nationality'] = $input['representative_nationality'] ?? null;
                $requestData['representative_position'] = $input['representative_position'] ?? null;
            }
            $requestRecord = VisitRequest::create($requestData);

            AuditLogger::log('visit_request_failed_validation', 'visit_requests', $requestRecord->id, null, $requestRecord->toArray());
            NotificationService::send('Registration Approved / Rejected', $visitor, "Your visit request was automatically rejected: " . implode(' | ', $validationResult['errors']));

            if ($validationResult['blacklist_match']) {
                NotificationService::blacklistAlert($input['visitor_name'], $input['id_document_no'], "Blacklist database match found.", $input['company_id'] ?? null, $input['branch_id'] ?? null);
            }

            return back()->with('error', 'Visit request failed security validation: ' . $validationResult['errors'][0]);
        }

        // Create pending request
        $requestData = [
            'visitor_id'      => $visitor->id,
            'host_id'         => $input['host_id'],
            'department_id'   => $input['department_id'],
            'branch_id'       => !empty($input['branch_id']) ? $input['branch_id'] : ($resolvedHost?->branch_id ?? null),
            'location_id'     => $input['location_id'] ?? null,
            'company_id'      => $input['company_id'] ?? null,
            'visitor_category_id' => $input['visitor_category_id'] ?? null,
            'purpose'         => $input['purpose'],
            'requested_time'  => $input['requested_time'],
            'duration'        => $input['duration'],
            'building'        => $input['building'] ?? null,
            'floor'           => $input['floor'] ?? null,
            'meeting_room_id' => $input['meeting_room_id'] ?? null,
            'status'          => 'pending',
        ];

        if ($visitType === 'company') {
            $requestData['visit_type'] = 'company';
            $requestData['company_name'] = $input['company_name'] ?? null;
            $requestData['company_email'] = $input['company_email'] ?? null;
            $requestData['company_phone'] = $input['company_phone'] ?? null;
            $requestData['company_address'] = $input['company_address'] ?? null;
            $requestData['company_website'] = $input['company_website'] ?? null;
            $requestData['representative_gender'] = $input['representative_gender'] ?? null;
            $requestData['representative_nationality'] = $input['representative_nationality'] ?? null;
            $requestData['representative_position'] = $input['representative_position'] ?? null;
        }

        $requestRecord = VisitRequest::create($requestData);

        // Save group members for company visits
        if ($visitType === 'company' && !empty($input['visitors'])) {
            foreach ($input['visitors'] as $member) {
                $requestRecord->groupMembers()->create([
                    'name'           => $member['name'],
                    'id_document_no' => $member['id_document_no'],
                    'email'          => $member['email'],
                    'phone'          => $member['phone'],
                    'gender'         => $member['gender'] ?? null,
                    'nationality'    => $member['nationality'] ?? null,
                    'position'       => $member['position'] ?? null,
                ]);
            }
        }

        // Save uploaded documents
        $docTypes = ['invitation_letter', 'visitor_list', 'government_letter', 'purchase_order', 'nda', 'other_docs'];
        foreach ($docTypes as $docType) {
            if ($request->hasFile($docType)) {
                $file = $request->file($docType);
                $path = $file->store('visit_documents/' . $requestRecord->id, 'public');
                $requestRecord->documents()->create([
                    'doc_type'      => $docType,
                    'file_path'     => $path,
                    'original_name' => $file->getClientOriginalName(),
                ]);
            }
        }

        AuditLogger::log('visit_request_submitted', 'visit_requests', $requestRecord->id, null, $requestRecord->toArray());

        $host = User::find($input['host_id']);
        $companyLabel = ($visitType === 'company' && !empty($input['company_name'])) ? "\nCompany: {$input['company_name']}" : '';
        $hostNotification = "New Visit Request — Action Required\n";
        $hostNotification .= "─────────────────────────────\n";
        $hostNotification .= "Visitor Name: {$input['visitor_name']}\n";
        $hostNotification .= $companyLabel . "\n";
        $hostNotification .= "Purpose: {$input['purpose']}\n";
        $hostNotification .= "Date & Time: " . $requestRecord->requested_time->format('M d, Y H:i') . " ({$input['duration']} min)\n";
        $hostNotification .= "Contact: {$input['visitor_email']} | {$input['visitor_phone']}\n";
        $hostNotification .= "ID Document: {$input['id_document_no']}\n";
        $hostNotification .= "─────────────────────────────\n";
        $hostNotification .= "Please review and approve or reject this request from your Host Dashboard.";

        NotificationService::sendDeferred('Visit Requested', $host, $hostNotification);
        NotificationService::sendDeferred('Visit Requested', $visitor, "Your visit request has been received and is pending host approval. You will be notified once the host reviews your request.");

        $adminCeoNotification = "New Visit Request — Awaiting Approval\n";
        $adminCeoNotification .= "─────────────────────────────\n";
        $adminCeoNotification .= "Visitor Name: {$input['visitor_name']}\n";
        $adminCeoNotification .= $companyLabel . "\n";
        $adminCeoNotification .= "Purpose: {$input['purpose']}\n";
        $adminCeoNotification .= "Date & Time: " . $requestRecord->requested_time->format('M d, Y H:i') . " ({$input['duration']} min)\n";
        $adminCeoNotification .= "Contact: {$input['visitor_email']} | {$input['visitor_phone']}\n";
        $adminCeoNotification .= "ID Document: {$input['id_document_no']}\n";
        $adminCeoNotification .= "─────────────────────────────\n";
        $adminCeoNotification .= "A visitor has submitted a visit request and is awaiting host approval.";

        NotificationService::sendToCompanyAdminsAndCeoDeferred('Visit Requested', $adminCeoNotification, $companyId, $input['branch_id'] ?? null);

        SmsSyncService::syncVisitDeferred($requestRecord, SmsSyncService::EVENT_REQUESTED);

        return back()->with('success', 'Visit request submitted successfully. Awaiting host approval.');
    }

    /**
     * Extract a human-readable message from an assignment failure. abort(422, $msg)
     * throws an HttpResponseException whose getMessage() is empty — the message
     * lives in the wrapped response body.
     */
    private static function assignmentErrorMessage(\Exception $e): string
    {
        if ($e instanceof \Illuminate\Http\Exceptions\HttpResponseException) {
            $content = $e->getResponse() ? $e->getResponse()->getContent() : '';
            if (is_string($content) && trim($content) !== '') {
                return $content;
            }
        }
        return $e->getMessage() ?: 'Host assignment failed. No valid host is available for the selected scope.';
    }

    /**
     * Resolve the appropriate department based on the visit purpose text.
     * Matches purpose keywords against department names.
     */
    private static function resolveDepartmentFromPurpose(string $purpose, $branchId = null, $companyId = null): Department
    {
        // Resolve the responsible department across the WHOLE company. Departments
        // may be company-wide (branch_id = NULL) or branch-specific, while hosts
        // carry their own branch_id. Restricting the department list by branch
        // up-front hides company-wide departments that hold the branch's hosts and
        // caused the false "No active host is available for a department matching
        // this purpose" rejection. The visitor's selected branch is enforced later
        // on the HOST itself (see resolveHostFromDepartment).
        $departments = Department::withoutGlobalScopes()
            ->when($companyId, fn($q) => $q->where('company_id', $companyId))
            ->get();
        if ($departments->isEmpty()) {
            \Log::warning("[HostAssignment] No departments found for company_id=" . ($companyId ?: 'null'));
            if ($companyId) {
                abort(422, 'No departments available for the selected company. Please contact the Administrator.');
            }
            $departments = Department::withoutGlobalScopes()->get();
            if ($departments->isEmpty()) {
                abort(422, 'No departments available. Please contact the Administrator.');
            }
        }

        // Prefer departments that already have an active host in the visitor's
        // selected branch, so purpose matching does not stop on a department whose
        // hosts all belong to a different branch (which would reject the request
        // even though a valid host exists in the selected branch).
        $deptIds = $departments->pluck('id');

        $companyHostCounts = User::withoutGlobalScopes()
            ->where('role', 'host')
            ->where('status', 'active')
            ->whereIn('department_id', $deptIds)
            ->when($companyId, fn($q) => $q->where('company_id', $companyId))
            ->selectRaw('department_id, COUNT(*) as aggregate')
            ->groupBy('department_id')
            ->pluck('aggregate', 'department_id');

        $branchHostCounts = $branchId
            ? User::withoutGlobalScopes()
                ->where('role', 'host')
                ->where('status', 'active')
                ->whereIn('department_id', $deptIds)
                ->when($companyId, fn($q) => $q->where('company_id', $companyId))
                ->where('branch_id', $branchId)
                ->selectRaw('department_id, COUNT(*) as aggregate')
                ->groupBy('department_id')
                ->pluck('aggregate', 'department_id')
            : $companyHostCounts;

        $departments = $departments
            ->sortByDesc(fn($dept) => ($branchHostCounts[$dept->id] ?? 0) > 0 ? 1 : 0)
            ->values();
        $keywordMap = [
            'it'             => ['information technology', 'tech'],
            'technology'     => ['information technology', 'tech'],
            'tech'           => ['information technology', 'tech'],
            'software'       => ['information technology', 'tech'],
            'computer'       => ['information technology', 'tech'],
            'network'        => ['information technology', 'tech'],
            'system'         => ['information technology', 'tech'],
            'digital'        => ['information technology', 'tech'],
            'data'           => ['information technology', 'tech'],
            'server'         => ['information technology', 'tech'],
            'database'       => ['information technology', 'tech'],
            'cyber'          => ['information technology', 'tech'],
            'development'    => ['information technology', 'tech'],
            'programming'    => ['information technology', 'tech'],
            'maintenance'    => ['information technology', 'security'],
            'hr'             => ['human resources', 'human resourse'],
            'human resource' => ['human resources', 'human resourse'],
            'recruit'        => ['human resources', 'human resourse'],
            'hiring'         => ['human resources', 'human resourse'],
            'payroll'        => ['human resources', 'human resourse'],
            'employee'       => ['human resources', 'human resourse'],
            'personnel'      => ['human resources', 'human resourse'],
            'job interview'  => ['human resources', 'human resourse'],
            'interview'      => ['human resources', 'human resourse'],
            'onboarding'     => ['human resources', 'human resourse'],
            'training'       => ['human resources', 'human resourse'],
            'resume'         => ['human resources', 'human resourse'],
            'applicant'      => ['human resources', 'human resourse'],
            'finance'        => ['finance'],
            'accounting'     => ['accounting'],
            'budget'         => ['finance'],
            'payment'        => ['finance'],
            'invoice'        => ['finance', 'accounting'],
            'audit'          => ['finance', 'accounting'],
            'billing'        => ['finance', 'accounting'],
            'expense'        => ['finance'],
            'tax'            => ['finance', 'accounting'],
            'procurement'    => ['finance', 'accounting'],
            'purchase'       => ['finance', 'accounting'],
            'security'       => ['security'],
            'safety'         => ['security'],
            'guard'          => ['security'],
            'surveillance'   => ['security'],
            'access'         => ['security'],
            'delivery'       => ['security', 'operations'],
            'package'        => ['security', 'operations'],
            'courier'        => ['security', 'operations'],
            'shipment'       => ['security', 'operations'],
            'logistics'      => ['security', 'operations'],
            'inspection'     => ['security', 'operations'],
            'visitor'        => ['security', 'operations'],
            'executive'      => ['executive'],
            'ceo'            => ['executive'],
            'director'       => ['executive'],
            'board'          => ['executive'],
            'management'     => ['executive'],
            'government'     => ['executive'],
            'legal'          => ['executive'],
            'compliance'     => ['executive'],
            'regulation'     => ['executive'],
            'affairs'        => ['executive'],
            'business'       => ['executive', 'information technology', 'finance'],
            'client'         => ['executive', 'finance'],
            'meeting'        => ['executive', 'information technology', 'human resources', 'finance'],
            'consultation'   => ['executive', 'finance'],
            'consult'        => ['executive', 'finance'],
            'sales'          => ['executive', 'finance'],
            'marketing'      => ['executive'],
            'proposal'       => ['executive', 'finance'],
            'contract'       => ['executive', 'finance'],
            'partnership'    => ['executive'],
            'vendor'         => ['finance', 'executive'],
            'supplier'       => ['finance'],
            'operations'     => ['operations', 'security'],
            'admin'          => ['executive', 'operations'],
            'general'        => ['operations', 'security'],
            'other'          => ['operations', 'security'],
            'service'        => ['operations', 'security'],
            'support'        => ['operations', 'security'],
            'repair'         => ['operations', 'information technology'],
            'cleaning'       => ['operations', 'security'],
            'facility'       => ['operations', 'security'],
            'facilities'     => ['operations', 'security'],
        ];

        $purposeDirectMap = [
            'business meeting' => ['executive', 'information technology', 'human resources', 'human resourse', 'finance', 'security'],
            'job interview'    => ['human resources', 'human resourse'],
            'delivery'         => ['security', 'operations'],
            'consultation'     => ['executive', 'finance'],
            'client visit'     => ['executive', 'finance'],
            'government affairs' => ['executive'],
        ];

        $purposeLower = strtolower(trim($purpose));

        // A department only counts as "has a host" when an ACTIVE host of that
        // department belongs to the visitor's company (any branch). The visitor's
        // branch is enforced separately inside resolveHostFromDepartment, which then
        // reports the precise "no host for this branch" message.
        $deptsWithHosts = $departments->filter(fn($dept) => ($companyHostCounts[$dept->id] ?? 0) > 0);

        // No department in the selected company has an active host at all — this is
        // the only time the "No active host" error is legitimate. Do NOT silently
        // re-route the request to a host from a different department, that would
        // create an "assigned host does not belong to the resolved department"
        // mismatch.
        if ($deptsWithHosts->isEmpty()) {
            \Log::warning('[HostAssignment] No department with an active host found for purpose: ' . $purpose);
            abort(422, 'No active host is available for a department matching this purpose in the selected location. Please contact the Administrator.');
        }

        $nameMatches = function (Department $dept, array $patterns): bool {
            $name = strtolower($dept->name);
            foreach ($patterns as $pattern) {
                if ($name !== '' && str_contains($name, $pattern)) {
                    return true;
                }
            }
            return false;
        };

        // 1. Exact purpose string match
        if (isset($purposeDirectMap[$purposeLower])) {
            foreach ($departments as $dept) {
                if ($nameMatches($dept, $purposeDirectMap[$purposeLower]) && $deptsWithHosts->contains('id', $dept->id)) {
                    \Log::info("[HostAssignment] Purpose='{$purpose}' => Dept '{$dept->name}' (id={$dept->id}) [Tier 1 exact]");
                    return $dept;
                }
            }
        }

        // 2. Keyword matching
        foreach ($keywordMap as $keyword => $patterns) {
            if (str_contains($purposeLower, $keyword)) {
                foreach ($departments as $dept) {
                    if ($nameMatches($dept, $patterns) && $deptsWithHosts->contains('id', $dept->id)) {
                        \Log::info("[HostAssignment] Purpose='{$purpose}' => Dept '{$dept->name}' (id={$dept->id}) [Tier 2 keyword='{$keyword}']");
                        return $dept;
                    }
                }
            }
        }

        // 3. Fuzzy: check if any department name words appear in purpose
        foreach ($departments as $dept) {
            $words = explode(' ', strtolower($dept->name));
            foreach ($words as $word) {
                if (strlen($word) > 2 && str_contains($purposeLower, $word)) {
                    if ($deptsWithHosts->contains('id', $dept->id)) {
                        \Log::info("[HostAssignment] Purpose='{$purpose}' => Dept '{$dept->name}' (id={$dept->id}) [Tier 3 fuzzy]");
                        return $dept;
                    }
                }
            }
        }

        // 4. Default: pick a department with the most active hosts in the visitor's
        // branch first, so the fallback never lands on a department whose hosts all
        // live in a different branch.
        $bestDept = $deptsWithHosts
            ->sortByDesc(fn($dept) => $branchHostCounts[$dept->id] ?? 0)
            ->values()
            ->first();

        \Log::info("[HostAssignment] Purpose='{$purpose}' => Dept '{$bestDept->name}' (id={$bestDept->id}) [Tier 4 fallback]");
        return $bestDept;
    }

    /**
     * Base query for ACTIVE hosts of a department, scoped to the visitor's company
     * (and optionally the selected branch). Uses withoutGlobalScopes() so the
     * current web user's CompanyScope never hides hosts that are valid for the
     * visitor's selection, and so the company filter is always explicit.
     */
    private static function activeHostQuery(Department $department, $companyId = null, $branchId = null)
    {
        $query = User::withoutGlobalScopes()
            ->where('role', 'host')
            ->where('status', 'active')
            ->where('department_id', $department->id);

        if ($companyId) {
            $query->where('company_id', $companyId);
        }

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        return $query;
    }

    /**
     * Resolve the best available host from a department.
     * Supports configurable load-balancing rules: 'least_busy' (default) or 'round_robin'.
     * For least_busy: picks the active host with the fewest current active visit requests.
     * For round_robin: rotates through hosts sequentially based on last assignment order.
     * Uses building/floor match as a tie-breaker for least_busy when available.
     */
    private static function resolveHostFromDepartment(Department $department, ?string $building = null, ?string $floor = null, ?string $requestedTime = null, $companyId = null, $branchId = null): User
    {
        $query = User::withoutGlobalScopes()
            ->where('role', 'host')
            ->where('status', 'active')
            ->where('department_id', $department->id);

        // The host's OWN branch_id is authoritative: a host serves the branch they
        // are assigned to even when the department row is tagged with a different
        // (or no) branch. Constraining hosts by the department row's branch_id
        // falsely rejected valid hosts whose branch matched the visitor's selection
        // (e.g. an HR host working at branch A while the HR department row was
        // recorded against branch B).
        if ($companyId) {
            $query->where('company_id', $companyId);
        }

        $hosts = $query->orderBy('id')->get();

        // Prefer hosts in the visitor's selected branch. When the selected branch
        // has no host for this department, fall back to the department's company-
        // wide hosts so the request still reaches a host. The request's branch_id
        // is then re-tagged to the assigned host's branch in store(), so the
        // request always stays visible on that host's dashboard.
        if ($branchId) {
            $branchHosts = $hosts->filter(fn($h) => (string) $h->branch_id === (string) $branchId);
            \Log::info("[HostAssignment] Branch filter: " . $hosts->count() . " host(s) -> " . $branchHosts->count() . " in branch_id={$branchId}");
            if ($branchHosts->isEmpty()) {
                $branchName = \App\Models\Branch::find($branchId)?->name ?? $branchId;
                \Log::warning("[HostAssignment] No host in Dept '{$department->name}' (id={$department->id}) for branch '{$branchName}' (id={$branchId}). Falling back to company-wide hosts of this department so the request still reaches a host.");
                if ($hosts->isEmpty()) {
                    abort(422, "No active host is assigned to the '{$department->name}' department. Please contact the Administrator.");
                }
            } else {
                $hosts = $branchHosts;
            }
        }

        if ($hosts->isEmpty()) {
            \Log::warning("[HostAssignment] No active hosts found in Dept '{$department->name}' (id={$department->id}) for the selected scope.");
            abort(422, "No active host is assigned to the '{$department->name}' department. Please contact the Administrator.");
        }

        // ── Consult the working schedule for the requested time ──
        // working_hours rows are keyed by numeric day_of_week (0=Sun..6=Sat) and
        // store open_time/close_time. The schedule is a company-wide rule, so it is
        // used as a time gate: when the requested time is inside the working window
        // every candidate host is available; when it falls outside, no host is
        // scheduled, so all active hosts are kept as a safe fallback (the rows have
        // no per-host linkage that would allow filtering individual hosts).
        if ($requestedTime) {
            try {
                $visitCarbon = \Carbon\Carbon::parse($requestedTime);
                $visitDay = (int) $visitCarbon->dayOfWeek; // 0 = Sunday ... 6 = Saturday
                $visitTime = $visitCarbon->format('H:i:s'); // e.g. '14:30:00'

                $workingHour = \App\Models\WorkingHour::where('day_of_week', $visitDay)->first();

                if ($workingHour && $workingHour->is_working_day && $visitTime >= $workingHour->open_time && $visitTime <= $workingHour->close_time) {
                    \Log::info("[HostAssignment] Requested time {$visitTime} on day {$visitDay} is within the working schedule ({$workingHour->open_time}-{$workingHour->close_time}). " . $hosts->count() . " host(s) available in '{$department->name}'.");
                } else {
                    \Log::warning("[HostAssignment] Requested time {$visitTime} on day {$visitDay} falls outside the configured working schedule — keeping all active hosts as fallback in '{$department->name}'.");
                }
            } catch (\Exception $e) {
                \Log::warning("[HostAssignment] Working schedule check failed: " . $e->getMessage() . " — proceeding without filter.");
            }
        }

        \Log::info("[HostAssignment] Available hosts in '{$department->name}': " . $hosts->map(fn($h) => "{$h->name}(id={$h->id})")->implode(', '));

        // Read configured assignment rule from database/cache (default: least_busy)
        $ruleSetting = \Illuminate\Support\Facades\DB::table('cache')->where('key', 'host_assignment_rule')->first();
        $assignmentRule = ($ruleSetting && in_array($ruleSetting->value, ['least_busy', 'round_robin']))
            ? $ruleSetting->value
            : 'least_busy';

        \Log::info("[HostAssignment] Assignment rule: '{$assignmentRule}' for Dept '{$department->name}'");

        $bestHost = null;

        if ($assignmentRule === 'round_robin') {
            // Round Robin: find the host with the fewest total assigned visits (least recently served)
            // Track via the last visit request ID assigned to each host
            $lastAssignedIds = VisitRequest::whereIn('host_id', $hosts->pluck('id'))
                ->selectRaw('host_id, MAX(id) as last_id')
                ->groupBy('host_id')
                ->pluck('last_id', 'host_id');

            // Sort hosts by last assigned visit ID ascending (least recently served first)
            $sortedHosts = $hosts->sortBy(fn($h) => (int) ($lastAssignedIds[$h->id] ?? 0));

            $bestHost = $sortedHosts->first();
            \Log::info("[HostAssignment] Round Robin selected host: '{$bestHost->name}' (id={$bestHost->id}) — last assigned visit ID: " . ($lastAssignedIds[$bestHost->id] ?? 0) . " in Dept '{$department->name}'");
        } else {
            // Least Busy: pick the host with the fewest active visit requests
            $activeCounts = VisitRequest::whereIn('host_id', $hosts->pluck('id'))
                ->whereIn('status', ['pending', 'approved', 'checked_in'])
                ->selectRaw('host_id, COUNT(*) as aggregate')
                ->groupBy('host_id')
                ->pluck('aggregate', 'host_id');

            $matchCounts = collect();
            if ($building || $floor) {
                $matchCounts = VisitRequest::whereIn('host_id', $hosts->pluck('id'))
                    ->whereIn('status', ['pending', 'approved', 'checked_in'])
                    ->where(function ($q) use ($building, $floor) {
                        if ($building) $q->where('building', $building);
                        if ($floor) $q->where('floor', $floor);
                    })
                    ->selectRaw('host_id, COUNT(*) as aggregate')
                    ->groupBy('host_id')
                    ->pluck('aggregate', 'host_id');
            }

            $minVisits = PHP_INT_MAX;
            foreach ($hosts as $host) {
                $score = (int) ($activeCounts[$host->id] ?? 0) * 100;

                if ($building || $floor) {
                    $score += (int) ($matchCounts[$host->id] ?? 0) * 5;
                }

                if ($score < $minVisits) {
                    $minVisits = $score;
                    $bestHost = $host;
                }
            }
            \Log::info("[HostAssignment] Least Busy selected host: '{$bestHost->name}' (id={$bestHost->id}) with score={$minVisits} in Dept '{$department->name}'");
        }

        \Log::info("[HostAssignment] Final: host='{$bestHost->name}' (id={$bestHost->id}) dept='{$department->name}' (id={$department->id}) rule='{$assignmentRule}'");
        return $bestHost;
    }

    /**
     * Enforce the admin-configured working hours for a requested visit time.
     *
     * Returns an error message string when the requested time (start and, when a
     * duration is supplied, the visit end) falls outside the configured working
     * schedule, or null when the time is allowed / no schedule is configured.
     */
    public static function workingHoursViolation(?string $requestedTime, int $duration = 0): ?string
    {
        if (!$requestedTime) {
            return null;
        }
        try {
            $carbon      = \Carbon\Carbon::parse($requestedTime);
            $dayOfWeek   = (int) $carbon->dayOfWeek; // 0 = Sunday .. 6 = Saturday
            $workingHour = \App\Models\WorkingHour::where('day_of_week', $dayOfWeek)->first();

            if (!$workingHour) {
                return null; // no schedule configured for this day — allow
            }
            if (!$workingHour->is_working_day) {
                return 'Requests can only be scheduled on working days. The selected day is not a working day.';
            }

            $openTime  = substr((string) $workingHour->open_time, 0, 5);
            $closeTime = substr((string) $workingHour->close_time, 0, 5);
            $visitTime = $carbon->format('H:i');

            if ($visitTime < $openTime || $visitTime > $closeTime) {
                return "The selected time is outside the configured working hours ({$openTime}–{$closeTime}).";
            }

            if ($duration > 0) {
                $endTime = $carbon->copy()->addMinutes($duration)->format('H:i');
                if ($endTime > $closeTime) {
                    return "The visit end time ({$endTime}) is after the configured closing time ({$closeTime}).";
                }
            }
        } catch (\Exception $e) {
            return null;
        }
        return null;
    }

    /**
     * Process decision (approve, reject, escalate, reschedule).
     */
    public function makeDecision(Request $request, $id)
    {
        $visitRequest = VisitRequest::findOrFail($id);
        $decision = $request->input('decision'); // approve, reject, escalate, reschedule
        $comments = $request->input('comments') ?? '';
        $actor = Auth::guard('web')->user();

        $validator = Validator::make($request->all(), [
            'decision'     => 'required|in:approve,reject,escalate,reschedule',
            'comments'     => 'nullable|string|max:2000',
            'reason'       => 'nullable|string|max:1000',
            'escalated_to' => 'nullable|integer',
        ]);
        if ($validator->fails()) return back()->withErrors($validator);

        if ($decision === 'reject' && trim($comments) === '') {
            return back()->with('error', 'A rejection reason is required.');
        }

        if ($visitRequest->branch && !$visitRequest->branch->is_active) {
            return back()->with('error', 'This appointment cannot be actioned because the branch is currently inactive.');
        }

        if ($actor->role === 'host' && $visitRequest->host_id !== $actor->id && $visitRequest->alternate_host_id !== $actor->id) {
            abort(403, 'You can only act on your own visit requests.');
        }

        if ($visitRequest->status === 'pending') {
            $scheduledEnd = \Carbon\Carbon::parse($visitRequest->requested_time)->addMinutes($visitRequest->duration);
            if (\Carbon\Carbon::now()->gt($scheduledEnd)) {
                return back()->with('error', 'This appointment has passed. No further actions are allowed.');
            }
        }

        $wasEscalated = $visitRequest->status === 'escalated';

        if ($decision === 'approve') {
            // If an admin (who does NOT own the visit) approves an SLA escalation,
            // send it back to the host for final confirmation. Admins/CEOs who
            // already own an escalated visit (responsibility transferred to them)
            // approve it directly like any host, falling through below.
            if ($actor->role === 'admin' && $wasEscalated && $visitRequest->host_id !== $actor->id) {
                $oldState = $visitRequest->toArray();

                $visitRequest->status = 'pending';
                $visitRequest->escalated_to = null;
                $visitRequest->comments = ($comments ? $comments . ' | ' : '') . 'Admin approved escalation. Returned to host for final confirmation.';
                // Returning the visit to its host starts a fresh host SLA round.
                app(\App\Services\SlaService::class)->restartHostSla($visitRequest);
                $visitRequest->save();
                $visitRequest->escalations()->whereNull('resolved_at')->update(['resolved_at' => now()]);

                AuditLogger::log('manager_approved_escalation', 'visit_requests', $visitRequest->id, $oldState, $visitRequest->toArray());

                // Notify Host to do final approve
                NotificationService::sendDeferred(
                    'Visit Requested',
                    $visitRequest->host,
                    "Manager {$actor->name} has approved the escalated visit from {$visitRequest->visitor->name}. Please do the final approval on your dashboard."
                );

                // Notify Visitor
                NotificationService::sendDeferred(
                    'Visit Requested',
                    $visitRequest->visitor,
                    "Your escalated visit request has been cleared by the manager. Awaiting final host confirmation."
                );

                return back()->with('success', 'Escalation approved. Request returned to host for final confirmation.');
            }

            // Host (or direct) approve → full approval + QR
            // Admins/CEOs can approve only if they own the escalated visit (responsibility transferred)
            if ($wasEscalated && $visitRequest->host_id === $actor->id) {
                // Admin/CEO owning an escalated visit must approve from 'escalated' status
                if ($visitRequest->status !== 'escalated') {
                    return back()->with('error', 'Cannot approve a visit that is not in escalated status.');
                }
            }
            
            // Only allow approval from: pending (host), escalated (admin/CEO owner), or from pending when admin returned it
            if (!in_array($visitRequest->status, ['pending', 'escalated'])) {
                return back()->with('error', 'Visit request cannot be approved in its current status.');
            }

            ApprovalService::approve($visitRequest, $comments);

            // If an admin/CEO who owns an escalated visit approves it, clear the
            // escalation assignment and mark the escalation as resolved.
            if ($wasEscalated && $visitRequest->host_id === $actor->id) {
                $visitRequest->escalated_to = null;
                $visitRequest->save();
                $visitRequest->escalations()->whereNull('resolved_at')->update(['resolved_at' => now()]);
                AuditLogger::log('escalation_resolved', 'visit_requests', $visitRequest->id, null, ['resolved_by' => $actor->id, 'outcome' => 'approved']);
            }

            // Refresh model so qrToken relationship is loaded after insert
            $visitRequest->refresh();

            // Auto-retry QR generation if it failed during approval (up to 3 attempts)
            if (!$visitRequest->qrToken) {
                for ($attempt = 1; $attempt <= 3; $attempt++) {
                    try {
                        \App\Services\QRTokenService::generate($visitRequest);
                        $visitRequest->refresh();
                        if ($visitRequest->qrToken) {
                            \Illuminate\Support\Facades\Log::info("QR token auto-retry succeeded on attempt {$attempt} for visit #{$visitRequest->id}");
                            break;
                        }
                    } catch (\Exception $e) {
                        \Illuminate\Support\Facades\Log::error("QR token auto-retry attempt {$attempt} failed for visit #{$visitRequest->id}: " . $e->getMessage());
                    }
                }
            }

            // Auto-retry group member QR tokens if missing
            if ($visitRequest->visit_type === 'company' && $visitRequest->groupMembers->count() > 0) {
                $hasMissingTokens = $visitRequest->groupMembers->contains('qr_token', null);
                if ($hasMissingTokens) {
                    for ($attempt = 1; $attempt <= 3; $attempt++) {
                        try {
                            \App\Services\QRTokenService::generateGroupMemberTokens($visitRequest);
                            $visitRequest->refresh();
                            if (!$visitRequest->groupMembers->contains('qr_token', null)) {
                                \Illuminate\Support\Facades\Log::info("Group member QR tokens auto-retry succeeded on attempt {$attempt} for visit #{$visitRequest->id}");
                                break;
                            }
                        } catch (\Exception $e) {
                            \Illuminate\Support\Facades\Log::error("Group member QR tokens auto-retry attempt {$attempt} failed for visit #{$visitRequest->id}: " . $e->getMessage());
                        }
                    }
                }
            }

            // ── Create VisitorBadge record at approval for dashboard display ──
            try {
                $badgeVisitor = $visitRequest->resolveVisitor();
                $existingBadge = \App\Models\VisitorBadge::where('visit_request_id', $visitRequest->id)->first();
                if (!$existingBadge) {
                    \App\Models\VisitorBadge::create([
                        'visit_request_id'   => $visitRequest->id,
                        'visitor_name'       => $badgeVisitor?->name ?? $visitRequest->company_name ?? 'Visitor',
                        'visitor_id_document'=> $badgeVisitor?->id_document_no ?? '',
                        'visitor_photo_url'  => $badgeVisitor?->photo_url ?? null,
                        'host_name'          => $visitRequest->host->name ?? '',
                        'department'         => $visitRequest->department->name ?? ($visitRequest->host->department->name ?? ''),
                        'purpose'            => $visitRequest->purpose ?? '',
                        'scheduled_time'     => $visitRequest->requested_time ? $visitRequest->requested_time->format('M d, Y H:i') : '',
                        'duration'           => $visitRequest->duration . ' min',
                        'priority'           => $visitRequest->priority ?? 'normal',
                        'is_vip'             => $visitRequest->is_vip ?? false,
                        'qr_token'           => $visitRequest->qrToken?->token_value ?? '',
                        'issued_at'          => now(),
                    ]);
                    \Illuminate\Support\Facades\Log::info("VisitorBadge created at approval for visit #{$visitRequest->id}");
                }
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error("VisitorBadge creation failed at approval for visit #{$visitRequest->id}: " . $e->getMessage());
            }

            // Notify Visitor (representative) with full approval details
            $hasQr = (bool) $visitRequest->qrToken;
            $token = $visitRequest->qrToken ? $visitRequest->qrToken->token_value : 'N/A';
            $hostName = $visitRequest->host->name ?? 'N/A';
            $departmentName = $visitRequest->department->name ?? ($visitRequest->host->department->name ?? 'N/A');
            $scheduledDate = $visitRequest->requested_time->format('M d, Y H:i');
            $duration = $visitRequest->duration;
            $locationParts = array_filter([
                $visitRequest->building,
                $visitRequest->floor ? "Floor {$visitRequest->floor}" : null,
                $visitRequest->meeting_room_id,
            ]);
            $meetingLocation = !empty($locationParts) ? implode(', ', $locationParts) : 'To be assigned by host';

            $notificationMsg = "Visit Request Approved\n";
            $notificationMsg .= "─────────────────────────────\n";
            $notificationMsg .= "Status: APPROVED\n";
            $notificationMsg .= "Host: {$hostName}\n";
            $notificationMsg .= "Department: {$departmentName}\n";
            $notificationMsg .= "Date & Time: {$scheduledDate} ({$duration} min)\n";
            $notificationMsg .= "Meeting Location: {$meetingLocation}\n";
            $notificationMsg .= "─────────────────────────────\n\n";
            $notificationMsg .= "Your Entry QR Token: {$token}\n\n";
            $notificationMsg .= "Visitor Pass Instructions:\n";
            $notificationMsg .= "- Present this QR code at the security gate on {$scheduledDate}\n";
            $notificationMsg .= "- Bring a valid ID document matching your registration\n";
            $notificationMsg .= "- The QR badge is also attached to this email for your convenience\n";
            if (!$hasQr) {
                $notificationMsg .= "\nNote: QR code generation is pending. Please check your dashboard shortly or contact the host.";
            }

            // For company/group visits, include registration link, group member QR tokens, and badge attachments
            if ($visitRequest->visit_type === 'company' && $visitRequest->groupMembers->count() > 0) {
                // Include registration link in the representative notification
                $registrationUrl = url("/register/representative/{$visitRequest->id}");
                $notificationMsg .= "\n\n── Company/Group Visit Registration ──";
                $notificationMsg .= "\nTo access your group's QR passes and manage your visit, please register your account:";
                $notificationMsg .= "\n{$registrationUrl}";
                $representativeEmail = $visitRequest->visitor?->email ?? $visitRequest->company_email;
                $notificationMsg .= "\nUse your email address ({$representativeEmail}) to register.";

                $memberTokens = [];
                foreach ($visitRequest->groupMembers as $m) {
                    $memberTokens[] = "  - {$m->name}: " . ($m->qr_token ?? 'Pending');
                }
                $notificationMsg .= "\n\nGroup Member QR Tokens:\n" . implode("\n", $memberTokens);

                // Notify each group member individually with QR badge SVG attachment
                foreach ($visitRequest->groupMembers as $member) {
                    if ($member->email) {
                        $memberToken = $member->qr_token ?? 'Pending — please check with the representative.';
                        $memberLocParts = array_filter([
                            $visitRequest->building,
                            $visitRequest->floor ? "Floor {$visitRequest->floor}" : null,
                            $visitRequest->meeting_room_id,
                        ]);
                        $memberMeetingLocation = !empty($memberLocParts) ? implode(', ', $memberLocParts) : 'To be assigned by host';
                        $memberNotification = "Dear {$member->name},\n\n";
                        $memberNotification .= "A visit has been arranged for you by company " . ($visitRequest->company_name ?? 'your organization') . ".\n\n";
                        $memberNotification .= "Visit Details:\n";
                        $memberNotification .= "  Date: " . $visitRequest->requested_time->format('M d, Y H:i') . "\n";
                        $memberNotification .= "  Host: {$visitRequest->host->name}\n";
                        $memberNotification .= "  Department: " . ($visitRequest->department->name ?? 'N/A') . "\n";
                        $memberNotification .= "  Purpose: {$visitRequest->purpose}\n";
                        $memberNotification .= "  Meeting Location: {$memberMeetingLocation}\n\n";
                        $memberNotification .= "Your personal entry QR token is: {$memberToken}\n\n";
                        $memberNotification .= "Please present this QR code at the security gate on the day of your visit. Bring a valid ID document.";

                        // Generate QR badge SVG attachment for this member
                        $memberAttachments = [];
                        if ($member->qr_token) {
                            try {
                                $memberBadgeSvg = \App\Services\QrBadgeService::generateMemberBadge($visitRequest, $member);
                                if ($memberBadgeSvg) {
                                    $safeMemberName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $member->name);
                                    $memberAttachments[] = ['raw' => $memberBadgeSvg, 'name' => "qr-badge-{$safeMemberName}.svg", 'mime' => 'image/svg+xml'];
                                }
                            } catch (\Exception $e) {
                                \Illuminate\Support\Facades\Log::error("QR badge attachment failed for group member {$member->name} in visit #{$visitRequest->id}: " . $e->getMessage());
                            }
                        }

                        $fakeVisitor = new \App\Models\Visitor([
                            'name' => $member->name, 'email' => $member->email, 'phone' => $member->phone,
                        ]);
                        NotificationService::sendDeferred('QR Code Generated', $fakeVisitor, $memberNotification, $memberAttachments, true);

                        // Create VisitorBadge for each group member at approval
                        try {
                            $existingMemberBadge = \App\Models\VisitorBadge::where('visit_request_id', $visitRequest->id)
                                ->where('visitor_name', $member->name)->first();
                            if (!$existingMemberBadge) {
                                \App\Models\VisitorBadge::create([
                                    'visit_request_id'   => $visitRequest->id,
                                    'visitor_name'       => $member->name,
                                    'visitor_id_document'=> $member->id_document_no ?? '',
                                    'visitor_photo_url'  => null,
                                    'host_name'          => $visitRequest->host->name ?? '',
                                    'department'         => $visitRequest->department->name ?? ($visitRequest->host->department->name ?? ''),
                                    'purpose'            => $visitRequest->purpose ?? '',
                                    'scheduled_time'     => $visitRequest->requested_time ? $visitRequest->requested_time->format('M d, Y H:i') : '',
                                    'duration'           => $visitRequest->duration . ' min',
                                    'priority'           => $visitRequest->priority ?? 'normal',
                                    'is_vip'             => $visitRequest->is_vip ?? false,
                                    'qr_token'           => $member->qr_token ?? '',
                                    'issued_at'          => now(),
                                ]);
                            }
                        } catch (\Exception $e) {
                            \Illuminate\Support\Facades\Log::error("Group member VisitorBadge creation failed for visit #{$visitRequest->id}, member {$member->name}: " . $e->getMessage());
                        }
                    }
                }
            }

            // Build QR badge SVG attachment for email
            $emailAttachments = [];
            if ($hasQr) {
                try {
                    $svg = \App\Services\QrBadgeService::generateVisitBadge($visitRequest);
                    if ($svg) {
                        $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $visitRequest->visitor?->name ?? $visitRequest->company_name ?? 'visitor');
                        $emailAttachments[] = ['raw' => $svg, 'name' => "qr-badge-{$safeName}.svg", 'mime' => 'image/svg+xml'];
                    }
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::error("QR badge attachment failed for visit #{$visitRequest->id}: " . $e->getMessage());
                }
            }

            NotificationService::sendDeferred(
                'QR Code Generated',
                $visitRequest->visitor ?? $visitRequest->company_email,
                $notificationMsg,
                $emailAttachments,
                true
            );

            $successMsg = 'Visit request approved. QR token generated and sent to visitor.';
            if (!$hasQr) {
                $successMsg = 'Visit request approved. QR token generation may be delayed — please check the visitor dashboard or retry from the host dashboard.';
            }

            return back()->with('success', $successMsg);

        } elseif ($decision === 'reject') {
            // Only allow rejection from: pending (host) or escalated (admin/CEO owner)
            if (!in_array($visitRequest->status, ['pending', 'escalated'])) {
                return back()->with('error', 'Visit request cannot be rejected in its current status.');
            }

            ApprovalService::reject($visitRequest, $comments);

            // Owning admin/CEO rejecting an escalated visit closes the escalation.
            if ($wasEscalated && $visitRequest->host_id === $actor->id) {
                $visitRequest->escalated_to = null;
                $visitRequest->save();
                $visitRequest->escalations()->whereNull('resolved_at')->update(['resolved_at' => now()]);
                AuditLogger::log('escalation_resolved', 'visit_requests', $visitRequest->id, null, ['resolved_by' => $actor->id, 'outcome' => 'rejected']);
            }
            
            // Notify Visitor
            NotificationService::sendDeferred(
                'Registration Approved / Rejected',
                $visitRequest->visitor ?? $visitRequest->company_email,
                "Your visit request has been declined by the host. Reason: " . ($comments ?: 'No reason specified.'),
                null,
                true
            );

            return back()->with('success', 'Visit request declined.');

        } elseif ($decision === 'escalate') {
            $targetId = (int) $request->input('escalated_to', 0);
            $reason   = $request->input('reason') ?: ($comments ?: 'Manual escalation by host.');

            if (!in_array($visitRequest->status, ['pending', 'approved', 'checked_in'], true)) {
                return back()->with('error', 'Only pending, approved or checked-in visits can be escalated.');
            }

            // The target must be an active Company Admin or CEO of the SAME company,
            // so an escalation never lands in another company's dashboard.
            // A CEO may only escalate to a Company Admin.
            $target = User::withoutGlobalScopes()
                ->whereIn('role', $actor->role === 'ceo' ? ['admin'] : ['admin', 'ceo'])
                ->where('status', 'active')
                ->where('company_id', $visitRequest->company_id)
                ->find($targetId);

            if (!$target) {
                return back()->with('error', 'Please select a valid escalation target from your company.');
            }

            if ($target->id === $visitRequest->host_id) {
                return back()->with('error', 'This visit is already assigned to the selected user.');
            }

            try {
                $oldState = $visitRequest->toArray();

                DB::transaction(function () use ($visitRequest, $target, $actor, $reason, $oldState) {
                    // 1. Move responsibility to the Admin/CEO. This records a
                    //    HostTransfer entry, writes the audit trail, clears the
                    //    previous host's associations and invalidates host caches.
                    app(HostTransferService::class)->transfer($visitRequest, $target, $actor, $reason);

                    // 2. Mark the visit as escalated and owned by the Admin/CEO.
                    $visitRequest->status = 'escalated';
                    $visitRequest->escalated_to = $target->id;
                    $visitRequest->comments = ($visitRequest->comments ? $visitRequest->comments . ' | ' : '') .
                        "Escalated to {$target->name} (" . ucfirst($target->role) . ") by {$actor->name} on " . now()->format('M d, Y H:i');
                    $visitRequest->save();

                    // SLA clock transition: handing the visit to an admin
                    // starts the level-2 (admin) clock; handing it to the CEO
                    // ends the SLA chain (level 3 is final, no deadline).
                    $sla = app(\App\Services\SlaService::class);
                    if ($target->role === 'admin') {
                        $sla->startAdminSla($visitRequest);
                        $deadline    = $sla->deadlineFor($visitRequest, 2);
                        $slaMinutes  = $deadline ? $sla->policy($visitRequest->company_id)->adminSlaMinutes() : null;
                    } else {
                        $sla->stopAdminSla($visitRequest);
                        $deadline   = null;
                        $slaMinutes = null;
                    }

                    // 3. Persist the escalation history record (with its SLA context).
                    Escalation::create([
                        'visit_request_id' => $visitRequest->id,
                        'escalated_by'     => $actor->id,
                        'escalated_to'     => $target->id,
                        'reason'           => $reason,
                        'trigger_type'     => Escalation::TRIGGER_MANUAL,
                        'level'            => $target->role === 'ceo' ? 2 : 1,
                        'sla_minutes'      => $slaMinutes,
                        'deadline_at'      => $deadline,
                    ]);

                    AuditLogger::log('escalated_visit', 'visit_requests', $visitRequest->id, $oldState, $visitRequest->fresh()->toArray());
                });

                $escalatedVisitorName = $visitRequest->visitor?->name ?? $visitRequest->company_name ?? 'Visitor';

                NotificationService::sendDeferred(
                    'Escalated Visit Assigned',
                    $target,
                    "Visit #{$visitRequest->id} ({$escalatedVisitorName}) has been escalated to you by {$actor->name}.\nReason: {$reason}\nYou are now the responsible person for this visit."
                );

                return back()->with('success', "Visit escalated to {$target->name} — they are now responsible for this visit.");
            } catch (\Exception $e) {
                return back()->with('error', $e->getMessage());
            }

        } elseif ($decision === 'reschedule') {
            if (!in_array($visitRequest->status, ['pending', 'approved', 'escalated'], true)) {
                return back()->with('error', 'Only pending, approved or escalated visits can be rescheduled.');
            }

            $validator = Validator::make($request->all(), [
                'rescheduled_time' => 'required|date_format:Y-m-d\TH:i',
                'duration'         => 'nullable|integer|min:15|max:480',
            ]);

            if ($validator->fails()) {
                return back()->withErrors($validator);
            }

            // Duration is optional from the decision dialog — fall back to the
            // visit's current duration so an admin/manager reschedule is never
            // rejected just because the UI did not send a duration.
            $newDuration = (int) ($request->input('duration') ?: $visitRequest->duration ?: 60);
            $newTime     = $request->input('rescheduled_time');
            $actorRole   = ucfirst($actor->role);

            if ($violation = self::workingHoursViolation($newTime, $newDuration)) {
                return back()->withInput()->withErrors(['rescheduled_time' => $violation])->with('error', $violation);
            }

            $reschedule = null;
            DB::transaction(function () use ($visitRequest, $actor, $actorRole, $newTime, $newDuration, $comments, &$reschedule) {
                // Supersede any in-flight request so the visit never carries
                // multiple competing schedules.
                $visitRequest->rescheduleRequests()->where('status', 'pending')->update(['status' => 'cancelled']);

                [$oldTime, $oldDuration] = app(RescheduleService::class)->apply($visitRequest, $newTime, $newDuration);

                $visitRequest->comments = ($visitRequest->comments ? $visitRequest->comments . ' | ' : '') . "Rescheduled by {$actorRole} on " . now()->format('M d, Y H:i');
                $visitRequest->save();

                $reschedule = \App\Models\RescheduleRequest::create([
                    'visit_request_id'   => $visitRequest->id,
                    'requested_by'       => $actor->id,
                    'requested_by_role'  => strtolower($actorRole),
                    'old_requested_time' => $oldTime,
                    'old_duration'       => $oldDuration,
                    'new_requested_time' => $newTime,
                    'new_duration'       => $newDuration,
                    'reason'             => $comments,
                    'status'             => 'approved',
                    'reviewed_by'        => $actor->id,
                    'reviewed_at'        => now(),
                ]);

                AuditLogger::log('reschedule_visit', 'visit_requests', $visitRequest->id, [
                    'old_time' => $oldTime, 'old_duration' => $oldDuration,
                ], [
                    'new_time' => $newTime, 'new_duration' => $newDuration,
                ]);
            });

            $visitRequest->refresh();

            // Notify Visitor
            NotificationService::sendDeferred(
                'Appointment Updated',
                $visitRequest->visitor,
                "Your visit appointment has been rescheduled by {$actorRole} to " .
                \Carbon\Carbon::parse($newTime)->format('M d, Y H:i') .
                " ({$newDuration} min). Please review.",
                null,
                true
            );

            // Notify Host
            NotificationService::sendDeferred(
                'Appointment Updated',
                $visitRequest->host,
                "Visit #{$visitRequest->id} for {$visitRequest->visitor?->name} has been rescheduled to " .
                \Carbon\Carbon::parse($newTime)->format('M d, Y H:i') .
                " ({$newDuration} min)."
            );

            // Notify Admin
            $admins = \App\Models\User::where('role', 'admin')->where('status', 'active')->get();
            foreach ($admins as $admin) {
                NotificationService::sendDeferred(
                    'Appointment Updated',
                    $admin,
                    "Visit #{$visitRequest->id} has been rescheduled by {$actorRole}. New time: " .
                    \Carbon\Carbon::parse($newTime)->format('M d, Y H:i')
                );
            }

            return back()->with('success', 'Visit rescheduled successfully.');
        }

        return back()->with('error', 'Invalid decision action.');
    }

    /**
     * Validate QR code and fetch visit details (Security Guard scanning).
     */
    public function scanQR(Request $request)
    {
        $tokenValue = $request->input('token');
        
        // Validate Token
        $validation = QRTokenService::validate($tokenValue);

        if (!$validation['valid']) {
            return response()->json([
                'success' => false,
                'message' => $validation['error'],
                'visit' => null
            ]);
        }

        $visit = $validation['request'];
        
        // Load relationships
        $visit->load('visitor', 'host');

        // Security Validation Gate - final check on blacklist at point of entry
        $visitorDocNo = $visit->visitor?->id_document_no ?? '';
        if ($visitorDocNo) {
            $blacklisted = \App\Models\Blacklist::where('id_document_no', $visitorDocNo)->first();
            if ($blacklisted) {
                $visitorName = $visit->visitor?->name ?? $visit->company_name ?? 'Visitor';
                NotificationService::blacklistAlert($visitorName, $visitorDocNo, "Entry denied: Scan-time blacklist trigger.", $visit->company_id, $visit->branch_id);
                return response()->json([
                    'success' => false,
                    'message' => 'SECURITY DENIAL: Visitor is blacklisted. Entrance forbidden.',
                    'visit' => null
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'QR Code successfully validated.',
            'visit' => [
                'id' => $visit->id,
                'visitor_name' => $visit->visitor?->name ?? $visit->company_name ?? '',
                'id_document_no' => $visit->visitor?->id_document_no ?? '',
                'visitor_phone' => $visit->visitor?->phone ?? '',
                'host_name' => $visit->host->name,
                'purpose' => $visit->purpose,
                'scheduled' => $visit->requested_time->format('M d, Y H:i'),
                'duration' => $visit->duration,
            ]
        ]);
    }

    /**
     * Check-in visitor (Security Guard action).
     */
    public function checkIn(Request $request, $id)
    {
        $visit = VisitRequest::findOrFail($id);
        $guard = Auth::guard('web')->user();

        if ($visit->branch && !$visit->branch->is_active) {
            return back()->with('error', 'Check-in is currently suspended: this branch has been deactivated.');
        }

        if (Lockdown::isLocked($visit->company_id, $visit->branch_id)) {
            return back()->with('error', 'Check-in is currently blocked: this location is under an active security lockdown.');
        }

        if ($visit->status !== 'approved') {
            return back()->with('error', 'Only approved visit requests can be checked in.');
        }

        $oldState = $visit->toArray();

        // 1. Create Check in Log
        CheckInOutLog::create([
            'visit_request_id' => $visit->id,
            'checkin_time' => now(),
            'guard_id' => $guard->id,
        ]);

        // 2. Update Visit Status
        $visit->status = 'checked_in';
        $visit->save();

        // QR stays valid during the visit so it can be re-verified at the gate.
        // It is consumed once the visitor checks out.

        AuditLogger::log('checkin', 'visit_requests', $visit->id, $oldState, $visit->toArray());

        // 4. Notify Host
        NotificationService::send('Visitor Arrived', $visit->host, "Visitor {$visit->visitor->name} has arrived at the front desk and is heading to your office.");

        return back()->with('success', "Check-in successful! Host has been notified of the arrival.");
    }

    /**
     * Check-out visitor (requires QR scan + property verification).
     */
    public function checkOut(Request $request, $id)
    {
        $visit = VisitRequest::findOrFail($id);
        $guard = Auth::guard('web')->user();

        if ($visit->status !== 'checked_in') {
            return back()->with('error', 'Only checked-in visits can be checked out.');
        }

        if (Lockdown::isLocked($visit->company_id, $visit->branch_id)) {
            return back()->with('error', 'Check-out is currently blocked: this location is under an active security lockdown.');
        }

        // Require QR token for checkout — must scan QR before checkout
        $tokenValue = $request->input('qr_token');
        if ($tokenValue) {
            $qrToken = QRToken::where('token_value', strtoupper(trim($tokenValue)))
                ->where('visit_request_id', $visit->id)
                ->first();

            if (!$qrToken) {
                return response()->json([
                    'success' => false,
                    'message' => 'QR code does not match this visit. Property owner verification failed.',
                ], 403);
            }
        }

        // Property verification — compare entry vs exit. Scope to the
        // representative/individual visitor's OWN property only, so group members'
        // registered items are never flagged as missing when the representative
        // checks out via the front desk.
        $entryProperties = $visit->properties()
            ->forExit($visit->resolveVisitor()?->id)
            ->where('status', 'checked_in')
            ->get();

        if ($entryProperties->count() > 0) {
            $returnedItems = $request->input('returned_items', []);
            $force = $request->boolean('force', false);

            if (!$force) {
                $allReturned = true;
                $missingItems = [];

                foreach ($entryProperties as $prop) {
                    // Property owner detection — verify properties belong to this visit
                    if ($prop->visit_request_id !== $visit->id) {
                        AuditLogger::log('property_owner_mismatch', 'visitor_properties', $prop->id, null, [
                            'visit_id'       => $visit->id,
                            'property_owner' => $prop->visit_request_id,
                            'visitor_name'   => $visit->visitor->name ?? '',
                        ]);
                        return response()->json([
                            'success' => false,
                            'message' => "SECURITY ALERT: Property '{$prop->item_name}' is registered under visit #{$prop->visit_request_id}, not this visit.",
                        ], 403);
                    }

                    $found = false;
                    foreach ($returnedItems as $rItem) {
                        if (($rItem['item_name'] ?? '') === $prop->item_name &&
                            ($rItem['serial_number'] ?? '') === ($prop->serial_number ?? '')) {
                            $found = true;
                            break;
                        }
                    }

                    if ($found) {
                        $prop->update(['status' => 'returned', 'returned_at' => now()]);
                    } else {
                        $allReturned = false;
                        $missingItems[] = $prop->item_name . ($prop->serial_number ? " (SN: {$prop->serial_number})" : '');
                    }
                }

                if (!$allReturned) {
                    return back()->with('error', 'Missing property detected: ' . implode(', ', $missingItems) . '. Please verify property return through the security exit gate.');
                }
            } else {
                foreach ($entryProperties as $prop) {
                    if ($prop->status === 'checked_in') {
                        $prop->update(['status' => 'returned', 'returned_at' => now()]);
                    }
                }
            }
        }

        $oldState = $visit->toArray();

        // Find current open check-in log
        $log = CheckInOutLog::where('visit_request_id', $visit->id)->whereNull('checkout_time')->first();
        if ($log) {
            $log->update([
                'checkout_time' => now(),
            ]);
        }

        // Close Visit
        $visit->status = 'checked_out';
        $visit->save();

        // Consume the entry QR now that the visitor has departed.
        if ($visit->qrToken && !$visit->qrToken->used_at) {
            $visit->qrToken->update(['status' => 'used', 'used_at' => now()]);
        }

        AuditLogger::log('checkout', 'visit_requests', $visit->id, $oldState, $visit->toArray());

        // Notify Host
        NotificationService::send('Visitor Checked Out', $visit->host, "Visitor {$visit->visitor->name} has checked out and departed the premises.");

        return back()->with('success', 'Visitor checked out successfully. Invitation is now archived.');
    }

    /**
     * Submit visitor rating and feedback (post-visit).
     */
    public function submitFeedback(Request $request)
    {
        $visitor = Auth::guard('visitor')->user();

        $validator = Validator::make($request->all(), [
            'visit_request_id' => 'required|exists:visit_requests,id',
            'rating' => 'required|integer|min:1|max:5',
            'comments' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator);
        }

        $visit = VisitRequest::with('host', 'visitor')->findOrFail($request->visit_request_id);

        if ($visit->visitor_id !== $visitor->id) {
            abort(403, 'You can only submit feedback for your own visits.');
        }

        if ($visit->status !== 'checked_out') {
            return back()->with('error', 'Feedback can only be submitted after checkout.');
        }

        $feedback = Feedback::create([
            'visit_request_id' => $visit->id,
            'rating'   => $request->rating,
            'comments' => $request->comments,
        ]);

        // Archive request after feedback received
        $visit->update(['status' => 'archived']);

        AuditLogger::log('feedback_submitted', 'feedback', $feedback->id);

        $stars     = str_repeat('⭐', $request->rating);
        $visitorName = $visit->visitor->name ?? 'Visitor';
        $ratingText  = "{$request->rating}/5 {$stars}";
        $commentText = $request->comments ? " — \"{$request->comments}\"" : '';

        // ── Notify HOST about the feedback ──
        if ($visit->host) {
            NotificationService::send(
                'Visitor Feedback',
                $visit->host,
                "Visitor {$visitorName} has rated their visit with you: {$ratingText}{$commentText} (Visit #{$visit->id})"
            );
        }

        // ── Notify Admin ──
        $admin = User::where('role', 'admin')->where('status', 'active')->first();
        NotificationService::send(
            'Feedback Submitted',
            $admin ?: 'System Admin',
            "New feedback from {$visitorName} for Visit #{$visit->id}. Rating: {$ratingText}"
        );

        // ── Store as a CEO broadcast-readable audit entry for visibility ──
        AuditLogger::log(
            'visitor_feedback_received',
            'feedback',
            $feedback->id,
            null,
            [
                'visitor'  => $visitorName,
                'host'     => $visit->host->name ?? 'Unknown',
                'rating'   => $request->rating,
                'comments' => $request->comments,
                'visit_id' => $visit->id,
            ]
        );

        return back()->with('success', 'Thank you for your feedback! Your host has been notified.');
    }

    /**
     * Update time and duration of a pending visit request (Host or Visitor).
     */
    public function updateTimeAndDuration(Request $request, $id)
    {
        $visitRequest = VisitRequest::findOrFail($id);

        // Only pending requests can be updated
        if ($visitRequest->status !== 'pending') {
            return back()->with('error', 'Only pending requests can have their time and duration updated.');
        }

        $validator = Validator::make($request->all(), [
            'requested_time' => 'required|date_format:Y-m-d\TH:i',
            'duration'        => 'required|integer|min:15|max:480',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator);
        }

        if ($violation = self::workingHoursViolation($request->input('requested_time'), (int) $request->input('duration'))) {
            return back()->withInput()->withErrors(['requested_time' => $violation])->with('error', $violation);
        }

        // Authorization: must be the host of this request OR the visitor
        $webUser     = Auth::guard('web')->user();
        $visitorUser = Auth::guard('visitor')->user();

        $isHost    = $webUser    && $webUser->id    === $visitRequest->host_id;
        $isVisitor = $visitorUser && $visitorUser->id === $visitRequest->visitor_id;

        if (!$isHost && !$isVisitor) {
            abort(403, 'You are not authorized to update this visit request.');
        }

        $oldState = $visitRequest->toArray();

        $visitRequest->requested_time = $request->input('requested_time');
        $visitRequest->duration        = $request->input('duration');
        $visitRequest->save();

        $actor = $isHost ? 'Host' : 'Visitor';
        AuditLogger::log('update_time_duration', 'visit_requests', $visitRequest->id, $oldState, $visitRequest->toArray());

        // Notify the other party
        if ($isHost) {
            NotificationService::send(
                'Visit Requested',
                $visitRequest->visitor,
                "Your visit appointment time/duration has been updated by the Host to " .
                $visitRequest->requested_time->format('M d, Y H:i') .
                " ({$visitRequest->duration} min). Please review."
            );
        } else {
            NotificationService::send(
                'Visit Requested',
                $visitRequest->host,
                "Visitor {$visitRequest->visitor->name} updated their appointment time to " .
                $visitRequest->requested_time->format('M d, Y H:i') .
                " ({$visitRequest->duration} min). Please review."
            );
        }

        return back()->with('success', 'Appointment time and duration updated successfully.');
    }

    /**
     * Visitor or Host: Request a reschedule for an approved visit.
     */
    public function requestReschedule(Request $request, $id)
    {
        $visitRequest = VisitRequest::findOrFail($id);
        
        $webUser = Auth::guard('web')->user();
        $visitorUser = Auth::guard('visitor')->user();

        $isHost = $webUser && ($webUser->id === $visitRequest->host_id || $webUser->id === $visitRequest->alternate_host_id);
        $isVisitor = $visitorUser && $visitorUser->id === $visitRequest->visitor_id;

        if (!$isHost && !$isVisitor) {
            abort(403, 'You are not authorized to reschedule this visit.');
        }

        // Allow reschedule on pending and approved visits
        if (!in_array($visitRequest->status, ['pending', 'approved'])) {
            return back()->with('error', 'Only pending or approved visits can be rescheduled.');
        }

        $validator = Validator::make($request->all(), [
            'new_requested_time' => 'required|date_format:Y-m-d\TH:i',
            'new_duration'       => 'required|integer|min:15|max:480',
            'reason'             => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator);
        }

        if ($violation = self::workingHoursViolation($request->input('new_requested_time'), (int) $request->input('new_duration'))) {
            return back()->withInput()->withErrors(['new_requested_time' => $violation])->with('error', $violation);
        }

        $actorRole = $isHost ? 'host' : 'visitor';

        $reschedule = DB::transaction(function () use ($visitRequest, $request, $isHost, $isVisitor, $webUser, $visitorUser, $actorRole) {
            // Supersede any earlier request that has not been decided yet so a
            // visit never ends up with two competing pending reschedules.
            $visitRequest->rescheduleRequests()->where('status', 'pending')->update(['status' => 'cancelled']);

            $oldTime     = $visitRequest->requested_time;
            $oldDuration = $visitRequest->duration;
            $newTime     = $request->input('new_requested_time');
            $newDuration = (int) $request->input('new_duration');

            $reschedule = \App\Models\RescheduleRequest::create([
                'visit_request_id'     => $visitRequest->id,
                'requested_by'         => $isHost ? ($webUser->id ?? null) : null,
                'requested_by_visitor' => $isVisitor ? $visitorUser->id : null,
                'requested_by_role'    => $actorRole,
                'old_requested_time'   => $oldTime,
                'old_duration'         => $oldDuration,
                'new_requested_time'   => $newTime,
                'new_duration'         => $newDuration,
                'reason'               => $request->input('reason'),
                // A host rescheduling their own visit is authoritative and is
                // applied immediately; a visitor's request waits for the host.
                'status'               => $isHost ? 'approved' : 'pending',
                'reviewed_by'          => $isHost ? ($webUser->id ?? null) : null,
                'reviewed_at'          => $isHost ? now() : null,
            ]);

            if ($isHost) {
                app(RescheduleService::class)->apply($visitRequest, $newTime, $newDuration);
            }

            return $reschedule;
        });

        AuditLogger::log('reschedule_requested', 'reschedule_requests', $reschedule->id, null, $reschedule->toArray());

        // Notify the other party
        if ($isHost) {
            NotificationService::send(
                'Appointment Updated',
                $visitRequest->visitor,
                "Your host has rescheduled your appointment to " .
                \Carbon\Carbon::parse($reschedule->new_requested_time)->format('M d, Y H:i') .
                " ({$reschedule->new_duration} min)."
            );

            // Notify the affected host — the alternate host when the primary host
            // reschedules, or the primary host when the alternate host does.
            $affectedHost = null;
            if ($webUser->id === $visitRequest->host_id && $visitRequest->alternate_host_id) {
                $affectedHost = \App\Models\User::find($visitRequest->alternate_host_id);
            } elseif ($webUser->id === $visitRequest->alternate_host_id && $visitRequest->host_id) {
                $affectedHost = $visitRequest->host;
            }

            if ($affectedHost) {
                NotificationService::send(
                    'Appointment Updated',
                    $affectedHost,
                    "The visitor has been rescheduled to " .
                    \Carbon\Carbon::parse($reschedule->new_requested_time)->format('M d, Y H:i') .
                    " ({$reschedule->new_duration} min)."
                );
            }
        } else {
            // Visitor requests, notify host for approval
            NotificationService::send(
                'Appointment Updated',
                $visitRequest->host,
                "Visitor {$visitRequest->visitor->name} has requested to reschedule visit #{$visitRequest->id} to " .
                \Carbon\Carbon::parse($reschedule->new_requested_time)->format('M d, Y H:i') .
                ". Reason: " . ($request->input('reason') ?: 'Not specified') . ". Please approve or reject."
            );
        }

        // Notify Admin
        $admins = \App\Models\User::where('role', 'admin')->where('status', 'active')->get();
        foreach ($admins as $admin) {
            NotificationService::send(
                'Appointment Updated',
                $admin,
                "Reschedule request for Visit #{$visitRequest->id} by {$actorRole}. New time: " .
                \Carbon\Carbon::parse($reschedule->new_requested_time)->format('M d, Y H:i')
            );
        }

        return back()->with('success', $isHost ? 'Appointment rescheduled.' : 'Reschedule request submitted. Awaiting approval.');
    }

    /**
     * Cancel a pending/approved appointment (by the visitor or the host).
     * Only appointments that have not started yet can be cancelled.
     */
    public function cancel(Request $request, $id)
    {
        $visitRequest = VisitRequest::findOrFail($id);

        $webUser     = Auth::guard('web')->user();
        $visitorUser = Auth::guard('visitor')->user();

        $isHost    = $webUser    && $webUser->id    === $visitRequest->host_id;
        $isVisitor = $visitorUser && $visitorUser->id === $visitRequest->visitor_id;

        if (!$isHost && !$isVisitor) {
            abort(403, 'You are not authorized to cancel this visit.');
        }

        if (!in_array($visitRequest->status, ['pending', 'approved'])) {
            return back()->with('error', 'Only pending or approved visits can be cancelled.');
        }

        if ($visitRequest->requested_time && !$visitRequest->requested_time->isFuture()) {
            return back()->with('error', 'This appointment has already started and can no longer be cancelled.');
        }

        $oldState = $visitRequest->toArray();

        $visitRequest->status = 'cancelled';
        $visitRequest->save();

        // Invalidate any pending reschedule requests for this visit
        $visitRequest->rescheduleRequests()->where('status', 'pending')->update(['status' => 'cancelled']);

        AuditLogger::log('visit_cancelled', 'visit_requests', $visitRequest->id, $oldState, $visitRequest->toArray());

        $visitor = $visitRequest->resolveVisitor();

        // Notify the other party
        $otherParty = $isVisitor ? $visitRequest->host : $visitor;
        if ($otherParty) {
            NotificationService::send(
                'Visit Cancelled',
                $otherParty,
                ($isVisitor ? "Visitor " . ($visitor->name ?? 'Unknown') . " cancelled" : "The host cancelled") .
                " visit #{$visitRequest->id} scheduled for " .
                $visitRequest->requested_time->format('M d, Y H:i') .
                ($request->input('reason') ? ' Reason: ' . $request->input('reason') : '')
            );
        }

        return back()->with('success', 'Visit cancelled successfully.');
    }

    /**
     * Review a reschedule request (approve/reject).
     */
    public function reviewReschedule(Request $request, $rescheduleId)
    {
        $reschedule = \App\Models\RescheduleRequest::findOrFail($rescheduleId);
        $visitRequest = \App\Models\VisitRequest::findOrFail($reschedule->visit_request_id);

        $actor = Auth::guard('web')->user();

        if (!$actor) {
            abort(403, 'You are not authorized to review this reschedule request.');
        }

        // Only the visit's host/alternate host, or an admin/CEO of the same
        // company, may approve or reject a reschedule request.
        $isHost   = in_array($actor->id, array_filter([$visitRequest->host_id, $visitRequest->alternate_host_id]), true);
        $isManager = in_array($actor->role, ['admin', 'ceo'], true)
            && (int) $actor->company_id === (int) $visitRequest->company_id;

        if (!$isHost && !$isManager) {
            abort(403, 'You are not authorized to review this reschedule request.');
        }

        $action = $request->input('action'); // approve or reject
        $comments = $request->input('comments', '');

        if (!in_array($action, ['approve', 'reject'], true)) {
            return back()->with('error', 'Invalid reschedule review action.');
        }

        if ($reschedule->status !== 'pending') {
            return back()->with('error', 'This reschedule request has already been reviewed.');
        }

        DB::transaction(function () use ($reschedule, $visitRequest, $actor, $action, $comments) {
            $reschedule->status           = $action === 'approve' ? 'approved' : 'rejected';
            $reschedule->reviewer_comments = $comments;
            $reschedule->reviewed_by      = $actor->id;
            $reschedule->reviewed_at      = now();
            $reschedule->save();

            if ($action === 'approve') {
                // Any other outstanding request for this visit is now moot.
                $visitRequest->rescheduleRequests()
                    ->where('status', 'pending')
                    ->where('id', '!=', $reschedule->id)
                    ->update(['status' => 'cancelled']);

                app(RescheduleService::class)->apply(
                    $visitRequest,
                    $reschedule->new_requested_time,
                    (int) $reschedule->new_duration
                );
            }
        });

        if ($action === 'approve') {
            AuditLogger::log('reschedule_approved', 'reschedule_requests', $reschedule->id, null, $reschedule->toArray());

            // Notify both parties
            NotificationService::send(
                'Appointment Updated',
                $visitRequest->visitor,
                "Your reschedule request has been approved. New appointment: " .
                \Carbon\Carbon::parse($reschedule->new_requested_time)->format('M d, Y H:i')
            );
            NotificationService::send(
                'Appointment Updated',
                $visitRequest->host,
                "Reschedule request for Visit #{$visitRequest->id} has been approved. New time: " .
                \Carbon\Carbon::parse($reschedule->new_requested_time)->format('M d, Y H:i')
            );
        } else {
            AuditLogger::log('reschedule_rejected', 'reschedule_requests', $reschedule->id, null, $reschedule->toArray());

            // Notify the requester (visitor-initiated -> visitor, host-initiated -> host).
            $notifiable = strtolower((string) $reschedule->requested_by_role) === 'host'
                ? $visitRequest->host
                : $visitRequest->visitor;
            NotificationService::send(
                'Appointment Updated',
                $notifiable,
                "Your reschedule request has been rejected. Reason: " . ($comments ?: 'No reason provided.')
            );
        }

        return back()->with('success', 'Reschedule request ' . ($action === 'approve' ? 'approved.' : 'rejected.'));
    }

    /**
     * Host: Delete all reviewed reschedule requests (reschedule history) for
     * this host's visits. Pending requests are left untouched.
     */
    public function deleteRescheduleHistory(Request $request)
    {
        $host = Auth::guard('web')->user();

        $reschedules = \App\Models\RescheduleRequest::with('visitRequest')
            ->whereHas('visitRequest', fn($q) => $q->where('host_id', $host->id))
            ->where('status', '!=', 'pending')
            ->get();

        if ($reschedules->isEmpty()) {
            return back()->with('error', 'No reschedule history to delete.');
        }

        $count = 0;
        try {
            DB::transaction(function () use ($reschedules, &$count) {
                foreach ($reschedules as $reschedule) {
                    AuditLogger::log('reschedule_deleted', 'reschedule_requests', $reschedule->id, null, [
                        'host_id'  => $host->id,
                        'visit_id' => $reschedule->visit_request_id,
                    ]);
                    $reschedule->delete();
                    $count++;
                }
            });

            Log::info("Host #{$host->id} deleted {$count} reschedule history record(s).");

            return back()->with('success', "Deleted {$count} reschedule history record(s).");
        } catch (\Exception $e) {
            Log::error("Reschedule history deletion failed for host #{$host->id}: " . $e->getMessage(), [
                'host_id' => $host->id,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Failed to delete the reschedule history. Please try again.');
        }
    }

    /**
     * Host: Assign or change meeting room for a visit.
     */
    public function assignRoom(Request $request, $id)
    {
        $visit = VisitRequest::findOrFail($id);
        $host = Auth::guard('web')->user();

        if ($visit->host_id !== $host->id) {
            abort(403, 'You can only assign rooms for your own visits.');
        }

        if (!in_array($visit->status, ['pending', 'approved', 'checked_in'])) {
            return back()->with('error', 'Cannot assign facility for this visit status.');
        }

        $meetingRoom = $request->input('meeting_room_id', '');
        $building = $request->input('building', '');
        $floor = $request->input('floor', '');

        $visit->update([
            'meeting_room_id' => $meetingRoom ?: null,
            'building' => $building ?: null,
            'floor' => $floor ?: null,
        ]);

        AuditLogger::log('facility_assigned', 'visit_requests', $visit->id, null, [
            'building' => $building,
            'floor' => $floor,
            'meeting_room_id' => $meetingRoom,
            'guard' => $host->name,
        ]);

        $parts = array_filter([$building, $floor ? "Floor {$floor}" : null, $meetingRoom]);
        $location = implode(', ', $parts);

        if ($location) {
            NotificationService::send(
                'Appointment Updated',
                $visit->visitor,
                "A meeting facility has been assigned for your visit: {$location}. Please proceed to this location on the day of your visit."
            );
        }

        return back()->with('success', $location ? "Facility assigned: {$location}" : 'Facility cleared.');
    }


    /**
     * Trigger SLA checks (for dashboard triggers).
     */
    public function processSla()
    {
        $escalatedCount = ApprovalService::processAutoEscalations();
        return back()->with('success', "SLA Processing completed. Auto-escalated {$escalatedCount} idle pending requests.");
    }

    /** Host: Start meeting */
    public function startMeeting(Request $request, $id)
    {
        $visit = VisitRequest::findOrFail($id);
        $host  = Auth::guard('web')->user();
        if ($visit->host_id !== $host->id) abort(403);

        if (Lockdown::isLocked($visit->company_id, $visit->branch_id)) {
            return back()->with('error', 'Meeting operations are currently blocked: this location is under an active security lockdown.');
        }

        $visit->update(['meeting_started_at' => now(), 'status' => 'checked_in']);
        AuditLogger::log('meeting_started', 'visit_requests', $visit->id);
        NotificationService::send('Meeting Started', $visit->visitor, "Your meeting with {$host->name} has begun. Welcome!");
        return back()->with('success', 'Meeting started.');
    }

    /** Host: End meeting / complete visit */
    public function endMeeting(Request $request, $id)
    {
        $visit = VisitRequest::with('groupMembers')->findOrFail($id);
        $host  = Auth::guard('web')->user();
        if ($visit->host_id !== $host->id) abort(403);

        if ($visit->status !== 'checked_in') {
            return back()->with('error', 'This visit is not currently checked in.');
        }

        if (Lockdown::isLocked($visit->company_id, $visit->branch_id)) {
            return back()->with('error', 'Meeting operations are currently blocked: this location is under an active security lockdown.');
        }

        $outcome = $request->input('meeting_outcome', '');

        $visitor = $visit->resolveVisitor();
        $visitorName = $visitor?->name ?? $visit->company_name ?? 'the visitor';

        // Generate exit QR token for the representative
        $exitToken = 'VMS-EXIT-' . strtoupper(\Illuminate\Support\Str::random(16));
        $exitExpires = now()->addHours(4);

        $visit->update([
            'meeting_ended_at'    => now(),
            'meeting_outcome'     => $outcome,
            'exit_qr_token'       => $exitToken,
            'exit_qr_expires_at'  => $exitExpires,
        ]);

        AuditLogger::log('meeting_ended', 'visit_requests', $visit->id, $visit->toArray(), $visit->fresh()->toArray());

        // Generate individual exit QR tokens for each checked-in group member
        $groupExitTokens = [];
        if ($visit->visit_type === 'company' && $visit->groupMembers->count() > 0) {
            $checkedInMembers = $visit->groupMembers->whereNotNull('checked_in_at')->whereNull('checked_out_at');
            foreach ($checkedInMembers as $member) {
                $token = \App\Services\QRTokenService::generateGroupMemberExitToken($visit, $member);
                $groupExitTokens[$member->name] = $token;
            }
        }

        // Notify visitor (representative) with exit QR
        NotificationService::send('Meeting Completed — Exit QR', $visit->visitor,
            "Your meeting with {$host->name} has ended. Your exit QR token is: {$exitToken}. Please proceed to the security exit gate and show this QR code to the guard for property verification and checkout.");

        // Notify each group member individually with their exit QR
        foreach ($groupExitTokens as $memberName => $token) {
            $member = $visit->groupMembers->first(fn($m) => $m->name === $memberName);
            if ($member && $member->email) {
                $fakeVisitor = new \App\Models\Visitor(['name' => $member->name, 'email' => $member->email, 'phone' => $member->phone]);
                NotificationService::send('Meeting Completed — Exit QR', $fakeVisitor,
                    "Dear {$memberName}, the meeting hosted by {$host->name} has ended. Your exit QR token is: {$token}. Please proceed to the security exit gate for property verification and checkout.");
            }
        }

        // Notify host
        NotificationService::send('Meeting Ended', $visit->host,
            "Meeting with {$visitorName} has ended. Exit QR {$exitToken} has been sent to the visitor." . ($groupExitTokens ? ' Individual exit QRs sent to ' . count($groupExitTokens) . ' group member(s).' : ''));

        // Notify security guard team
        NotificationService::send('Exit Pending', 'Security',
            "Visitor {$visitorName} (Visit #{$visit->id}) has completed their meeting and will proceed to the exit gate with exit QR: {$exitToken}" . ($groupExitTokens ? ' (' . count($groupExitTokens) . ' group member(s) with individual exit QRs)' : ''),
            null, false, $visit->company_id, $visit->branch_id);

        $msg = 'Meeting ended. Exit QR code ' . $exitToken . ' has been sent to the visitor.';
        if ($groupExitTokens) {
            $msg .= ' Individual exit QR codes sent to ' . count($groupExitTokens) . ' group member(s).';
        }
        return back()->with('success', $msg);
    }

    /** Host: Extend visit duration */
    public function extendVisit(Request $request, $id)
    {
        $visit = VisitRequest::findOrFail($id);
        $host  = Auth::guard('web')->user();
        if ($visit->host_id !== $host->id) abort(403);

        $extra = (int)$request->input('extra_minutes', 30);
        $visit->update(['duration' => $visit->duration + $extra]);
        AuditLogger::log('visit_extended', 'visit_requests', $visit->id, null, ['extra_minutes' => $extra]);
        NotificationService::send('Visit Extended', $visit->visitor, "Your visit has been extended by {$extra} minutes by {$host->name}.");
        return back()->with('success', "Visit extended by {$extra} minutes.");
    }

    /** Host: Mark no-show */
    public function markNoShow(Request $request, $id)
    {
        $visit = VisitRequest::findOrFail($id);
        $host  = Auth::guard('web')->user();
        if ($visit->host_id !== $host->id) abort(403);

        $visit->update([
            'is_no_show' => true,
            'no_show_at' => now(),
            'status'     => 'rejected',
            'comments'   => ($visit->comments ? $visit->comments . ' | ' : '') . 'Visitor did not show up.',
        ]);
        AuditLogger::log('visitor_no_show', 'visit_requests', $visit->id);

        NotificationService::send(
            'Visit Requested',
            $visit->visitor,
            "Your visit request with {$host->name} was marked as no-show. If this is an error, please contact the host."
        );

        NotificationService::send(
            'Visit Requested',
            'admin',
            "Visitor {$visit->visitor->name} (Visit #{$visit->id}) was marked as no-show by host {$host->name}."
        );

        return back()->with('success', 'Visitor marked as no-show.');
    }

    /** Host: Delete an approved, checked-in or completed visit (permanent, with all related data). */
    public function destroy(Request $request, $id)
    {
        $host  = Auth::guard('web')->user();
        $visit = VisitRequest::with('visitor', 'documents', 'badge', 'groupMembers')
            ->where('id', $id)
            ->where(function ($q) use ($host) {
                $q->where('host_id', $host->id)->orWhere('alternate_host_id', $host->id);
            })
            ->firstOrFail();

        if (!in_array($visit->status, ['approved', 'checked_in', 'checked_out', 'archived', 'rejected'])) {
            return back()->with('error', 'Only approved, checked-in, completed, archived or rejected visits can be deleted.');
        }

        $visitorName = $visit->visitor->name ?? 'Visitor';
        $visitorId   = $visit->visitor_id;
        $status      = $visit->status;

        try {
            $this->deleteVisitWithDependencies($visit);

            AuditLogger::log('host_deleted_visit', 'visit_requests', $id, null, [
                'visitor_id' => $visitorId,
                'visitor'    => $visitorName,
                'status'     => $status,
            ]);

            Log::info("Host #{$host->id} deleted visit #{$id} ({$visitorName}) [{$status}]");

            return back()->with('success', "Visit for {$visitorName} deleted successfully.");
        } catch (\Exception $e) {
            Log::error("Host visit deletion failed for visit #{$id}: " . $e->getMessage(), [
                'visit_request_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Failed to delete the visit. Please try again.');
        }
    }

    /**
     * Host: Delete all archived/rejected visits (or all active/checked-in visits
     * when called with ?status=checked_in), with all related data.
     */
    public function bulkDelete(Request $request)
    {
        $host = Auth::guard('web')->user();

        $isActive = $request->query('status') === 'checked_in';
        $statuses = $isActive ? ['checked_in'] : ['archived', 'rejected'];

        $visits = VisitRequest::with('visitor', 'documents', 'badge', 'groupMembers')
            ->where(function ($q) use ($host) {
                $q->where('host_id', $host->id)->orWhere('alternate_host_id', $host->id);
            })
            ->whereIn('status', $statuses)
            ->get();

        if ($visits->isEmpty()) {
            return back()->with('error', $isActive ? 'No active visits to delete.' : 'No archived or rejected visits to delete.');
        }

        $count = 0;
        try {
            DB::transaction(function () use ($visits, &$count) {
                foreach ($visits as $visit) {
                    $this->deleteVisitWithDependencies($visit);
                    $count++;
                }
            });

            AuditLogger::log('host_deleted_visits_bulk', 'visit_requests', null, null, [
                'host_id' => $host->id,
                'count'   => $count,
                'statuses' => $statuses,
            ]);

            Log::info("Host #{$host->id} bulk deleted {$count} " . implode('/', $statuses) . " visits.");

            return back()->with('success', $isActive ? "Deleted {$count} active visit(s)." : "Deleted {$count} archived/rejected visit(s).");
        } catch (\Exception $e) {
            Log::error("Host bulk visit deletion failed for host #{$host->id}: " . $e->getMessage(), [
                'host_id' => $host->id,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Failed to delete the visits. Please try again.');
        }
    }

    /**
     * Visitor: Delete all completed/old visits from their history
     * (checked_out, archived, rejected), with all related data.
     */
    public function visitorDeleteHistory(Request $request)
    {
        $visitor = Auth::guard('visitor')->user();
        if (!$visitor) abort(403);

        $visits = VisitRequest::with('visitor', 'documents', 'badge', 'groupMembers')
            ->where('visitor_id', $visitor->id)
            ->whereIn('status', ['checked_out', 'archived', 'rejected'])
            ->get();

        if ($visits->isEmpty()) {
            return back()->with('error', 'No completed visits to delete.');
        }

        $count = 0;
        try {
            DB::transaction(function () use ($visits, &$count) {
                foreach ($visits as $visit) {
                    $this->deleteVisitWithDependencies($visit);
                    $count++;
                }
            });

            AuditLogger::log('visitor_deleted_history', 'visit_requests', null, null, [
                'visitor_id' => $visitor->id,
                'count'      => $count,
                'statuses'   => ['checked_out', 'archived', 'rejected'],
            ]);

            Log::info("Visitor #{$visitor->id} deleted {$count} completed visit(s) from history.");

            return back()->with('success', "Deleted {$count} completed visit(s) from your history.");
        } catch (\Exception $e) {
            Log::error("Visitor history deletion failed for visitor #{$visitor->id}: " . $e->getMessage(), [
                'visitor_id' => $visitor->id,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Failed to delete the visits. Please try again.');
        }
    }

    /**
     * Admin/CEO: Delete a visit request (with full dependency cleanup).
     * Scoped to the acting user's company access — preserves all existing
     * delete behavior, just authorized for the Admin Appointments screen.
     */
    public function adminDestroyVisit(Request $request, $id)
    {
        $actor = Auth::guard('web')->user();

        if (!in_array($actor->role, ['admin', 'ceo'], true)) {
            abort(403, 'Only Company Admins/CEOs can delete appointments.');
        }

        $visit = VisitRequest::with('visitor', 'documents', 'badge', 'groupMembers')
            ->where('id', $id)
            ->firstOrFail();

        if (!$actor->hasCompanyAccess($visit->company_id)) {
            abort(403, 'You do not have access to this company.');
        }

        $visitorName = $visit->visitor->name ?? $visit->company_name ?? 'Visitor';
        $visitorId   = $visit->visitor_id;
        $status      = $visit->status;

        try {
            $this->deleteVisitWithDependencies($visit);

            AuditLogger::log('admin_deleted_visit', 'visit_requests', $id, null, [
                'visitor_id' => $visitorId,
                'visitor'    => $visitorName,
                'status'     => $status,
            ]);

            Log::info("Admin #{$actor->id} deleted visit #{$id} ({$visitorName}) [{$status}]");

            return back()->with('success', "Appointment for {$visitorName} deleted successfully.");
        } catch (\Exception $e) {
            Log::error("Admin visit deletion failed for visit #{$id}: " . $e->getMessage(), [
                'visit_request_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Failed to delete the appointment. Please try again.');
        }
    }

    /**
     * Admin/CEO: End an active visit.
     *
     * The visitor is checked out, the visit is closed cleanly (the record is
     * preserved for history/reporting), the affected users are notified and the
     * action is written to the audit trail. Replaces the former Delete action
     * on the Admin Appointments and CEO Visit screens for active visits.
     */
    public function endVisit(Request $request, $id)
    {
        $actor = Auth::guard('web')->user();

        if (!in_array($actor->role, ['admin', 'ceo'], true)) {
            abort(403, 'Only Company Admins/CEOs can end visits.');
        }

        $visit = VisitRequest::with('groupMembers')->findOrFail($id);

        if (!$actor->hasCompanyAccess($visit->company_id)) {
            abort(403, 'You do not have access to this company.');
        }

        if (Lockdown::isLocked($visit->company_id, $visit->branch_id)) {
            return back()->with('error', 'Visit operations are currently blocked: this location is under an active security lockdown.');
        }

        if ($visit->status !== 'checked_in') {
            return back()->with('error', 'Only a currently checked-in (active) visit can be ended.');
        }

        $oldState = $visit->toArray();

        // Generate (once) a valid Exit QR token for the representative so they
        // can be checked out at the security exit gate. An existing token is
        // always reused — it is never regenerated for the same visit, so the
        // exit gate keeps recognizing it and no duplicate pass is issued.
        if (empty($visit->exit_qr_token)) {
            $visit->exit_qr_token = 'VMS-EXIT-' . strtoupper(Str::random(16));
            $visit->exit_qr_expires_at = now()->addHours(4);
        }

        // Close the open check-in record for the visit.
        $log = CheckInOutLog::where('visit_request_id', $visit->id)->whereNull('checkout_time')->first();
        if ($log) {
            $log->update(['checkout_time' => now()]);
        }

        // Mark every checked-in group member as departed. Each one first gets a
        // personal (once-only) Exit QR token so they too can check out at the
        // exit gate — existing tokens are kept untouched.
        foreach ($visit->groupMembers->whereNotNull('checked_in_at')->whereNull('checked_out_at') as $member) {
            if (empty($member->exit_qr_token)) {
                QRTokenService::generateGroupMemberExitToken($visit, $member);
            }
            $member->update(['checked_out_at' => now(), 'badge_status' => 'checked_out']);
        }

        // Consume the entry QR token — the visitor has departed.
        if ($visit->qrToken && !$visit->qrToken->used_at) {
            $visit->qrToken->update(['status' => 'used', 'used_at' => now()]);
        }

        $visit->meeting_ended_at = $visit->meeting_ended_at ?: now();
        $visit->status = 'checked_out';
        $visit->save();

        AuditLogger::log('admin_ended_visit', 'visit_requests', $visit->id, $oldState, $visit->fresh()->toArray());

        $visitor = $visit->resolveVisitor();
        $visitorName = $visitor?->name ?? $visit->company_name ?? 'the visitor';
        $hostName = $visit->host?->name ?? 'the host';

        if ($visitor) {
            NotificationService::sendDeferred(
                'Visit Ended — Exit QR',
                $visitor,
                "Your visit with {$hostName} has been ended by {$actor->name}. You have been checked out.\n\nYour Exit QR token is: {$visit->exit_qr_token}\nPlease present this QR code at the security exit gate for property verification and checkout. Thank you for visiting!"
            );
        }

        // Send each checked-in group member their own Exit QR token.
        foreach ($visit->groupMembers->whereNotNull('exit_qr_token') as $member) {
            if ($member->email) {
                $fakeVisitor = new Visitor(['name' => $member->name, 'email' => $member->email, 'phone' => $member->phone]);
                NotificationService::sendDeferred(
                    'Visit Ended — Exit QR',
                    $fakeVisitor,
                    "Dear {$member->name}, the visit hosted by {$hostName} has been ended by {$actor->name}. Your Exit QR token is: {$member->exit_qr_token}. Please proceed to the security exit gate for property verification and checkout."
                );
            }
        }

        NotificationService::sendDeferred(
            'Visit Ended',
            $visit->host,
            "The visit by {$visitorName} (Visit #{$visit->id}) has been ended by {$actor->name}. The visitor has been checked out."
        );

        NotificationService::sendDeferred(
            'Visit Ended',
            'Security',
            "Visitor {$visitorName} (Visit #{$visit->id}) has been checked out by {$actor->name} and has departed the premises.",
            null, false, $visit->company_id, $visit->branch_id
        );

        return back()->with('success', "Visit for {$visitorName} ended successfully. The visitor has been checked out and their Exit QR code sent.");
    }

    /**
     * Delete a visit and all of its related records inside a transaction.
     */
    private function deleteVisitWithDependencies(VisitRequest $visit): void
    {
        DB::transaction(function () use ($visit) {
            foreach ($visit->documents as $doc) {
                if ($doc->file_path && Storage::exists($doc->file_path)) {
                    Storage::delete($doc->file_path);
                }
            }

            if ($visit->badge && $visit->badge->file_path && Storage::exists($visit->badge->file_path)) {
                Storage::delete($visit->badge->file_path);
            }

            $visit->feedback()?->delete();
            $visit->qrToken()?->delete();
            $visit->badge()?->delete();
            $visit->checkInOutLogs()->delete();
            $visit->escalations()->delete();
            $visit->properties()->delete();
            $visit->rescheduleRequests()->delete();

            \App\Models\HostChangeRequest::where('visit_request_id', $visit->id)->delete();
            \App\Models\HostTransfer::where('visit_request_id', $visit->id)->delete();
            \App\Models\RepresentativeLink::where('visit_request_id', $visit->id)->delete();

            foreach ($visit->groupMembers as $member) {
                QRToken::where('visit_group_member_id', $member->id)->delete();
                \App\Models\VisitorBadge::where('visit_group_member_id', $member->id)->delete();
                \App\Models\VisitorProperty::where('visit_group_member_id', $member->id)->delete();
                CheckInOutLog::where('visit_group_member_id', $member->id)->delete();
                $member->delete();
            }

            $visit->documents()->delete();
            $visit->delete();
        });
    }

    /** Host: Save meeting notes / agenda */
    public function saveNotes(Request $request, $id)
    {
        $visit = VisitRequest::findOrFail($id);
        $host  = Auth::guard('web')->user();
        if ($visit->host_id !== $host->id) abort(403);

        $validator = Validator::make($request->all(), [
            'meeting_notes'  => 'nullable|string|max:2000',
            'meeting_agenda' => 'nullable|string|max:2000',
            'visitor_notes'  => 'nullable|string|max:2000',
        ]);
        if ($validator->fails()) return back()->withErrors($validator);

        $visit->update([
            'meeting_notes'  => $request->input('meeting_notes'),
            'meeting_agenda' => $request->input('meeting_agenda'),
            'visitor_notes'  => $request->input('visitor_notes'),
        ]);
        AuditLogger::log('visit_notes_saved', 'visit_requests', $visit->id);
        return back()->with('success', 'Notes saved successfully.');
    }

    /** Host: Set request priority */
    public function setPriority(Request $request, $id)
    {
        $visit = VisitRequest::findOrFail($id);
        $host  = Auth::guard('web')->user();
        if ($visit->host_id !== $host->id) abort(403);

        $priority = $request->input('priority', 'normal');
        $validator = Validator::make($request->all(), [
            'priority' => 'nullable|in:low,normal,high,vip',
        ]);
        if ($validator->fails()) return back()->withErrors($validator);
        if (!in_array($priority, ['low', 'normal', 'high', 'vip'])) $priority = 'normal';
        $visit->update(['priority' => $priority]);
        AuditLogger::log('visit_priority_set', 'visit_requests', $visit->id, null, ['priority' => $priority]);
        return back()->with('success', "Priority set to {$priority}.");
    }

    /** Host: Toggle VIP status */
    public function toggleVip(Request $request, $id)
    {
        $visit = VisitRequest::findOrFail($id);
        $host  = Auth::guard('web')->user();
        if ($visit->host_id !== $host->id) abort(403);

        $visit->update(['is_vip' => !$visit->is_vip, 'priority' => !$visit->is_vip ? 'vip' : 'normal']);
        AuditLogger::log('visit_vip_toggled', 'visit_requests', $visit->id);

        if ($visit->is_vip) {
            NotificationService::send(
                'Visit Updated',
                $visit->visitor,
                "Congratulations! You have been granted VIP status for your visit with {$visit->host->name}. You will receive priority handling at reception."
            );
        } else {
            NotificationService::send(
                'Visit Updated',
                $visit->visitor,
                "Your VIP status for the visit with {$visit->host->name} has been removed."
            );
        }

        return back()->with('success', $visit->is_vip ? 'Visitor marked as VIP. Visitor notified.' : 'VIP status removed. Visitor notified.');
    }

    /** Host: Transfer visitor to another host */
    public function transferVisitor(Request $request, $id)
    {
        $visit = VisitRequest::findOrFail($id);
        $host  = Auth::guard('web')->user();
        if ($visit->host_id !== $host->id) abort(403);

        $validator = Validator::make($request->all(), [
            'new_host_id' => 'required|integer|exists:users,id',
            'reason'      => 'nullable|string|max:1000',
        ]);
        if ($validator->fails()) return back()->withErrors($validator);

        $newHostId = $request->input('new_host_id');
        $newHost   = User::whereIn('role', ['host', 'admin', 'ceo'])->where('status', 'active')->findOrFail($newHostId);

        try {
            $transferService = app(\App\Services\HostTransferService::class);
            $transferService->transfer($visit, $newHost, $host, $request->input('reason'), ['moveToHostCompany' => true]);
            return back()->with('success', "Visit transferred to {$newHost->name}.");
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * JSON cascade for the Admin/CEO escalation transfer modal.
     *
     * Company -> Branch -> Department -> Host, with dynamic loading. The actor
     * picks any company they are authorized for (own company OR companies granted
     * via the company_user pivot); the selected company's active branches, its
     * departments, its eligible hosts and its company-level managers are returned.
     * Everything is validated against the live database relationships and no
     * companies/branches/departments/hosts are hardcoded.
     *
     * Query params: ?company_id=X&branch_id=Y&department_id=Z (all optional).
     */
    public function escalationTransferOptions(Request $request, $id)
    {
        $visit = VisitRequest::findOrFail($id);
        $actor = Auth::guard('web')->user();

        if (!$this->canActOnEscalation($visit, $actor)) {
            abort(403, 'You are not the responsible person for this escalated visit.');
        }
        if (!in_array($visit->status, ['escalated', 'checked_in', 'approved'], true)) {
            abort(403, 'This visit cannot be transferred in its current state.');
        }
        if (!$actor->hasCompanyAccess($visit->company_id)) {
            abort(403, 'You do not have access to this company.');
        }

        $payload = [
            'companies' => $this->authorizedCompaniesFor($actor),
            'visit'     => [
                'id'            => $visit->id,
                'company_id'    => $visit->company_id,
                'branch_id'     => $visit->branch_id,
                'department_id' => $visit->department_id,
                'status'        => $visit->status,
            ],
            'managers'    => [],
            'branches'    => [],
            'departments' => [],
            'hosts'       => [],
        ];

        if (!$request->filled('company_id')) {
            return response()->json($payload);
        }

        $companyId = (int) $request->input('company_id');
        if (!$actor->hasCompanyAccess($companyId)) {
            abort(403, 'You are not authorized for the selected company.');
        }

        $branchId = $request->filled('branch_id') ? (int) $request->input('branch_id') : null;
        if ($branchId !== null && !\App\Models\Branch::withoutGlobalScopes()
                ->where('id', $branchId)
                ->where('company_id', $companyId)
                ->exists()) {
            return response()->json(['message' => 'The selected branch does not belong to this company.'], 422);
        }

        // Company-level Admins/CEOs of the selected company. These are always
        // offered once a company is chosen; they have no branch/department.
        // Once a branch is selected the CEO (company-level, no branch) is
        // excluded from the offer.
        $payload['managers'] = $this->companyManagersFor($companyId, $branchId, $visit, $actor);

        // Active branches must belong to the selected company.
        $payload['branches'] = \App\Models\Branch::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        // Departments must belong to the selected company and branch.
        $payload['departments'] = \App\Models\Department::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->orderBy('name')
            ->get(['id', 'name', 'branch_id']);

        $deptId = $request->filled('department_id') ? (int) $request->input('department_id') : null;
        if ($deptId !== null && !\App\Models\Department::withoutGlobalScopes()
                ->where('id', $deptId)
                ->where('company_id', $companyId)
                ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
                ->exists()) {
            return response()->json(['message' => 'The selected department does not belong to this branch/company.'], 422);
        }

        // Hosts must belong to the selected company + branch + department, be
        // active, and exclude the current responsible person.
        $payload['hosts'] = $this->companyHostsFor($companyId, $branchId, $deptId, $visit, $actor);

        return response()->json($payload);
    }

    /** Companies the actor may transfer INTO (own company + authorized pivots). */
    protected function authorizedCompaniesFor(User $actor): array
    {
        if ($actor->hasRole('system_admin')) {
            $companies = \App\Models\Company::withoutGlobalScopes()->orderBy('company_name')->get(['company_id', 'company_name']);
        } else {
            $ids = array_values(array_filter(array_unique([
                $actor->company_id,
                ...$actor->companies()->withoutGlobalScopes()->pluck('companies.company_id')->all(),
            ])));
            $companies = $ids
                ? \App\Models\Company::withoutGlobalScopes()->whereIn('company_id', $ids)->orderBy('company_name')->get(['company_id', 'company_name'])
                : collect();
        }

        return $companies->map(fn ($c) => [
            'company_id'   => $c->company_id,
            'company_name' => $c->company_name,
        ])->all();
    }

    /** Active Admins/CEOs of a company (the cross-company "managers" group). */
    protected function companyManagersFor(int $companyId, ?int $branchId, VisitRequest $visit, User $actor)
    {
        return User::withoutGlobalScopes()
            ->whereIn('role', ['admin', 'ceo'])
            ->where('status', 'active')
            ->where('company_id', $companyId)
            // Strict branch scoping: once a branch is selected only managers
            // assigned to that branch remain eligible (company-level Admins/CEOs
            // are excluded), matching the branch-scoped recipient list.
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->whereNotIn('id', [$visit->host_id, $actor->id])
            ->orderBy('name')
            ->get(['id', 'name', 'role'])
            ->map(fn ($m) => [
                'id'   => $m->id,
                'name' => $m->name,
                'role' => $m->role,
            ]);
    }

    /** Active hosts of a company, optionally narrowed to a branch and department. */
    protected function companyHostsFor(int $companyId, ?int $branchId, ?int $deptId, VisitRequest $visit, User $actor)
    {
        return User::withoutGlobalScopes()
            ->where('role', 'host')
            ->where('status', 'active')
            ->where('company_id', $companyId)
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->when($deptId !== null, fn ($q) => $q->where('department_id', $deptId))
            ->whereNotIn('id', [$visit->host_id, $actor->id])
            ->with([
                'department' => fn ($q) => $q->select('id', 'name')->withoutGlobalScopes(),
                'branch'     => fn ($q) => $q->select('id', 'name')->withoutGlobalScopes(),
            ])
            ->orderBy('name')
            ->get(['id', 'name', 'branch_id', 'department_id'])
            ->map(fn ($h) => [
                'id'            => $h->id,
                'name'          => $h->name,
                'branch_id'     => $h->branch_id,
                'department_id' => $h->department_id,
                'department'    => $h->department?->name,
                'branch'        => $h->branch?->name,
            ]);
    }

    /**
     * The Admin/CEO is allowed to act when they own the visit (host_id) OR when
     * they are the escalation assignee (escalated_to) — SLA auto-escalations
     * keep host_id on the original host, so the assignee must still be able to
     * receive / complete / transfer the visit.
     */
    protected function canActOnEscalation(VisitRequest $visit, User $actor): bool
    {
        return in_array($actor->role, ['admin', 'ceo'], true)
            && ($visit->host_id === $actor->id || $visit->escalated_to === $actor->id);
    }

    /** Admin/CEO (responsible person): receive / check in an escalated visitor. */
    public function escalationReceive(Request $request, $id)
    {
        $visit = VisitRequest::findOrFail($id);
        $actor = Auth::guard('web')->user();

        if (!$this->canActOnEscalation($visit, $actor)) {
            abort(403, 'You are not the responsible person for this escalated visit.');
        }
        if ($visit->status !== 'escalated') {
            return back()->with('error', 'This visit is no longer escalated.');
        }

        $oldState = $visit->toArray();

        // SLA auto-escalations keep host_id on the original host — taking
        // responsibility here moves it to the receiving Admin/CEO.
        if ($visit->host_id !== $actor->id) {
            app(HostTransferService::class)->transfer($visit, $actor, $actor, 'Escalation received by ' . $actor->name);
        }

        CheckInOutLog::create([
            'visit_request_id' => $visit->id,
            'checkin_time'     => now(),
            'guard_id'         => $actor->id,
        ]);

        $visit->status = 'checked_in';
        $visit->save();

        AuditLogger::log('escalation_received', 'visit_requests', $visit->id, $oldState, $visit->fresh()->toArray());

        NotificationService::sendDeferred('Visitor Arrived', $visit->visitor,
            "Your arrival has been confirmed by {$actor->name}. Please proceed to the reception desk.");

        return back()->with('success', 'Visitor received and checked in.');
    }

    /** Admin/CEO (responsible person): complete an escalated visit and notify Reception/Security for checkout. */
    public function escalationComplete(Request $request, $id)
    {
        $visit = VisitRequest::findOrFail($id);
        $actor = Auth::guard('web')->user();

        if (!$this->canActOnEscalation($visit, $actor)) {
            abort(403, 'You are not the responsible person for this escalated visit.');
        }
        if (!in_array($visit->status, ['escalated', 'checked_in'], true)) {
            return back()->with('error', 'Only escalated or checked-in visits can be completed.');
        }

        $oldState = $visit->toArray();

        $visit->meeting_ended_at = $visit->meeting_ended_at ?: now();
        $visit->status = 'completed';
        $visit->escalated_to = null;
        $visit->save();
        $visit->escalations()->whereNull('resolved_at')->update(['resolved_at' => now()]);

        AuditLogger::log('escalation_completed', 'visit_requests', $visit->id, $oldState, $visit->fresh()->toArray());

        $visitorName = $visit->resolveVisitor()?->name ?? $visit->company_name ?? 'the visitor';

        NotificationService::sendDeferred('Visit Completed', $visit->visitor,
            "Your visit (#{$visit->id}) has been completed by {$actor->name}. Please proceed to the security/reception desk for checkout.");

        NotificationService::sendDeferred('Visit Completed — Checkout Pending', 'Security',
            "Visit #{$visit->id} by {$visitorName} has been completed. The visitor is proceeding to the exit gate for QR scan and property verification checkout.",
            null, false, $visit->company_id, $visit->branch_id);

        return back()->with('success', 'Visit completed. Reception/Security notified for checkout.');
    }

    /** Admin/CEO (responsible person): mark an escalated visit as no-show (status = no_show). */
    public function escalationNoShow(Request $request, $id)
    {
        $visit = VisitRequest::findOrFail($id);
        $actor = Auth::guard('web')->user();

        if (!$this->canActOnEscalation($visit, $actor)) {
            abort(403, 'You are not the responsible person for this escalated visit.');
        }
        if ($visit->status !== 'escalated') {
            return back()->with('error', 'Only escalated visits can be marked as no-show.');
        }

        $oldState = $visit->toArray();

        $visit->is_no_show = true;
        $visit->no_show_at = now();
        $visit->status = 'no_show';
        $visit->escalated_to = null;
        $visit->comments = ($visit->comments ? $visit->comments . ' | ' : '') . 'Visitor did not show up (escalated visit).';
        $visit->save();

        if ($visit->qrToken) {
            $visit->qrToken->update(['status' => 'expired']);
        }

        $visit->escalations()->whereNull('resolved_at')->update(['resolved_at' => now()]);

        AuditLogger::log('escalation_no_show', 'visit_requests', $visit->id, $oldState, $visit->fresh()->toArray());

        NotificationService::sendDeferred('Visit Requested', $visit->visitor,
            "Your visit request with {$actor->name} was marked as no-show. If this is an error, please contact the responsible person.");

        NotificationService::sendDeferred('Visit Requested', 'admin',
            "Visitor {$visit->visitor?->name} (Visit #{$visit->id}) was marked as no-show by {$actor->name}.");

        return back()->with('success', 'Visitor marked as no-show.');
    }

    /** Admin/CEO (responsible person): transfer an escalated visit back to a host (or another person). */
    public function escalationTransfer(Request $request, $id)
    {
        $visit = VisitRequest::findOrFail($id);
        $actor = Auth::guard('web')->user();

        if (!$this->canActOnEscalation($visit, $actor)) {
            abort(403, 'You are not the responsible person for this escalated visit.');
        }
        if (!in_array($visit->status, ['escalated', 'checked_in', 'approved'], true)) {
            return back()->with('error', 'Only escalated, checked-in or approved visits can be transferred.');
        }

        $newHostId = (int) $request->input('new_host_id', 0);
        $reason    = (string) $request->input('reason', '');

        $newHost = User::withoutGlobalScopes()
            ->whereIn('role', ['host', 'admin', 'ceo'])
            ->where('status', 'active')
            ->find($newHostId);

        if (!$newHost) {
            return back()->with('error', 'Please select a valid active host, admin or CEO.');
        }
        if ($newHost->id === $visit->host_id) {
            return back()->with('error', 'The visit is already assigned to the selected user.');
        }
        if ($newHost->id === $actor->id) {
            return back()->with('error', 'The visit is already assigned to you.');
        }
        if (!$actor->hasCompanyAccess($newHost->company_id)) {
            return back()->with('error', 'The selected user belongs to a company you are not authorized for.');
        }

        try {
            $oldState = $visit->toArray();

            DB::transaction(function () use ($visit, $newHost, $actor, $reason, $oldState) {
                // Cascade recipients are pre-validated against the selected
                // company/branch/department, so the same-company scope check is
                // bypassed and the visit is relocated to the recipient's company.
                app(HostTransferService::class)->transfer($visit, $newHost, $actor, $reason, [
                    'validateScope'     => false,
                    'moveToHostCompany' => true,
                ]);

                // The visit keeps its actual status so the receiving Host's
                // sections stay correct: approved visits stay in Appointments,
                // checked-in visits stay during Visit / Active Visits. Only
                // an escalated visit returns to 'pending' so the new Host
                // performs the final approval again.
                if ($visit->status === 'escalated') {
                    $visit->status = 'pending';
                }
                $visit->escalated_to = null;

                // SLA: a visit handed back to a host starts a fresh host
                // round; any other hand-off simply stops the admin clock.
                $sla = app(\App\Services\SlaService::class);
                if ($visit->status === 'pending') {
                    $sla->restartHostSla($visit);
                } else {
                    $sla->stopAdminSla($visit);
                }
                $visit->save();
                $visit->escalations()->whereNull('resolved_at')->update(['resolved_at' => now()]);

                AuditLogger::log('escalation_transferred', 'visit_requests', $visit->id, $oldState, $visit->fresh()->toArray());
            });

            return back()->with('success', "Visit transferred to {$newHost->name}.");
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /** Host: Assign parking space */
    public function assignParking(Request $request, $id)
    {
        $visit = VisitRequest::findOrFail($id);
        $host  = Auth::guard('web')->user();
        if ($visit->host_id !== $host->id) abort(403);

        $validator = Validator::make($request->all(), [
            'parking_space' => 'nullable|string|max:50',
        ]);
        if ($validator->fails()) return back()->withErrors($validator);

        $space = $request->input('parking_space', 'P-' . rand(1, 99));
        $visit->update(['parking_space' => $space]);
        NotificationService::send('Parking Assigned', $visit->visitor,
            "A parking space has been reserved for you: Slot {$space}. Please display this number on your vehicle.");
        return back()->with('success', "Parking space {$space} assigned.");
    }

    /** Host: Generate guest Wi-Fi credentials */
    public function generateWifi(Request $request, $id)
    {
        $visit = VisitRequest::findOrFail($id);
        $host  = Auth::guard('web')->user();
        if ($visit->host_id !== $host->id) abort(403);

        $ssid     = 'VMS-Guest';
        $password = 'VMS-' . strtoupper(\Illuminate\Support\Str::random(6));
        $creds    = "SSID: {$ssid} | Password: {$password}";
        $visit->update(['wifi_credentials' => $creds]);
        NotificationService::send('Wi-Fi Credentials', $visit->visitor,
            "Your guest Wi-Fi credentials: {$creds}. Valid for this visit only.");
        return back()->with('success', "Guest Wi-Fi generated: {$creds}");
    }

    /** Host: Confirm visitor arrival (delay / confirm in-person) */
    public function confirmArrival(Request $request, $id)
    {
        $visit = VisitRequest::findOrFail($id);
        $host  = Auth::guard('web')->user();
        if ($visit->host_id !== $host->id) abort(403);

        if (Lockdown::isLocked($visit->company_id, $visit->branch_id)) {
            return back()->with('error', 'Arrival confirmation is currently blocked: this location is under an active security lockdown.');
        }

        if ($visit->status === 'approved') {
            CheckInOutLog::create([
                'visit_request_id' => $visit->id,
                'checkin_time' => now(),
                'guard_id' => $host->id,
            ]);

            $visit->status = 'checked_in';
            $visit->save();
            // QR stays valid during the visit; it is consumed at check-out.
        }

        AuditLogger::log('arrival_confirmed', 'visit_requests', $visit->id);
        NotificationService::send('Arrival Confirmed', $visit->visitor,
            "Your arrival has been confirmed by {$host->name}. Please proceed to the reception desk.");
        return back()->with('success', 'Visitor arrival confirmed. Reception notified.');
    }

    /** Host: Bulk approve */
    public function bulkApprove(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'ids'   => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);
        if ($validator->fails()) return back()->withErrors($validator);

        $ids  = $request->input('ids', []);
        $host = Auth::guard('web')->user();
        $count = 0;
        foreach ($ids as $id) {
            $visit = VisitRequest::where('id', $id)->where(function ($q) use ($host) {
                $q->where('host_id', $host->id)->orWhere('alternate_host_id', $host->id);
            })->where('status', 'pending')->first();
            if ($visit) {
                ApprovalService::approve($visit, 'Bulk approved by host.');
                $visit->refresh();

                // Auto-retry QR generation if it failed during approval
                if (!$visit->qrToken) {
                    try {
                        \App\Services\QRTokenService::generate($visit);
                        $visit->refresh();
                    } catch (\Exception $e) {
                        \Illuminate\Support\Facades\Log::error("Bulk approve QR retry failed for visit #{$visit->id}: " . $e->getMessage());
                    }
                }

                // Auto-retry group member QR tokens if missing
                if ($visit->visit_type === 'company' && $visit->groupMembers->count() > 0) {
                    $hasMissingTokens = $visit->groupMembers->contains('qr_token', null);
                    if ($hasMissingTokens) {
                        try {
                            \App\Services\QRTokenService::generateGroupMemberTokens($visit);
                            $visit->refresh();
                        } catch (\Exception $e) {
                            \Illuminate\Support\Facades\Log::error("Bulk approve group QR retry failed for visit #{$visit->id}: " . $e->getMessage());
                        }
                    }
                }

                // Create VisitorBadge at bulk approval for dashboard display
                try {
                    $badgeVisitor = $visit->resolveVisitor();
                    $existingBadge = \App\Models\VisitorBadge::where('visit_request_id', $visit->id)->first();
                    if (!$existingBadge) {
                        \App\Models\VisitorBadge::create([
                            'visit_request_id'   => $visit->id,
                            'visitor_name'       => $badgeVisitor?->name ?? $visit->company_name ?? 'Visitor',
                            'visitor_id_document'=> $badgeVisitor?->id_document_no ?? '',
                            'visitor_photo_url'  => $badgeVisitor?->photo_url ?? null,
                            'host_name'          => $visit->host->name ?? '',
                            'department'         => $visit->department->name ?? ($visit->host->department->name ?? ''),
                            'purpose'            => $visit->purpose ?? '',
                            'scheduled_time'     => $visit->requested_time ? $visit->requested_time->format('M d, Y H:i') : '',
                            'duration'           => $visit->duration . ' min',
                            'priority'           => $visit->priority ?? 'normal',
                            'is_vip'             => $visit->is_vip ?? false,
                            'qr_token'           => $visit->qrToken?->token_value ?? '',
                            'issued_at'          => now(),
                        ]);
                    }
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::error("Bulk approve VisitorBadge creation failed for visit #{$visit->id}: " . $e->getMessage());
                }

                $token = $visit->qrToken ? $visit->qrToken->token_value : 'N/A';
                NotificationService::send(
                    'QR Code Generated',
                    $visit->visitor ?? $visit->company_email,
                    "Your visit request has been approved by {$host->name}! Your entry QR token is: {$token}. Valid on " . $visit->requested_time->format('M d, Y H:i')
                );

                if ($visit->visit_type === 'company' && $visit->groupMembers->count() > 0) {
                    foreach ($visit->groupMembers as $member) {
                        if ($member->email) {
                            $memberToken = $member->qr_token ?? 'Pending';
                            $fakeVisitor = new \App\Models\Visitor([
                                'name' => $member->name, 'email' => $member->email, 'phone' => $member->phone,
                            ]);
                            NotificationService::send('QR Code Generated', $fakeVisitor,
                                "Dear {$member->name}, your group visit has been approved. Your personal entry QR token is: {$memberToken}. Present this at the security gate on " . $visit->requested_time->format('M d, Y H:i'));
                        }

                        // Create VisitorBadge for each group member at bulk approval
                        try {
                            $existingMemberBadge = \App\Models\VisitorBadge::where('visit_request_id', $visit->id)
                                ->where('visitor_name', $member->name)->first();
                            if (!$existingMemberBadge) {
                                \App\Models\VisitorBadge::create([
                                    'visit_request_id'   => $visit->id,
                                    'visitor_name'       => $member->name,
                                    'visitor_id_document'=> $member->id_document_no ?? '',
                                    'visitor_photo_url'  => null,
                                    'host_name'          => $visit->host->name ?? '',
                                    'department'         => $visit->department->name ?? ($visit->host->department->name ?? ''),
                                    'purpose'            => $visit->purpose ?? '',
                                    'scheduled_time'     => $visit->requested_time ? $visit->requested_time->format('M d, Y H:i') : '',
                                    'duration'           => $visit->duration . ' min',
                                    'priority'           => $visit->priority ?? 'normal',
                                    'is_vip'             => $visit->is_vip ?? false,
                                    'qr_token'           => $member->qr_token ?? '',
                                    'issued_at'          => now(),
                                ]);
                            }
                        } catch (\Exception $e) {
                            \Illuminate\Support\Facades\Log::error("Bulk approve group member VisitorBadge failed for visit #{$visit->id}, member {$member->name}: " . $e->getMessage());
                        }
                    }
                }

                $count++;
            }
        }
        return back()->with('success', "{$count} visit request(s) approved. Visitors notified via email and SMS.");
    }

    /** Host: Bulk reject */
    public function bulkReject(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'ids'    => 'required|array|min:1',
            'ids.*'  => 'integer',
            'reason' => 'nullable|string|max:500',
        ]);
        if ($validator->fails()) return back()->withErrors($validator);

        $ids    = $request->input('ids', []);
        $reason = $request->input('reason', 'Bulk rejected by host.');
        $host   = Auth::guard('web')->user();
        $count  = 0;
        foreach ($ids as $id) {
            $visit = VisitRequest::where('id', $id)->where(function ($q) use ($host) {
                $q->where('host_id', $host->id)->orWhere('alternate_host_id', $host->id);
            })->where('status', 'pending')->first();
            if ($visit) {
                ApprovalService::reject($visit, $reason);

                NotificationService::send(
                    'Registration Approved / Rejected',
                    $visit->visitor,
                    "Your visit request has been declined by {$host->name}. Reason: " . ($reason ?: 'No reason specified.')
                );

                $count++;
            }
        }
        return back()->with('success', "{$count} visit request(s) rejected. Visitors notified via email and SMS.");
    }

    /**
     * Validate group members against the blacklist for a company/group visit.
     * Returns security status for each member.
     */
    public function validateGroupMembers(Request $request, $id)
    {
        $visit = VisitRequest::findOrFail($id);
        $host = Auth::guard('web')->user();

        if ($visit->host_id !== $host->id) {
            abort(403, 'You can only validate members for your own visit requests.');
        }

        $members = $visit->groupMembers;
        $results = $members->map(function ($member) {
            $blacklisted = \App\Models\Blacklist::where('id_document_no', $member->id_document_no)->first();
            $watchlisted = \App\Models\Watchlist::where('id_document_no', $member->id_document_no)->first();

            return [
                'id'                => $member->id,
                'name'              => $member->name,
                'id_document_no'    => $member->id_document_no,
                'email'             => $member->email,
                'phone'             => $member->phone,
                'gender'            => $member->gender,
                'nationality'       => $member->nationality,
                'position'          => $member->position,
                'status'            => $blacklisted ? 'blocked' : ($watchlisted ? 'warning' : 'cleared'),
                'status_label'      => $blacklisted ? 'BLOCKED' : ($watchlisted ? 'WATCHLIST' : 'CLEARED'),
                'reason'            => $blacklisted ? $blacklisted->reason : ($watchlisted ? $watchlisted->reason : null),
                'level'             => $watchlisted ? $watchlisted->level : null,
            ];
        });

        AuditLogger::log('group_member_security_check', 'visit_requests', $visit->id, null, [
            'total_members'   => $results->count(),
            'blocked_count'   => $results->where('status', 'blocked')->count(),
            'warning_count'   => $results->where('status', 'warning')->count(),
            'cleared_count'   => $results->where('status', 'cleared')->count(),
        ]);

        return response()->json([
            'success'    => true,
            'members'    => $results,
            'summary'    => [
                'total'    => $results->count(),
                'cleared'  => $results->where('status', 'cleared')->count(),
                'warning'  => $results->where('status', 'warning')->count(),
                'blocked'  => $results->where('status', 'blocked')->count(),
            ],
        ]);
    }
}
