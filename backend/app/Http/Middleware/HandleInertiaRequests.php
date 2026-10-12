<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        // ── CEO Broadcasts: DB-driven announcements scoped to the viewer's
        //    company/branch. Company-wide (branch_id null) reaches every branch;
        //    branch-scoped announcements reach only that branch. Nothing leaks
        //    across companies.
        $ceoBroadcasts = [];
        $webUser     = $request->user('web');
        $visitorUser = $request->user('visitor');
        $isCeo       = $webUser && $webUser->role === 'ceo';

        // Broadcasts reach logged-in company users EXCEPT the CEO sending them;
        // visitors and system admins (no company/branch) never receive them.
        if ($webUser && !$isCeo && $webUser->company_id) {
            $uCompany = (int) $webUser->company_id;
            $uBranch  = $webUser->branch_id ? (int) $webUser->branch_id : null;

            // Announcements are polled every 30s by the frontend, so a short
            // cache removes a DB round-trip from essentially every Inertia
            // navigation without meaningfully delaying new broadcasts.
            $ceoBroadcasts = Cache::remember(
                "vms.broadcasts.{$uCompany}." . ($uBranch ?? 'all'),
                20,
                function () use ($uCompany, $uBranch) {
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

                    return $rows->map(fn($b) => [
                        'id'       => 'ann_' . $b->id,
                        'message'  => $b->message,
                        'priority' => $b->priority,
                        'ts'       => $b->created_at ? $b->created_at->timestamp : now()->timestamp,
                        'time'     => $b->created_at ? $b->created_at->toDateTimeString() : now()->toDateTimeString(),
                    ])->values()->all();
                }
            );
        }

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $webUser ? [
                    'id'                => $webUser->id,
                    'name'              => $webUser->name,
                    'email'             => $webUser->email,
                    'phone'             => $webUser->phone,
                    'role'              => $webUser->role,
                    'department_id'     => $webUser->department_id,
                    'status'            => $webUser->status,
                    'company_id'        => $webUser->company_id,
                    'company_name'      => $webUser->company?->company_name,
                    'two_factor_secret' => $webUser->two_factor_secret ? true : false,
                    'profile_photo'     => $webUser->profile_photo,
                ] : null,
                'visitor' => $visitorUser ? [
                    'id'              => $visitorUser->id,
                    'name'            => $visitorUser->name,
                    'first_name'      => $visitorUser->first_name,
                    'last_name'       => $visitorUser->last_name,
                    'username'        => $visitorUser->username,
                    'email'           => $visitorUser->email,
                    'phone'           => $visitorUser->phone,
                    'id_document_no'  => $visitorUser->id_document_no,
                    'nationality'     => $visitorUser->nationality,
                    'age'             => $visitorUser->age,
                    'sex'             => $visitorUser->sex,
                    'company_name'    => $visitorUser->company_name,
                    'company_position'=> $visitorUser->company_position,
                    'profile_complete'=> $visitorUser->profile_complete,
                    'photo_url'       => $visitorUser->photo_url,
                ] : null,
            ],
            'flash' => [
                'success'     => $request->session()->get('success'),
                'error'       => $request->session()->get('error'),
                'require_2fa' => $request->session()->get('require_2fa'),
                'email_input' => $request->session()->get('email_input'),
                'cooldown_remaining' => $request->session()->get('cooldown_remaining'),
            ],
            'app_timezone' => date_default_timezone_get(),
            'ceo_broadcasts' => $ceoBroadcasts,
        ];
    }
}
