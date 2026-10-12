<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\HostTransfer;
use App\Models\VisitRequest;
use App\Models\Visitor;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminCeoVisitorTransferTest extends TestCase
{
    use RefreshDatabase;

    private function seedTransferData(): array
    {
        $company = Company::create(['company_name' => 'Transfer Corp', 'status' => 'active']);

        $branch = Branch::create([
            'name'       => 'Branch One',
            'slug'       => 'branch-one',
            'company_id' => $company->company_id,
            'is_active'  => true,
        ]);

        $dept = Department::create([
            'name'       => 'Sales',
            'company_id' => $company->company_id,
            'branch_id'  => $branch->id,
        ]);

        $host = User::create([
            'name'          => 'Current Host',
            'email'         => 'host@transfer.test',
            'password'      => 'password',
            'role'          => 'host',
            'company_id'    => $company->company_id,
            'branch_id'     => $branch->id,
            'department_id' => $dept->id,
            'status'        => 'active',
        ]);

        $recipientHost = User::create([
            'name'          => 'Receiving Host',
            'email'         => 'host2@transfer.test',
            'password'      => 'password',
            'role'          => 'host',
            'company_id'    => $company->company_id,
            'branch_id'     => $branch->id,
            'department_id' => $dept->id,
            'status'        => 'active',
        ]);

        $admin = User::create([
            'name'       => 'Company Admin',
            'email'      => 'admin@transfer.test',
            'password'   => 'password',
            'role'       => 'admin',
            'company_id' => $company->company_id,
            'status'     => 'active',
        ]);

        $ceo = User::create([
            'name'       => 'Company CEO',
            'email'      => 'ceo@transfer.test',
            'password'   => 'password',
            'role'       => 'ceo',
            'company_id' => $company->company_id,
            'status'     => 'active',
        ]);

        $otherCompany = Company::create(['company_name' => 'Other Corp', 'status' => 'active']);
        $otherBranch = Branch::create([
            'name'       => 'Branch Two',
            'slug'       => 'branch-two',
            'company_id' => $otherCompany->company_id,
            'is_active'  => true,
        ]);
        $otherDept = Department::create([
            'name'       => 'Ops',
            'company_id' => $otherCompany->company_id,
            'branch_id'  => $otherBranch->id,
        ]);
        $foreignHost = User::create([
            'name'          => 'Foreign Host',
            'email'         => 'foreign@other.test',
            'password'      => 'password',
            'role'          => 'host',
            'company_id'    => $otherCompany->company_id,
            'branch_id'     => $otherBranch->id,
            'department_id' => $otherDept->id,
            'status'        => 'active',
        ]);

        $visitor = Visitor::create([
            'name'           => 'Transfer Visitor',
            'email'          => 'visitor@transfer.test',
            'id_document_no' => 'ID-TRANSFER-2',
            'phone'          => '555001',
            'password'       => 'password',
            'company_id'     => $company->company_id,
        ]);

        $visit = VisitRequest::create([
            'visitor_id'     => $visitor->id,
            'host_id'        => $host->id,
            'department_id'  => $dept->id,
            'branch_id'      => $branch->id,
            'purpose'        => 'Negotiation',
            'visit_type'     => 'solo',
            'requested_time' => Carbon::now()->addDay()->startOfHour(),
            'duration'       => 60,
            'status'         => 'approved',
            'company_id'     => $company->company_id,
        ]);

        return compact(
            'company', 'branch', 'dept', 'host', 'recipientHost', 'admin', 'ceo',
            'otherCompany', 'otherBranch', 'otherDept', 'foreignHost', 'visitor', 'visit'
        );
    }

    private function optionsUrl($visitId, array $params = []): string
    {
        $url = "/visitor-transfer/{$visitId}/options";
        if ($params) {
            $url .= '?' . http_build_query($params);
        }
        return $url;
    }

    public function test_admin_options_cascade_returns_only_authorized_data(): void
    {
        $d = $this->seedTransferData();
        $this->actingAs($d['admin']);

        $this->getJson($this->optionsUrl($d['visit']->id))
            ->assertOk()
            ->assertJsonCount(1, 'companies')
            ->assertJsonPath('companies.0.company_id', $d['company']->company_id)
            ->assertJsonCount(0, 'branches')
            ->assertJsonCount(0, 'departments')
            ->assertJsonCount(0, 'hosts')
            ->assertJsonPath('visit.id', $d['visit']->id);

        $this->getJson($this->optionsUrl($d['visit']->id, ['company_id' => $d['company']->company_id]))
            ->assertOk()
            ->assertJsonCount(1, 'branches')
            ->assertJsonPath('branches.0.id', $d['branch']->id);

        $this->getJson($this->optionsUrl($d['visit']->id, [
            'company_id' => $d['company']->company_id,
            'branch_id'  => $d['branch']->id,
        ]))
            ->assertOk()
            ->assertJsonCount(1, 'departments')
            ->assertJsonPath('departments.0.id', $d['dept']->id);
    }

    public function test_admin_options_lists_only_eligible_hosts_for_selected_department(): void
    {
        $d = $this->seedTransferData();
        $this->actingAs($d['admin']);

        $response = $this->getJson($this->optionsUrl($d['visit']->id, [
            'company_id'    => $d['company']->company_id,
            'branch_id'     => $d['branch']->id,
            'department_id' => $d['dept']->id,
        ]))
            ->assertOk();

        $hostIds = array_column($response->json('hosts'), 'id');

        // Only the other active host of the exact department is eligible:
        // current host, Admins, CEOs and foreign-company hosts are excluded.
        $this->assertSame([$d['recipientHost']->id], $hostIds);
        $this->assertNotContains($d['host']->id, $hostIds);
        $this->assertNotContains($d['admin']->id, $hostIds);
        $this->assertNotContains($d['ceo']->id, $hostIds);
        $this->assertNotContains($d['foreignHost']->id, $hostIds);
    }

    public function test_options_rejects_company_the_admin_cannot_manage(): void
    {
        $d = $this->seedTransferData();
        $this->actingAs($d['admin']);

        $this->getJson($this->optionsUrl($d['visit']->id, ['company_id' => $d['otherCompany']->company_id]))
            ->assertStatus(403);
    }

    public function test_host_role_cannot_use_admin_ceo_flow(): void
    {
        $d = $this->seedTransferData();
        $this->actingAs($d['host']);

        $this->getJson($this->optionsUrl($d['visit']->id))->assertStatus(403);

        $this->post("/visitor-transfer/{$d['visit']->id}", [
            'new_host_id'   => $d['recipientHost']->id,
            'company_id'    => $d['company']->company_id,
            'branch_id'     => $d['branch']->id,
            'department_id' => $d['dept']->id,
        ])->assertStatus(403);

        $this->assertDatabaseCount('host_transfers', 0);
    }

    public function test_admin_transfers_visitor_to_host_and_records_everything(): void
    {
        $d = $this->seedTransferData();
        $this->actingAs($d['admin']);

        $this->post("/visitor-transfer/{$d['visit']->id}", [
            'new_host_id'   => $d['recipientHost']->id,
            'company_id'    => $d['company']->company_id,
            'branch_id'     => $d['branch']->id,
            'department_id' => $d['dept']->id,
            'reason'        => 'Department reassignment',
        ])->assertSessionHas('success');

        $fresh = $d['visit']->fresh();
        $this->assertSame($d['recipientHost']->id, $fresh->host_id);
        $this->assertSame($d['branch']->id, $fresh->branch_id);
        $this->assertSame($d['dept']->id, $fresh->department_id);
        $this->assertSame('approved', $fresh->status);
        $this->assertSame($d['visitor']->id, $fresh->visitor_id);
        $this->assertSame('Negotiation', $fresh->purpose);

        // No duplication: same visit, same visitor, history preserved.
        $this->assertDatabaseCount('visit_requests', 1);
        $this->assertDatabaseCount('visitors', 1);

        // Old host loses the visit; new host gains it.
        $this->assertSame(0, VisitRequest::where('host_id', $d['host']->id)->where('status', 'approved')->count());
        $this->assertSame(1, VisitRequest::where('host_id', $d['recipientHost']->id)->where('status', 'approved')->count());

        // Transfer log records the full scope.
        $this->assertDatabaseHas('host_transfers', [
            'visit_request_id' => $d['visit']->id,
            'previous_host_id' => $d['host']->id,
            'new_host_id'      => $d['recipientHost']->id,
            'transferred_by'   => $d['admin']->id,
            'company_id'       => $d['company']->company_id,
            'branch_id'        => $d['branch']->id,
            'department_id'    => $d['dept']->id,
            'reason'           => 'Department reassignment',
        ]);

        // Audit trail entry.
        $this->assertDatabaseHas('audit_logs', [
            'action'   => 'visitor_transferred',
            'entity'   => 'visit_requests',
            'entity_id' => $d['visit']->id,
            'actor_id' => $d['admin']->id,
            'company_id' => $d['company']->company_id,
        ]);

        // Notifications: previous host, new host and visitor.
        $this->assertDatabaseHas('system_notifications', [
            'recipient_type' => 'user',
            'recipient_id'   => $d['host']->id,
            'title'          => 'Host Transfer',
        ]);
        $this->assertDatabaseHas('system_notifications', [
            'recipient_type' => 'user',
            'recipient_id'   => $d['recipientHost']->id,
            'title'          => 'New Visit Assigned',
        ]);
        $this->assertDatabaseHas('system_notifications', [
            'recipient_type' => 'visitor',
            'recipient_id'   => $d['visitor']->id,
            'title'          => 'Host Changed',
        ]);
    }

    public function test_transferred_visit_leaves_original_admin_dashboard_and_appears_for_new_host(): void
    {
        $d = $this->seedTransferData();

        // The visit is assigned to the Admin themselves (direct ownership).
        $d['visit']->update(['host_id' => $d['admin']->id]);

        $this->actingAs($d['admin']);

        // Before the transfer the Admin sees it in their Appointments list,
        // escalation queue and statistics.
        $this->get('/admin/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard/Admin')
                ->where('all_visits', fn ($visits) => collect($visits)->pluck('id')->contains($d['visit']->id))
                ->where('escalations', fn ($es) => collect($es)->pluck('id')->contains($d['visit']->id))
                ->where('visit_stats.total', 1));

        $this->post("/visitor-transfer/{$d['visit']->id}", [
            'new_host_id'   => $d['recipientHost']->id,
            'company_id'    => $d['company']->company_id,
            'branch_id'     => $d['branch']->id,
            'department_id' => $d['dept']->id,
            'reason'        => 'Reassigned to receiving host',
        ])->assertSessionHas('success');

        // Ownership moved to the new host; the record is still the same one.
        $this->assertSame($d['recipientHost']->id, $d['visit']->fresh()->host_id);
        $this->assertDatabaseCount('visit_requests', 1);

        // The original Admin no longer sees the visit anywhere on their
        // dashboard: Appointments list, escalation queue, monitor poll or stats.
        $this->get('/admin/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard/Admin')
                ->where('all_visits', fn ($visits) => collect($visits)->pluck('id')->doesntContain($d['visit']->id))
                ->where('escalations', fn ($es) => collect($es)->pluck('id')->doesntContain($d['visit']->id))
                ->where('visit_stats.total', 0));

        $this->getJson('/admin/monitor-data')
            ->assertOk()
            ->assertJsonCount(0, 'escalations')
            ->assertJsonPath('visit_stats.total', 0);

        // The new assigned host sees it on their dashboard.
        $this->actingAs($d['recipientHost']);
        $this->get('/host/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard/Host')
                ->where('visits', fn ($visits) => collect($visits)->pluck('id')->contains($d['visit']->id)));
    }

    public function test_transferred_visit_not_duplicated_between_two_admins(): void
    {
        $d = $this->seedTransferData();

        $secondAdmin = User::create([
            'name'       => 'Second Admin',
            'email'      => 'admin2@transfer.test',
            'password'   => 'password',
            'role'       => 'admin',
            'company_id' => $d['company']->company_id,
            'status'     => 'active',
        ]);

        $d['visit']->update(['host_id' => $d['admin']->id]);

        $this->actingAs($d['admin']);
        $this->post("/visitor-transfer/{$d['visit']->id}", [
            'new_host_id' => $secondAdmin->id,
            'company_id'  => $d['company']->company_id,
            'reason'      => 'Hand over to second admin',
        ])->assertSessionHas('success');

        $this->assertSame($secondAdmin->id, $d['visit']->fresh()->host_id);
        $this->assertDatabaseCount('visit_requests', 1);

        // The original Admin is no longer the owner: nothing left on dashboard.
        $this->get('/admin/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard/Admin')
                ->where('all_visits', fn ($visits) => collect($visits)->pluck('id')->doesntContain($d['visit']->id))
                ->where('escalations', fn ($es) => collect($es)->pluck('id')->doesntContain($d['visit']->id))
                ->where('visit_stats.total', 0));

        // The new assigned Admin sees it in their own queue and Appointments.
        $this->actingAs($secondAdmin);
        $this->get('/admin/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard/Admin')
                ->where('all_visits', fn ($visits) => collect($visits)->pluck('id')->contains($d['visit']->id))
                ->where('escalations', fn ($es) => collect($es)->pluck('id')->contains($d['visit']->id))
                ->where('visit_stats.total', 1));
    }

    public function test_ceo_transfers_visitor_to_host(): void
    {
        $d = $this->seedTransferData();
        $this->actingAs($d['ceo']);

        $this->post("/visitor-transfer/{$d['visit']->id}", [
            'new_host_id'   => $d['recipientHost']->id,
            'company_id'    => $d['company']->company_id,
            'branch_id'     => $d['branch']->id,
            'department_id' => $d['dept']->id,
            'reason'        => 'CEO reassignment',
        ])->assertSessionHas('success');

        $this->assertSame($d['recipientHost']->id, $d['visit']->fresh()->host_id);
        $this->assertDatabaseHas('host_transfers', [
            'visit_request_id' => $d['visit']->id,
            'new_host_id'      => $d['recipientHost']->id,
            'transferred_by'   => $d['ceo']->id,
        ]);
    }

    public function test_transferring_escalated_visit_to_host_returns_to_pending_and_appears_on_host_dashboard(): void
    {
        $d = $this->seedTransferData();

        // The visit is escalated and the Admin is the responsible person.
        $d['visit']->update([
            'host_id'      => $d['admin']->id,
            'escalated_to' => $d['admin']->id,
            'status'       => 'escalated',
        ]);

        \App\Models\Escalation::create([
            'visit_request_id' => $d['visit']->id,
            'escalated_by'     => $d['host']->id,
            'escalated_to'     => $d['admin']->id,
            'reason'           => 'Needs reassignment',
            'trigger_type'     => 'manual',
        ]);

        $this->actingAs($d['admin']);

        $this->post("/visitor-transfer/{$d['visit']->id}", [
            'new_host_id'   => $d['recipientHost']->id,
            'company_id'    => $d['company']->company_id,
            'branch_id'     => $d['branch']->id,
            'department_id' => $d['dept']->id,
            'reason'        => 'Handing back to a host',
        ])->assertSessionHas('success');

        $fresh = $d['visit']->fresh();
        $this->assertSame($d['recipientHost']->id, $fresh->host_id);
        $this->assertSame('pending', $fresh->status);
        $this->assertNull($fresh->escalated_to);
        $this->assertNotNull($fresh->escalations()->first()->resolved_at);

        // The receiving host sees it on their dashboard under pending.
        $this->actingAs($d['recipientHost']);
        $this->get('/host/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard/Host')
                ->where('visits', function ($visits) use ($d) {
                    $row = collect($visits)->firstWhere('id', $d['visit']->id);
                    return $row !== null && ($row['status'] ?? null) === 'pending';
                }));
    }

    public function test_store_rejects_foreign_company_host(): void
    {
        $d = $this->seedTransferData();
        $this->actingAs($d['admin']);

        // Foreign company is not authorized for this admin.
        $this->post("/visitor-transfer/{$d['visit']->id}", [
            'new_host_id'   => $d['foreignHost']->id,
            'company_id'    => $d['otherCompany']->company_id,
            'branch_id'     => $d['otherBranch']->id,
            'department_id' => $d['otherDept']->id,
        ])->assertStatus(403);

        // Same company but the target host belongs to the other company: no match.
        $this->post("/visitor-transfer/{$d['visit']->id}", [
            'new_host_id'   => $d['foreignHost']->id,
            'company_id'    => $d['company']->company_id,
            'branch_id'     => $d['branch']->id,
            'department_id' => $d['dept']->id,
        ])->assertSessionHas('error');

        $this->assertSame($d['host']->id, $d['visit']->fresh()->host_id);
        $this->assertDatabaseCount('host_transfers', 0);
    }

    public function test_store_rejects_inactive_and_same_host(): void
    {
        $d = $this->seedTransferData();
        $d['recipientHost']->update(['status' => 'inactive']);
        $this->actingAs($d['admin']);

        $this->post("/visitor-transfer/{$d['visit']->id}", [
            'new_host_id'   => $d['recipientHost']->id,
            'company_id'    => $d['company']->company_id,
            'branch_id'     => $d['branch']->id,
            'department_id' => $d['dept']->id,
        ])->assertSessionHas('error');

        $this->post("/visitor-transfer/{$d['visit']->id}", [
            'new_host_id'   => $d['host']->id,
            'company_id'    => $d['company']->company_id,
            'branch_id'     => $d['branch']->id,
            'department_id' => $d['dept']->id,
        ])->assertSessionHas('error');

        $this->assertDatabaseCount('host_transfers', 0);
        $this->assertSame($d['host']->id, $d['visit']->fresh()->host_id);
    }

    public function test_store_rejects_completed_visit(): void
    {
        $d = $this->seedTransferData();
        $d['visit']->update(['status' => 'completed']);
        $this->actingAs($d['admin']);

        $this->post("/visitor-transfer/{$d['visit']->id}", [
            'new_host_id'   => $d['recipientHost']->id,
            'company_id'    => $d['company']->company_id,
            'branch_id'     => $d['branch']->id,
            'department_id' => $d['dept']->id,
        ])->assertSessionHas('error');

        $this->assertSame($d['host']->id, $d['visit']->fresh()->host_id);
        $this->assertDatabaseCount('host_transfers', 0);
    }

    public function test_transfer_moves_visit_company_to_new_host_company_when_cross_company_admin(): void
    {
        $d = $this->seedTransferData();

        // Admin authorized for both companies via company_user pivot.
        $d['admin']->companies()->attach($d['otherCompany']->company_id, [
            'role_id'   => 1,
            'role_name' => 'admin',
            'is_active' => true,
        ]);
        $this->actingAs($d['admin']);

        $this->getJson($this->optionsUrl($d['visit']->id))
            ->assertOk()
            ->assertJsonCount(2, 'companies');

        // Admin moves the visit into the other company for its host.
        $this->post("/visitor-transfer/{$d['visit']->id}", [
            'new_host_id'   => $d['foreignHost']->id,
            'company_id'    => $d['otherCompany']->company_id,
            'branch_id'     => $d['otherBranch']->id,
            'department_id' => $d['otherDept']->id,
            'reason'        => 'Cross-company move',
        ])->assertSessionHas('success');

        $fresh = $d['visit']->fresh();
        $this->assertSame($d['foreignHost']->id, $fresh->host_id);
        $this->assertSame($d['otherCompany']->company_id, $fresh->company_id);
        $this->assertSame($d['otherBranch']->id, $fresh->branch_id);
        $this->assertSame($d['otherDept']->id, $fresh->department_id);

        $this->assertDatabaseHas('host_transfers', [
            'visit_request_id' => $d['visit']->id,
            'previous_host_id' => $d['host']->id,
            'new_host_id'      => $d['foreignHost']->id,
            'company_id'       => $d['otherCompany']->company_id,
        ]);
    }

    public function test_branch_selection_lists_only_branch_assigned_users(): void
    {
        $d = $this->seedTransferData();

        // A company-level (no-branch) Host that belongs to the branch's own
        // department — it must NOT appear once a branch is selected.
        $nullBranchHost = User::create([
            'name'          => 'No Branch Host',
            'email'         => 'nobranch@transfer.test',
            'password'      => 'password',
            'role'          => 'host',
            'company_id'    => $d['company']->company_id,
            'branch_id'     => null,
            'department_id' => $d['dept']->id,
            'status'        => 'active',
        ]);

        // A second branch with its own department and Host — also excluded when
        // the first branch is selected.
        $branchTwo = Branch::create([
            'name'       => 'Branch Two',
            'slug'       => 'branch-two-extra',
            'company_id' => $d['company']->company_id,
            'is_active'  => true,
        ]);
        $deptTwo = Department::create([
            'name'       => 'Ops',
            'company_id' => $d['company']->company_id,
            'branch_id'  => $branchTwo->id,
        ]);
        $branchTwoHost = User::create([
            'name'          => 'Branch Two Host',
            'email'         => 'host-b2@transfer.test',
            'password'      => 'password',
            'role'          => 'host',
            'company_id'    => $d['company']->company_id,
            'branch_id'     => $branchTwo->id,
            'department_id' => $deptTwo->id,
            'status'        => 'active',
        ]);

        $this->actingAs($d['admin']);

        // Company only: every eligible user of the company is offered.
        $companyOnly = $this->getJson($this->optionsUrl($d['visit']->id, [
            'company_id' => $d['company']->company_id,
        ]))->assertOk();
        $companyHostIds = array_column($companyOnly->json('hosts'), 'id');
        $this->assertContains($d['recipientHost']->id, $companyHostIds);
        $this->assertContains($nullBranchHost->id, $companyHostIds);
        $this->assertContains($branchTwoHost->id, $companyHostIds);
        // The acting Admin is excluded from their own recipient list, but the
        // company-level CEO is offered with a company-only selection.
        $this->assertContains('ceo', array_column($companyOnly->json('managers'), 'role'));

        // Branch selected: only users assigned to that branch are offered.
        $branchOnly = $this->getJson($this->optionsUrl($d['visit']->id, [
            'company_id' => $d['company']->company_id,
            'branch_id'  => $d['branch']->id,
        ]))->assertOk();

        $branchHostIds = array_column($branchOnly->json('hosts'), 'id');
        $this->assertContains($d['recipientHost']->id, $branchHostIds);
        $this->assertNotContains($nullBranchHost->id, $branchHostIds);
        $this->assertNotContains($branchTwoHost->id, $branchHostIds);

        // Company-level managers are no longer offered.
        $this->assertSame([], $branchOnly->json('managers'));

        // Only the selected branch's departments are listed.
        $this->assertSame([$d['dept']->id], array_column($branchOnly->json('departments'), 'id'));
    }

    public function test_store_accepts_company_level_host_when_no_branch_selected(): void
    {
        $d = $this->seedTransferData();

        $companyDept = Department::create([
            'name'       => 'Finance HQ',
            'company_id' => $d['company']->company_id,
            'branch_id'  => null,
        ]);

        $nullBranchHost = User::create([
            'name'          => 'No Branch Host',
            'email'         => 'nobranch@transfer.test',
            'password'      => 'password',
            'role'          => 'host',
            'company_id'    => $d['company']->company_id,
            'branch_id'     => null,
            'department_id' => $companyDept->id,
            'status'        => 'active',
        ]);

        $this->actingAs($d['admin']);

        // Company-only selection: a company-level Host is eligible.
        $this->post("/visitor-transfer/{$d['visit']->id}", [
            'new_host_id'   => $nullBranchHost->id,
            'company_id'    => $d['company']->company_id,
            'department_id' => $companyDept->id,
            'reason'        => 'HQ finance takes over',
        ])->assertSessionHas('success');

        $this->assertSame($nullBranchHost->id, $d['visit']->fresh()->host_id);
        $this->assertDatabaseHas('host_transfers', [
            'visit_request_id' => $d['visit']->id,
            'previous_host_id' => $d['host']->id,
            'new_host_id'      => $nullBranchHost->id,
            'company_id'       => $d['company']->company_id,
            'department_id'    => $companyDept->id,
        ]);
    }

    public function test_store_rejects_company_level_host_when_branch_selected(): void
    {
        $d = $this->seedTransferData();

        $nullBranchHost = User::create([
            'name'          => 'No Branch Host',
            'email'         => 'nobranch@transfer.test',
            'password'      => 'password',
            'role'          => 'host',
            'company_id'    => $d['company']->company_id,
            'branch_id'     => null,
            'department_id' => $d['dept']->id,
            'status'        => 'active',
        ]);

        $this->actingAs($d['admin']);

        // A branch is selected, so a company-level (no-branch) Host is not an
        // eligible recipient even though they share the branch's department.
        $this->post("/visitor-transfer/{$d['visit']->id}", [
            'new_host_id'   => $nullBranchHost->id,
            'company_id'    => $d['company']->company_id,
            'branch_id'     => $d['branch']->id,
            'department_id' => $d['dept']->id,
        ])->assertSessionHas('error');

        $this->assertSame($d['host']->id, $d['visit']->fresh()->host_id);
        $this->assertDatabaseCount('host_transfers', 0);
    }

    public function test_ceo_transfer_removes_visit_from_ceo_company_visits(): void
    {
        $d = $this->seedTransferData();

        // The visit belongs to the CEO.
        $d['visit']->update(['host_id' => $d['ceo']->id, 'status' => 'approved']);

        $this->actingAs($d['ceo']);
        $this->get('/ceo/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard/CEO')
                ->where('all_visits', fn ($v) => collect($v)->pluck('id')->contains($d['visit']->id)));

        // CEO hands it to a host.
        $this->post("/visitor-transfer/{$d['visit']->id}", [
            'new_host_id'   => $d['recipientHost']->id,
            'company_id'    => $d['company']->company_id,
            'branch_id'     => $d['branch']->id,
            'department_id' => $d['dept']->id,
            'reason'        => 'CEO hands to host',
        ])->assertSessionHas('success');

        $this->assertSame($d['recipientHost']->id, $d['visit']->fresh()->host_id);

        // It must be gone from the CEO dashboard entirely.
        $this->get('/ceo/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard/CEO')
                ->where('all_visits', fn ($v) => collect($v)->pluck('id')->doesntContain($d['visit']->id)));

        // And now owned by the receiving host.
        $this->actingAs($d['recipientHost']);
        $this->get('/host/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard/Host')
                ->where('visits', fn ($v) => collect($v)->pluck('id')->contains($d['visit']->id)));
    }

    public function test_admin_transfers_visitor_to_ceo_and_appears_on_ceo_visits_page(): void
    {
        $d = $this->seedTransferData();

        $this->actingAs($d['admin']);
        $this->post("/visitor-transfer/{$d['visit']->id}", [
            'new_host_id' => $d['ceo']->id,
            'company_id'  => $d['company']->company_id,
            'reason'      => 'Escalated to CEO',
        ])->assertSessionHas('success');

        $fresh = $d['visit']->fresh();
        $this->assertSame($d['ceo']->id, $fresh->host_id);

        // Verify the visit appears on the CEO Visits page
        $this->actingAs($d['ceo']);
        $this->get('/ceo/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard/CEO')
                ->where('all_visits', fn ($visits) => collect($visits)->pluck('id')->contains($d['visit']->id))
            );

        // Verify transfer notification sent to CEO
        $this->assertDatabaseHas('system_notifications', [
            'recipient_type' => 'user',
            'recipient_id'   => $d['ceo']->id,
            'title'          => 'New Visit Assigned',
        ]);
    }

    public function test_cross_company_transfer_to_ceo_keeps_full_visitor_record_on_ceo_dashboard(): void
    {
        $d = $this->seedTransferData();

        $foreignCeo = User::create([
            'name'       => 'Foreign CEO',
            'email'      => 'ceo@other.test',
            'password'   => 'password',
            'role'       => 'ceo',
            'company_id' => $d['otherCompany']->company_id,
            'status'     => 'active',
        ]);

        // Admin authorized for the other company via company_user pivot.
        $d['admin']->companies()->attach($d['otherCompany']->company_id, [
            'role_id'   => 1,
            'role_name' => 'admin',
            'is_active' => true,
        ]);
        $this->actingAs($d['admin']);

        $this->post("/visitor-transfer/{$d['visit']->id}", [
            'new_host_id' => $foreignCeo->id,
            'company_id'  => $d['otherCompany']->company_id,
            'reason'      => 'Cross-company escalation to CEO',
        ])->assertSessionHas('success');

        $fresh = $d['visit']->fresh();
        $this->assertSame($foreignCeo->id, $fresh->host_id);
        $this->assertSame($d['otherCompany']->company_id, $fresh->company_id);
        // The visitor still belongs to the original company.
        $this->assertSame($d['company']->company_id, $fresh->visitor()->withoutGlobalScopes()->first()->company_id);

        // The receiving CEO must see the complete visitor record, not a null/partial one.
        $this->actingAs($foreignCeo);
        $this->get('/ceo/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard/CEO')
                ->where('all_visits', function ($visits) use ($d) {
                    $row = collect($visits)->firstWhere('id', $d['visit']->id);
                    return $row !== null
                        && ($row['visitor']['id'] ?? null) === $d['visitor']->id
                        && ($row['visitor']['name'] ?? null) === 'Transfer Visitor';
                })
            );
    }

    public function test_admin_end_visit_generates_exit_qr_notifies_visitor_and_enables_security_checkout(): void
    {
        $d = $this->seedTransferData();
        $d['visit']->update(['status' => 'checked_in']);

        $this->actingAs($d['admin']);
        $this->post("/admin/visit-requests/{$d['visit']->id}/end")
            ->assertSessionHas('success');

        $fresh = $d['visit']->fresh();
        $this->assertSame('checked_out', $fresh->status);
        $this->assertNotEmpty($fresh->exit_qr_token);

        // Duplicate call reuses existing Exit QR code
        $firstToken = $fresh->exit_qr_token;
        $d['visit']->update(['status' => 'checked_in']);
        $this->post("/admin/visit-requests/{$d['visit']->id}/end");
        $this->assertSame($firstToken, $d['visit']->fresh()->exit_qr_token);

        // Security Checkout validation
        $securityGuard = User::create([
            'name'       => 'Guard',
            'email'      => 'guard@transfer.test',
            'password'   => 'password',
            'role'       => 'security_guard',
            'company_id' => $d['company']->company_id,
            'branch_id'  => $d['branch']->id,
            'status'     => 'active',
        ]);

        $this->actingAs($securityGuard);
        $res = $this->postJson('/security/scan', [
            'token_value' => $firstToken,
        ]);
        $res->assertOk();
        $this->assertTrue($res->json('is_exit_scan'));
    }
}
