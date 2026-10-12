<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\CompanyPolicy;
use App\Models\Escalation;
use App\Models\Holiday;
use App\Models\User;
use App\Models\VisitRequest;
use App\Models\WorkingHour;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Centralised SLA engine: deadline calculation (server-side calendar maths),
 * automatic escalation (host -> admin -> CEO), pre-deadline warnings, and
 * dashboard decoration payloads.
 *
 * Every deadline is computed on the server from the company's stored policy.
 * The frontend only ever displays values produced here — it never does SLA
 * maths of its own.
 */
class SlaService
{
    /** @var array<int|null, CompanyPolicy> Memoised per company for this request/run. */
    protected array $policies = [];

    /** @var array<int, array<int, WorkingHour>|null> */
    protected array $hours = [];
    protected bool $hoursLoaded = false;

    /** @var array<int, array<string>> Holiday date strings (Y-m-d) per company. */
    protected array $holidays = [];

    // ═══════════════════════════════════════════════════════
    // Policy helpers
    // ═══════════════════════════════════════════════════════

    public function policy(?int $companyId): CompanyPolicy
    {
        $key = $companyId ?? 0;

        return $this->policies[$key] ??= CompanyPolicy::forCompany($companyId);
    }

    /**
     * Clear memoised policy/holiday data (call when a policy or holiday is
     * updated mid-request so subsequent reads see the new values).
     */
    public function flushMemo(): void
    {
        $this->policies = [];
        $this->hours = [];
        $this->hoursLoaded = false;
        $this->holidays = [];
    }

    // ═══════════════════════════════════════════════════════
    // Deadlines
    // ═══════════════════════════════════════════════════════

    /**
     * Absolute deadline for a clock that started at $startedAt.
     *
     * Wall-clock mode (default): started + minutes, 24/7 — identical to the
     * original behaviour. Business-hours mode (sla_pause_off_hours = true):
     * only working hours / working weekends / non-holidays accumulate.
     */
    public function computeDeadline($startedAt, int $minutes, CompanyPolicy $policy, ?int $companyId): Carbon
    {
        $start = Carbon::parse($startedAt);

        if (!$policy->sla_pause_off_hours) {
            return $start->copy()->addMinutes($minutes);
        }

        return $this->addWorkingMinutes($start, $minutes, $policy, $companyId);
    }

    /** Level currently active for a visit: 1 host, 2 admin, 3 CEO, null none. */
    public function activeLevel(VisitRequest $visit): ?int
    {
        if ($visit->status === 'pending') {
            return 1;
        }

        if ($visit->status === 'escalated' && $visit->escalated_to) {
            $owner = $this->ownerOf($visit);

            if ($owner && $owner->role === 'ceo') {
                return 3;
            }

            return $owner && $owner->role === 'admin' ? 2 : null;
        }

        return null;
    }

    /** Deadline for the active SLA level of a visit (null when none applies). */
    public function deadlineFor(VisitRequest $visit, int $level): ?Carbon
    {
        $policy = $this->policy($visit->company_id);

        if ($level === 1) {
            if ($visit->status !== 'pending') {
                return null;
            }
            $started = $visit->host_sla_started_at ?? $visit->created_at;
            $minutes = $policy->hostSlaMinutes();
        } elseif ($level === 2) {
            if ($visit->status !== 'escalated') {
                return null;
            }
            $started = $visit->admin_sla_started_at ?? $visit->updated_at ?? $visit->created_at;
            $minutes = $policy->adminSlaMinutes();
        } else {
            return null; // level 3 (CEO) is the top of the chain — no SLA left
        }

        return $this->computeDeadline($started, $minutes, $policy, $visit->company_id);
    }

    /** Signed minutes until the deadline (negative when already breached). */
    public function remainingMinutes(?Carbon $deadline): ?int
    {
        if (!$deadline) {
            return null;
        }

        return (int) floor(($deadline->getTimestamp() - Carbon::now()->getTimestamp()) / 60);
    }

