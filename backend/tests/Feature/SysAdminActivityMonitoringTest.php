<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SysAdminActivityMonitoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_system_admin_dashboard_shows_actions_performed_by_system_admins(): void
    {
        $company = Company::create(['company_name' => 'Alpha Corp', 'status' => 'active']);

        $systemAdmin = User::create([
            'name'     => 'Platform Owner',
            'email'    => 'system@admin.test',
            'password' => 'password',
            'role'     => 'system_admin',
            'status'   => 'active',
        ]);

        $ceo = User::create([
            'name'       => 'Ceo Alpha',
            'email'      => 'ceo@alpha.test',
            'password'   => 'password',
            'role'       => 'ceo',
            'status'     => 'active',
            'company_id' => $company->company_id,
        ]);

        $this->actingAs($systemAdmin);

        AuditLogger::log('company_created', 'companies', $company->company_id, null, [], $company->company_id);

        // A company user also performs an action — must NOT appear in system admin activity
        $this->actingAs($ceo);
        AuditLogger::log('create_department', 'departments', 1);

        $this->actingAs($systemAdmin);
        $response = $this->get(route('system-admin.section', 'sys_activity'));
        $response->assertOk();

        $props = $response->viewData('page')['props'];
        $activity = collect($props['system_admin_activity']);

        $this->assertTrue($activity->contains('action', 'company_created'), 'System Admin action should be monitored');
        $this->assertTrue($activity->contains(fn($a) => $a['actor_name'] === 'Platform Owner'));

        $this->assertFalse($activity->contains('action', 'create_department'), 'Other users actions must be excluded');
    }

    public function test_system_admin_activity_is_immune_to_company_scoping(): void
    {
        // Simulate the assign_default_company scenario: system admin has a company_id
        $defaultCompany = Company::create(['company_name' => 'Default Organization', 'status' => 'active']);
        $otherCompany   = Company::create(['company_name' => 'Other Corp', 'status' => 'active']);

        $systemAdmin = User::create([
            'name'       => 'Platform Owner',
            'email'      => 'system@admin.test',
            'password'   => 'password',
            'role'       => 'system_admin',
            'status'     => 'active',
            'company_id' => $defaultCompany->company_id,
        ]);

        $this->actingAs($systemAdmin);

        // Action attributed to a different company must still be visible to the system admin
        AuditLogger::log('company_created', 'companies', $otherCompany->company_id, null, [], $otherCompany->company_id);
        // Platform-wide action with no company
        AuditLogger::log('login', 'users', $systemAdmin->id);

        $response = $this->get(route('system-admin.section', 'sys_activity'));
        $response->assertOk();

        $props = $response->viewData('page')['props'];
        $activity = collect($props['system_admin_activity']);

        $this->assertTrue($activity->contains('action', 'company_created'));
        $this->assertTrue($activity->contains('action', 'login'));
        $this->assertSame(2, $activity->count());
    }
}
