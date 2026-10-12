<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\User;
use App\Models\VisitRequest;
use App\Models\Visitor;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HostAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private Company $companyA;
    private Company $companyB;
    private Branch $branchA;
    private Branch $branchB;
    private Department $itDept;
    private Department $hrDept;
    private Department $financeDept;
    private Department $procurementDept;
    private User $hostItA;
    private User $hostHrA;
    private User $hostFinanceA;
    private User $hostOtherCompany;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyA = Company::create(['company_name' => 'Alpha Corp', 'status' => 'active']);
        $this->companyB = Company::create(['company_name' => 'Beta Corp', 'status' => 'active']);

        $this->branchA = Branch::create(['company_id' => $this->companyA->company_id, 'name' => 'HQ', 'slug' => 'alpha-hq', 'is_active' => true]);
        $this->branchB = Branch::create(['company_id' => $this->companyA->company_id, 'name' => 'West', 'slug' => 'alpha-west', 'is_active' => true]);

        $this->itDept        = Department::create(['name' => 'Information Technology', 'company_id' => $this->companyA->company_id]);
        $this->hrDept        = Department::create(['name' => 'Human Resources', 'company_id' => $this->companyA->company_id]);
        $this->financeDept   = Department::create(['name' => 'Finance', 'company_id' => $this->companyA->company_id]);
        $this->procurementDept = Department::create(['name' => 'Procurement', 'company_id' => $this->companyA->company_id]);

        $this->hostItA = $this->host('Host IT HQ', $this->itDept, $this->branchA);
        $this->hostHrA = $this->host('Host HR HQ', $this->hrDept, $this->branchA);
        $this->hostFinanceA = $this->host('Host Finance HQ', $this->financeDept, $this->branchA);

        $itDeptOtherCompany = Department::create(['name' => 'Information Technology Other', 'company_id' => $this->companyB->company_id]);
        $this->hostOtherCompany = $this->host('Host IT Beta', $itDeptOtherCompany, null);
    }

    private function host(string $name, Department $dept, ?Branch $branch): User
    {
        return User::create([
            'name'          => $name,
            'email'         => strtolower(str_replace([' ', "'"], '', $name)) . '@' . $dept->id . '.test',
            'password'      => 'password',
            'role'          => 'host',
            'status'        => 'active',
            'company_id'    => $dept->company_id,
            'department_id' => $dept->id,
            'branch_id'     => $branch?->id,
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'visitor_name'   => 'John Doe',
            'id_document_no' => '123456',
            'visitor_email'  => 'john@example.com',
            'visitor_phone'  => '123456789',
            'company_id'     => $this->companyA->company_id,
            'branch_id'      => $this->branchA->id,
            'purpose'        => 'Software support',
            'requested_time' => $this->nextWeekdayTime(),
            'duration'       => 60,
        ], $overrides);
    }

    private function nextWeekdayTime(): string
    {
        $time = Carbon::tomorrow();
        while ($time->isWeekend()) {
            $time->addDay();
        }
        return $time->setTime(10, 0)->format('Y-m-d\TH:i');
    }

    private function latestRequest(): VisitRequest
    {
        return VisitRequest::latest('id')->first();
    }

    public function test_purpose_resolves_department_and_host_belongs_to_it(): void
    {
        $response = $this->post('/request-visit', $this->payload(['purpose' => 'Software support']));

        $response->assertSessionHas('success');

        $request = $this->latestRequest();
        $this->assertSame($this->itDept->id, $request->department_id, 'Request must be routed to the IT department for a software purpose.');

        $host = User::find($request->host_id);
        $this->assertSame($this->itDept->id, $host->department_id, 'Assigned host must belong to the resolved department.');
        $this->assertSame($this->companyA->company_id, $host->company_id, 'Host must belong to the visitor\'s company.');
        $this->assertSame($this->branchA->id, $host->branch_id, 'Host must belong to the visitor\'s branch.');
        $this->assertSame($this->hostItA->id, $request->host_id, 'The only valid host in IT/company/branch should be selected.');
    }

    public function test_job_interview_purpose_resolves_hr_department(): void
    {
        $response = $this->post('/request-visit', $this->payload(['purpose' => 'Job Interview']));

        $response->assertSessionHas('success');

        $request = $this->latestRequest();
        $this->assertSame($this->hrDept->id, $request->department_id);

        $host = User::find($request->host_id);
        $this->assertSame($this->hrDept->id, $host->department_id);
        $this->assertSame($this->hostHrA->id, $request->host_id);
    }

    public function test_explicit_host_selection_syncs_department_to_host(): void
    {
        $response = $this->post('/request-visit', $this->payload([
            'purpose' => 'Anything at all',
            'host_id' => $this->hostFinanceA->id,
        ]));

        $response->assertSessionHas('success');

        $request = $this->latestRequest();
        $this->assertSame($this->hostFinanceA->id, $request->host_id);
        $this->assertSame($this->financeDept->id, $request->department_id, 'Department must follow the explicitly selected host.');
        $this->assertSame($this->financeDept->id, $this->hostFinanceA->department_id, 'Host and department must always agree.');
    }

    public function test_explicit_department_honored_and_host_comes_from_that_department(): void
    {
        $response = $this->post('/request-visit', $this->payload([
            'purpose'      => 'Something unrelated',
            'department_id' => $this->financeDept->id,
        ]));

        $response->assertSessionHas('success');

        $request = $this->latestRequest();
        $this->assertSame($this->financeDept->id, $request->department_id);
        $this->assertSame($this->hostFinanceA->id, $request->host_id, 'Host must come from the explicitly selected department.');
    }

    public function test_no_host_in_department_returns_clear_error_not_mismatch(): void
    {
        $response = $this->post('/request-visit', $this->payload([
            'department_id' => $this->procurementDept->id,
        ]));

        $response->assertSessionHas('error', fn($error) => str_contains($error, 'No active host is assigned to the \'Procurement\' department'));

        $this->assertDatabaseMissing('visit_requests', ['department_id' => $this->procurementDept->id]);
    }

    public function test_no_department_with_host_for_purpose_returns_clear_error(): void
    {
        $loneCompany = Company::create(['company_name' => 'Gamma Corp', 'status' => 'active']);
        $loneDept = Department::create(['name' => 'Information Technology', 'company_id' => $loneCompany->company_id]);

        $response = $this->post('/request-visit', $this->payload([
            'company_id' => $loneCompany->company_id,
            'purpose'    => 'Software support',
        ]));

        $response->assertSessionHas('error', fn($error) => str_contains($error, 'No active host is available for a department matching this purpose'));

        $this->assertDatabaseMissing('visit_requests', ['department_id' => $loneDept->id]);
    }

    public function test_no_host_in_resolved_department_for_selected_branch_falls_back_to_company_host(): void
    {
        // The IT department only has a host in branch A; the visitor picks branch B.
        // The request must still reach a host: fall back to the department's
        // company-wide hosts and re-tag the request to that host's branch.
        $response = $this->post('/request-visit', $this->payload([
            'branch_id' => $this->branchB->id,
            'purpose'   => 'Software support',
        ]));

        $response->assertSessionHas('success');

        $request = $this->latestRequest();
        $this->assertSame($this->itDept->id, $request->department_id);
        $this->assertSame($this->hostItA->id, $request->host_id, 'Falls back to the department host from another branch.');
        $this->assertSame($this->branchA->id, (int) $request->branch_id, 'Request re-tagged to the assigned host\'s branch so it stays visible.');
    }

    public function test_host_from_another_company_is_never_assigned(): void
    {
        $response = $this->post('/request-visit', $this->payload([
            'company_id' => $this->companyA->company_id,
            'purpose'    => 'Software support',
        ]));

        $response->assertSessionHas('success');

        $request = $this->latestRequest();
        $this->assertNotSame($this->hostOtherCompany->id, $request->host_id, 'A host from another company must never be assigned.');
        $this->assertSame($this->companyA->company_id, User::find($request->host_id)->company_id);
    }

    public function test_inactive_host_in_department_is_not_assigned(): void
    {
        User::create([
            'name'          => 'Host IT Inactive',
            'email'         => 'inactive-it@' . $this->itDept->id . '.test',
            'password'      => 'password',
            'role'          => 'host',
            'status'        => 'inactive',
            'company_id'    => $this->companyA->company_id,
            'department_id' => $this->itDept->id,
            'branch_id'     => $this->branchA->id,
        ]);

        $response = $this->post('/request-visit', $this->payload(['purpose' => 'Software support']));

        $response->assertSessionHas('success');

        $request = $this->latestRequest();
        $this->assertSame($this->hostItA->id, $request->host_id, 'Only the active host in the department may be assigned.');
    }

    public function test_false_no_active_host_error_is_fixed_when_company_wide_department_has_the_host(): void
    {
        // Regression for the production bug: a branch-specific department row exists
        // for the selected branch but holds no host, while the only active host lives
        // in the company-wide Finance department. The old code restricted the search
        // to branch-specific departments, saw no hosts, and rejected the request with
        // the false "No active host is available..." error even though a valid host
        // exists in the selected branch.
        $company = Company::create(['company_name' => 'Gamma Corp', 'status' => 'active']);
        $branch  = Branch::create(['company_id' => $company->company_id, 'name' => 'HQ', 'slug' => 'gamma-hq', 'is_active' => true]);

        Department::create(['name' => 'Human Resourse', 'company_id' => $company->company_id, 'branch_id' => $branch->id]);
        $finDept  = Department::create(['name' => 'Finance', 'company_id' => $company->company_id]);
        $hostFin  = $this->host('Host Gamma Finance', $finDept, $branch);

        $response = $this->post('/request-visit', $this->payload([
            'company_id' => $company->company_id,
            'branch_id'  => $branch->id,
            'purpose'    => 'Invoice payment',
        ]));

        $response->assertSessionHas('success');

        $request = $this->latestRequest();
        $this->assertSame($hostFin->id, $request->host_id);
        $this->assertSame($finDept->id, $request->department_id);
        $this->assertSame($branch->id, User::find($request->host_id)->branch_id, 'Assigned host must belong to the selected branch.');
    }

    public function test_host_serves_branch_based_on_own_branch_not_department_row_branch(): void
    {
        // Regression: the HR department row is tagged as branch B, but the active HR
        // host works at branch A. The host's own branch is authoritative — the old
        // code constrained hosts to the department row's branch and falsely rejected
        // the request.
        $company = Company::create(['company_name' => 'Delta Corp', 'status' => 'active']);
        $branchA = Branch::create(['company_id' => $company->company_id, 'name' => 'North', 'slug' => 'delta-north', 'is_active' => true]);
        $branchB = Branch::create(['company_id' => $company->company_id, 'name' => 'South', 'slug' => 'delta-south', 'is_active' => true]);

        $hrDept = Department::create(['name' => 'Human Resources', 'company_id' => $company->company_id, 'branch_id' => $branchB->id]);
        $hostHr = $this->host('Host Delta HR', $hrDept, $branchA);

        $response = $this->post('/request-visit', $this->payload([
            'company_id' => $company->company_id,
            'branch_id'  => $branchA->id,
            'purpose'    => 'Job Interview',
        ]));

        $response->assertSessionHas('success');
        $request = $this->latestRequest();
        $this->assertSame($hostHr->id, $request->host_id);
        $this->assertSame($hrDept->id, $request->department_id);
    }

    public function test_job_interview_matches_typo_department_name_human_resourse(): void
    {
        // The production department is named "Human Resourse" (typo). The purpose
        // 'Job Interview' must resolve to it (not silently fall back to the busiest
        // department) even though the Finance department has more hosts.
        $company = Company::create(['company_name' => 'Zeta Corp', 'status' => 'active']);
        $branch  = Branch::create(['company_id' => $company->company_id, 'name' => 'HQ', 'slug' => 'zeta-hq', 'is_active' => true]);

        $hrDept = Department::create(['name' => 'Human Resourse', 'company_id' => $company->company_id]);
        $finDept = Department::create(['name' => 'Finance', 'company_id' => $company->company_id]);
        $hostHr  = $this->host('Host Zeta HR', $hrDept, $branch);
        $this->host('Host Zeta Finance 1', $finDept, $branch);
        $this->host('Host Zeta Finance 2', $finDept, $branch);

        $response = $this->post('/request-visit', $this->payload([
            'company_id' => $company->company_id,
            'branch_id'  => $branch->id,
            'purpose'    => 'Job Interview',
        ]));

        $response->assertSessionHas('success');
        $request = $this->latestRequest();
        $this->assertSame($hrDept->id, $request->department_id, "Job Interview must resolve to the (typo'd) Human Resourse department.");
        $this->assertSame($hostHr->id, $request->host_id);
    }

    public function test_duplicate_department_names_resolve_to_department_with_branch_host(): void
    {
        // Multiple departments share the name "Information Technology" (one per
        // branch plus a company-wide row). Only the company-wide row holds a host
        // that works at the selected branch. The old mapWithKeys() collapsed the
        // duplicate names, so the wrong (host-less) row could win.
        $company = Company::create(['company_name' => 'Epsilon Corp', 'status' => 'active']);
        $branchA = Branch::create(['company_id' => $company->company_id, 'name' => 'East', 'slug' => 'eps-east', 'is_active' => true]);
        $branchB = Branch::create(['company_id' => $company->company_id, 'name' => 'West', 'slug' => 'eps-west', 'is_active' => true]);

        Department::create(['name' => 'Information Technology', 'company_id' => $company->company_id, 'branch_id' => $branchA->id]);
        Department::create(['name' => 'Information Technology', 'company_id' => $company->company_id, 'branch_id' => $branchB->id]);
        $itCompany = Department::create(['name' => 'Information Technology', 'company_id' => $company->company_id]);
        $hostIt    = $this->host('Host Epsilon IT', $itCompany, $branchB);

        $response = $this->post('/request-visit', $this->payload([
            'company_id' => $company->company_id,
            'branch_id'  => $branchB->id,
            'purpose'    => 'Software support',
        ]));

        $response->assertSessionHas('success');
        $request = $this->latestRequest();
        $this->assertSame($hostIt->id, $request->host_id);
        $this->assertSame($itCompany->id, $request->department_id);
    }

    public function test_multiple_hosts_in_same_department_and_branch_select_least_busy(): void
    {
        $visitor = Visitor::create([
            'name'          => 'Existing Visitor',
            'email'         => 'existing@visitor.test',
            'phone'         => '555000111',
            'id_document_no' => '999888',
            'password'      => 'password',
            'company_id'    => $this->companyA->company_id,
        ]);

        VisitRequest::create([
            'visitor_id'     => $visitor->id,
            'host_id'        => $this->hostItA->id,
            'department_id'  => $this->itDept->id,
            'branch_id'      => $this->branchA->id,
            'company_id'     => $this->companyA->company_id,
            'purpose'        => 'Existing visit',
            'requested_time' => Carbon::now()->addDay(),
            'duration'       => 60,
            'status'         => 'pending',
        ]);

        $secondHost = $this->host('Host IT A 2', $this->itDept, $this->branchA);

        $response = $this->post('/request-visit', $this->payload(['purpose' => 'Software support']));

        $response->assertSessionHas('success');
        $request = $this->latestRequest();
        $this->assertSame($secondHost->id, $request->host_id, 'Least-busy host (no active visits) must be selected.');
    }

    public function test_no_host_anywhere_in_company_returns_no_active_host_error(): void
    {
        $company = Company::create(['company_name' => 'Eta Corp', 'status' => 'active']);
        Department::create(['name' => 'Finance', 'company_id' => $company->company_id]);

        $response = $this->post('/request-visit', $this->payload([
            'company_id' => $company->company_id,
            'purpose'    => 'Invoice payment',
        ]));

        $response->assertSessionHas('error', fn($error) => str_contains($error, 'No active host is available for a department matching this purpose'));
        $this->assertDatabaseCount('visit_requests', 0);
    }

    public function test_host_transfer_moves_visit_to_new_host_branch_so_scoped_dashboard_shows_it(): void
    {
        $hostNoBranch = User::create([
            'name'          => 'Host No Branch',
            'email'         => 'hostnobranch@alpha.test',
            'password'      => 'password',
            'role'          => 'host',
            'company_id'    => $this->companyA->company_id,
            'department_id' => $this->itDept->id,
            'branch_id'     => null,
            'status'        => 'active',
        ]);

        $visitor = Visitor::create([
            'name'           => 'Relocated Visitor',
            'email'          => 'relocated@visitor.test',
            'phone'          => '555000222',
            'id_document_no' => '777666',
            'password'       => 'password',
            'company_id'     => $this->companyA->company_id,
        ]);

        // Company-level visit (no branch) assigned to a branch-less host.
        $visit = VisitRequest::create([
            'visitor_id'     => $visitor->id,
            'host_id'        => $hostNoBranch->id,
            'department_id'  => $this->itDept->id,
            'branch_id'      => null,
            'company_id'     => $this->companyA->company_id,
            'purpose'        => 'Handoff to branch host',
            'requested_time' => Carbon::now()->addDay(),
            'duration'       => 60,
            'status'         => 'pending',
        ]);

        $this->actingAs($hostNoBranch);

        $this->post('/visit-requests/' . $visit->id . '/transfer', [
            'visit_request_id' => $visit->id,
            'new_host_id'      => $this->hostItA->id,
            'reason'           => 'Handoff to branch host',
        ])->assertSessionHas('success');

        $fresh = $visit->fresh();

        // New host assigned and no duplication.
        $this->assertSame($this->hostItA->id, $fresh->host_id);
        $this->assertDatabaseCount('visit_requests', 1);

        // The visit follows the new host's branch so the recipient's scoped
        // dashboard query actually returns it (matches Admin→Host/escalation).
        $this->assertSame($this->branchA->id, $fresh->branch_id);
        $this->assertSame($this->companyA->company_id, $fresh->company_id);
        $this->assertSame($this->itDept->id, $fresh->department_id);
    }
}
