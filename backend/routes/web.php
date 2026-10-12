<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\EmailVerificationController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\VisitRequestController;
use App\Http\Controllers\ManagementController;
use App\Http\Controllers\SecurityController;
use App\Models\Department;
use App\Models\User;
use App\Models\AuditLog;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\HostChangeController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\Admin\BranchController;
use App\Http\Controllers\Admin\BranchActivityLogController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\IncidentController;
use App\Http\Controllers\Admin\CompanyController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\HostTransferController;
use App\Http\Controllers\VisitorTransferController;
use App\Http\Controllers\RepresentativeDashboardController;
use App\Http\Controllers\SystemAdminController;
use App\Http\Controllers\PublicQrController;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Public Pages & General Auth
|--------------------------------------------------------------------------
*/

Route::get('/', function () {
    return Inertia::render('Public/Home');
})->name('home');

Route::get('/about', function () {
    return Inertia::render('Public/About');
})->name('about');

Route::get('/features', function () {
    return Inertia::render('Public/Features');
})->name('features');

Route::get('/guidelines', function () {
    return Inertia::render('Public/VisitorGuidelines');
})->name('guidelines');

Route::get('/faq', function () {
    return Inertia::render('Public/FAQ');
})->name('faq');

Route::get('/contact', function () {
    $company = \App\Models\CompanySetting::first();
    $workingHours = \App\Models\WorkingHour::orderBy('day_of_week')->get();
    return Inertia::render('Public/Contact', [
        'company_setting' => $company,
        'working_hours'   => $workingHours,
    ]);
})->name('contact');

Route::post('/contact', [ContactController::class, 'store'])->name('contact.submit');

// Public QR landing page opened by any device that scans a pass.
// Throttled to slow down token-enumeration attempts.
Route::get('/qr/{token}', [PublicQrController::class, 'show'])
    ->middleware('throttle:60,1')
    ->name('public.qr');

Route::get('/request-visit', function () {
    $hosts = User::where('role', 'host')->where('status', 'active')->with('department.location', 'branch')->get();
    $categories = \App\Models\VisitorCategory::orderBy('name')->get();
    $locations = \App\Models\Location::with('departments')->where('is_active', true)->get();
    $branches = \App\Models\Branch::with('location', 'departments')->where('is_active', true)->get();
    $companies = \App\Models\Company::where('is_active', true)
        ->with(['locations.branches', 'departments'])
        ->get(['company_id', 'company_name', 'code', 'industry']);
    $company = \App\Models\CompanySetting::first();
    $workingHours = \App\Models\WorkingHour::orderBy('day_of_week')->get();
    return Inertia::render('Public/RequestVisit', [
        'hosts' => $hosts,
        'categories' => $categories,
        'locations' => $locations,
        'branches' => $branches,
        'companies' => $companies,
        'company_setting' => $company,
        'working_hours' => $workingHours,
    ]);
})->name('request-visit');

Route::post('/request-visit', [VisitRequestController::class, 'store']);

Route::get('/login', function () {
    return Inertia::render('Public/Login');
})->name('login');

Route::post('/login', [AuthController::class, 'login']);

Route::get('/register', function () {
    $depts = Department::all();
    $verification = app(\App\Http\Controllers\EmailVerificationController::class);
    $pendingEmail = $verification->pendingEmail();

    return Inertia::render('Public/Register', [
        'departments'        => $depts,
        'pending_email'      => $pendingEmail,
        'email_verified'     => $verification->hasProofFor($pendingEmail),
        'cooldown_remaining' => $verification->cooldownRemaining($pendingEmail),
    ]);
})->name('register');

Route::post('/register', [AuthController::class, 'register']);

// Email verification for the registration flow (additive feature — the
// existing /register submission stays untouched and simply refuses to create
// an account until the submitted email has been verified).
//
// The verification code is only ever delivered by email. It is never
// rendered on any page and never returned in an API response.
Route::post('/register/verify-email', [EmailVerificationController::class, 'send'])
    ->name('register.verify-email');
Route::post('/register/verify-code', [EmailVerificationController::class, 'verify'])
    ->name('register.verify-code');
Route::post('/register/resend-code', [EmailVerificationController::class, 'resend'])
    ->name('register.resend-code');

Route::get('/verify-email', function () {
    $verification = app(\App\Http\Controllers\EmailVerificationController::class);
    $email = $verification->pendingEmail();

    if ($verification->hasProofFor($email)) {
        return redirect()->route('register')
            ->with('success', 'Your email has been verified. You can now complete your registration.');
    }

    return Inertia::render('Public/VerifyEmail', [
        'email'              => $email,
        'cooldown_remaining' => $verification->cooldownRemaining($email),
    ]);
})->name('verify-email');

// Representative Registration for approved company/group visits
Route::get('/register/representative/{visitRequestId}', [AuthController::class, 'showRepresentativeRegistration'])
    ->name('register.representative');
Route::post('/register/representative', [AuthController::class, 'registerRepresentative'])
    ->name('register.representative.submit');

Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

Route::get('/forgot-password', [PasswordResetController::class, 'showForgotForm'])->name('password.request');
Route::post('/forgot-password', [PasswordResetController::class, 'sendResetLink'])->name('password.email');
Route::get('/forgot-password/verify', [PasswordResetController::class, 'showVerifyForm'])->name('password.verify');
Route::post('/forgot-password/verify', [PasswordResetController::class, 'verifyOtp'])->name('password.verify.submit');
Route::post('/forgot-password/resend', [PasswordResetController::class, 'resendOtp'])->name('password.resend');
Route::get('/reset-password/{token}', [PasswordResetController::class, 'showResetForm'])->name('password.reset');
Route::post('/reset-password', [PasswordResetController::class, 'resetPassword'])->name('password.update');