    // ═══════════════════════════════════════════════════════
    // Working calendar (only used when sla_pause_off_hours is on)
    // ═══════════════════════════════════════════════════════

    /** Add $minutes of working time to $start according to the policy calendar. */
    protected function addWorkingMinutes(Carbon $start, int $minutes, CompanyPolicy $policy, ?int $companyId): Carbon
    {
        $remaining = $minutes;
        $cursor    = $start->copy();
        $guard     = 0;

        while ($remaining > 0 && $guard++ < 3660) {
            if (!$this->isWorkingDay($cursor, $policy, $companyId)) {
                $cursor = $cursor->copy()->startOfDay()->addDay();
                continue;
            }

            [$open, $close] = $this->workingWindow($cursor, $companyId);

            if ($cursor->lt($open)) {
                $cursor = $open->copy();
            }

            if ($cursor->gte($close)) {
                $cursor = $cursor->copy()->startOfDay()->addDay();
                continue;
            }

            $available = ($close->getTimestamp() - $cursor->getTimestamp()) / 60;

            if ($remaining <= $available) {
                return $cursor->copy()->addMinutes($remaining);
            }

            $remaining -= (int) floor($available);
            $cursor = $cursor->copy()->startOfDay()->addDay();
        }

        // Calendar exhausted (bad working-hours data) — fall back to wall clock.
        return $start->copy()->addMinutes($minutes);
    }

    public function isWorkingDay(Carbon $day, CompanyPolicy $policy, ?int $companyId): bool
    {
        $dayOfWeek = (int) $day->format('w'); // 0=Sun .. 6=Sat

        if ($policy->sla_count_weekends === false && in_array($dayOfWeek, [0, 6], true)) {
            return false;
        }

        if ($policy->sla_count_holidays === false
            && in_array($day->format('Y-m-d'), $this->holidayDates($companyId), true)) {
            return false;
        }

        $hours = $this->workingHoursFor($dayOfWeek);
        if (!$hours) {
            return true; // no row configured -> treat as a full working day
        }

        return (bool) $hours->is_working_day;
    }

    /** Working window [open, close] as Carbon instances on $day. */
    protected function workingWindow(Carbon $day, ?int $companyId): array
    {
        $open  = $day->copy()->startOfDay();
        $close = $day->copy()->endOfDay()->addSecond(); // start of next day

        $hours = $this->workingHoursFor((int) $day->format('w'));
        if ($hours) {
            $openTime  = $hours->open_time ?: '00:00:00';
            $closeTime = $hours->close_time ?: '00:00:00';

            $openCandidate  = $day->copy()->startOfDay()->addSeconds($this->timeToSeconds($openTime));
            $closeCandidate = $day->copy()->startOfDay()->addSeconds($this->timeToSeconds($closeTime));

            // Only honour the row when it describes a sane same-day window;
            // otherwise keep the full-day fallback (overnight rows etc.).
            if ($closeCandidate->gt($openCandidate)) {
                $open  = $openCandidate;
                $close = $closeCandidate;
            }
        }

        return [$open, $close];
    }

    protected function timeToSeconds(string $time): int
    {
        $parts = array_map('intval', explode(':', $time));

        return ($parts[0] ?? 0) * 3600 + ($parts[1] ?? 0) * 60 + ($parts[2] ?? 0);
    }

    protected function workingHoursFor(int $dayOfWeek): ?WorkingHour
    {
        if (!$this->hoursLoaded) {
            $this->hours = WorkingHour::all()->keyBy('day_of_week')->all();
            $this->hoursLoaded = true;
        }

        return $this->hours[$dayOfWeek] ?? null;
    }

    /** @return array<string> Y-m-d holiday dates for a company (global + own). */
    protected function holidayDates(?int $companyId): array
    {
        return $this->holidays[$companyId ?? 0] ??= Holiday::datesForCompany($companyId);
    }

