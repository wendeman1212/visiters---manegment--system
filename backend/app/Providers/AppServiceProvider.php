<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use App\Listeners\LinkRepresentativeToGroupVisit;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Resolve the SLA engine once per request so its per-company policy /
        // working-hour / holiday memoisation is shared across every row instead
        // of re-querying company_policies for each visit on the dashboards.
        $this->app->singleton(\App\Services\SlaService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Relation::enforceMorphMap([
            'user'    => \App\Models\User::class,
            'visitor' => \App\Models\Visitor::class,
        ]);

        Event::listen(Registered::class, LinkRepresentativeToGroupVisit::class);

        Gate::policy(\App\Models\Company::class, \App\Policies\CompanyPolicy::class);
        Gate::policy(\App\Models\Department::class, \App\Policies\CompanyPolicy::class);
        Gate::policy(\App\Models\Location::class, \App\Policies\CompanyPolicy::class);
        Gate::policy(\App\Models\Visitor::class, \App\Policies\CompanyPolicy::class);
        Gate::policy(\App\Models\VisitRequest::class, \App\Policies\CompanyPolicy::class);

        $this->registerAutoSlaTrigger();
    }

    /**
     * Run the SLA escalation pass automatically after web responses.
     *
     * Deployments without a running cron/scheduler (for example XAMPP on
     * Windows) would otherwise never execute `vms:process-sla`. This mirrors
     * that command — the host -> admin -> CEO auto-escalation — and is
     * throttled to at most once per minute so it adds no noticeable overhead.
     */
    protected function registerAutoSlaTrigger(): void
    {
        $this->app->terminating(function () {
            // Console runs (the scheduler and the command itself) already own
            // this; tests assert exact SLA state and must not be perturbed.
            if ($this->app->runningInConsole() || $this->app->runningUnitTests()) {
                return;
            }

            // Atomic, self-expiring throttle shared across web workers.
            if (!Cache::add('vms:sla-auto-run', true, 55)) {
                return;
            }

            try {
                $this->app->make(\App\Services\SlaService::class)->process();
            } catch (\Throwable $e) {
                Log::warning('Automatic SLA escalation failed: ' . $e->getMessage());
            }
        });
    }
}