/*
|--------------------------------------------------------------------------
| Authenticated Employee Routes (Auth:web Guard)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:web'])->group(function () {
    
    // Profile Management
    Route::get('/profile', function () {
        $user = Auth::guard('web')->user();
        return Inertia::render('Public/Profile', [
            'user' => $user->load('department'),
            'from' => url()->previous(),
        ]);
    })->name('profile');
    Route::post('/profile/update', [AuthController::class, 'updateProfile'])->name('profile.update');
    Route::post('/profile/2fa', [AuthController::class, 'toggle2FA'])->name('profile.2fa');
    
    // SLA cron runner trigger
    Route::get('/visit-requests/process-sla', [VisitRequestController::class, 'processSla'])->name('visit.process-sla');

// Cron-safe SLA endpoint (no auth required — called by scheduler)
Route::get('/cron/process-sla', function () {
    $count = \App\Services\ApprovalService::processAutoEscalations();
    return response()->json(['status' => 'ok', 'escalated' => $count]);
})->withoutMiddleware(['auth:web'])->middleware('throttle:10,1');

    // CEO Dashboard
    Route::middleware(['permission:dashboard,view'])->group(function () {
        Route::get('/ceo/dashboard', [ManagementController::class, 'ceoDashboard'])->name('ceo.dashboard');
        Route::get('/ceo/section/{section}', [ManagementController::class, 'ceoDashboard'])->name('ceo.section');
        Route::post('/ceo/lockdown', [ManagementController::class, 'toggleLockdown'])->name('ceo.lockdown');
        Route::post('/ceo/incidents', [ManagementController::class, 'createIncident'])->name('ceo.incident.create');
        Route::post('/ceo/incidents/{id}/lift', [ManagementController::class, 'liftIncidentLockdown'])->name('ceo.incident.lift');
        Route::post('/ceo/admins/{id}', [ManagementController::class, 'approveAdmin'])->name('ceo.approve-admin');
        Route::post('/ceo/broadcast', [ManagementController::class, 'broadcastAnnouncement'])->name('ceo.broadcast');
        Route::delete('/ceo/broadcasts', [ManagementController::class, 'deleteAllBroadcasts'])->name('ceo.broadcasts.delete-all');
        Route::post('/ceo/policies', [ManagementController::class, 'updatePolicy'])->name('ceo.policies');

        // SLA Holiday Calendar (CEO)
        Route::post('/ceo/sla-holidays', [ManagementController::class, 'storeHoliday'])->name('ceo.sla-holidays.store');
        Route::delete('/ceo/sla-holidays/{holiday}', [ManagementController::class, 'destroyHoliday'])->name('ceo.sla-holidays.delete');
        Route::get('/ceo/export/visits', [ManagementController::class, 'exportVisits'])->name('ceo.export.visits');
        Route::get('/ceo/export/audit', [ManagementController::class, 'exportAuditLogs'])->name('ceo.export.audit');
        Route::delete('/ceo/audit-logs/{id}', [ManagementController::class, 'deleteAuditLog'])->name('ceo.audit.delete');
        Route::delete('/ceo/audit-logs', [ManagementController::class, 'deleteAllAuditLogs'])->name('ceo.audit.delete-all');
        Route::delete('/ceo/incidents/{id}', [ManagementController::class, 'deleteIncident'])->name('ceo.incident.delete');
        Route::delete('/ceo/incidents', [ManagementController::class, 'deleteAllIncidents'])->name('ceo.incident.delete-all');
    });

    // System Admin Dashboard — platform owner oversight
    Route::middleware(['permission:dashboard,view'])->group(function () {
        Route::get('/system-admin/dashboard', [SystemAdminController::class, 'dashboard'])->name('system-admin.dashboard');
        Route::get('/system-admin/section/{section}', [SystemAdminController::class, 'dashboard'])->name('system-admin.section');
        Route::get('/system-admin/companies', [SystemAdminController::class, 'dashboard'])->name('system-admin.companies');
        Route::get('/system-admin/users', [SystemAdminController::class, 'dashboard'])->name('system-admin.users');

        // Activity filtering API
        Route::get('/system-admin/activities/filter', [SystemAdminController::class, 'getFilteredActivities'])->name('system-admin.activities.filter');

        // Security incident log API (cascading company → branch + time-frame filters)
        Route::get('/system-admin/incidents/data', [SystemAdminController::class, 'getIncidentData'])->name('system-admin.incidents.data');

        // Global activity log API (cascading company → branch + time-frame filters)
        Route::get('/system-admin/activity/data', [SystemAdminController::class, 'getActivityData'])->name('system-admin.activity.data');

        // Company management (inside System Admin dashboard)
        Route::middleware(['permission:companies,full'])->group(function () {
            Route::post('/system-admin/companies', [SystemAdminController::class, 'storeCompany'])->name('system-admin.companies.store');
            Route::put('/system-admin/companies/{company}', [SystemAdminController::class, 'updateCompany'])->name('system-admin.companies.update');
            Route::delete('/system-admin/companies/{company}', [SystemAdminController::class, 'destroyCompany'])->name('system-admin.companies.destroy');
            Route::post('/system-admin/companies/{company}/status', [SystemAdminController::class, 'toggleCompanyStatus'])->name('system-admin.companies.status');
        });

        // User management (inside System Admin dashboard)
        Route::middleware(['permission:users,full'])->group(function () {
            Route::post('/system-admin/users', [SystemAdminController::class, 'storeUser'])->name('system-admin.users.store');
            Route::put('/system-admin/users/{user}', [SystemAdminController::class, 'updateUser'])->name('system-admin.users.update');
            Route::post('/system-admin/users/{user}/toggle-status', [SystemAdminController::class, 'toggleUserStatus'])->name('system-admin.users.toggle-status');
            Route::delete('/system-admin/users/{user}', [SystemAdminController::class, 'destroyUser'])->name('system-admin.users.destroy');
        });
    });

    // Admin Dashboard — includes Manager features (escalations)
    Route::middleware(['permission:dashboard,view'])->group(function () {
        Route::get('/admin/dashboard', [ManagementController::class, 'adminDashboard'])->name('admin.dashboard');
        Route::get('/admin/section/{section}', [ManagementController::class, 'adminSectionPage'])->name('admin.section');
        Route::get('/admin/monitor-data', [ManagementController::class, 'adminMonitorData'])->name('admin.monitor-data');
        Route::post('/admin/users/{id}/approve', [ManagementController::class, 'approveUser'])->name('admin.approve-user');

        // Department CRUD
        Route::post('/admin/departments', [ManagementController::class, 'storeDepartment'])->name('admin.departments.store');
        Route::put('/admin/departments/{id}', [ManagementController::class, 'updateDepartment'])->name('admin.departments.update');
        Route::delete('/admin/departments/{id}', [ManagementController::class, 'deleteDepartment'])->name('admin.departments.delete');

        // Location CRUD
        Route::post('/admin/locations', [ManagementController::class, 'storeLocation'])->name('admin.locations.store');
        Route::put('/admin/locations/{id}', [ManagementController::class, 'updateLocation'])->name('admin.locations.update');
        Route::delete('/admin/locations/{id}', [ManagementController::class, 'deleteLocation'])->name('admin.locations.delete');

        // Branch CRUD (standalone pages)
        Route::get('/admin/branches', [BranchController::class, 'index'])->name('admin.branches.index');
        Route::get('/admin/branches/create', [BranchController::class, 'create'])->name('admin.branches.create');
        Route::post('/admin/branches', [BranchController::class, 'store'])->name('admin.branches.store');
        Route::get('/admin/branches/{branch}/edit', [BranchController::class, 'edit'])->name('admin.branches.edit');
        Route::put('/admin/branches/{branch}', [BranchController::class, 'update'])->name('admin.branches.update');
        Route::delete('/admin/branches/{branch}', [BranchController::class, 'destroy'])->name('admin.branches.destroy');
        Route::post('/admin/branches/{branch}/status', [BranchController::class, 'toggleStatus'])->name('admin.branches.status');
        Route::get('/admin/branches/{branch}/activity-logs', [BranchActivityLogController::class, 'index'])->name('admin.branches.activity-logs');

    // Company CRUD (standalone pages)
    Route::middleware(['permission:companies,view'])->group(function () {
        Route::get('/admin/companies', [CompanyController::class, 'index'])->name('admin.companies.index');
        Route::get('/admin/companies/create', [CompanyController::class, 'create'])->name('admin.companies.create');
        Route::post('/admin/companies', [CompanyController::class, 'store'])->name('admin.companies.store');
        Route::get('/admin/companies/{company}/edit', [CompanyController::class, 'edit'])->name('admin.companies.edit');
        Route::put('/admin/companies/{company}', [CompanyController::class, 'update'])->name('admin.companies.update');
        Route::delete('/admin/companies/{company}', [CompanyController::class, 'destroy'])->name('admin.companies.destroy');
        Route::post('/admin/companies/{company}/assign', [CompanyController::class, 'assign'])->name('admin.companies.assign');
        Route::post('/admin/companies/{company}/status', [CompanyController::class, 'toggleStatus'])->name('admin.companies.status');
    });

        // Branch CRUD (inline admin section)
        Route::post('/admin/branches/inline', [ManagementController::class, 'storeBranch'])->name('admin.branches.inline.store');
        Route::put('/admin/branches/inline/{id}', [ManagementController::class, 'updateBranch'])->name('admin.branches.inline.update');
        Route::delete('/admin/branches/inline/{id}', [ManagementController::class, 'deleteBranch'])->name('admin.branches.inline.delete');

        // User CRUD
        Route::post('/admin/users', [ManagementController::class, 'storeUser'])->name('admin.users.store');
        Route::put('/admin/users/{id}', [ManagementController::class, 'updateUser'])->name('admin.users.update');
        Route::delete('/admin/users/{id}', [ManagementController::class, 'deleteUser'])->name('admin.users.delete');

        // Blacklist CRUD
        Route::post('/admin/blacklist', [ManagementController::class, 'storeBlacklist'])->name('admin.blacklist.store');
        Route::delete('/admin/blacklist/{id}', [ManagementController::class, 'deleteBlacklist'])->name('admin.blacklist.delete');

        // Escalation decisions
        Route::post('/visit-requests/{id}/decision', [VisitRequestController::class, 'makeDecision'])->name('admin.visit.decision');

        // ── New Admin Module Routes ──
        // Company Settings
        Route::post('/admin/company-settings', [ManagementController::class, 'saveCompanySettings'])->name('admin.company.save');

        // Working Hours
        Route::post('/admin/working-hours', [ManagementController::class, 'saveWorkingHours'])->name('admin.hours.save');

        // Visitor Categories
        Route::post('/admin/visitor-categories', [ManagementController::class, 'storeVisitorCategory'])->name('admin.categories.store');
        Route::put('/admin/visitor-categories/{id}', [ManagementController::class, 'updateVisitorCategory'])->name('admin.categories.update');
        Route::delete('/admin/visitor-categories/{id}', [ManagementController::class, 'deleteVisitorCategory'])->name('admin.categories.delete');

        // Watchlist
        Route::post('/admin/watchlist', [ManagementController::class, 'storeWatchlist'])->name('admin.watchlist.store');
        Route::delete('/admin/watchlist/{id}', [ManagementController::class, 'deleteWatchlist'])->name('admin.watchlist.delete');

        // Badge Templates
        Route::post('/admin/badge-templates', [ManagementController::class, 'storeBadgeTemplate'])->name('admin.badges.store');
        Route::delete('/admin/badge-templates/{id}', [ManagementController::class, 'deleteBadgeTemplate'])->name('admin.badges.delete');

        // Notification Templates
        Route::post('/admin/notification-templates', [ManagementController::class, 'saveNotificationTemplate'])->name('admin.notifications.save');
        Route::delete('/admin/notification-templates/{id}', [ManagementController::class, 'deleteNotificationTemplate'])->name('admin.notifications.delete');

        // Reports Export
        Route::get('/admin/export/visits', [ManagementController::class, 'adminExportVisits'])->name('admin.export.visits');
        Route::get('/admin/export/audit', [ManagementController::class, 'adminExportAudit'])->name('admin.export.audit');
        Route::delete('/admin/audit-logs/{id}', [ManagementController::class, 'deleteAuditLog'])->name('admin.audit.delete');
        Route::delete('/admin/audit-logs', [ManagementController::class, 'deleteAllAuditLogs'])->name('admin.audit.delete-all');
        Route::get('/admin/export/staff', [ManagementController::class, 'adminExportStaff'])->name('admin.export.staff');

        // Branch-Based & Time-Framed Reports
        Route::get('/admin/reports/data', [ReportController::class, 'data'])->name('admin.reports.data');
        Route::get('/admin/reports/export/{type}', [ReportController::class, 'export'])->name('admin.reports.export');

        // Branch Security Incident Management
        Route::get('/admin/incidents/data', [IncidentController::class, 'data'])->name('admin.incidents.data');
        Route::get('/admin/incidents/export', [IncidentController::class, 'export'])->name('admin.incidents.export');
        Route::post('/admin/incidents/{id}/update', [IncidentController::class, 'update'])->name('admin.incidents.update');

        // Emergency broadcast
        Route::post('/admin/broadcast', [ManagementController::class, 'adminBroadcast'])->name('admin.broadcast');

        // SLA Policy Settings (Admin)
        Route::post('/admin/sla-settings', [ManagementController::class, 'saveSlaSettings'])->name('admin.sla-settings');

        // SLA Holiday Calendar (Admin)
        Route::post('/admin/sla-holidays', [ManagementController::class, 'storeHoliday'])->name('admin.sla-holidays.store');
        Route::delete('/admin/sla-holidays/{holiday}', [ManagementController::class, 'destroyHoliday'])->name('admin.sla-holidays.delete');

        // SMS Gateway Integration (Admin)
        Route::post('/admin/integrations/sms', [ManagementController::class, 'saveSmsIntegration'])->name('admin.integrations.sms.save');
        Route::post('/admin/integrations/sms/test', [ManagementController::class, 'testSmsIntegration'])->name('admin.integrations.sms.test');

        // Admin: Update visit appointment date & time
        Route::post('/admin/visit-requests/{id}/update-time', [ManagementController::class, 'adminUpdateVisitTime'])->name('admin.visit.update-time');

        // Admin/CEO: End an active visit (checked-out, record preserved)
        Route::post('/admin/visit-requests/{id}/end', [VisitRequestController::class, 'endVisit'])->name('admin.visit.end');

        // Admin: Delete a visit appointment (with full dependency cleanup)
        Route::delete('/admin/visit-requests/{id}', [VisitRequestController::class, 'adminDestroyVisit'])->name('admin.visit.destroy');

        // Admin: Configure host assignment rule (least_busy or round_robin)
        Route::post('/admin/host-assignment-rule', function (\Illuminate\Http\Request $request) {
            $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
                'rule' => 'required|in:least_busy,round_robin',
            ]);
            if ($validator->fails()) {
                return back()->withErrors($validator);
            }
            $far = now()->addYears(10)->timestamp;
            \Illuminate\Support\Facades\DB::table('cache')->updateOrInsert(
                ['key' => 'host_assignment_rule'],
                ['value' => $request->rule, 'expiration' => $far]
            );
            \App\Services\AuditLogger::log('admin_host_assignment_rule_update', 'cache', null, null, ['rule' => $request->rule]);
            return back()->with('success', 'Host assignment rule updated to: ' . $request->rule);
        })->name('admin.host-assignment-rule');
    });

    // Shared Security Operations — QR scan & check-in accessible by both Host (basic tab) and Security Guard
    Route::middleware(['permission:visit_approval,scan'])->group(function () {
        Route::post('/security/scan', [SecurityController::class, 'scanQR'])->name('security.scan');
        Route::post('/security/visits/{id}/checkin', [SecurityController::class, 'checkIn'])->name('security.checkin');
    });

    // Host Dashboard
    Route::middleware(['permission:dashboard,view'])->group(function () {
        Route::get('/host/dashboard', [ManagementController::class, 'hostDashboard'])->name('host.dashboard');
        Route::get('/host/section/{section}', [ManagementController::class, 'hostDashboard'])->name('host.section');
        Route::post('/visit-requests/{id}/host-decision', [VisitRequestController::class, 'makeDecision'])->name('visit.decision');
        Route::post('/visit-requests/{id}/assign-room', [VisitRequestController::class, 'assignRoom'])->name('visit.assign-room');
        Route::post('/host/facilities',                [\App\Http\Controllers\FacilityController::class, 'store'])->name('host.facility.store');
        Route::put('/host/facilities/{id}',            [\App\Http\Controllers\FacilityController::class, 'update'])->name('host.facility.update');
        Route::delete('/host/facilities/{id}',         [\App\Http\Controllers\FacilityController::class, 'destroy'])->name('host.facility.destroy');

        // ── New Host Module Routes ──
        Route::post('/host/visits/{id}/meeting-start',   [VisitRequestController::class, 'startMeeting'])->name('host.meeting.start');
        Route::post('/host/visits/{id}/meeting-end',     [VisitRequestController::class, 'endMeeting'])->name('host.meeting.end');
        Route::post('/host/visits/{id}/extend',          [VisitRequestController::class, 'extendVisit'])->name('host.visit.extend');
        Route::post('/host/visits/{id}/no-show',         [VisitRequestController::class, 'markNoShow'])->name('host.visit.noshow');
        Route::post('/host/visits/{id}/notes',           [VisitRequestController::class, 'saveNotes'])->name('host.visit.notes');
        Route::post('/host/visits/{id}/priority',        [VisitRequestController::class, 'setPriority'])->name('host.visit.priority');
        Route::post('/host/visits/{id}/vip',             [VisitRequestController::class, 'toggleVip'])->name('host.visit.vip');
        Route::post('/host/visits/{id}/transfer',        [VisitRequestController::class, 'transferVisitor'])->name('host.visit.transfer');
        Route::post('/host/visits/{id}/parking',         [VisitRequestController::class, 'assignParking'])->name('host.visit.parking');
        Route::post('/host/visits/{id}/wifi',            [VisitRequestController::class, 'generateWifi'])->name('host.visit.wifi');
        Route::post('/host/visits/{id}/confirm-arrival', [VisitRequestController::class, 'confirmArrival'])->name('host.visit.confirm-arrival');
        Route::post('/host/visits/bulk-approve',         [VisitRequestController::class, 'bulkApprove'])->name('host.visit.bulk-approve');
        Route::post('/host/visits/bulk-reject',          [VisitRequestController::class, 'bulkReject'])->name('host.visit.bulk-reject');
        Route::post('/host/visits/{id}/regenerate-qr',   [ManagementController::class, 'hostRegenerateQrToken'])->name('host.visit.regenerate-qr');
        Route::delete('/host/visits/bulk-delete',        [VisitRequestController::class, 'bulkDelete'])->name('host.visit.bulk-delete');
        Route::delete('/host/visits/{id}',               [VisitRequestController::class, 'destroy'])->name('host.visit.destroy');
        Route::get('/host/visits/{id}/validate-group',   [VisitRequestController::class, 'validateGroupMembers'])->name('host.visit.validate-group');
        Route::post('/reschedule-requests/{id}/review', [VisitRequestController::class, 'reviewReschedule'])->name('visit.review-reschedule');
        Route::delete('/reschedule-requests/history/delete', [VisitRequestController::class, 'deleteRescheduleHistory'])->name('host.reschedule-history.delete');
    });

    // ── Escalation Workflow (Admin / CEO — the responsible person) ──
    // Controller methods additionally enforce that only the Admin/CEO who owns
    // the escalated visit can act on it. The receive/meeting actions reuse the
    // shared host routes above (confirm-arrival / meeting-start / meeting-end).
    Route::middleware(['permission:dashboard,view'])->group(function () {
        Route::get('/escalation/visits/{id}/transfer-options', [VisitRequestController::class, 'escalationTransferOptions'])->name('escalation.transfer-options');
        Route::post('/escalation/visits/{id}/receive',  [VisitRequestController::class, 'escalationReceive'])->name('escalation.receive');
        Route::post('/escalation/visits/{id}/complete', [VisitRequestController::class, 'escalationComplete'])->name('escalation.complete');
        Route::post('/escalation/visits/{id}/no-show',  [VisitRequestController::class, 'escalationNoShow'])->name('escalation.noshow');
        Route::post('/escalation/visits/{id}/transfer', [VisitRequestController::class, 'escalationTransfer'])->name('escalation.transfer');
    });

    // ── Visitor Transfer (Admin / CEO — company-level) ──
    // Both Company Admins and CEOs can transfer a visitor to an eligible Host
    // within any company they are authorized for. The cascade endpoints are
    // shared by both dashboards and validate every selection server-side.
    Route::middleware(['permission:dashboard,view'])->group(function () {
        Route::get('/visitor-transfer/{id}/options', [VisitorTransferController::class, 'options'])->name('visitor-transfer.options');
        Route::post('/visitor-transfer/{id}', [VisitorTransferController::class, 'store'])->name('visitor-transfer.store');
    });

    // Security Guard Dedicated Routes
    Route::middleware(['permission:security,dashboard'])->group(function () {
        Route::get('/security/dashboard', [SecurityController::class, 'dashboard'])->name('security.dashboard');
        Route::get('/security/section/{section}', [SecurityController::class, 'dashboard'])->name('security.section');
        Route::get('/security/live-visitors', [SecurityController::class, 'liveVisitors'])->name('security.live-visitors');
        Route::get('/security/daily-report', [SecurityController::class, 'dailyReport'])->name('security.daily-report');
        // /security/scan and /security/visits/{id}/checkin are in the shared group above
        Route::post('/security/exit-scan', [SecurityController::class, 'exitScan'])->name('security.exit-scan');
        Route::post('/security/verify-identity', [SecurityController::class, 'verifyIdentity'])->name('security.verify-identity');
        Route::post('/security/register-property', [SecurityController::class, 'registerProperty'])->name('security.register-property');
        Route::post('/security/visits/{id}/checkout', [SecurityController::class, 'checkOut'])->name('security.checkout');
        Route::post('/security/visits/{id}/verify-return', [SecurityController::class, 'verifyPropertyReturn'])->name('security.verify-return');
        Route::post('/security/incidents', [SecurityController::class, 'createIncidentFromRequest'])->name('security.incidents.create');
        Route::post('/security/incidents/{id}/update', [SecurityController::class, 'updateIncident'])->name('security.incidents.update');

        // Incident lifecycle API endpoints
        Route::get('/security/incidents/data', [SecurityController::class, 'incidentData'])->name('security.incidents.data');
        Route::get('/security/incidents/counts', [SecurityController::class, 'incidentCounts'])->name('security.incidents.counts');
        Route::get('/security/incidents/{id}', [SecurityController::class, 'incidentDetail'])->name('security.incidents.detail');
        Route::post('/security/incidents/{id}/investigate', [SecurityController::class, 'investigateIncident'])->name('security.incidents.investigate');
        Route::post('/security/incidents/{id}/resolve', [SecurityController::class, 'resolveIncident'])->name('security.incidents.resolve');
        Route::post('/security/incidents/{id}/dismiss', [SecurityController::class, 'dismissIncident'])->name('security.incidents.dismiss');

        // Visit lookup for incident form dropdown
        Route::get('/security/visits/recent', [SecurityController::class, 'recentVisits'])->name('security.visits.recent');

        // Group member operations
        Route::get('/security/visit/{id}/group-members', [SecurityController::class, 'getGroupMembers'])->name('security.group-members');
        Route::get('/security/visit/{id}/properties', [SecurityController::class, 'getVisitProperties'])->name('security.visit-properties');

        // QR Code download endpoints
        Route::get('/security/visit/{id}/qr-download', [SecurityController::class, 'downloadQr'])->name('security.qr-download');
        Route::get('/security/visit/{id}/qr-all-download', [SecurityController::class, 'downloadAllQr'])->name('security.qr-all-download');
        Route::get('/security/visit/{id}/group-qr-package', [SecurityController::class, 'downloadGroupQrPackage'])->name('security.group-qr-package');
    });

    // ── Shared Notification API Routes (CEO / Admin / Security / Host) ──
    Route::get('/api/notifications', function () {
        $user = auth('web')->user();
        $notifications = \App\Services\NotificationService::getAll('user', $user->id);
        $unreadCount = \App\Services\NotificationService::getUnreadCount('user', $user->id);
        return response()->json(['notifications' => $notifications, 'unread_count' => $unreadCount]);
    })->name('api.notifications.index');

    // Lightweight CEO-broadcast poll used by the shared Layout so the
    // announcement bar refreshes without re-executing the full dashboard
    // controller (which previously ran every 30s per logged-in user).
    Route::get('/api/broadcasts', function () {
        $user = auth('web')->user();
        if (!$user || $user->role === 'ceo' || !$user->company_id) {
            return response()->json(['broadcasts' => []]);
        }

        $uCompany = (int) $user->company_id;
        $uBranch  = $user->branch_id ? (int) $user->branch_id : null;

        $rows = \App\Models\Announcement::withoutGlobalScopes()
            ->where('company_id', $uCompany)
            ->where('created_at', '>=', now()->subHours(24))
            ->where(function ($q) use ($uBranch) {
                $q->whereNull('branch_id');
                if ($uBranch) {
                    $q->orWhere('branch_id', $uBranch);
                }
            })
            ->latest('created_at')
            ->take(20)
            ->get();

        return response()->json([
            'broadcasts' => $rows->map(fn($b) => [
                'id'       => 'ann_' . $b->id,
                'message'  => $b->message,
                'priority' => $b->priority,
                'ts'       => $b->created_at ? $b->created_at->timestamp : now()->timestamp,
                'time'     => $b->created_at ? $b->created_at->toDateTimeString() : now()->toDateTimeString(),
            ])->values(),
        ]);
    })->name('api.broadcasts');

    Route::post('/api/notifications/mark-all-read', function () {
        $user = auth('web')->user();
        \App\Services\NotificationService::markAllRead('user', $user->id);
        $unreadCount = \App\Services\NotificationService::getUnreadCount('user', $user->id);
        return response()->json(['success' => true, 'unread_count' => $unreadCount]);
    })->name('api.notifications.mark-all-read');

    Route::post('/api/notifications/{id}/read', function ($id) {
        \App\Services\NotificationService::markRead($id);
        $user = auth('web')->user();
        $unreadCount = \App\Services\NotificationService::getUnreadCount('user', $user->id);
        return response()->json(['success' => true, 'unread_count' => $unreadCount]);
    })->name('api.notifications.read');

    Route::post('/api/notifications/{id}/unread', function ($id) {
        \App\Services\NotificationService::markUnread($id);
        $user = auth('web')->user();
        $unreadCount = \App\Services\NotificationService::getUnreadCount('user', $user->id);
        return response()->json(['success' => true, 'unread_count' => $unreadCount]);
    })->name('api.notifications.unread');

    Route::post('/api/notifications/{id}/cancel', function ($id) {
        $user = auth('web')->user();
        \App\Services\NotificationService::cancel('user', $user->id, $id);
        $unreadCount = \App\Services\NotificationService::getUnreadCount('user', $user->id);
        return response()->json(['success' => true, 'unread_count' => $unreadCount]);
    })->name('api.notifications.cancel');

    Route::post('/api/notifications/cancel-read', function () {
        $user = auth('web')->user();
        \App\Services\NotificationService::cancelRead('user', $user->id);
        $unreadCount = \App\Services\NotificationService::getUnreadCount('user', $user->id);
        return response()->json(['success' => true, 'unread_count' => $unreadCount]);
    })->name('api.notifications.cancel-read');

    // Legacy Inertia routes (kept for backwards compatibility)
    Route::post('/notifications/mark-all-read', function () {
        $user = auth('web')->user();
        \App\Services\NotificationService::markAllRead('user', $user->id);
        return back()->with('success', 'All notifications marked as read.');
    })->name('notifications.mark-all-read');
    Route::post('/notifications/{id}/read', function ($id) {
        \App\Services\NotificationService::markRead($id);
        return back()->with('success', 'Notification marked as read.');
    })->name('notifications.read');
    Route::post('/notifications/{id}/unread', function ($id) {
        \App\Models\SystemNotification::where('id', $id)->update([
            'is_read' => false,
            'read_at' => null,
        ]);
        return back()->with('success', 'Notification marked as unread.');
    })->name('notifications.unread');
    Route::post('/notifications/{id}/cancel', function ($id) {
        $user = auth('web')->user();
        \App\Services\NotificationService::cancel('user', $user->id, $id);
        return back()->with('success', 'Notification cancelled.');
    })->name('notifications.cancel');
    Route::post('/notifications/cancel-read', function () {
        $user = auth('web')->user();
        \App\Services\NotificationService::cancelRead('user', $user->id);
        return back()->with('success', 'Read notifications cancelled.');
    })->name('notifications.cancel-read');

    // ── Messaging System (CEO / Admin / Security / Host) ──
    Route::get('/messages', [MessageController::class, 'index'])->name('messages.index');
    Route::post('/messages/send', [MessageController::class, 'send'])->name('messages.send');
    Route::post('/messages/{id}/reply', [MessageController::class, 'reply'])->name('messages.reply');
    Route::get('/messages/{id}/thread', [MessageController::class, 'thread'])->name('messages.thread');
    Route::delete('/messages/{id}', [MessageController::class, 'destroy'])->name('messages.destroy');
    Route::post('/messages/{id}/read', [MessageController::class, 'markRead'])->name('messages.read');
    Route::get('/messages/unread-count', [MessageController::class, 'unreadCount'])->name('messages.unread-count');
    Route::get('/messages/recipients', [MessageController::class, 'recipients'])->name('messages.recipients');

    // ── Host Change / Transfer System ──
    Route::post('/host-change-requests', [HostChangeController::class, 'store'])->name('host-change.store');
    Route::post('/host-change-requests/{id}/accept', [HostChangeController::class, 'accept'])->name('host-change.accept');
    Route::post('/host-change-requests/{id}/reject', [HostChangeController::class, 'reject'])->name('host-change.reject');
    Route::post('/host-change-requests/{id}/cancel', [HostChangeController::class, 'cancel'])->name('host-change.cancel');
    Route::get('/api/host-change-requests/pending', [HostChangeController::class, 'myPendingRequests'])->name('host-change.my-pending');
    Route::get('/api/host-change-requests', [HostChangeController::class, 'index'])->name('host-change.index');
    Route::get('/api/host-change-requests/stats', [HostChangeController::class, 'stats'])->name('host-change.stats');

    // ── Representative Dashboard ──
    Route::get('/representative/dashboard', [RepresentativeDashboardController::class, 'index'])
        ->name('representative.dashboard');

    Route::get('/representative/qr/download/{token}', function ($token) {
        $options = new \chillerlan\QRCode\QROptions([
            'outputBase64' => false,
        ]);
        $qrCode = new \chillerlan\QRCode\QRCode($options);
        return response($qrCode->render(\App\Services\QrBadgeService::scanPayload($token)))
            ->header('Content-Type', 'image/svg+xml');
    })->name('representative.qr.download');

    Route::get('/representative/qr/download-all', function () {
        // return zip of all QR codes
    })->name('representative.qr.download-all');

    Route::get('/representative/pass/download-all', function () {
        // return zip of all visitor passes
    })->name('representative.pass.download-all');

    // ── Host Transfer ──
    Route::post('/visit-requests/{visitRequest}/transfer', [HostTransferController::class, 'store'])
        ->name('visit-requests.transfer')
        ->middleware(['auth']);

});

/*
|--------------------------------------------------------------------------
| Appointment Editing — shared by hosting staff (web) and visitors
|--------------------------------------------------------------------------
| Registering these under a single route with both guards avoids Laravel
| collapsing two identical method+URI definitions (which previously left only
| the visitor route active and logged hosts out). The controller resolves the
| acting party from whichever guard is authenticated.
*/
Route::middleware(['auth:web,visitor'])->group(function () {
    Route::post('/visit-requests/{id}/update-time', [VisitRequestController::class, 'updateTimeAndDuration'])->name('visit.update-time');
    Route::post('/visit-requests/{id}/request-reschedule', [VisitRequestController::class, 'requestReschedule'])->name('visit.request-reschedule');
});

