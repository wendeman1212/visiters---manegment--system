<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyPolicy;
use App\Models\Department;
use App\Models\Escalation;
use App\Models\Holiday;
use App\Models\User;
use App\Models\VisitRequest;
use App\Models\Visitor;
use App\Models\WorkingHour;
use App\Services\ApprovalService;
use App\Services\SlaService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SlaEscalationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeCompany(string $name): Company
    {
        return Company::create(['company_name' => $name, 'status' => 'active']);
    }

    private function makeUser(Company $company, string $role, string $email): User
    {
        return User::create([
            'name'         => ucfirst($role) . ' of ' . $company->company_name,
            'email'        => $email,
            'password'     => 'password',
            'role'         => $role,
            'company_id'   => $company->company_id,
            'department_id'=> null,
            'status'       => 'active',
        ]);
    }

    private function makeVisitor(Company $company, string $suffix): Visitor
    {
        return Visitor::create([
            'name'           => 'Visitor ' . $suffix,
            'email'          => 'visitor-' . $suffix . '@test.test',
            'id_document_no' => 'ID-' . strtoupper(uniqid($suffix)),
            'phone'          => '555' . random_int(1000, 9999),
            'password'       => 'password',
            'company_id'     => $company->company_id,
        ]);
    }

    private function makePendingVisit(Company $company, User $host, Carbon $startedAt): VisitRequest
    {
        $suffix = $company->company_name . '-' . uniqid();

        $visit = VisitRequest::create([
            'visitor_id'     => $this->makeVisitor($company, $suffix)->id,
            'host_id'        => $host->id,
            'purpose'        => 'SLA escalation test visit',
            'visit_type'     => 'solo',
            'requested_time' => Carbon::now()->addDay()->startOfHour(),
            'duration'       => 60,
            'status'         => 'pending',
            'company_id'     => $company->company_id,
        ]);

        $visit->created_at = $startedAt;
        $visit->updated_at = $startedAt;
        $visit->save();

        return $visit;
    }

    /** A full company with host/admin/ceo and a pending visit started $agoMinutes ago. */
    private function seedScenario(int $slaMinutes, int $agoMinutes, array $policyOverrides = []): array
    {
        $company = $this->makeCompany('SLA Chain ' . uniqid());
        $dept    = Department::create(['name' => 'Ops', 'company_id' => $company->company_id]);
        $host    = $this->makeUser($company, 'host', 'host-' . uniqid() . '@sla.test');
        $admin   = $this->makeUser($company, 'admin', 'admin-' . uniqid() . '@sla.test');
        $ceo     = $this->makeUser($company, 'ceo', 'ceo-' . uniqid() . '@sla.test');

        CompanyPolicy::create(array_merge([
            'company_id'  => $company->company_id,
            'sla_minutes' => $slaMinutes,
        ], $policyOverrides));

        $visit = $this->makePendingVisit($company, $host, Carbon::now()->subMinutes($agoMinutes));

        return compact('company', 'dept', 'host', 'admin', 'ceo', 'visit');
    }

    // ═══════════════════════════════════════════════════════
    // Level 1: host timeout -> admin
    // ═══════════════════════════════════════════════════════

    public function test_expired_host_sla_auto_escalates_to_admin_with_full_record(): void
    {
        $s = $this->seedScenario(30, 31);

        $count = ApprovalService::processAutoEscalations();

        $this->assertSame(1, $count);

        $fresh = $s['visit']->fresh();
        $this->assertSame('escalated', $fresh->status);
        $this->assertSame($s['admin']->id, $fresh->escalated_to);
        $this->assertSame($s['host']->id, $fresh->host_id); // host_id untouched by SLA
        $this->assertNotNull($fresh->admin_sla_started_at); // level-2 clock started

        $escalation = Escalation::where('visit_request_id', $s['visit']->id)->first();
        $this->assertSame('sla', $escalation->trigger_type);
        $this->assertSame(1, $escalation->level);
        $this->assertSame(30, $escalation->sla_minutes);
        $this->assertNotNull($escalation->deadline_at);
        $this->assertNotNull($escalation->notified_at);
        $this->assertStringContainsString('30', $escalation->reason);

        // The new owner is notified in-app.
        $this->assertDatabaseHas('system_notifications', [
            'recipient_type' => 'user',
            'recipient_id'   => $s['admin']->id,
            'title'          => 'Escalated Visit Assigned',
        ]);

        // Audit trail written.
        $this->assertDatabaseHas('audit_logs', [
            'action'    => 'escalated_visit',
            'entity'    => 'visit_requests',
            'entity_id' => $s['visit']->id,
        ]);
    }

    public function test_repeated_runs_never_duplicate_escalations_or_notifications(): void
    {
        $s = $this->seedScenario(5, 30);

        $this->assertSame(1, ApprovalService::processAutoEscalations());
        $this->assertSame(0, ApprovalService::processAutoEscalations());
        $this->assertSame(0, ApprovalService::processAutoEscalations());

        $this->assertSame(1, Escalation::where('visit_request_id', $s['visit']->id)->count());
        $this->assertSame(1, \App\Models\SystemNotification::where('title', 'Escalated Visit Assigned')
            ->where('recipient_id', $s['admin']->id)->count());
    }

    public function test_visit_within_sla_is_untouched(): void
    {
        $s = $this->seedScenario(60, 5);

        $this->assertSame(0, ApprovalService::processAutoEscalations());

        $this->assertSame('pending', $s['visit']->fresh()->status);
        $this->assertNull($s['visit']->fresh()->escalated_to);
        $this->assertDatabaseCount('escalations', 0);
    }

    public function test_sla_expiry_never_auto_approves_or_issues_qr(): void
    {
        $s = $this->seedScenario(5, 60);

        ApprovalService::processAutoEscalations();

        $fresh = $s['visit']->fresh();
        $this->assertSame('escalated', $fresh->status);
        $this->assertNotSame('approved', $fresh->status);
        $this->assertNull($fresh->approved_at);
        $this->assertDatabaseCount('qr_tokens', 0);
    }

    // ═══════════════════════════════════════════════════════
    // Level 2: admin timeout -> CEO
    // ═══════════════════════════════════════════════════════

    public function test_expired_admin_sla_escalates_to_ceo_and_stops_the_chain(): void
    {
        $s = $this->seedScenario(10, 1);
        $s['visit']->update([
            'status'             => 'escalated',
            'escalated_to'       => $s['admin']->id,
            'admin_sla_started_at' => Carbon::now()->subMinutes(11),
        ]);

        $count = ApprovalService::processAutoEscalations();
        $this->assertSame(1, $count);

        $fresh = $s['visit']->fresh();
        $this->assertSame('escalated', $fresh->status);
        $this->assertSame($s['ceo']->id, $fresh->escalated_to);

        $escalation = Escalation::where('visit_request_id', $s['visit']->id)
            ->where('level', 2)->first();
        $this->assertNotNull($escalation);
        $this->assertSame('sla', $escalation->trigger_type);
        $this->assertSame(10, $escalation->sla_minutes);
        $this->assertStringContainsString('10', $escalation->reason);

        $this->assertDatabaseHas('system_notifications', [
            'recipient_type' => 'user',
            'recipient_id'   => $s['ceo']->id,
            'title'          => 'Escalated Visit Assigned',
        ]);

        // Level 3 (CEO) is final — a second run has nothing left to escalate.
        $this->assertSame(0, ApprovalService::processAutoEscalations());
        $this->assertSame(1, Escalation::where('visit_request_id', $s['visit']->id)->count());
    }

    public function test_admin_within_sla_is_not_re_escalated(): void
    {
        $s = $this->seedScenario(60, 1);
        $s['visit']->update([
            'status'               => 'escalated',
            'escalated_to'         => $s['admin']->id,
            'admin_sla_started_at' => Carbon::now()->subMinutes(3),
        ]);

        $this->assertSame(0, ApprovalService::processAutoEscalations());
        $this->assertSame($s['admin']->id, $s['visit']->fresh()->escalated_to);
        $this->assertDatabaseCount('escalations', 0);
    }

    // ═══════════════════════════════════════════════════════
    // Auto-escalation toggle
    // ═══════════════════════════════════════════════════════

    public function test_disabling_auto_escalation_freezes_the_chain(): void
    {
        $s = $this->seedScenario(5, 60, ['auto_escalation_enabled' => false]);

        $this->assertSame(0, ApprovalService::processAutoEscalations());

        $fresh = $s['visit']->fresh();
        $this->assertSame('pending', $fresh->status);
        $this->assertNull($fresh->escalated_to);
        $this->assertDatabaseCount('escalations', 0);
        $this->assertDatabaseMissing('system_notifications', ['title' => 'Escalated Visit Assigned']);
    }

    public function test_disabling_auto_escalation_stops_admin_to_ceo_too(): void
    {
        $s = $this->seedScenario(5, 1, ['auto_escalation_enabled' => false]);
        $s['visit']->update([
            'status'               => 'escalated',
            'escalated_to'         => $s['admin']->id,
            'admin_sla_started_at' => Carbon::now()->subMinutes(60),
        ]);

        $this->assertSame(0, ApprovalService::processAutoEscalations());
        $this->assertSame($s['admin']->id, $s['visit']->fresh()->escalated_to);
        $this->assertDatabaseCount('escalations', 0);
    }

    // ═══════════════════════════════════════════════════════
    // Warning threshold
    // ═══════════════════════════════════════════════════════

    public function test_owner_gets_exactly_one_warning_before_the_deadline(): void
    {
        $s = $this->seedScenario(30, 26, [
            'sla_warning_minutes'     => 5,
            'auto_escalation_enabled' => false,
        ]);

        ApprovalService::processAutoEscalations();

        $fresh = $s['visit']->fresh();
        $this->assertNotNull($fresh->host_sla_warned_at);
        $this->assertSame('pending', $fresh->status); // warning does not escalate

        $this->assertSame(1, \App\Models\SystemNotification::where('title', 'SLA Warning')
            ->where('recipient_id', $s['host']->id)->count());

        // A second pass must not warn again.
        ApprovalService::processAutoEscalations();
        $this->assertSame(1, \App\Models\SystemNotification::where('title', 'SLA Warning')
            ->where('recipient_id', $s['host']->id)->count());

        $this->assertDatabaseHas('audit_logs', [
            'action'    => 'sla_warning_sent',
            'entity_id' => $s['visit']->id,
        ]);
    }

    public function test_no_warning_is_sent_when_far_from_the_deadline(): void
    {
        $s = $this->seedScenario(60, 5, ['sla_warning_minutes' => 5]);

        ApprovalService::processAutoEscalations();

        $this->assertNull($s['visit']->fresh()->host_sla_warned_at);
        $this->assertDatabaseMissing('system_notifications', ['title' => 'SLA Warning']);
    }

    public function test_admin_owner_gets_the_level_two_warning(): void
    {
        $s = $this->seedScenario(30, 1, [
            'sla_warning_minutes'     => 5,
            'auto_escalation_enabled' => false,
        ]);
        $s['visit']->update([
            'status'               => 'escalated',
            'escalated_to'         => $s['admin']->id,
            'admin_sla_started_at' => Carbon::now()->subMinutes(26),
        ]);

        ApprovalService::processAutoEscalations();

        $this->assertNotNull($s['visit']->fresh()->admin_sla_warned_at);
        $this->assertSame(1, \App\Models\SystemNotification::where('title', 'SLA Warning')
            ->where('recipient_id', $s['admin']->id)->count());
    }

    // ═══════════════════════════════════════════════════════
    // Notification channels (policy toggles)
    // ═══════════════════════════════════════════════════════

    public function test_in_app_channel_toggle_controls_the_notification_record(): void
    {
        $off = $this->seedScenario(5, 30, ['sla_notify_in_app' => false, 'sla_notify_email' => true]);
        ApprovalService::processAutoEscalations();

        $this->assertSame('escalated', $off['visit']->fresh()->status);
        $this->assertDatabaseMissing('system_notifications', [
            'recipient_id' => $off['admin']->id,
            'title'        => 'Escalated Visit Assigned',
        ]);

        $on = $this->seedScenario(5, 30, ['sla_notify_in_app' => true]);
        ApprovalService::processAutoEscalations();

        $this->assertDatabaseHas('system_notifications', [
            'recipient_id' => $on['admin']->id,
            'title'        => 'Escalated Visit Assigned',
        ]);
    }

    public function test_sms_channel_is_opt_in_and_recorded_when_enabled(): void
    {
        $s = $this->seedScenario(5, 30, ['sla_notify_sms' => true]);
        ApprovalService::processAutoEscalations();

        $notif = \App\Models\SystemNotification::where('recipient_id', $s['admin']->id)
            ->where('title', 'Escalated Visit Assigned')->first();

        $this->assertNotNull($notif);
        $this->assertContains('sms', CompanyPolicy::forCompany($s['company']->company_id)->notifyChannels());

        // ...and is off by default for another company.
        $plain = $this->seedScenario(5, 30);
        ApprovalService::processAutoEscalations();
        $this->assertNotContains('sms', CompanyPolicy::forCompany($plain['company']->company_id)->notifyChannels());
    }

    // ═══════════════════════════════════════════════════════
    // Clock transitions (viewing never completes; decisions/transfers do)
    // ═══════════════════════════════════════════════════════

    public function test_viewing_dashboards_never_changes_sla_state(): void
    {
        $s = $this->seedScenario(5, 60); // already breached, not yet processed

        $this->actingAs($s['host'])->get('/host/dashboard')->assertOk();
        $this->actingAs($s['admin'])->get('/admin/dashboard')->assertOk();
        $this->actingAs($s['ceo'])->get('/ceo/dashboard')->assertOk();

        $fresh = $s['visit']->fresh();
        $this->assertSame('pending', $fresh->status);
        $this->assertNull($fresh->escalated_to);
        $this->assertNull($fresh->host_sla_warned_at);
        $this->assertDatabaseCount('escalations', 0);
    }

    public function test_admin_hand_back_restarts_the_host_sla_round(): void
    {
        $s = $this->seedScenario(5, 1);
        $s['visit']->update([
            'status'               => 'escalated',
            'escalated_to'         => $s['admin']->id,
            'admin_sla_started_at' => Carbon::now()->subMinutes(30),
            'host_sla_warned_at'   => Carbon::now()->subMinutes(40),
        ]);

        $this->actingAs($s['admin'])->post("/visit-requests/{$s['visit']->id}/decision", [
            'decision' => 'approve',
        ])->assertSessionHas('success');

        $fresh = $s['visit']->fresh();
        $this->assertSame('pending', $fresh->status);
        $this->assertNull($fresh->escalated_to);
        $this->assertNotNull($fresh->host_sla_started_at);
        $this->assertNull($fresh->host_sla_warned_at);
        $this->assertNull($fresh->admin_sla_started_at);

        // Fresh round: the old breach no longer applies immediately.
        $this->assertSame(0, ApprovalService::processAutoEscalations());
    }

    public function test_transfer_to_another_host_restarts_the_host_sla_clock(): void
    {
        $s = $this->seedScenario(30, 10);
        $newHost = $this->makeUser($s['company'], 'host', 'host2-' . uniqid() . '@sla.test');

        $s['visit']->update(['host_sla_warned_at' => Carbon::now()->subMinutes(5)]);

        $this->actingAs($s['host'])->post("/visit-requests/{$s['visit']->id}/transfer", [
            'visit_request_id' => $s['visit']->id,
            'new_host_id'      => $newHost->id,
            'reason'           => 'Handing over',
        ])->assertSessionHas('success');

        $fresh = $s['visit']->fresh();
        $this->assertSame($newHost->id, $fresh->host_id);
        $this->assertNotNull($fresh->host_sla_started_at);
        $this->assertNull($fresh->host_sla_warned_at);
        // Restarted recently -> still inside a 30-minute SLA.
        $this->assertSame(0, ApprovalService::processAutoEscalations());
        $this->assertSame('pending', $fresh->status);
    }

    // ═══════════════════════════════════════════════════════
    // Server-side calendar maths (working hours / weekends / holidays)
    // ═══════════════════════════════════════════════════════

    public function test_default_policy_uses_plain_wall_clock(): void
    {
        $policy = CompanyPolicy::forCompany(null);
        $deadline = app(SlaService::class)->computeDeadline('2026-10-08 23:30:00', 60, $policy, null);

        // 24/7 wall clock: crosses midnight regardless of day.
        $this->assertSame('2026-10-09 00:30:00', $deadline->format('Y-m-d H:i:s'));
    }

    public function test_weekends_pause_the_clock_when_not_counted(): void
    {
        Carbon::setTestNow('2026-10-10 10:00:00'); // Saturday
        $company = $this->makeCompany('Calendar Co');
        CompanyPolicy::create([
            'company_id'         => $company->company_id,
            'sla_minutes'        => 60,
            'sla_pause_off_hours'=> true,
            'sla_count_weekends' => false,
            'sla_count_holidays' => true,
        ]);

        $policy   = CompanyPolicy::forCompany($company->company_id);
        $deadline = app(SlaService::class)->computeDeadline(
            Carbon::now(), 60, $policy, $company->company_id
        );

        // Saturday + Sunday are skipped: 60 working minutes from Monday 00:00.
        $this->assertSame('2026-10-12 01:00:00', $deadline->format('Y-m-d H:i:s'));
        $this->assertSame(1, (int) $deadline->format('w')); // Monday
    }

    public function test_holidays_pause_the_clock_when_not_counted(): void
    {
        Carbon::setTestNow('2026-10-08 23:00:00'); // Thursday
        $company = $this->makeCompany('Holiday Co');
        Holiday::create([
            'name'         => 'Founders Day',
            'holiday_date' => '2026-10-09',
            'company_id'   => $company->company_id,
        ]);
        CompanyPolicy::create([
            'company_id'         => $company->company_id,
            'sla_minutes'        => 120,
            'sla_pause_off_hours'=> true,
            'sla_count_weekends' => true,
            'sla_count_holidays' => false,
        ]);

        $policy   = CompanyPolicy::forCompany($company->company_id);
        $deadline = app(SlaService::class)->computeDeadline(
            Carbon::now(), 120, $policy, $company->company_id
        );

        // 60 min fit before midnight, the holiday Friday is skipped, the
        // remaining 60 minutes land on Saturday (weekends count here).
        $this->assertSame('2026-10-10 01:00:00', $deadline->format('Y-m-d H:i:s'));
    }

    public function test_business_hours_rows_bound_the_working_window(): void
    {
        Carbon::setTestNow('2026-10-08 18:00:00'); // Thursday 18:00
        $company = $this->makeCompany('Hours Co');
        WorkingHour::insert([
            ['day_of_week' => 4, 'is_working_day' => true, 'open_time' => '09:00:00', 'close_time' => '17:00:00', 'created_at' => now(), 'updated_at' => now()],
            ['day_of_week' => 5, 'is_working_day' => true, 'open_time' => '09:00:00', 'close_time' => '17:00:00', 'created_at' => now(), 'updated_at' => now()],
        ]);
        CompanyPolicy::create([
            'company_id'         => $company->company_id,
            'sla_minutes'        => 60,
            'sla_pause_off_hours'=> true,
            'sla_count_weekends' => true,
            'sla_count_holidays' => true,
        ]);

        $policy   = CompanyPolicy::forCompany($company->company_id);
        $deadline = app(SlaService::class)->computeDeadline(
            Carbon::now(), 60, $policy, $company->company_id
        );

        // 18:00 is after close (17:00): the hour waits for the next working
        // window — Friday 09:00 + 60 min.
        $this->assertSame('2026-10-09 10:00:00', $deadline->format('Y-m-d H:i:s'));
    }

    // ═══════════════════════════════════════════════════════
    // Dashboard payloads (all server-provided, no client maths)
    // ═══════════════════════════════════════════════════════

    public function test_host_dashboard_carries_sla_payload_per_visit(): void
    {
        $s = $this->seedScenario(5, 30);

        $this->actingAs($s['host'])->get('/host/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard/Host')
                ->where('visits.0.sla.active', true)
                ->where('visits.0.sla.level', 1)
                ->where('visits.0.sla.status', 'overdue')
                ->where('visits.0.sla.deadline_at', $s['visit']->created_at->addMinutes(5)->toDateTimeString())
                ->where('sla_policy.auto_escalation_enabled', true));
    }

    public function test_admin_dashboard_carries_sla_payload(): void
    {
        $s = $this->seedScenario(5, 30);
        ApprovalService::processAutoEscalations();

        $this->actingAs($s['admin'])->get('/admin/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard/Admin')
                ->where('all_visits.0.sla.active', true)
                ->where('all_visits.0.sla.level', 2)
                ->where('escalations.0.escalation_history.0.trigger_type', 'sla')
                ->where('escalations.0.escalation_history.0.level', 1)
                ->where('sla_policy.sla_minutes', 5));
    }

    public function test_ceo_dashboard_carries_sla_payload_and_history(): void
    {
        $s = $this->seedScenario(5, 30);
        ApprovalService::processAutoEscalations();
        // Move it up to the CEO as well.
        $s['visit']->fresh()->update(['admin_sla_started_at' => Carbon::now()->subMinutes(30)]);
        ApprovalService::processAutoEscalations();

        $this->actingAs($s['ceo'])->get('/ceo/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard/CEO')
                ->where('escalations.0.sla.level', 3)
                ->where('escalations.0.sla.stage', 'ceo')
                ->where('escalations.0.sla.deadline_at', null)
                ->where('escalations.0.sla.status', 'final')
                ->where('escalations.0.escalation_history.0.trigger_type', 'sla')
                ->where('escalations.0.escalation_history.1.trigger_type', 'sla')
                ->where('policies.auto_escalation_enabled', true));
    }

    // ═══════════════════════════════════════════════════════
    // Policy endpoints
    // ═══════════════════════════════════════════════════════

    public function test_admin_can_store_full_sla_policy_and_holidays(): void
    {
        $company = $this->makeCompany('Settings Co');
        $admin   = $this->makeUser($company, 'admin', 'admin-settings@sla.test');

        $this->actingAs($admin)->post('/admin/sla-settings', [
            'sla_minutes'             => 20,
            'admin_sla_minutes'       => 40,
            'auto_escalation_enabled' => true,
            'sla_warning_minutes'     => 10,
            'sla_pause_off_hours'     => true,
            'sla_count_weekends'      => false,
            'sla_count_holidays'      => false,
            'sla_notify_in_app'       => true,
            'sla_notify_email'        => false,
            'sla_notify_sms'          => true,
            'allow_weekends'          => true,
            'enforce_biz_hours'       => true,
        ])->assertSessionHas('success');

        $policy = CompanyPolicy::where('company_id', $company->company_id)->first();
        $this->assertSame(20, $policy->hostSlaMinutes());
        $this->assertSame(40, $policy->adminSlaMinutes());
        $this->assertTrue($policy->auto_escalation_enabled);
        $this->assertSame(10, $policy->sla_warning_minutes);
        $this->assertTrue($policy->sla_pause_off_hours);
        $this->assertFalse($policy->sla_count_weekends);
        $this->assertFalse($policy->sla_count_holidays);
        $this->assertSame(['in_app', 'sms'], $policy->notifyChannels());

        $this->actingAs($admin)->post('/admin/sla-holidays', [
            'name'         => 'Company Day',
            'holiday_date' => '2026-12-25',
        ])->assertSessionHas('success');

        $this->assertDatabaseHas('holidays', [
            'name'         => 'Company Day',
            'holiday_date' => '2026-12-25 00:00:00',
            'company_id'   => $company->company_id,
        ]);
    }

    public function test_ceo_policy_update_keeps_unset_sla_fields(): void
    {
        $company = $this->makeCompany('CEO Policy Co');
        $ceo     = $this->makeUser($company, 'ceo', 'ceo-policy@sla.test');

        CompanyPolicy::create([
            'company_id'          => $company->company_id,
            'sla_minutes'         => 15,
            'admin_sla_minutes'   => 45,
            'sla_warning_minutes' => 7,
            'sla_notify_sms'      => true,
        ]);

        $this->actingAs($ceo)->post('/ceo/policies', [
            'allow_weekends'    => 'true',
            'enforce_biz_hours' => 'true',
            'sla_minutes'       => 25,
        ])->assertSessionHas('success');

        $policy = CompanyPolicy::where('company_id', $company->company_id)->first();
        $this->assertSame(25, $policy->hostSlaMinutes());
        // Fields the form did not submit keep their stored values.
        $this->assertSame(45, $policy->adminSlaMinutes());
        $this->assertSame(7, $policy->sla_warning_minutes);
        $this->assertTrue($policy->sla_notify_sms);
    }

    public function test_admin_cannot_manage_another_companies_holiday(): void
    {
        $companyA = $this->makeCompany('Holiday A');
        $companyB = $this->makeCompany('Holiday B');
        $adminA   = $this->makeUser($companyA, 'admin', 'admin-ha@sla.test');

        $holidayB = Holiday::create([
            'name'         => 'B Day',
            'holiday_date' => '2026-11-01',
            'company_id'   => $companyB->company_id,
        ]);

        $this->actingAs($adminA)->delete("/admin/sla-holidays/{$holidayB->id}")
            ->assertSessionHas('error');

        $this->assertDatabaseHas('holidays', ['id' => $holidayB->id]);
    }

    // ═══════════════════════════════════════════════════════
    // Cron endpoint
    // ═══════════════════════════════════════════════════════

    public function test_cron_endpoint_processes_sla_without_auth(): void
    {
        $s = $this->seedScenario(5, 30);

        $response = $this->get('/cron/process-sla');
        $response->assertOk()->assertJson(['status' => 'ok', 'escalated' => 1]);

        $this->assertSame('escalated', $s['visit']->fresh()->status);
    }
}
