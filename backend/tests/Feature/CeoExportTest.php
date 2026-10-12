<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\VisitRequest;
use App\Models\Visitor;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CeoExportTest extends TestCase
{
    use RefreshDatabase;

    private function seedExportData(string $companyName, string $branchSuffix): array
    {
        $company = Company::create(['company_name' => $companyName, 'status' => 'active']);

        $branch = Branch::create([
            'name'       => "Branch {$branchSuffix}",
            'slug'       => 'branch-' . strtolower($branchSuffix),
            'company_id' => $company->company_id,
            'is_active'  => true,
        ]);

        $dept = Department::create([
            'name'       => "Dept {$branchSuffix}",
            'company_id' => $company->company_id,
            'branch_id'  => $branch->id,
        ]);

        $ceo = User::create([
            'name'       => "CEO {$branchSuffix}",
            'email'      => "ceo{$branchSuffix}@export.test",
            'password'   => 'password',
            'role'       => 'ceo',
            'company_id' => $company->company_id,
            'status'     => 'active',
        ]);

        $host = User::create([
            'name'          => "Host {$branchSuffix}",
            'email'         => "host{$branchSuffix}@export.test",
            'password'      => 'password',
            'role'          => 'host',
            'company_id'    => $company->company_id,
            'department_id' => $dept->id,
            'branch_id'     => $branch->id,
            'status'        => 'active',
        ]);

        $visitor = Visitor::create([
            'name'           => "Visitor {$branchSuffix}",
            'email'          => "visitor{$branchSuffix}@export.test",
            'id_document_no' => "ID-EXP-{$branchSuffix}",
            'phone'          => "555{$branchSuffix}",
            'password'       => 'password',
            'company_id'     => $company->company_id,
        ]);

        return compact('company', 'branch', 'dept', 'ceo', 'host', 'visitor');
    }

    public function test_ceo_export_visits_contains_required_columns_and_company_branch_info(): void
    {
        $d = $this->seedExportData('Export Corp', 'HQ');

        VisitRequest::create([
            'visitor_id'     => $d['visitor']->id,
            'host_id'        => $d['host']->id,
            'department_id'  => $d['dept']->id,
            'branch_id'      => $d['branch']->id,
            'purpose'        => 'Platform audit',
            'visit_type'     => 'solo',
            'requested_time' => Carbon::now()->addDay()->startOfHour(),
            'duration'       => 90,
            'status'         => 'approved',
            'company_id'     => $d['company']->company_id,
            'approved_at'    => Carbon::now(),
        ]);

        $this->actingAs($d['ceo']);

        $response = $this->get('/ceo/export/visits');
        $response->assertOk();
        $response->assertHeaderContains('Content-Type', 'text/csv');

        $lines = array_map('str_getcsv', explode("\n", trim($response->streamedContent())));

        // The exact required header set, with Duration (not "Duration (min)").
        $this->assertSame([
            'ID', 'Visitor Name', 'Visitor Email', 'Host Name', 'Department',
            'Purpose', 'Requested Time', 'Duration', 'Status', 'Approved At',
            'Rejected At', 'Comments', 'Company', 'Branch',
        ], $lines[0]);

        $row = $lines[1];
        $this->assertSame('Export Corp', $row[12]);
        $this->assertSame('Branch HQ', $row[13]);
        $this->assertSame('Dept HQ', $row[4]);
        $this->assertSame('Platform audit', $row[5]);
        $this->assertSame('90', $row[7]);
        $this->assertSame('approved', $row[8]);
        $this->assertNotEmpty($row[9]);
        $this->assertSame('', $row[10]);
    }

    public function test_ceo_export_visits_is_scoped_to_own_company(): void
    {
        $d = $this->seedExportData('Export Corp A', 'A');
        $other = $this->seedExportData('Export Corp B', 'B');

        VisitRequest::create([
            'visitor_id'     => $d['visitor']->id,
            'host_id'        => $d['host']->id,
            'department_id'  => $d['dept']->id,
            'branch_id'      => $d['branch']->id,
            'purpose'        => 'Own company visit',
            'visit_type'     => 'solo',
            'requested_time' => Carbon::now()->addDay()->startOfHour(),
            'duration'       => 30,
            'status'         => 'pending',
            'company_id'     => $d['company']->company_id,
        ]);

        VisitRequest::create([
            'visitor_id'     => $other['visitor']->id,
            'host_id'        => $other['host']->id,
            'department_id'  => $other['dept']->id,
            'branch_id'      => $other['branch']->id,
            'purpose'        => 'Foreign company visit',
            'visit_type'     => 'solo',
            'requested_time' => Carbon::now()->addDay()->startOfHour(),
            'duration'       => 30,
            'status'         => 'pending',
            'company_id'     => $other['company']->company_id,
        ]);

        $this->actingAs($d['ceo']);

        $csv = $this->get('/ceo/export/visits')->streamedContent();

        $this->assertStringContainsString('Own company visit', $csv);
        $this->assertStringContainsString('Export Corp A', $csv);
        $this->assertStringNotContainsString('Foreign company visit', $csv);
        $this->assertStringNotContainsString('Export Corp B', $csv);
    }
}
