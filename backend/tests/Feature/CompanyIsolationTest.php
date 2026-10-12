<?php

namespace Tests\Feature;

use App\Models\CheckInOutLog;
use App\Models\Company;
use App\Models\Department;
use App\Models\QRToken;
use App\Models\User;
use App\Models\VisitRequest;
use App\Models\Visitor;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CompanyIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function company(string $name): Company
    {
        return Company::create([
            'company_name' => $name,
            'status'       => 'active',
        ]);
    }

    private function user(string $name, string $role, Company $company, string $status = 'active'): User
    {
        return User::create([
            'name'       => $name,
            'email'      => strtolower($name) . '@' . strtolower(str_replace(' ', '', $company->company_name)) . '.test',
            'password'   => 'password',
            'role'       => $role,
            'company_id' => $company->company_id,
            'status'     => $status,
        ]);
    }

    private function seedTwoCompanies(): array
    {
        $companyA = $this->company('Alpha Corp');
        $companyB = $this->company('Beta Corp');

        $ceoA   = $this->user('Ceo Alpha', 'ceo', $companyA);
        $adminA = $this->user('Admin Alpha', 'admin', $companyA);
        $hostA  = $this->user('Host Alpha', 'host', $companyA);
        $guardA = $this->user('Guard Alpha', 'security_guard', $companyA);

        $ceoB   = $this->user('Ceo Beta', 'ceo', $companyB);
        $adminB = $this->user('Admin Beta', 'admin', $companyB);
        $hostB  = $this->user('Host Beta', 'host', $companyB);

        $deptA = Department::create(['name' => 'Engineering Alpha', 'company_id' => $companyA->company_id]);
        $deptB = Department::create(['name' => 'Engineering Beta', 'company_id' => $companyB->company_id]);

        $hostA->update(['department_id' => $deptA->id]);
        $hostB->update(['department_id' => $deptB->id]);

        $visitorA = Visitor::create([
            'name'           => 'Visitor Alpha',
            'email'          => 'visitor@alpha.test',
            'id_document_no' => 'ID-ALPHA-1',
            'phone'          => '111111',
            'password'       => 'password',
            'company_id'     => $companyA->company_id,
        ]);
        $visitorB = Visitor::create([
            'name'           => 'Visitor Beta',
            'email'          => 'visitor@beta.test',
            'id_document_no' => 'ID-BETA-1',
            'phone'          => '222222',
            'password'       => 'password',
            'company_id'     => $companyB->company_id,
        ]);

        $visitA = VisitRequest::create([
            'visitor_id'     => $visitorA->id,
            'host_id'        => $hostA->id,
            'department_id'  => $deptA->id,
            'purpose'        => 'Onboarding Alpha',
            'requested_time' => Carbon::now()->addDay()->startOfHour(),
            'duration'       => 60,
            'status'         => 'pending',
            'company_id'     => $companyA->company_id,
        ]);
        $visitB = VisitRequest::create([
            'visitor_id'     => $visitorB->id,
            'host_id'        => $hostB->id,
            'department_id'  => $deptB->id,
            'purpose'        => 'Onboarding Beta',
            'requested_time' => Carbon::now()->addDay()->startOfHour(),
            'duration'       => 60,
            'status'         => 'pending',
            'company_id'     => $companyB->company_id,
        ]);

        return compact(
            'companyA', 'companyB', 'ceoA', 'adminA', 'hostA', 'guardA',
            'ceoB', 'adminB', 'hostB', 'deptA', 'deptB', 'visitorA', 'visitorB', 'visitA', 'visitB'
        );
    }

    public function test_company_scope_hides_other_companies_data_for_ceo(): void
    {
        $d = $this->seedTwoCompanies();

        $this->actingAs($d['ceoA']);

        $this->assertSame(1, Department::count());
        $this->assertSame('Engineering Alpha', Department::first()->name);

        $this->assertSame(4, User::count()); // ceoA, adminA, hostA, guardA
        $this->assertTrue(Department::where('name', 'Engineering Beta')->doesntExist());

        $this->assertSame(1, Visitor::count());
        $this->assertSame('Visitor Alpha', Visitor::first()->name);
        $this->assertTrue(Visitor::where('email', 'visitor@beta.test')->doesntExist());

        $this->assertSame(1, VisitRequest::count());
        $this->assertSame('Onboarding Alpha', VisitRequest::first()->purpose);
    }

    public function test_system_admin_sees_all_companies_data(): void
    {
        $d = $this->seedTwoCompanies();

        $systemAdmin = User::create([
            'name'     => 'Platform',
            'email'    => 'system@admin.test',
            'password' => 'password',
            'role'     => 'system_admin',
            'status'   => 'active',
        ]);

        $this->actingAs($systemAdmin);

        $this->assertSame(2, Department::count());
        $this->assertSame(2, Visitor::count());
        $this->assertSame(2, VisitRequest::count());
        $this->assertTrue(Company::where('company_name', 'Beta Corp')->exists());
    }

    public function test_cross_company_visit_decision_is_blocked(): void
    {
        $d = $this->seedTwoCompanies();

        // Host from company A tries to act on company B's visit request
        $this->actingAs($d['hostA']);

        $response = $this->post("/visit-requests/{$d['visitB']->id}/host-decision", [
            'decision' => 'approve',
        ]);

        $response->assertNotFound();

        $this->assertSame('pending', $d['visitB']->fresh()->status);
    }

    public function test_cross_company_security_checkin_is_blocked(): void
    {
        $d = $this->seedTwoCompanies();

        $d['visitB']->update(['status' => 'approved']);

        // Guard from company A tries to check in company B's visitor
        $this->actingAs($d['guardA']);

        $response = $this->post("/security/visits/{$d['visitB']->id}/checkin");

        $response->assertNotFound();
        $this->assertSame(0, CheckInOutLog::where('visit_request_id', $d['visitB']->id)->count());
    }

    public function test_ceo_of_company_a_cannot_manage_company_b(): void
    {
        $d = $this->seedTwoCompanies();

        $this->actingAs($d['ceoA']);

        $this->post("/admin/companies/{$d['companyB']->company_id}/status")->assertForbidden();
        $this->post("/admin/companies/{$d['companyB']->company_id}/assign", [
            'role' => 'admin', 'name' => 'Nope', 'email' => 'nope@beta.test',
        ])->assertForbidden();
        $this->get('/admin/companies')->assertForbidden();
    }

    public function test_visit_workflow_retains_company(): void
    {
        $d = $this->seedTwoCompanies();

        // Host of company A approves their own visit request
        $this->actingAs($d['hostA']);

        $this->post("/visit-requests/{$d['visitA']->id}/host-decision", [
            'decision' => 'approve',
        ])->assertSessionHas('success');

        $visit = $d['visitA']->fresh();
        $this->assertSame('approved', $visit->status);
        $this->assertSame($d['companyA']->company_id, $visit->company_id);
        $this->assertNotNull($visit->approved_at);

        $qrToken = QRToken::where('visit_request_id', $visit->id)->first();
        $this->assertNotNull($qrToken);

        // Security guard of company A checks the visitor in
        $this->actingAs($d['guardA']);

        $this->post("/security/visits/{$visit->id}/checkin")->assertSessionHas('success');

        $visit->refresh();
        $this->assertSame('checked_in', $visit->status);
        $this->assertSame($d['companyA']->company_id, $visit->company_id);

        $log = CheckInOutLog::where('visit_request_id', $visit->id)->first();
        $this->assertNotNull($log);
        $this->assertSame($d['guardA']->id, $log->guard_id);
        $this->assertNotNull($log->checkin_time);

        // Check out
        $this->post("/security/visits/{$visit->id}/checkout")
            ->assertJson(['success' => true]);

        $visit->refresh();
        $this->assertSame('checked_out', $visit->status);
        $this->assertSame($d['companyA']->company_id, $visit->company_id);
        $this->assertNotNull($log->fresh()->checkout_time);
    }

    public function test_company_scope_applies_to_visitors_created_by_companies(): void
    {
        $d = $this->seedTwoCompanies();

        $this->actingAs($d['adminA']);

        // Admin of company A cannot see company B's visitors or requests
        $this->assertTrue(Visitor::where('email', 'visitor@beta.test')->doesntExist());
        $this->assertTrue(VisitRequest::where('purpose', 'Onboarding Beta')->doesntExist());
    }
}
