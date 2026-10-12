<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NewFeatureSmokeTest extends TestCase
{
    use RefreshDatabase;

    private function createAdmin(): User
    {
        return User::create([
            'name'     => 'Smoke Admin',
            'email'    => 'smokeadmin@test.local',
            'password' => 'password',
            'role'     => 'admin',
            'status'   => 'active',
        ]);
    }

    public function test_incident_data_endpoint(): void
    {
        $admin = $this->createAdmin();
        $branch = Branch::create(['name' => 'Smoke Branch', 'slug' => 'smoke-branch', 'is_active' => true]);

        Incident::create([
            'company_id'   => null,
            'branch_id'    => $branch->id,
            'type'         => 'unauthorized_access',
            'severity'     => 'high',
            'status'       => 'open',
            'title'        => 'Smoke incident',
            'description'  => 'test',
            'reported_by'  => $admin->id,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        $this->actingAs($admin)
            ->get('/admin/incidents/data?period=yearly')
            ->assertOk()
            ->assertJsonStructure(['period', 'period_label', 'range', 'incidents', 'count', 'stats']);
    }

    public function test_incident_export_csv(): void
    {
        $admin = $this->createAdmin();
        $this->actingAs($admin)
            ->get('/admin/incidents/export?format=csv&period=yearly')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=utf-8');
    }

    public function test_report_export_pdf_still_works_after_trait_refactor(): void
    {
        $admin = $this->createAdmin();
        $this->actingAs($admin)
            ->get('/admin/reports/export/visits?format=pdf&period=yearly')
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_incident_status_update(): void
    {
        $admin = $this->createAdmin();
        $branch = Branch::create(['name' => 'Smoke Branch 2', 'slug' => 'smoke-branch-2', 'is_active' => true]);
        $incident = Incident::create([
            'company_id'  => null,
            'branch_id'   => $branch->id,
            'type'        => 'security_alert',
            'severity'    => 'medium',
            'status'      => 'open',
            'title'       => 'Smoke incident 2',
            'description' => 'test',
            'reported_by' => $admin->id,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $this->actingAs($admin)
            ->post('/admin/incidents/' . $incident->id . '/update', ['status' => 'escalated'])
            ->assertRedirect();

        $this->assertSame('escalated', $incident->fresh()->status);
    }

    public function test_branch_status_toggle(): void
    {
        $admin = $this->createAdmin();
        $branch = Branch::create(['name' => 'Smoke Branch 3', 'slug' => 'smoke-branch-3', 'is_active' => true]);

        $this->actingAs($admin)
            ->post('/admin/branches/' . $branch->id . '/status')
            ->assertRedirect();

        $this->assertFalse((bool) $branch->fresh()->is_active);
    }

    public function test_login_blocked_for_inactive_branch(): void
    {
        $branch = Branch::create(['name' => 'Inactive Branch', 'slug' => 'inactive-branch', 'is_active' => false]);
        $user = User::create([
            'name'      => 'Blocked Staff',
            'email'     => 'blocked@test.local',
            'password'  => 'password',
            'role'      => 'host',
            'status'    => 'active',
            'branch_id' => $branch->id,
        ]);

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest('web');
    }

    public function test_login_allowed_for_active_branch(): void
    {
        $branch = Branch::create(['name' => 'Active Branch', 'slug' => 'active-branch', 'is_active' => true]);
        User::create([
            'name'      => 'Allowed Staff',
            'email'     => 'allowed@test.local',
            'password'  => 'password',
            'role'      => 'host',
            'status'    => 'active',
            'branch_id' => $branch->id,
        ]);

        $this->post('/login', ['email' => 'allowed@test.local', 'password' => 'password'])
            ->assertRedirect();

        $this->assertAuthenticated('web');
    }

    public function test_visitor_notification_stored_with_visitor_recipient_type(): void
    {
        $visitor = \App\Models\Visitor::create([
            'name'           => 'Notified Visitor',
            'email'          => 'visitor@test.local',
            'phone'          => '+251911000000',
            'id_document_no' => '123456',
            'password'       => bcrypt('password'),
        ]);

        \App\Services\NotificationService::send('Visit Approved', $visitor, 'Your visit has been approved.', null, true);

        $this->assertDatabaseHas('system_notifications', [
            'recipient_type' => 'visitor',
            'recipient_id'   => $visitor->id,
            'event'          => 'Visit Approved',
        ]);
    }
}