    // ═══════════════════════════════════════════════════════
    // Clock transitions
    // ═══════════════════════════════════════════════════════

    /** A (re)started host round: fresh clock, no stale warning. */
    public function restartHostSla(VisitRequest $visit): void
    {
        $visit->forceFill([
            'host_sla_started_at' => now(),
            'host_sla_warned_at'  => null,
            'admin_sla_started_at'=> null,
            'admin_sla_warned_at' => null,
        ])->save();
    }

    /** The visit is now owned by an admin: start the level-2 clock. */
    public function startAdminSla(VisitRequest $visit): void
    {
        $visit->forceFill([
            'admin_sla_started_at' => now(),
            'admin_sla_warned_at'  => null,
        ])->save();
    }

    /** Responsibility left the admin level — freeze/stop the level-2 clock. */
    public function stopAdminSla(VisitRequest $visit): void
    {
        $visit->forceFill([
            'admin_sla_started_at' => null,
            'admin_sla_warned_at'  => null,
        ])->save();
    }

    // ═══════════════════════════════════════════════════════
    // Orchestrator — called every minute by the scheduler
    // ═══════════════════════════════════════════════════════

    /**
     * One full SLA pass: expire stale visits, warn owners before the
     * deadline, escalate host timeouts to the admin and admin timeouts to
     * the CEO. Idempotent — a visit can never be escalated twice for the
     * same level, warned twice, or notified twice.
     *
     * Returns the number of escalations performed.
     */
    public function process(): int
    {
        $lock = Cache::lock('sla:process', 30);

        if (!$lock->get()) {
            return 0; // another runner is mid-pass
        }

        try {
            $now = Carbon::now();

            $this->autoExpire($now);
            $this->processWarnings($now);

            return $this->processHostTimeouts($now)
                 + $this->processAdminTimeouts($now);
        } finally {
            $lock->release();
        }
    }

    /** Auto-expire approved/pending visits whose scheduled time has passed. */
    protected function autoExpire(Carbon $now): void
    {
        $driver = \Illuminate\Support\Facades\DB::getDriverName();
        $expiredRequests = VisitRequest::whereIn('status', ['pending', 'approved'])
            ->whereRaw($driver === 'sqlite'
                ? "datetime(requested_time, '+' || duration || ' minutes') < datetime('now')"
                : 'DATE_ADD(requested_time, INTERVAL duration MINUTE) < NOW()')
            ->get();

        foreach ($expiredRequests as $req) {
            $oldState = $req->toArray();
            $req->status = 'rejected';
            $req->comments = ($req->comments ? $req->comments . ' | ' : '') . 'Auto-expired: scheduled visit time has passed.';
            $req->save();

            if ($req->qrToken) {
                $req->qrToken->update(['status' => 'expired']);
            }

            AuditLogger::log('auto_expired_visit', 'visit_requests', $req->id, $oldState, $req->toArray());
        }
    }

