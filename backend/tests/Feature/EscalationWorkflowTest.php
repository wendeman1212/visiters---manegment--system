<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\Escalation;
use App\Models\HostTransfer;
use App\Models\QRToken;
use App\Models\VisitRequest;
use App\Models\Visitor;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EscalationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function seedEscalationData(string $companyName = 'Escalation Corp'): array
    {
        $company = Company::create(['company_name' => $companyName, 'status' => 'active']);

        $dept = Department::create(['name' => 'Operations', 'company_id' => $company->company_id]);

        $host = User::create([
            'name'       => 'Escalating Host',
            'email'      => 'host@esc.test',
            'password'   => 'password',
            'role'       => 'host',
            'company_id' => $company->company_id,
            'department_id' => $dept->id,
            'status'     => 'active',
        ]);

        $secondHost = User::create([
            'name'       => 'Receiving Host',
            'email'      => 'host2@esc.test',
            'password'   => 'password',
            'role'       => 'host',
            'company_id' => $company->company_id,
            'department_id' => $dept->id,
            'status'     => 'active',
        ]);

        $admin = User::create([
            'name'       => 'Company Admin',
            'email'      => 'admin@esc.test',
            'password'   => 'password',
            'role'       => 'admin',
            'company_id' => $company->company_id,
            'status'     => 'active',
        ]);

        $ceo = User::create([
            'name'       => 'Company CEO',
            'email'      => 'ceo@esc.test',
            'password'   => 'password',
            'role'       => 'ceo',
            'company_id' => $company->company_id,
            'status'     => 'active',
        ]);

        $visitor = Visitor::create([
            'name'           => 'Escalation Visitor',
            'email'          => 'visitor@esc.test',
            'id_document_no' => 'ID-ESC-1',
            'phone'          => '555111',
            'password'       => 'password',
            'company_id'     => $company->company_id,
        ]);

        $visit = VisitRequest::create([
            'visitor_id'     => $visitor->id,
            'host_id'        => $host->id,
            'department_id'  => $dept->id,
            'purpose'        => 'Contract review',
            'visit_type'     => 'solo',
            'requested_time' => Carbon::now()->addDay()->startOfHour(),
            'duration'       => 60,
            'status'         => 'approved',
            'company_id'     => $company->company_id,
        ]);

        return compact('company', 'dept', 'host', 'secondHost', 'admin', 'ceo', 'visitor', 'visit');
    }

    public function test_host_escalates_to_company_admin_who_becomes_responsible(): void
    {
        $d = $this->seedEscalationData();
        $this->actingAs($d['host']);

        $this->post("/visit-requests/{$d['visit']->id}/host-decision", [
            'decision'    => 'escalate',
            'escalated_to' => $d['admin']->id,
            'comments'    => 'Visitor requires executive approval',
        ])->assertSessionHas('success');

        $fresh = $d['visit']->fresh();

        // Responsibility moved to the Admin without duplicating the visit.
        $this->assertSame('escalated', $fresh->status);
        $this->assertSame($d['admin']->id, $fresh->host_id);
        $this->assertSame($d['admin']->id, $fresh->escalated_to);
        $this->assertDatabaseCount('visit_requests', 1);
        $this->assertDatabaseCount('visitors', 1);
        $this->assertSame($d['visitor']->id, $fresh->visitor_id);
        $this->assertSame('Contract review', $fresh->purpose);

        // Both history tables written.
        $this->assertDatabaseHas('host_transfers', [
            'visit_request_id' => $d['visit']->id,
            'previous_host_id' => $d['host']->id,
            'new_host_id'      => $d['admin']->id,
        ]);
        $this->assertDatabaseHas('escalations', [
            'visit_request_id' => $d['visit']->id,
            'escalated_by'     => $d['host']->id,
            'escalated_to'     => $d['admin']->id,
            'reason'           => 'Visitor requires executive approval',
        ]);

        // The target Admin is notified immediately.
        $this->assertDatabaseHas('system_notifications', [
            'recipient_type' => 'user',
            'recipient_id'   => $d['admin']->id,
            'title'          => 'Escalated Visit Assigned',
        ]);
    }

    public function test_host_escalation_reason_sent_via_reason_field_is_preserved(): void
    {
        $d = $this->seedEscalationData();
        $this->actingAs($d['host']);

        // The frontend sends the typed reason via the 'reason' field (not 'comments').
        $this->post("/visit-requests/{$d['visit']->id}/host-decision", [
            'decision'     => 'escalate',
            'escalated_to' => $d['admin']->id,
            'reason'       => 'Visitor needs CIO sign-off before the 2pm meeting',
        ])->assertSessionHas('success');

        $fresh = $d['visit']->fresh();

        // Responsibility moved to the Admin.
        $this->assertSame('escalated', $fresh->status);
        $this->assertSame($d['admin']->id, $fresh->host_id);
        $this->assertSame($d['admin']->id, $fresh->escalated_to);

        // The actual reason is recorded - not the generic fallback.
        $this->assertDatabaseHas('escalations', [
            'visit_request_id' => $d['visit']->id,
            'escalated_by'     => $d['host']->id,
            'escalated_to'     => $d['admin']->id,
            'reason'           => 'Visitor needs CIO sign-off before the 2pm meeting',
        ]);

        // All information previously entered by the old host is preserved.
        $this->assertSame('Contract review', $fresh->purpose);
        $this->assertSame(60, $fresh->duration);
        $this->assertSame($d['dept']->id, $fresh->department_id);
        $this->assertSame($d['visitor']->id, $fresh->visitor_id);
        $this->assertDatabaseCount('visit_requests', 1);
        $this->assertDatabaseCount('visitors', 1);
    }

    public function test_host_cannot_escalate_to_admin_of_other_company(): void
    {
        $d = $this->seedEscalationData('Company A');

        $otherCompany = Company::create(['company_name' => 'Company B', 'status' => 'active']);
        $foreignAdmin = User::create([
            'name'       => 'Foreign Admin',
            'email'      => 'foreign@b.test',
            'password'   => 'password',
            'role'       => 'admin',
            'company_id' => $otherCompany->company_id,
            'status'     => 'active',
        ]);

        $this->actingAs($d['host']);

        $this->post("/visit-requests/{$d['visit']->id}/host-decision", [
            'decision'    => 'escalate',
            'escalated_to' => $foreignAdmin->id,
        ])->assertSessionHas('error');

        // Nothing changed and no cross-company hand-off was recorded.
        $this->assertSame('approved', $d['visit']->fresh()->status);
        $this->assertSame($d['host']->id, $d['visit']->fresh()->host_id);
        $this->assertNull($d['visit']->fresh()->escalated_to);
        $this->assertDatabaseCount('escalations', 0);
        $this->assertDatabaseCount('host_transfers', 0);
    }

    public function test_responsible_admin_receives_escalated_visitor(): void
    {
        $d = $this->seedEscalationData();
        $this->actingAs($d['host']);
        $this->post("/visit-requests/{$d['visit']->id}/host-decision", [
            'decision'    => 'escalate',
            'escalated_to' => $d['admin']->id,
        ]);

        $this->actingAs($d['admin']);
        $this->post("/escalation/visits/{$d['visit']->id}/receive")
            ->assertSessionHas('success');

        $this->assertSame('checked_in', $d['visit']->fresh()->status);
        $this->assertDatabaseHas('check_in_out_logs', [
            'visit_request_id' => $d['visit']->id,
            'guard_id'         => $d['admin']->id,
        ]);
    }

    public function test_responsible_admin_completes_escalated_visit(): void
    {
        $d = $this->seedEscalationData();
        $this->actingAs($d['host']);
        $this->post("/visit-requests/{$d['visit']->id}/host-decision", [
            'decision'    => 'escalate',
            'escalated_to' => $d['admin']->id,
        ]);

        $this->actingAs($d['admin']);
        $this->post("/escalation/visits/{$d['visit']->id}/receive");
        $this->post("/escalation/visits/{$d['visit']->id}/complete")
            ->assertSessionHas('success');

        $fresh = $d['visit']->fresh();
        $this->assertSame('completed', $fresh->status);
        $this->assertNull($fresh->escalated_to);
        $this->assertNotNull($fresh->meeting_ended_at);

        // Escalation history is closed.
        $this->assertNotNull($fresh->escalations()->first()->resolved_at);
    }

    public function test_responsible_admin_marks_no_show_and_expires_qr(): void
    {
        $d = $this->seedEscalationData();
        QRToken::create([
            'visit_request_id' => $d['visit']->id,
            'token_value'      => 'ESC-QR-123',
            'type'             => 'entry',
            'status'           => 'active',
        ]);

        $this->actingAs($d['host']);
        $this->post("/visit-requests/{$d['visit']->id}/host-decision", [
            'decision'    => 'escalate',
            'escalated_to' => $d['admin']->id,
        ]);

        $this->actingAs($d['admin']);
        $this->post("/escalation/visits/{$d['visit']->id}/no-show")
            ->assertSessionHas('success');

        $fresh = $d['visit']->fresh();
        $this->assertSame('no_show', $fresh->status);
        $this->assertTrue((bool) $fresh->is_no_show);
        $this->assertNotNull($fresh->no_show_at);
        $this->assertNull($fresh->escalated_to);
        $this->assertSame('expired', $fresh->qrToken->status);
        $this->assertNotNull($fresh->escalations()->first()->resolved_at);
    }

    public function test_responsible_admin_transfers_back_to_host_and_visit_returns_to_pending(): void
    {
        $d = $this->seedEscalationData();
        $this->actingAs($d['host']);
        $this->post("/visit-requests/{$d['visit']->id}/host-decision", [
            'decision'    => 'escalate',
            'escalated_to' => $d['admin']->id,
        ]);

        $this->actingAs($d['admin']);
        $this->post("/escalation/visits/{$d['visit']->id}/transfer", [
            'new_host_id' => $d['secondHost']->id,
            'reason'      => 'Back to operations',
        ])->assertSessionHas('success');

        $fresh = $d['visit']->fresh();

        // Not checked in yet, so it returns to pending for the new host.
        $this->assertSame('pending', $fresh->status);
        $this->assertSame($d['secondHost']->id, $fresh->host_id);
        $this->assertNull($fresh->escalated_to);
        $this->assertDatabaseCount('visit_requests', 1);

        // Second transfer logged and escalation closed.
        $this->assertDatabaseCount('host_transfers', 2);
        $this->assertNotNull($fresh->escalations()->first()->resolved_at);
    }

    public function test_transfer_options_are_company_scoped_and_require_responsibility(): void
    {
        $d = $this->seedEscalationData('Company A');

        $otherCompany = Company::create(['company_name' => 'Company B', 'status' => 'active']);
        $foreignHost = User::create([
            'name'       => 'Foreign Host',
            'email'      => 'fh@b.test',
            'password'   => 'password',
            'role'       => 'host',
            'company_id' => $otherCompany->company_id,
            'status'     => 'active',
        ]);

        $this->actingAs($d['host']);
        $this->post("/visit-requests/{$d['visit']->id}/host-decision", [
            'decision'    => 'escalate',
            'escalated_to' => $d['admin']->id,
        ]);

        // Non-responsible user cannot fetch options.
        $this->actingAs($d['secondHost']);
        $this->get("/escalation/visits/{$d['visit']->id}/transfer-options")
            ->assertForbidden();

        // Responsible Admin sees only authorized companies (Company A only,
        // Company B is not pivot-granted) and only hosts of the selected company.
        $this->actingAs($d['admin']);
        $this->get("/escalation/visits/{$d['visit']->id}/transfer-options")
            ->assertOk()
            ->assertJsonFragment(['company_id' => $d['company']->company_id])
            ->assertJsonMissing(['company_name' => 'Company B']);

        $this->get("/escalation/visits/{$d['visit']->id}/transfer-options?company_id={$d['company']->company_id}")
            ->assertOk()
            ->assertJsonFragment(['name' => 'Receiving Host'])
            ->assertJsonMissing(['name' => 'Foreign Host']);
    }

    public function test_transfer_options_load_for_sla_escalated_assignee(): void
    {
        // SLA auto-escalations keep host_id on the original host and only set
        // escalated_to. The assignee must still be able to open transfer options.
        $d = $this->seedEscalationData();
        $d['visit']->update(['status' => 'escalated', 'escalated_to' => $d['admin']->id]);
        $this->assertSame($d['host']->id, $d['visit']->fresh()->host_id);

        $this->actingAs($d['admin']);
        $this->get("/escalation/visits/{$d['visit']->id}/transfer-options")
            ->assertOk()
            ->assertJsonFragment(['company_id' => $d['company']->company_id]);
    }

    public function test_sla_assignee_can_transfer_to_host(): void
    {
        $d = $this->seedEscalationData();
        $d['visit']->update(['status' => 'escalated', 'escalated_to' => $d['admin']->id]);

        $this->actingAs($d['admin']);
        $this->post("/escalation/visits/{$d['visit']->id}/transfer", [
            'new_host_id' => $d['secondHost']->id,
            'reason'      => 'Handing to a host for final confirmation',
        ])->assertSessionHas('success');

        $fresh = $d['visit']->fresh();
        $this->assertSame($d['secondHost']->id, $fresh->host_id);
        $this->assertNull($fresh->escalated_to);
        $this->assertSame('pending', $fresh->status);
        $this->assertDatabaseCount('visit_requests', 1);
        $this->assertDatabaseHas('host_transfers', [
            'visit_request_id' => $d['visit']->id,
            'previous_host_id' => $d['host']->id,
            'new_host_id'      => $d['secondHost']->id,
        ]);
    }

    public function test_sla_escalation_approve_by_non_owner_admin_returns_to_host(): void
    {
        // SLA escalations do not move host_id — the Admin approves and the
        // visit is handed back to the host for the final confirmation.
        $d = $this->seedEscalationData();
        $d['visit']->update(['status' => 'escalated', 'escalated_to' => $d['admin']->id]);

        $this->actingAs($d['admin']);
        $this->post("/visit-requests/{$d['visit']->id}/decision", [
            'decision' => 'approve',
        ])->assertSessionHas('success');

        $fresh = $d['visit']->fresh();
        $this->assertSame('pending', $fresh->status);
        $this->assertSame($d['host']->id, $fresh->host_id);
        $this->assertNull($fresh->escalated_to);
    }

    public function test_owner_admin_approve_resolves_manual_escalation_directly(): void
    {
        $d = $this->seedEscalationData();
        $this->actingAs($d['host']);
        $this->post("/visit-requests/{$d['visit']->id}/host-decision", [
            'decision'    => 'escalate',
            'escalated_to' => $d['admin']->id,
        ]);

        // The responsible Admin approves directly — no hand-back loop.
        $this->actingAs($d['admin']);
        $this->post("/visit-requests/{$d['visit']->id}/decision", [
            'decision' => 'approve',
        ])->assertSessionHas('success');

        $fresh = $d['visit']->fresh();
        $this->assertSame('approved', $fresh->status);
        $this->assertSame($d['admin']->id, $fresh->host_id);
        $this->assertNull($fresh->escalated_to);
        $this->assertNotNull($fresh->escalations()->first()->resolved_at);
        $this->assertNotNull($fresh->qrToken);
    }

    public function test_manually_escalated_visit_appears_on_ceo_dashboard(): void
    {
        $d = $this->seedEscalationData();
        $this->actingAs($d['host']);
        $this->post("/visit-requests/{$d['visit']->id}/host-decision", [
            'decision'    => 'escalate',
            'escalated_to' => $d['ceo']->id,
        ])->assertSessionHas('success');

        $this->actingAs($d['ceo']);
        $this->get('/ceo/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard/CEO')
                ->has('escalations', 1)
                ->where('escalations.0.id', $d['visit']->id));
    }

    public function test_transferred_visit_appears_on_ceo_dashboard(): void
    {
        // Host uses the "Transfer Visitor" feature to hand the visit to the CEO.
        // This changes host_id but leaves escalated_to NULL, so the CEO list
        // must also match on host_id to show it.
        $d = $this->seedEscalationData();
        $this->actingAs($d['host']);
        $this->post("/visit-requests/{$d['visit']->id}/transfer", [
            'visit_request_id' => $d['visit']->id,
            'new_host_id'      => $d['ceo']->id,
            'reason'           => 'Executive handling',
        ])->assertSessionHas('success');

        $this->assertSame($d['ceo']->id, $d['visit']->fresh()->host_id);

        $this->actingAs($d['ceo']);
        $this->get('/ceo/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard/CEO')
                ->has('escalations', 1)
                ->where('escalations.0.id', $d['visit']->id)
                ->where('escalations.0.status', 'approved'));
    }

    public function test_transferred_visit_not_visible_on_non_owner_admin_dashboard(): void
    {
        // A visit handed to the CEO belongs to the CEO only — it must not
        // linger on the original Admin's queue nor be duplicated across every
        // Admin of the company. It is visible solely to the new assignee.
        $d = $this->seedEscalationData();
        $this->actingAs($d['host']);
        $this->post("/visit-requests/{$d['visit']->id}/transfer", [
            'visit_request_id' => $d['visit']->id,
            'new_host_id'      => $d['ceo']->id,
            'reason'           => 'Executive handling',
        ])->assertSessionHas('success');

        $this->actingAs($d['admin']);
        $this->get('/admin/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard/Admin')
                ->has('escalations', 0)
                ->where('all_visits', []));
    }

    public function test_admin_dashboard_stays_company_scoped_for_executive_visits(): void
    {
        $d = $this->seedEscalationData('Company A');
        $this->actingAs($d['host']);
        $this->post("/visit-requests/{$d['visit']->id}/transfer", [
            'visit_request_id' => $d['visit']->id,
            'new_host_id'      => $d['ceo']->id,
        ])->assertSessionHas('success');

        $otherCompany = Company::create(['company_name' => 'Company B', 'status' => 'active']);
        $otherAdmin = User::create([
            'name'       => 'Foreign Admin',
            'email'      => 'fa@b.test',
            'password'   => 'password',
            'role'       => 'admin',
            'company_id' => $otherCompany->company_id,
            'status'     => 'active',
        ]);

        $this->actingAs($otherAdmin);
        $this->get('/admin/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard/Admin')
                ->has('escalations', 0));
    }

    public function test_transfer_options_include_same_company_admins_and_ceos(): void
    {
        $d = $this->seedEscalationData('Company A');

        $secondAdmin = User::create([
            'name'       => 'Second Admin',
            'email'      => 'admin2@esc.test',
            'password'   => 'password',
            'role'       => 'admin',
            'company_id' => $d['company']->company_id,
            'status'     => 'active',
        ]);

        $otherCompany = Company::create(['company_name' => 'Company B', 'status' => 'active']);
        $foreignAdmin = User::create([
            'name'       => 'Foreign Admin',
            'email'      => 'fa@b.test',
            'password'   => 'password',
            'role'       => 'admin',
            'company_id' => $otherCompany->company_id,
            'status'     => 'active',
        ]);

        $this->actingAs($d['host']);
        $this->post("/visit-requests/{$d['visit']->id}/host-decision", [
            'decision'    => 'escalate',
            'escalated_to' => $d['admin']->id,
        ]);

        $this->actingAs($d['admin']);
        $this->get("/escalation/visits/{$d['visit']->id}/transfer-options?company_id={$d['company']->company_id}")
            ->assertOk()
            ->assertJsonFragment(['name' => 'Second Admin'])
            ->assertJsonFragment(['name' => 'Company CEO'])
            // The responsible person cannot be offered as their own replacement.
            ->assertJsonMissing(['name' => 'Company Admin'])
            ->assertJsonMissing(['name' => 'Foreign Admin']);
    }

    public function test_responsible_admin_can_transfer_escalated_visit_to_another_admin(): void
    {
        $d = $this->seedEscalationData();

        $secondAdmin = User::create([
            'name'       => 'Second Admin',
            'email'      => 'admin2@esc.test',
            'password'   => 'password',
            'role'       => 'admin',
            'company_id' => $d['company']->company_id,
            'status'     => 'active',
        ]);

        $this->actingAs($d['host']);
        $this->post("/visit-requests/{$d['visit']->id}/host-decision", [
            'decision'    => 'escalate',
            'escalated_to' => $d['admin']->id,
        ]);

        $this->actingAs($d['admin']);
        $this->post("/escalation/visits/{$d['visit']->id}/transfer", [
            'new_host_id' => $secondAdmin->id,
            'reason'      => 'Assign to second admin',
        ])->assertSessionHas('success');

        $fresh = $d['visit']->fresh();

        $this->assertSame($secondAdmin->id, $fresh->host_id);
        $this->assertNull($fresh->escalated_to);
        $this->assertSame('pending', $fresh->status);
        $this->assertDatabaseCount('host_transfers', 2);
        $this->assertNotNull($fresh->escalations()->first()->resolved_at);

        // The receiving Admin is notified directly.
        $this->assertDatabaseHas('system_notifications', [
            'recipient_type' => 'user',
            'recipient_id'   => $secondAdmin->id,
            'title'          => 'New Visit Assigned',
        ]);
    }

    public function test_responsible_admin_can_transfer_escalated_visit_to_ceo(): void
    {
        $d = $this->seedEscalationData();

        $this->actingAs($d['host']);
        $this->post("/visit-requests/{$d['visit']->id}/host-decision", [
            'decision'    => 'escalate',
            'escalated_to' => $d['admin']->id,
        ]);

        $this->actingAs($d['admin']);
        $this->post("/escalation/visits/{$d['visit']->id}/transfer", [
            'new_host_id' => $d['ceo']->id,
            'reason'      => 'Executive escalation',
        ])->assertSessionHas('success');

        $fresh = $d['visit']->fresh();

        $this->assertSame($d['ceo']->id, $fresh->host_id);
        $this->assertNull($fresh->escalated_to);
        $this->assertSame('pending', $fresh->status);
        $this->assertDatabaseCount('host_transfers', 2);
        $this->assertNotNull($fresh->escalations()->first()->resolved_at);
    }

    public function test_admin_can_transfer_to_host_in_pivot_authorized_company(): void
    {
        $d = $this->seedEscalationData('Company A');

        $companyB = Company::create(['company_name' => 'Company B', 'status' => 'active']);
        $branchB = \App\Models\Branch::create([
            'name'       => 'Branch B',
            'slug'       => 'branch-b',
            'company_id' => $companyB->company_id,
            'is_active'  => true,
        ]);
        $deptB = Department::create([
            'name'       => 'Support B',
            'company_id' => $companyB->company_id,
            'branch_id'  => $branchB->id,
        ]);
        $hostB = User::create([
            'name'          => 'Host B',
            'email'         => 'hostb@b.test',
            'password'      => 'password',
            'role'          => 'host',
            'company_id'    => $companyB->company_id,
            'branch_id'     => $branchB->id,
            'department_id' => $deptB->id,
            'status'        => 'active',
        ]);

        // Grant the Admin of Company A access to Company B via the pivot.
        $d['admin']->companies()->attach($companyB->company_id);

        $this->actingAs($d['host']);
        $this->post("/visit-requests/{$d['visit']->id}/host-decision", [
            'decision'    => 'escalate',
            'escalated_to' => $d['admin']->id,
        ]);

        // Company B appears in the cascade and its host is offered.
        $this->actingAs($d['admin']);
        $this->get("/escalation/visits/{$d['visit']->id}/transfer-options")
            ->assertOk()
            ->assertJsonFragment(['company_name' => 'Company B']);

        $this->get("/escalation/visits/{$d['visit']->id}/transfer-options?company_id={$companyB->company_id}&branch_id={$branchB->id}&department_id={$deptB->id}")
            ->assertOk()
            ->assertJsonFragment(['name' => 'Branch B'])
            ->assertJsonFragment(['name' => 'Host B'])
            ->assertJsonMissing(['name' => 'Receiving Host']);

        // Transfer relocates the whole visit to the new host's company/branch/dept.
        $this->post("/escalation/visits/{$d['visit']->id}/transfer", [
            'new_host_id' => $hostB->id,
            'company_id'  => $companyB->company_id,
            'reason'      => 'Cross-company handover',
        ])->assertSessionHas('success');

        $fresh = $d['visit']->fresh();
        $this->assertSame($hostB->id, $fresh->host_id);
        $this->assertSame($companyB->company_id, $fresh->company_id);
        $this->assertSame($branchB->id, $fresh->branch_id);
        $this->assertSame($deptB->id, $fresh->department_id);
        $this->assertNull($fresh->escalated_to);
        $this->assertSame('pending', $fresh->status);
        $this->assertDatabaseCount('visit_requests', 1);
        $this->assertDatabaseHas('host_transfers', [
            'visit_request_id' => $d['visit']->id,
            'previous_host_id' => $d['admin']->id,
            'new_host_id'      => $hostB->id,
            'transferred_by'   => $d['admin']->id,
        ]);
    }

    public function test_transfer_options_reject_branch_from_other_company(): void
    {
        $d = $this->seedEscalationData('Company A');

        $companyB = Company::create(['company_name' => 'Company B', 'status' => 'active']);
        $branchB = \App\Models\Branch::create([
            'name'       => 'Branch B',
            'slug'       => 'branch-b',
            'company_id' => $companyB->company_id,
            'is_active'  => true,
        ]);

        $this->actingAs($d['host']);
        $this->post("/visit-requests/{$d['visit']->id}/host-decision", [
            'decision'    => 'escalate',
            'escalated_to' => $d['admin']->id,
        ]);

        $this->actingAs($d['admin']);
        $this->get("/escalation/visits/{$d['visit']->id}/transfer-options?company_id={$d['company']->company_id}&branch_id={$branchB->id}")
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'The selected branch does not belong to this company.']);
    }

    public function test_transfer_options_return_empty_hosts_instead_of_error(): void
    {
        $d = $this->seedEscalationData('Empty Co');

        $this->actingAs($d['host']);
        $this->post("/visit-requests/{$d['visit']->id}/host-decision", [
            'decision'    => 'escalate',
            'escalated_to' => $d['admin']->id,
        ]);

        // Remove every eligible host/manager so the cascade reports empty
        // lists instead of an API error.
        $d['host']->delete();
        $d['secondHost']->delete();
        $d['ceo']->delete();

        $this->actingAs($d['admin']);
        $this->get("/escalation/visits/{$d['visit']->id}/transfer-options?company_id={$d['company']->company_id}")
            ->assertOk()
            ->assertJsonCount(0, 'hosts')
            ->assertJsonCount(0, 'managers')
            ->assertJsonCount(0, 'branches')
            ->assertJsonCount(1, 'departments');
    }

    public function test_escalation_transfer_of_approved_visit_preserves_approved_status(): void
    {
        $d = $this->seedEscalationData();
        $d['visit']->update([
            'host_id'    => $d['admin']->id,
            'escalated_to' => $d['admin']->id,
            'status'     => 'approved',
        ]);

        $this->actingAs($d['admin']);
        $this->post("/escalation/visits/{$d['visit']->id}/transfer", [
            'new_host_id' => $d['secondHost']->id,
            'reason'      => 'Handoff of approved visit',
        ])->assertSessionHas('success');

        $fresh = $d['visit']->fresh();
        $this->assertSame($d['secondHost']->id, $fresh->host_id);
        $this->assertSame('approved', $fresh->status);
        $this->assertNull($fresh->escalated_to);
    }

    public function test_escalation_transfer_of_checked_in_visit_preserves_checked_in_status(): void
    {
        $d = $this->seedEscalationData();
        $d['visit']->update([
            'host_id'    => $d['admin']->id,
            'escalated_to' => $d['admin']->id,
            'status'     => 'checked_in',
        ]);

        $this->actingAs($d['admin']);
        $this->post("/escalation/visits/{$d['visit']->id}/transfer", [
            'new_host_id' => $d['secondHost']->id,
            'reason'      => 'Mid-visit handoff',
        ])->assertSessionHas('success');

        $fresh = $d['visit']->fresh();
        $this->assertSame($d['secondHost']->id, $fresh->host_id);
        $this->assertSame('checked_in', $fresh->status);
        $this->assertNull($fresh->escalated_to);
    }
}
