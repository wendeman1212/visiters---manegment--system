<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class ApplyCompanyTimezone
{
    /** Cache key for the resolved application timezone. */
    public const TIMEZONE_CACHE_KEY = 'vms.company_timezone';

    public function handle(Request $request, Closure $next)
    {
        // The timezone changes very rarely, but the old code ran a
        // Schema::hasTable() probe plus a CompanySetting lookup on EVERY
        // request. Resolve it once and cache it; ManagementController clears
        // this key whenever the settings are saved.
        $tz = Cache::remember(self::TIMEZONE_CACHE_KEY, 3600, function () {
            try {
                $setting = Schema::hasTable('company_settings')
                    ? \App\Models\CompanySetting::first()
                    : null;

                return $setting?->timezone ?: 'Africa/Addis_Ababa';
            } catch (\Throwable $e) {
                return 'Africa/Addis_Ababa';
            }
        });

        try {
            date_default_timezone_set($tz);
            config(['app.timezone' => $tz]);
        } catch (\Exception $e) {
            date_default_timezone_set('Africa/Addis_Ababa');
            config(['app.timezone' => 'Africa/Addis_Ababa']);
        }

        return $next($request);
    }
}
