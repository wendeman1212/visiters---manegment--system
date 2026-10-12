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

class CeoBranchFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_ceo_excluded_from_managers_when_branch_selected(): void
    {
        $company = Company::create(['company_name' => 'Test Corp', 'status' => 'active']);
        $branch = Branch::create(['name' => 'Branch One', 'slug' => 'branch-one', 'company_id' => $company->company_id, 'is_active' => true]);
        $dept = Department::create(['name' => 'Sales', 'company_id' => $company->company_id, 'branch_id' => $branch->id]);

        $host = User::create(['name' => 'Host', 'email' => 'host@test.test', 'password' => 'password', 'role' => 'host', 'company_id' => $company->company_id, 'branch_id' => $branch->id, 'department_id' => $dept->id, 'status' => 'active']);
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@test.test', 'password' => 'password', 'role' => 'admin', 'company_id' => $company->company_id, 'status' => 'active']);
        $secondAdmin = User::create(['name' => 'Admin Two', 'email' => 'admin2@test.test', 'password' => 'password', 'role' => 'admin', 'company_id' => $company->company_id, 'status' => 'active']);
        $ceo = User::create(['name' => 'CEO', 'email' => 'ceo@test.test', 'password' => 'password', 'role' => 'ceo', 'company_id' => $company->company_id, 'status' => 'active']);

        $visitor = Visitor::create(['name' => 'Visitor', 'email' => 'visitor@test.test', 'id_document_no' => 'ID-1', 'phone' => '555001', 'password' => 'password', 'company_id' => $company->company_id]);
        $visit = VisitRequest::create(['visitor_id' => $visitor->id, 'host_id' => $host->id, 'department_id' => $dept->id, 'branch_id' => $branch->id, 'purpose' => 'Test', 'visit_type' => 'solo', 'requested_time' => Carbon::now()->addDay(), 'duration' => 60, 'status' => 'approved', 'company_id' => $company->company_id]);

        $this->actingAs($admin);

        // Without branch - CEO should be in managers
        $responseNoBranch = $this->getJson("/visitor-transfer/{$visit->id}/options?company_id={$company->company_id}");
        $managersNoBranch = $responseNoBranch->json('managers');
        $managerRolesNoBranch = array_column($managersNoBranch, 'role');
        $this->assertContains('ceo', $managerRolesNoBranch, 'CEO should be in managers when no branch selected');
        $this->assertContains('admin', $managerRolesNoBranch, 'A fellow admin should be in managers when no branch selected');

        // With branch selected, recipients are strictly branch-scoped: the
        // company-level CEO and Admins are no longer offered as managers.
        $responseWithBranch = $this->getJson("/visitor-transfer/{$visit->id}/options?company_id={$company->company_id}&branch_id={$branch->id}");
        $managersWithBranch = $responseWithBranch->json('managers');
        $this->assertSame([], $managersWithBranch, 'Company-level managers must be excluded when a branch is selected');
    }
}