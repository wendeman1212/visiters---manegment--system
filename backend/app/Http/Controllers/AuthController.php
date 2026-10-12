<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Visitor;
use App\Services\AuditLogger;
use App\Services\NotificationService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;

class AuthController extends Controller
{
    /**
     * Handle Login submission for both employees and visitors.
     */
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        $credentials = $request->only('email', 'password');

        // Try employee (web) guard first
        if (Auth::guard('web')->attempt($credentials, $request->boolean('remember'))) {
            $user = Auth::guard('web')->user();

            if ($user->status !== 'active') {
                Auth::guard('web')->logout();
                return back()->withErrors(['email' => 'Your account is pending approval or has been suspended.'])->withInput();
            }

            if ($user->company_id && $user->company && $user->company->status !== 'active') {
                Auth::guard('web')->logout();
                return back()->withErrors(['email' => 'Access denied: your company account has been deactivated.'])->withInput();
            }

            if ($user->branch_id && $user->branch && !$user->branch->is_active) {
                Auth::guard('web')->logout();
                return back()->withErrors(['email' => 'Access denied: your branch has been temporarily deactivated. Please contact your administrator.'])->withInput();
            }

            $request->session()->regenerate();
            AuditLogger::log('login', 'users', $user->id);

            return Inertia::location($this->getDashboardRoute($user->role));
        }

        // Try visitor guard
        if (Auth::guard('visitor')->attempt($credentials, $request->boolean('remember'))) {
            $visitor = Auth::guard('visitor')->user();

            $blacklisted = \App\Models\Blacklist::where('id_document_no', $visitor->id_document_no)->first();
            if ($blacklisted) {
                Auth::guard('visitor')->logout();
                return back()->withErrors(['email' => 'Access denied: Your ID document is on the security blacklist. Reason: ' . $blacklisted->reason])->withInput();
            }

            AuditLogger::log('login', 'visitors', $visitor->id);

            $request->session()->regenerate();
            return Inertia::location('/visitor/dashboard');
        }