    /** Notify owners when the deadline is close (once per level per visit). */
    protected function processWarnings(Carbon $now): void
    {
        $visits = VisitRequest::whereIn('status', ['pending', 'escalated'])
            ->whereNotNull('company_id')
            ->with('host', 'escalatedTo')
            ->get();

        foreach ($visits->groupBy('company_id') as $companyId => $group) {
            $policy = $this->policy((int) $companyId);
            $warningMinutes = (int) $policy->sla_warning_minutes;

            if ($warningMinutes <= 0) {
                continue;
            }

            foreach ($group as $visit) {
                $level = $this->activeLevel($visit);
                if ($level !== 1 && $level !== 2) {
                    continue;
                }

                $deadline = $this->deadlineFor($visit, $level);
                if (!$deadline) {
                    continue;
                }

                $remaining = $this->remainingMinutes($deadline);
                if ($remaining === null || $remaining > $warningMinutes || $remaining < 0) {
                    continue;
                }

                $warnedColumn = $level === 1 ? 'host_sla_warned_at' : 'admin_sla_warned_at';
                if ($visit->{$warnedColumn}) {
                    continue; // already warned for this level
                }

                $owner = $level === 1 ? $visit->host : $this->ownerOf($visit);
                if (!$owner) {
                    continue;
                }

                $visitorName = $visit->resolveVisitor()?->name ?? $visit->company_name ?? 'Visitor';
                $stage = $level === 1 ? 'your approval' : 'admin approval';

                NotificationService::sendWithChannels(
                    'SLA Warning',
                    $owner,
                    "Visit #{$visit->id} ({$visitorName}) will breach its SLA in {$remaining} minute(s) — awaiting {$stage}. Deadline: {$deadline->format('M d, Y H:i')}.",
                    $policy->notifyChannels(),
                    null, true, $visit->company_id
                );

                $visit->forceFill([$warnedColumn => now()])->save();

                AuditLogger::log('sla_warning_sent', 'visit_requests', $visit->id, null, [
                    'level'       => $level,
                    'deadline_at' => $deadline->toDateTimeString(),
                    'recipient'   => $owner->id,
                ]);
            }
        }
    }

    /** Level 1: pending host requests past their deadline -> admin. */
    protected function processHostTimeouts(Carbon $now): int
    {
        $count = 0;

        $visits = VisitRequest::where('status', 'pending')
            ->whereNull('escalated_to')
            ->whereNotNull('company_id')
            ->with('host')
            ->get();

        foreach ($visits->groupBy('company_id') as $companyId => $group) {
            $policy = $this->policy((int) $companyId);

            if (!$policy->auto_escalation_enabled) {
                continue;
            }

            $minutes = $policy->hostSlaMinutes();

            foreach ($group as $visit) {
                $deadline = $this->deadlineFor($visit, 1);
                if (!$deadline || $now->lt($deadline)) {
                    continue;
                }

                try {
                    if ($this->escalateToAdmin($visit, $policy, $minutes, $deadline)) {
                        $count++;
                    }
                } catch (\Exception $e) {
                    Log::warning("SLA auto-escalation failed for request #{$visit->id}: " . $e->getMessage());
                }
            }
        }

        return $count;
    }

    /** Level 2: escalated visits idle with an admin -> CEO. */
    protected function processAdminTimeouts(Carbon $now): int
    {
        $count = 0;

        $visits = VisitRequest::where('status', 'escalated')
            ->whereNotNull('company_id')
            ->whereHas('escalatedTo', fn ($q) => $q->where('role', 'admin'))
            ->get();

        foreach ($visits->groupBy('company_id') as $companyId => $group) {
            $policy = $this->policy((int) $companyId);

            if (!$policy->auto_escalation_enabled) {
                continue;
            }

            $minutes = $policy->adminSlaMinutes();

            foreach ($group as $visit) {
                $deadline = $this->deadlineFor($visit, 2);
                if (!$deadline || $now->lt($deadline)) {
                    continue;
                }

                try {
                    if ($this->escalateToCeo($visit, $policy, $minutes, $deadline)) {
                        $count++;
                    }
                } catch (\Exception $e) {
                    Log::warning("SLA admin-to-CEO escalation failed for request #{$visit->id}: " . $e->getMessage());
                }
            }
        }

        return $count;
    }

