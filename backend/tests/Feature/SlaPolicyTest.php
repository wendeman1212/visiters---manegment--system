<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyPolicy;
use App\Models\Department;
use App\Models\Escalation;
use App\Models\User;
use App\Models\VisitRequest;
use App\Models\Visitor;
use App\Services\ApprovalService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SlaPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function makeCompany(string $name): Company
    {
        return Company::create(['company_name' => $name, 'status' => 'active']);
    }

    private function makeUser(Company $company, string $role, string $email): User
    {
        return User::create([
            'name'       => $role . ' of ' . $company->company_name,
            'email'      => $email,
            'password'   => 'password',
            'role'       => $role,
            'company_id' => $company->company_id,
            'status'     => 'active',
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

    private function makePendingVisit(Company $company, User $host, Carbon $createdAt, ?int $branchId = null): VisitRequest
    {
        $suffix = $company->company_name . '-' . uniqid();

        $visit = VisitRequest::create([
            'visitor_id'     => $this->makeVisitor($company, $suffix)->id,
            'host_id'        => $host->id,
            'branch_id'      => $branchId,
            'purpose'        => 'SLA policy test visit',
            'visit_type'     => 'solo',
            'requested_time' => Carbon::now()->addDay()->startOfHour(),
            'duration'       => 60,
            'status'         => 'pending',
            'company_id'     => $company->company_id,
        ]);

        $visit->created_at = $createdAt;
        $visit->updated_at = $createdAt;
        $visit->save();

        return $visit;
    }

    public function test_ceo_can_set_sla_timeout_for_own_company(): void
    {
        $company = $this->makeCompany('Policy Corp');
        $ceo     = $this->makeUser($company, 'ceo', 'ceo@policy.test');

        $this->actingAs($ceo)->post('/ceo/policies', [
            'allow_weekends'    => 'true',
            'enforce_biz_hours' => 'true',
            'sla_minutes'       => 30,
        ])->assertSessionHas('success');

        $this->assertDatabaseHas('company_policies', [
            'company_id'  => $company->company_id,
            'sla_minutes' => 30,
        ]);
        $this->assertSame(30, CompanyPolicy::slaMinutesForCompany($company->company_id));
    }

    public function test_sla_policy_is_independent_per_company(): void
    {
        $companyA = $this->makeCompany('Isolation A');
        $companyB = $this->makeCompany('Isolation B');

        CompanyPolicy::create(['company_id' => $companyA->company_id, 'sla_minutes' => 30]);
        CompanyPolicy::create(['company_id' => $companyB->company_id, 'sla_minutes' => 5]);

        $this->assertSame(30, CompanyPolicy::slaMinutesForCompany($companyA->company_id));
        $this->assertSame(5, CompanyPolicy::slaMinutesForCompany($companyB->company_id));
    }

    public function test_sla_policy_never_affects_another_company(): void
    {
        $companyA = $this->makeCompany('Fast SLA');
        $companyB = $this->makeCompany('Slow SLA');

        $adminA = $this->makeUser($companyA, 'admin', 'admin@fast.test');
        $this->makeUser($companyB, 'admin', 'admin@slow.test');
        $hostA = $this->makeUser($companyA, 'host', 'host@fast.test');
        $hostB = $this->makeUser($companyB, 'host', 'host@slow.test');

        // A escalates after 5 min, B after 120 min. Both visits idle 60 min.
        CompanyPolicy::create(['company_id' => $companyA->company_id, 'sla_minutes' => 5]);
        CompanyPolicy::create(['company_id' => $companyB->company_id, 'sla_minutes' => 120]);

        $old = Carbon::now()->subMinutes(60);
        $visitA = $this->makePendingVisit($companyA, $hostA, $old);
        $visitB = $this->makePendingVisit($companyB, $hostB, $old);

        ApprovalService::processAutoEscalations();

        // Company A's request breached its own SLA and escalated to A's Admin.
        $this->assertSame('escalated', $visitA->fresh()->status);
        $this->assertSame($adminA->id, $visitA->fresh()->escalated_to);
        $this->assertDatabaseHas('escalations', [
            'visit_request_id' => $visitA->id,
            'escalated_to'     => $adminA->id,
        ]);

        // Company B's request is still within its own (longer) SLA — untouched.
        $this->assertSame('pending', $visitB->fresh()->status);
        $this->assertNull($visitB->fresh()->escalated_to);
        $this->assertDatabaseMissing('escalations', ['visit_request_id' => $visitB->id]);
    }

    public function test_all_branches_of_a_company_inherit_the_company_sla_policy(): void
    {
        $company = $this->makeCompany('Branch Corp');
        $admin   = $this->makeUser($company, 'admin', 'admin@branch.test');
        $host    = $this->makeUser($company, 'host', 'host@branch.test');

        $dept = Department::create(['name' => 'Operations', 'company_id' => $company->company_id]);

        $branch1 = Branch::create(['company_id' => $company->company_id, 'name' => 'HQ', 'slug' => 'hq']);
        $branch2 = Branch::create(['company_id' => $company->company_id, 'name' => 'Satellite', 'slug' => 'satellite']);

        // No branch-level policy: every branch inherits the company SLA of 10 min.
        CompanyPolicy::create(['company_id' => $company->company_id, 'sla_minutes' => 10]);

        $old = Carbon::now()->subMinutes(30);
        $visit1 = $this->makePendingVisit($company, $host, $old, $branch1->id);
        $visit2 = $this->makePendingVisit($company, $host, $old, $branch2->id);

        ApprovalService::processAutoEscalations();

        $this->assertSame('escalated', $visit1->fresh()->status);
        $this->assertSame('escalated', $visit2->fresh()->status);
        $this->assertSame($admin->id, $visit1->fresh()->escalated_to);
        $this->assertSame($admin->id, $visit2->fresh()->escalated_to);
    }

    public function test_auto_escalation_records_time_original_host_admin_and_reason(): void
    {
        $company = $this->makeCompany('Record Corp');
        $admin   = $this->makeUser($company, 'admin', 'admin@record.test');
        $host    = $this->makeUser($company, 'host', 'host@record.test');

        CompanyPolicy::create(['company_id' => $company->company_id, 'sla_minutes' => 30]);

        $visit = $this->makePendingVisit($company, $host, Carbon::now()->subMinutes(31));

        ApprovalService::processAutoEscalations();

        $escalation = Escalation::where('visit_request_id', $visit->id)->first();

        $this->assertNotNull($escalation);
        $this->assertSame($host->id, $escalation->escalated_by);
        $this->assertSame($admin->id, $escalation->escalated_to);
        $this->assertStringContainsString('30', $escalation->reason);
        $this->assertNotNull($escalation->created_at);
    }

    public function test_admin_saves_sla_policy_for_own_company(): void
    {
        $company = $this->makeCompany('Admin Policy Corp');
        $admin   = $this->makeUser($company, 'admin', 'admin2@policy.test');

        $this->actingAs($admin)->post('/admin/sla-settings', [
            'sla_minutes'       => 45,
            'allow_weekends'    => true,
            'enforce_biz_hours' => true,
        ])->assertSessionHas('success');

        $this->assertSame(45, CompanyPolicy::slaMinutesForCompany($company->company_id));
        $this->assertDatabaseHas('company_policies', [
            'company_id'  => $company->company_id,
            'sla_minutes' => 45,
        ]);
    }
}