        return back()->withErrors([
            'email' => 'The provided credentials do not match our records.',
        ])->withInput();
    }

    /**
     * Handle registration for both employees (pending approval) and visitors.
     */
    public function register(Request $request)
    {
        // Block all registrations during emergency lockdown
        $lockdownActive = \Illuminate\Support\Facades\DB::table('cache')->where('key', 'emergency_lockdown')->first();
        if ($lockdownActive && $lockdownActive->value == 'true') {
            return back()->withErrors(['email' => 'System is in EMERGENCY LOCKDOWN. New registrations are suspended until the lockdown is lifted.'])->withInput();
        }

        // Email verification gate (additive): an account can only be created
        // after the submitted email has been verified via the emailed code.
        // The single-use proof is only consumed once the account is actually
        // created further below, so failed validation never burns it.
        if (!$this->emailIsVerified($request->input('email'))) {
            return back()->withErrors([
                'email' => 'Please verify your email address before creating your account. A verification code was sent to you — enter it on the registration form first.',
            ])->withInput();
        }

        $regType = $request->input('reg_type', 'visitor');

        $messages = [
            'first_name.required' => 'First name is required.',
            'last_name.required' => 'Last name is required.',
            'email.required' => 'Email address is required.',
            'email.email' => 'Please enter a valid email address.',
            'email.unique' => 'This email address is already registered.',
            'phone.required' => 'Phone number is required.',
            'phone.regex' => 'Please enter a valid phone number (e.g. +1 234 567 8900).',
            'phone.unique' => 'This phone number is already registered.',
            'id_document_no.required' => 'National ID / Passport number is required.',
            'id_document_no.regex' => 'ID document may only contain letters, numbers, and hyphens.',
            'id_document_no.unique' => 'This ID document number is already registered.',
            'age.required' => 'Age is required.',
            'age.integer' => 'Age must be a valid number.',
            'age.min' => 'You must be at least 16 years old to register.',
            'age.max' => 'Please enter a valid age.',
            'sex.required' => 'Please select your gender.',
            'sex.in' => 'Please select a valid gender.',
            'company_position.max' => 'Company position must not exceed 255 characters.',
            'password.required' => 'Password is required.',
            'password.min' => 'Password must be at least 8 characters long.',
            'password.confirmed' => 'Passwords do not match.',
            'password.regex' => 'Password must contain at least one uppercase letter, one lowercase letter, one number, and one special character.',
            'profile_photo.image' => 'The uploaded file must be an image (JPEG, PNG, JPG, or GIF).',
            'profile_photo.max' => 'Profile photo must not exceed 2 MB.',
            'profile_photo.mimes' => 'Profile photo must be a JPEG, PNG, JPG, or GIF file.',
        ];

        if ($regType === 'employee') {
            $validator = Validator::make($request->all(), [
                'name' => 'nullable|string|max:255',
                'first_name' => 'required|string|max:255',
                'last_name' => 'required|string|max:255',
                'username' => 'nullable|string|max:255|unique:users,username',
                'email' => 'required|string|email|max:255|unique:users,email',
                'phone' => ['required', 'string', 'max:30', 'regex:/^\+?[0-9][0-9\s\-\.\(\)]{7,16}$/', 'unique:users,phone'],
                'nationality' => 'nullable|string|max:255',
                'age' => 'required|integer|min:16|max:120',
                'sex' => 'required|in:male,female,other',
                'company_position' => 'nullable|string|max:255',
                'role' => 'required|in:admin,host,security_guard',
                'department_id' => 'required|exists:departments,id',
                'password' => ['required', 'string', 'min:8', 'confirmed', 'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).+$/'],
                'profile_photo' => 'nullable|image|max:2048|mimes:jpeg,png,jpg,gif',
            ], $messages);

            if ($validator->fails()) {
                return back()->withErrors($validator)->withInput();
            }

            $profilePhoto = null;
            if ($request->hasFile('profile_photo')) {
                $profilePhoto = $request->file('profile_photo')->store('profile-photos', 'public');
            }

            $user = User::create([
                'name' => $request->name,
                'first_name' => $request->first_name,
                'last_name' => $request->last_name,
                'username' => $request->username,
                'email' => $request->email,
                'phone' => $request->phone,
                'nationality' => $request->nationality,
                'age' => $request->age,
                'sex' => $request->sex,
                'company_position' => $request->company_position,
                'profile_photo' => $profilePhoto,
                'role' => $request->role,
                'department_id' => $request->department_id,
                'company_id' => \App\Models\Department::find($request->department_id)?->company_id,
                'status' => 'pending',
                'password' => $request->password,
                'email_verified_at' => now(),
            ]);

            // Auto-assign manager_id — now deprecated, keeping field for historical data
            // No auto-assignment since managers are removed

            // Fire Registered event to link representative to company visit
            event(new Registered($user));

            // Notify Approver
            $approverRole = ($request->role === 'admin') ? 'ceo' : 'admin';
            $approver = User::where('role', $approverRole)->where('status', 'active')->first();
            
            NotificationService::send(
                'Registration Submitted', 
                $approver ? $approver : 'System Administrator', 
                "New employee registration submitted by {$user->name} ({$user->role}). Approval required."
            );

            $this->consumeEmailVerification($request->email);

            return Inertia::location(route('login'));
        } else {
            // Visitor Registration
            $name = trim($request->input('first_name', '') . ' ' . $request->input('last_name', ''));

            $validator = Validator::make($request->all(), [
                'name' => 'nullable|string|max:255',
                'first_name' => 'required|string|max:255',
                'last_name' => 'required|string|max:255',
                'email' => 'required|string|email|max:255',
                'phone' => ['required', 'string', 'max:30', 'regex:/^\+?[0-9][0-9\s\-\.\(\)]{7,16}$/'],
                'id_document_no' => ['required', 'string', 'max:50', 'regex:/^[a-zA-Z0-9\-]+$/'],
                'age' => 'required|integer|min:16|max:120',
                'sex' => 'required|in:male,female',
                'company_position' => 'nullable|string|max:255',
                'password' => ['required', 'string', 'min:8', 'confirmed'],
            ], $messages);

            if ($validator->fails()) {
                return back()->withErrors($validator)->withInput();
            }

            $newFields = [
                'first_name' => $request->first_name,
                'last_name' => $request->last_name,
                'age' => $request->age,
                'sex' => $request->sex,
                'company_position' => $request->company_position,
            ];

            // Check if an auto-created visitor account exists (from a public request submission)
            $existingVisitor = Visitor::where('email', $request->email)
                ->orWhere('id_document_no', $request->id_document_no)
                ->first();

            if ($existingVisitor) {
                // Check if this is a fully registered account (password was set by the visitor themselves)
                // Auto-created accounts use bcrypt('password') — we detect them by checking
                // if no other unique email/ID conflict exists beyond the same record
                $emailConflict = Visitor::where('email', $request->email)
                    ->where('id', '!=', $existingVisitor->id)->exists();
                $idConflict = Visitor::where('id_document_no', $request->id_document_no)
                    ->where('id', '!=', $existingVisitor->id)->exists();

                if ($emailConflict || $idConflict) {
                    $dupErrors = [];
                    if ($emailConflict) {
                        $dupErrors['email'] = 'This email address is already registered. Please log in instead.';
                    }
                    if ($idConflict) {
                        $dupErrors['id_document_no'] = 'This National ID / Passport number is already registered. Please log in instead.';
                    }
                    return back()->withErrors($dupErrors)->withInput();
                }

                // It's the same record (auto-created from public request) — upgrade it with real password
                $existingVisitor->update(array_merge([
                    'name'              => $name,
                    'phone'             => $request->phone,
                    'password'          => $request->password,
                    'email_verified_at' => now(),
                ], $newFields));

                AuditLogger::log('registration_upgrade', 'visitors', $existingVisitor->id);

                $this->consumeEmailVerification($request->email);

                return redirect()->route('login')->with('success', 'Account created successfully. Please log in.');
            }

            // Completely new visitor
            $visitor = Visitor::create(array_merge([
                'name'          => $name,
                'email'         => $request->email,
                'phone'         => $request->phone,
                'id_document_no' => $request->id_document_no,
                'password'      => $request->password,
            ], $newFields));

            $visitor->forceFill([
                'email_verified_at' => now(),
            ])->save();

            AuditLogger::log('registration', 'visitors', $visitor->id);

            $this->consumeEmailVerification($request->email);

            return redirect()->route('login')->with('success', 'Account created successfully. Please log in.');
        }
    }

    /**
     * Handle logout.
     */
    public function logout(Request $request)
    {
        if (Auth::guard('web')->check()) {
            $user = Auth::guard('web')->user();
            AuditLogger::log('logout', 'users', $user->id);
            Auth::guard('web')->logout();
        } elseif (Auth::guard('visitor')->check()) {
            $visitor = Auth::guard('visitor')->user();
            AuditLogger::log('logout', 'visitors', $visitor->id);
            Auth::guard('visitor')->logout();
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Inertia::location('/');
    }

    /**
     * Toggle Two-Factor Authentication for employees.
     */
    public function toggle2FA(Request $request)
    {
        $user = Auth::guard('web')->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        if ($user->two_factor_secret) {
            // Disable 2FA
            $user->update([
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
            ]);
            AuditLogger::log('disable_2fa', 'users', $user->id);
            return back()->with('success', 'Two-Factor Authentication has been disabled.');
        } else {
            // Enable 2FA (Mock setup secret)
            $user->update([
                'two_factor_secret' => 'MOCK-2FA-SECRET-KEY-VMS',
                'two_factor_recovery_codes' => json_encode(['REC1-AAAA', 'REC2-BBBB', 'REC3-CCCC']),
                'two_factor_confirmed_at' => now(),
            ]);
            AuditLogger::log('enable_2fa', 'users', $user->id);
            return back()->with('success', 'Two-Factor Authentication is enabled. Verification Code: 123456');
        }
    }

    /**
     * Update user profile settings.
     */
    public function updateProfile(Request $request)
    {
        $user = Auth::guard('web')->user() ?: Auth::guard('visitor')->user();
        if (!$user) {
            abort(403);
        }

        $table = $user instanceof Visitor ? 'visitors' : 'users';

        $docColumn = ($user instanceof Visitor) ? 'id_document_no' : 'national_id';

        $validator = Validator::make($request->all(), [
            'name'         => 'required|string|max:255',
            'first_name'   => 'nullable|string|max:255',
            'last_name'    => 'nullable|string|max:255',
            'username'     => 'nullable|string|max:255|unique:' . $table . ',username,' . $user->id,
            'email'        => 'nullable|email|max:255|unique:' . $table . ',email,' . $user->id,
            'phone'        => 'nullable|string|max:50',
            'nationality'  => 'nullable|string|max:255',
            'age'          => 'nullable|integer|min:16|max:120',
            'sex'          => 'nullable|in:male,female,other',
            'id_document_no' => 'nullable|string|max:255|unique:' . $table . ',' . $docColumn . ',' . $user->id,
            'company_position' => 'nullable|string|max:255',
            'password'     => 'nullable|string|min:8|confirmed',
            'profile_photo' => 'nullable|image|max:2048',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator);
        }

        $before = $user->toArray();

        $fields = $request->only('name', 'first_name', 'last_name', 'username', 'email', 'phone', 'nationality', 'age', 'sex');

        // National ID / Passport Number and Company Position map to different columns
        // for users (national_id / position) vs visitors (id_document_no / company_position).
        if ($request->exists('id_document_no')) {
            $fields[$docColumn] = $request->id_document_no;
        }
        if ($request->exists('company_position')) {
            $fields[($user instanceof Visitor) ? 'company_position' : 'position'] = $request->company_position;
        }
        if ($request->filled('password')) {
            $fields['password'] = $request->password;
        }

        // Handle profile photo upload
        if ($request->hasFile('profile_photo')) {
            $photoField = ($user instanceof Visitor) ? 'photo_url' : 'profile_photo';
            $fields[$photoField] = $request->file('profile_photo')->store('profile-photos', 'public');
        }

        $user->update($fields);

        $actorType = Auth::guard('web')->check() ? 'users' : 'visitors';
        AuditLogger::log('profile_update', $actorType, $user->id, $before, $user->fresh()->toArray());

        return back()->with('success', 'Profile updated successfully.');
    }

    /**
     * Show the representative registration page for an approved company/group visit.
     */
    public function showRepresentativeRegistration($visitRequestId)
    {
        $visitRequest = \App\Models\VisitRequest::with(['host.department', 'department', 'groupMembers', 'visitor'])
            ->where('id', $visitRequestId)
            ->where('visit_type', 'company')
            ->where('status', 'approved')
            ->first();

        if (!$visitRequest) {
            return Inertia::render('Public/RegisterRepresentative', [
                'error' => 'Visit request not found or not eligible for representative registration.',
                'visitRequest' => null,
            ]);
        }

        return Inertia::render('Public/RegisterRepresentative', [
            'visitRequest' => $visitRequest,
            'error' => null,
        ]);
    }

    /**
     * Handle representative registration for an approved company/group visit.
     * After registration, automatically links the representative to all matching
     * approved company/group requests via Email, National ID/Passport Number, or Phone Number.
     */
    public function registerRepresentative(Request $request)
    {
        $lockdownActive = \Illuminate\Support\Facades\DB::table('cache')->where('key', 'emergency_lockdown')->first();
        if ($lockdownActive && $lockdownActive->value == 'true') {
            return back()->withErrors(['email' => 'System is in EMERGENCY LOCKDOWN. New registrations are suspended.'])->withInput();
        }

        $validator = Validator::make($request->all(), [
            'visit_request_id' => 'required|exists:visit_requests,id',
            'email'            => 'required|email',
            'phone'            => 'required|string|max:30|regex:/^\+?[0-9][0-9\s\-\.\(\)]{7,16}$/',
            'password'         => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        $visitRequest = \App\Models\VisitRequest::with('groupMembers')
            ->where('id', $request->visit_request_id)
            ->where('visit_type', 'company')
            ->where('status', 'approved')
            ->first();

        if (!$visitRequest) {
            return back()->withErrors(['email' => 'This visit request is not eligible for representative registration.'])->withInput();
        }

        // Match the representative by email to the visitor linked to this visit request
        $visitor = \App\Models\Visitor::where('id', $visitRequest->visitor_id)->first();

        if (!$visitor) {
            return back()->withErrors(['email' => 'No matching visitor record found for this visit request.'])->withInput();
        }

        // Verify the email matches the visitor record
        if (strtolower($request->email) !== strtolower($visitor->email)) {
            return back()->withErrors(['email' => 'The email address does not match the representative on file for this visit request.'])->withInput();
        }

        // Check if already has a real password (fully registered)
        if ($visitor->password && !Hash::is('password', $visitor->password)) {
            // Already registered — just log them in, but still try linking
            Auth::guard('visitor')->login($visitor);
            AuditLogger::log('representative_login', 'visitors', $visitor->id);
            $request->session()->regenerate();

            // Link to any additional matching approved company/group requests
            self::linkRepresentativeToGroupRequests($visitor);

            return Inertia::location(route('visitor.dashboard'));
        }

        // Upgrade the auto-created account with the real password
        $name = trim(($visitor->first_name ?? '') . ' ' . ($visitor->last_name ?? ''));
        if (empty($name)) {
            $name = $visitor->name ?? $request->input('name', '');
        }

        $visitor->update([
            'name'     => $name ?: $visitor->name,
            'phone'    => $request->phone,
            'password' => $request->password,
        ]);

        Auth::guard('visitor')->login($visitor);
        AuditLogger::log('representative_registration', 'visitors', $visitor->id);
        $request->session()->regenerate();

        // Link to all matching approved company/group requests
        self::linkRepresentativeToGroupRequests($visitor);

        // Ensure QR tokens exist for all group members of the primary visit
        if ($visitRequest->groupMembers->count() > 0) {
            $hasMissingTokens = $visitRequest->groupMembers->contains('qr_token', null);
            if ($hasMissingTokens) {
                try {
                    \App\Services\QRTokenService::generateGroupMemberTokens($visitRequest);
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::error("QR token generation during representative registration failed for visit #{$visitRequest->id}: " . $e->getMessage());
                }
            }
        }

        // Generate individual QR Codes and Visitor Passes for representative and all group members
        self::generatePassesForRepresentativeGroup($visitRequest, $visitor);

        return Inertia::location(route('visitor.dashboard'));
    }

    /**
     * Link a representative visitor account to all matching approved company/group visit requests.
     * Matches by Email, National ID/Passport Number (id_document_no), or Phone Number.
     * Does NOT create system accounts for group members — they remain unauthenticated entries.
     */
    private static function linkRepresentativeToGroupRequests(Visitor $visitor): void
    {
        if (!$visitor) return;

        // Find all approved company/group requests where this visitor could be the representative
        // but is not yet linked (visitor_id doesn't match)
        $matchingRequests = \App\Models\VisitRequest::where('visit_type', 'company')
            ->where('status', 'approved')
            ->where('visitor_id', '!=', $visitor->id)
            ->where(function ($query) use ($visitor) {
                // Match by email on company_email field
                $query->where('company_email', $visitor->email)
                    // Match by email on the linked visitor's email
                    ->orWhereHas('visitor', function ($q) use ($visitor) {
                        $q->where('email', $visitor->email);
                    })
                    // Match by phone on company_phone field
                    ->orWhere('company_phone', $visitor->phone)
                    // Match by phone on the linked visitor's phone
                    ->orWhereHas('visitor', function ($q) use ($visitor) {
                        $q->where('phone', $visitor->phone);
                    });
            })
            ->get();

        $linkedCount = 0;

        foreach ($matchingRequests as $request) {
            // Additional check: match by ID document number if available
            // (check the original visitor's id_document_no against the request's linked visitor)
            if ($request->visitor_id) {
                $originalVisitor = Visitor::find($request->visitor_id);
                if ($originalVisitor && $originalVisitor->id_document_no === $visitor->id_document_no) {
                    // ID document matches — re-link this request to the representative
                    $request->update(['visitor_id' => $visitor->id]);
                    $linkedCount++;

                    \Illuminate\Support\Facades\Log::info("[RepresentativeLink] Re-linked visit #{$request->id} to representative #{$visitor->id} (ID document match)");

                    // Ensure QR tokens exist for this request's group members
                    $groupMembers = $request->groupMembers;
                    if ($groupMembers->count() > 0) {
                        $hasMissing = $groupMembers->contains('qr_token', null);
                        if ($hasMissing) {
                            try {
                                \App\Services\QRTokenService::generateGroupMemberTokens($request);
                            } catch (\Exception $e) {
                                \Illuminate\Support\Facades\Log::error("QR token generation during representative linking failed for visit #{$request->id}: " . $e->getMessage());
                            }
                        }
                    }

                    continue;
                }
            }

            // Check if company_email or company_phone matches
            $emailMatch = strcasecmp($visitor->email, $request->company_email ?? '') === 0;
            $phoneMatch = $visitor->phone && $visitor->phone === ($request->company_phone ?? '');

            if ($emailMatch || $phoneMatch) {
                $request->update(['visitor_id' => $visitor->id]);
                $linkedCount++;

                $matchType = $emailMatch ? 'email' : 'phone';
                \Illuminate\Support\Facades\Log::info("[RepresentativeLink] Re-linked visit #{$request->id} to representative #{$visitor->id} ({$matchType} match)");

                // Ensure QR tokens exist for this request's group members
                $groupMembers = $request->groupMembers;
                if ($groupMembers->count() > 0) {
                    $hasMissing = $groupMembers->contains('qr_token', null);
                    if ($hasMissing) {
                        try {
                            \App\Services\QRTokenService::generateGroupMemberTokens($request);
                        } catch (\Exception $e) {
                            \Illuminate\Support\Facades\Log::error("QR token generation during representative linking failed for visit #{$request->id}: " . $e->getMessage());
                        }
                    }
                }
            }
        }

        if ($linkedCount > 0) {
            \Illuminate\Support\Facades\Log::info("[RepresentativeLink] Linked {$linkedCount} additional company/group request(s) to representative #{$visitor->id} ({$visitor->name})");
        }
    }

    /**
     * Generate individual QR Codes and Visitor Passes for the representative and every group member.
     * Sends notifications (email/SMS) to each group member and copies to the representative.
     */
    private static function generatePassesForRepresentativeGroup(\App\Models\VisitRequest $visitRequest, Visitor $visitor): void
    {
        // Generate QR badge for representative
        if ($visitRequest->qrToken) {
            try {
                $existingBadge = \App\Models\VisitorBadge::where('visit_request_id', $visitRequest->id)
                    ->where('visitor_name', $visitor->name)->first();
                if (!$existingBadge) {
                    \App\Models\VisitorBadge::create([
                        'visit_request_id'   => $visitRequest->id,
                        'visitor_name'       => $visitor->name ?? 'Representative',
                        'visitor_id_document'=> $visitor->id_document_no ?? '',
                        'visitor_photo_url'  => $visitor->photo_url ?? null,
                        'host_name'          => $visitRequest->host->name ?? '',
                        'department'         => $visitRequest->department->name ?? ($visitRequest->host->department->name ?? ''),
                        'purpose'            => $visitRequest->purpose ?? '',
                        'scheduled_time'     => $visitRequest->requested_time ? $visitRequest->requested_time->format('M d, Y H:i') : '',
                        'duration'           => $visitRequest->duration . ' min',
                        'priority'           => $visitRequest->priority ?? 'normal',
                        'is_vip'             => $visitRequest->is_vip ?? false,
                        'qr_token'           => $visitRequest->qrToken->token_value ?? '',
                        'issued_at'          => now(),
                    ]);
                }
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error("Representative badge creation failed for visit #{$visitRequest->id}: " . $e->getMessage());
            }
        }

        // Generate passes for each group member
        foreach ($visitRequest->groupMembers as $member) {
            // Create VisitorBadge record for each member
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
                \Illuminate\Support\Facades\Log::error("Group member badge creation failed for visit #{$visitRequest->id}, member {$member->name}: " . $e->getMessage());
            }

            // Notify each group member individually
            if ($member->email) {
                $memberToken = $member->qr_token ?? 'Pending — please check with the representative.';
                $memberNotification = "Dear {$member->name},\n\n";
                $memberNotification .= "A visit has been arranged for you by company " . ($visitRequest->company_name ?? 'your organization') . ".\n\n";
                $memberNotification .= "Visit Details:\n";
                $memberNotification .= "  Date: " . $visitRequest->requested_time->format('M d, Y H:i') . "\n";
                $memberNotification .= "  Host: " . ($visitRequest->host->name ?? 'TBD') . "\n";
                $memberNotification .= "  Purpose: {$visitRequest->purpose}\n\n";
                $memberNotification .= "Your personal entry QR token is: {$memberToken}\n\n";
                $memberNotification .= "Please present this QR code at the security gate on the day of your visit.";

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
                        \Illuminate\Support\Facades\Log::error("QR badge attachment failed for member {$member->name}: " . $e->getMessage());
                    }
                }

                $fakeVisitor = new Visitor([
                    'name' => $member->name, 'email' => $member->email, 'phone' => $member->phone,
                ]);
                NotificationService::send('QR Code Generated', $fakeVisitor, $memberNotification, $memberAttachments);
            }
        }

        // Send copy of all notifications to the representative
        if ($visitor->email) {
            $repNotification = "Dear {$visitor->name},\n\n";
            $repNotification .= "Your company/group visit to " . ($visitRequest->company_name ?? 'your organization') . " has been confirmed.\n\n";
            $repNotification .= "Visit Details:\n";
            $repNotification .= "  Date: " . $visitRequest->requested_time->format('M d, Y H:i') . "\n";
            $repNotification .= "  Host: " . ($visitRequest->host->name ?? 'TBD') . "\n";
            $repNotification .= "  Purpose: {$visitRequest->purpose}\n";
            $repNotification .= "  Group Members: " . $visitRequest->groupMembers->count() . "\n\n";
            $repNotification .= "Your QR token: " . ($visitRequest->qrToken->token_value ?? 'N/A') . "\n\n";
            $repNotification .= "Individual QR passes have been sent to each group member via email.\n";
            $repNotification .= "You can download all passes from your visitor dashboard.";

            NotificationService::send('Company Visit Confirmed', $visitor, $repNotification);
        }
    }

    /**
     * Helper to route users to correct dashboards.
     */
    private function getDashboardRoute(string $role): string
    {
        return match ($role) {
            'system_admin'   => '/system-admin/dashboard',
            'ceo'            => '/ceo/dashboard',
            'admin'          => '/admin/dashboard',
            'host'           => '/host/dashboard',
            'security_guard' => '/security/dashboard',
            default          => '/',
        };
    }

    /**
     * Whether the submitted email currently holds a valid, unconsumed
     * verification proof (issued by the email-verification step).
     */
    private function emailIsVerified(?string $email): bool
    {
        if (!$email) {
            return false;
        }

        return app(\App\Http\Controllers\EmailVerificationController::class)->hasProofFor($email);
    }

    /**
     * Consume the single-use email-verification proof once the account has
     * actually been created, so the same proof can never be used again.
     */
    private function consumeEmailVerification(?string $email): void
    {
        app(\App\Http\Controllers\EmailVerificationController::class)->consumeProofFor($email);
    }
}