    /** Escalate one visit host -> admin. Returns true when a row was written. */
    protected function escalateToAdmin(VisitRequest $visit, CompanyPolicy $policy, int $minutes, Carbon $deadline): bool
    {
        $reason = "Auto-escalation due to SLA host timeout ({$minutes} mins).";

        try {
            ApprovalService::escalate($visit, $visit->host_id, $reason, [
                'trigger_type' => Escalation::TRIGGER_SLA,
                'level'        => 1,
                'sla_minutes'  => $minutes,
                'deadline_at'  => $deadline,
            ]);
        } catch (\Exception $e) {
            Log::warning("SLA auto-escalation failed for request #{$visit->id}: " . $e->getMessage());

            return false;
        }

        $this->startAdminSla($visit);

        $admin = User::withoutGlobalScopes()->find($visit->escalated_to);
        if (!$admin) {
            return true; // escalated (row written) but nobody to notify
        }

        $visitorName = $visit->resolveVisitor()?->name ?? $visit->company_name ?? 'Visitor';
        $notified = NotificationService::sendWithChannels(
            'Escalated Visit Assigned',
            $admin,
            "Visit #{$visit->id} ({$visitorName}) was automatically escalated from its host after the {$minutes}-minute SLA expired. You are now the responsible person. Deadline recorded: {$deadline->format('M d, Y H:i')}.",
            $policy->notifyChannels(),
            null, true, $visit->company_id
        );

        if ($notified) {
            $visit->escalations()->latest('id')->first()?->update(['notified_at' => now()]);
        }

        return true;
    }

    /** Escalate one visit admin -> CEO. Returns true when a row was written. */
    protected function escalateToCeo(VisitRequest $visit, CompanyPolicy $policy, int $minutes, Carbon $deadline): bool
    {
        $ceo = User::withoutGlobalScopes()
            ->where('role', 'ceo')
            ->where('status', 'active')
            ->where('company_id', $visit->company_id)
            ->first();

        if (!$ceo) {
            // No CEO in the company: keep the admin responsible, but do not
            // spam the audit trail every single minute.
            $recent = AuditLog::where('action', 'sla_admin_overdue')
                ->where('entity', 'visit_requests')
                ->where('entity_id', $visit->id)
                ->where('created_at', '>=', now()->subMinutes(30))
                ->exists();

            if (!$recent) {
                AuditLogger::log('sla_admin_overdue', 'visit_requests', $visit->id, null, $visit->toArray());
            }

            return false;
        }

        $oldState      = $visit->toArray();
        $previousAdminId = $visit->escalated_to;

        $visit->forceFill(['escalated_to' => $ceo->id])->save();

        Escalation::create([
            'visit_request_id' => $visit->id,
            'escalated_by'     => $previousAdminId,
            'escalated_to'     => $ceo->id,
            'reason'           => "Admin SLA timeout ({$minutes} mins). Re-escalated to CEO.",
            'trigger_type'     => Escalation::TRIGGER_SLA,
            'level'            => 2,
            'sla_minutes'      => $minutes,
            'deadline_at'      => $deadline,
        ]);

        $this->stopAdminSla($visit);

        AuditLogger::log('escalation_re_escalated_to_ceo', 'visit_requests', $visit->id, $oldState, $visit->toArray());

        if (in_array('sms', $policy->notifyChannels(), true)) {
            SmsSyncService::syncUserDeferred($ceo, $visit, SmsSyncService::EVENT_ESCALATED_TO_CEO);
        }

        $visitorName = $visit->resolveVisitor()?->name ?? $visit->company_name ?? 'Visitor';
        $notified = NotificationService::sendWithChannels(
            'Escalated Visit Assigned',
            $ceo,
            "Visit #{$visit->id} ({$visitorName}) breached its admin SLA ({$minutes} mins) and has been escalated to you. Deadline recorded: {$deadline->format('M d, Y H:i')}.",
            $policy->notifyChannels(),
            null, true, $visit->company_id
        );

        if ($notified) {
            $visit->escalations()->latest('id')->first()?->update(['notified_at' => now()]);
        }

        return true;
    }

    // ═══════════════════════════════════════════════════════
    // Dashboard payloads
    // ═══════════════════════════════════════════════════════