/*
|--------------------------------------------------------------------------
| Authenticated Visitor Routes (Auth:visitor Guard)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:visitor'])->group(function () {
    Route::get('/visitor/dashboard', [ManagementController::class, 'visitorDashboard'])->name('visitor.dashboard');
    Route::get('/visitor/section/{section}', [ManagementController::class, 'visitorDashboard'])->name('visitor.section');
    Route::post('/visitor/feedback', [VisitRequestController::class, 'submitFeedback'])->name('visitor.feedback');
    Route::post('/visit-requests/{id}/cancel', [VisitRequestController::class, 'cancel'])->name('visitor.cancel');
    Route::delete('/visit-requests/history/delete', [VisitRequestController::class, 'visitorDeleteHistory'])->name('visitor.history-delete');
    Route::post('/visitor/profile/update', [ManagementController::class, 'updateVisitorProfile'])->name('visitor.profile.update');

    // ── Visitor Messaging ──
    Route::get('/visitor/messages', [MessageController::class, 'index'])->name('visitor.messages.index');
    Route::post('/visitor/messages/send', [MessageController::class, 'send'])->name('visitor.messages.send');
    Route::post('/visitor/messages/{id}/reply', [MessageController::class, 'reply'])->name('visitor.messages.reply');
    Route::get('/visitor/messages/{id}/thread', [MessageController::class, 'thread'])->name('visitor.messages.thread');
    Route::delete('/visitor/messages/{id}', [MessageController::class, 'destroy'])->name('visitor.messages.destroy');
    Route::post('/visitor/messages/{id}/read', [MessageController::class, 'markRead'])->name('visitor.messages.read');
    Route::get('/visitor/messages/unread-count', [MessageController::class, 'unreadCount'])->name('visitor.messages.unread-count');
    Route::get('/visitor/messages/recipients', [MessageController::class, 'recipients'])->name('visitor.messages.recipients');
    Route::get('/visitor/badge/{visitId}', function ($visitId) {
        $visitor = auth('visitor')->user();
        $badge = \App\Models\VisitorBadge::where('visit_request_id', $visitId)
            ->whereHas('visitRequest', fn($q) => $q->where('visitor_id', $visitor->id))
            ->first();
        if (!$badge) abort(404);
        return response()->json($badge);
    })->name('visitor.badge');
    // ── Visitor Notification API Routes ──
    Route::get('/api/visitor/notifications', function () {
        $visitor = auth('visitor')->user();
        $notifications = \App\Services\NotificationService::getAll('visitor', $visitor->id);
        $unreadCount = \App\Services\NotificationService::getUnreadCount('visitor', $visitor->id);
        return response()->json(['notifications' => $notifications, 'unread_count' => $unreadCount]);
    })->name('api.visitor.notifications.index');

    Route::post('/api/visitor/notifications/mark-all-read', function () {
        \App\Services\NotificationService::markAllRead('visitor', auth('visitor')->id());
        $unreadCount = \App\Services\NotificationService::getUnreadCount('visitor', auth('visitor')->id());
        return response()->json(['success' => true, 'unread_count' => $unreadCount]);
    })->name('api.visitor.notifications.mark-all-read');

    Route::post('/api/visitor/notifications/{id}/read', function ($id) {
        \App\Services\NotificationService::markRead($id);
        $unreadCount = \App\Services\NotificationService::getUnreadCount('visitor', auth('visitor')->id());
        return response()->json(['success' => true, 'unread_count' => $unreadCount]);
    })->name('api.visitor.notifications.read');

    Route::post('/api/visitor/notifications/{id}/unread', function ($id) {
        \App\Services\NotificationService::markUnread($id);
        $unreadCount = \App\Services\NotificationService::getUnreadCount('visitor', auth('visitor')->id());
        return response()->json(['success' => true, 'unread_count' => $unreadCount]);
    })->name('api.visitor.notifications.unread');

    Route::post('/api/visitor/notifications/{id}/cancel', function ($id) {
        \App\Services\NotificationService::cancel('visitor', auth('visitor')->id(), $id);
        $unreadCount = \App\Services\NotificationService::getUnreadCount('visitor', auth('visitor')->id());
        return response()->json(['success' => true, 'unread_count' => $unreadCount]);
    })->name('api.visitor.notifications.cancel');

    Route::post('/api/visitor/notifications/cancel-read', function () {
        \App\Services\NotificationService::cancelRead('visitor', auth('visitor')->id());
        $unreadCount = \App\Services\NotificationService::getUnreadCount('visitor', auth('visitor')->id());
        return response()->json(['success' => true, 'unread_count' => $unreadCount]);
    })->name('api.visitor.notifications.cancel-read');

    // ── Visitor QR Code Access ──
    Route::get('/visitor/visit/{id}/qr-image', [ManagementController::class, 'visitorQrImage'])->name('visitor.qr-image');
    Route::get('/visitor/visit/{id}/group-member/{memberId}/qr-image', [ManagementController::class, 'visitorGroupMemberQrImage'])->name('visitor.group-member-qr-image');
    Route::get('/visitor/visit/{id}/group-member/{memberId}/exit-qr-image', [ManagementController::class, 'visitorGroupMemberExitQrImage'])->name('visitor.group-member-exit-qr-image');
    Route::get('/visitor/visit/{id}/qr-download', [ManagementController::class, 'visitorQrDownload'])->name('visitor.qr-download');
    Route::get('/visitor/visit/{id}/qr-all-download', [ManagementController::class, 'visitorQrAllDownload'])->name('visitor.qr-all-download');
    Route::get('/visitor/visit/{id}/group-qr-package', [ManagementController::class, 'visitorGroupQrPackage'])->name('visitor.group-qr-package');
    Route::get('/visitor/visit/{id}/group-member/{memberId}/qr-download', [ManagementController::class, 'visitorGroupMemberQrDownload'])->name('visitor.group-member-qr-download');
    Route::get('/visitor/visit/{id}/group-member/{memberId}/exit-qr-download', [ManagementController::class, 'visitorGroupMemberExitQrDownload'])->name('visitor.group-member-exit-qr-download');

    // QR token regeneration (retry mechanism)
    Route::post('/visitor/visit/{id}/regenerate-qr', [ManagementController::class, 'regenerateQrToken'])->name('visitor.regenerate-qr');

    // Delete an expired, unused QR token
    Route::post('/visitor/visit/{id}/delete-expired-qr', [ManagementController::class, 'deleteExpiredQr'])->name('visitor.delete-expired-qr');

    // Download all visitor passes (representative + group members)
    Route::get('/visitor/visit/{id}/all-passes-download', [ManagementController::class, 'visitorAllPassesDownload'])->name('visitor.all-passes-download');

    // Legacy visitor notification routes
    Route::post('/visitor/notifications/mark-all-read', function () {
        \App\Services\NotificationService::markAllRead('visitor', auth('visitor')->id());
        return back()->with('success', 'Notifications marked as read.');
    })->name('visitor.notifications.mark-read');
    Route::post('/visitor/notifications/{id}/read', function ($id) {
        \App\Services\NotificationService::markRead($id);
        return back()->with('success', 'Notification marked as read.');
    })->name('visitor.notifications.read');
    Route::post('/visitor/notifications/{id}/cancel', function ($id) {
        \App\Services\NotificationService::cancel('visitor', auth('visitor')->id(), $id);
        return back()->with('success', 'Notification cancelled.');
    })->name('visitor.notifications.cancel');
    Route::post('/visitor/notifications/cancel-read', function () {
        \App\Services\NotificationService::cancelRead('visitor', auth('visitor')->id());
        return back()->with('success', 'Read notifications cancelled.');
    })->name('visitor.notifications.cancel-read');
});