    /**
     * SLA block for one visit — every value the Host/Admin/CEO pages display.
     * Purely informational: viewing never changes any SLA state.
     */
    public function decorate(VisitRequest $visit): array
    {
        $policy  = $this->policy($visit->company_id);
        $level   = $this->activeLevel($visit);
        $deadline = $level ? $this->deadlineFor($visit, $level) : null;
        $remaining = $this->remainingMinutes($deadline);

        $status = 'stopped';
        if ($level === 3) {
            $status = 'final';
            $deadline = null;
            $remaining = null;
        } elseif ($level) {
            if ($deadline && $deadline->lt(Carbon::now())) {
                $status = 'overdue';
            } elseif ($deadline && $remaining !== null && $remaining <= (int) $policy->sla_warning_minutes && (int) $policy->sla_warning_minutes > 0) {
                $status = 'warning';
            } else {
                $status = 'ok';
            }
        }

        $startedAt = null;
        if ($level === 1) {
            $startedAt = $visit->host_sla_started_at ?? $visit->created_at;
        } elseif ($level === 2) {
            $startedAt = $visit->admin_sla_started_at ?? $visit->updated_at ?? $visit->created_at;
        }

        $stage = match ($level) {
            1       => 'host',
            2       => 'admin',
            3       => 'ceo',
            default => 'none',
        };

        return [
            'sla' => [
                'active'                => (bool) $level,
                'level'                 => $level,
                'stage'                 => $stage,
                'minutes'               => $level === 1 ? $policy->hostSlaMinutes() : ($level === 2 ? $policy->adminSlaMinutes() : null),
                'started_at'            => $startedAt ? Carbon::parse($startedAt)->toDateTimeString() : null,
                'deadline_at'           => $deadline ? $deadline->toDateTimeString() : null,
                'remaining_minutes'     => $remaining,
                'status'                => $status,
                'warning_minutes'       => (int) $policy->sla_warning_minutes,
                'warned_at'             => ($level === 1 ? $visit->host_sla_warned_at : ($level === 2 ? $visit->admin_sla_warned_at : null))?->toDateTimeString(),
                'auto_escalation_enabled' => (bool) $policy->auto_escalation_enabled,
                'uses_business_hours'   => (bool) $policy->sla_pause_off_hours,
            ],
            'escalation_history' => $this->historyFor($visit),
        ];
    }

    /** Merge decorate() into an array payload (e.g. host dashboard rows). */
    public function decorateArray(VisitRequest $visit, array $payload): array
    {
        return $payload + $this->decorate($visit);
    }

    /** Map a collection of visit models into payload arrays with SLA data. */
    public function decorateCollection($visits)
    {
        return $visits->map(fn ($visit) => $visit->toArray() + $this->decorate($visit));
    }

    /** Full escalation history rows for the UI (complete audit chain). */
    public function historyFor(VisitRequest $visit): array
    {
        $escalations = $visit->relationLoaded('escalations')
            ? $visit->escalations
            : $visit->escalations()->with('escalatedBy', 'escalatedTo')->get();

        return $escalations->sortBy('id')->map(fn ($e) => [
            'id'            => $e->id,
            'trigger_type'  => $e->trigger_type ?? 'manual',
            'level'         => $e->level,
            'escalated_by'  => $e->escalated_by,
            'escalated_by_name' => $e->escalatedBy?->name ?? 'System',
            'escalated_to'  => $e->escalated_to,
            'escalated_to_name' => $e->escalatedTo?->name ?? '',
            'escalated_to_role' => $e->escalatedTo?->role ?? '',
            'reason'        => $e->reason,
            'sla_minutes'   => $e->sla_minutes,
            'deadline_at'   => $e->deadline_at?->toDateTimeString(),
            'notified_at'   => $e->notified_at?->toDateTimeString(),
            'resolved_at'   => $e->resolved_at?->toDateTimeString(),
            'created_at'    => $e->created_at?->toDateTimeString(),
        ])->values()->all();
    }

    protected function ownerOf(VisitRequest $visit): ?User
    {
        if (!$visit->escalated_to) {
            return null;
        }

        if ($visit->relationLoaded('escalatedTo') && $visit->escalatedTo) {
            return $visit->escalatedTo;
        }

        return User::withoutGlobalScopes()->find($visit->escalated_to);
    }
}
