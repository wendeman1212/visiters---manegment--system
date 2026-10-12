<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\SystemNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CeoEmergencyNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function makeCompany(string $name): Company
    {
        return Company::create([
            'company_name' => $name,
            'status'       => 'active',
        ]);
    }

    private function makeUser(Company $company, string $role, string $email, array $extra = []): User
    {
        return User::create(array_merge([
            'name'       => $email,
            'email'      => $email,
            'password'   => 'password',
            'role'       => $role,
            'company_id' => $company->company_id,
            'status'     => 'active',
        ], $extra));
    }

    private function sendEmergency(Company $company, User $ceo, string $message = 'EMERGENCY: Evacuate immediately.', string $scope = 'company', ?int $branchId = null): void
    {
        $this->actingAs($ceo);

        $payload = [
            'message'    => $message,
            'priority'   => 'critical',
            'scope_type' => $scope,
            'branch_id'  => $branchId,
        ];

        $this->post('/ceo/broadcast', $payload, ['Accept' => 'application/json'])
            ->assertJson(['success' => true]);
    }

    public function test_ceo_emergency_broadcast_creates_unread_notification_for_every_active_user_in_company(): void
    {
        $company = $this->makeCompany('Alpha Corp');
        $other   = $this->makeCompany('Beta Corp');

        $ceo       = $this->makeUser($company, 'ceo', 'ceo@alpha.test');
        $admin     = $this->makeUser($company, 'admin', 'admin@alpha.test');
        $host      = $this->makeUser($company, 'host', 'host@alpha.test');
        $guard     = $this->makeUser($company, 'security_guard', 'guard@alpha.test');
        $suspended = $this->makeUser($company, 'host', 'suspended@alpha.test', ['status' => 'suspended']);

        $otherCeo   = $this->makeUser($other, 'ceo', 'ceo@beta.test');
        $otherAdmin = $this->makeUser($other, 'admin', 'admin@beta.test');

        $this->sendEmergency($company, $ceo, 'EMERGENCY: Evacuate immediately.');

        // Every active user in the CEO's company (except the CEO) gets a per-user
        // notification, stored as unread in the database.
        foreach ([$admin, $host, $guard] as $recipient) {
            $this->assertDatabaseHas('system_notifications', [
                'recipient_type' => 'user',
                'recipient_id'   => $recipient->id,
                'event'          => 'CEO Broadcast [CRITICAL]',
                'title'          => 'CEO Broadcast [CRITICAL]',
                'message'        => 'EMERGENCY: Evacuate immediately.',
                'is_read'        => 0,
            ]);
        }

        // Suspended users and every user of an unrelated company are never notified.
        $this->assertDatabaseMissing('system_notifications', ['recipient_id' => $suspended->id]);
        $this->assertDatabaseMissing('system_notifications', ['recipient_id' => $otherCeo->id]);
        $this->assertDatabaseMissing('system_notifications', ['recipient_id' => $otherAdmin->id]);

        // No notification is stored for the CEO themself.
        $this->assertDatabaseMissing('system_notifications', ['recipient_id' => $ceo->id]);
    }

    public function test_branch_scoped_emergency_only_reaches_users_of_that_branch(): void
    {
        $company = $this->makeCompany('Alpha Corp');

        $branchA = Branch::create(['company_id' => $company->company_id, 'name' => 'HQ', 'slug' => 'hq', 'is_active' => true]);
        $branchB = Branch::create(['company_id' => $company->company_id, 'name' => 'South', 'slug' => 'south', 'is_active' => true]);

        $ceo     = $this->makeUser($company, 'ceo', 'ceo@alpha.test');
        $hostA   = $this->makeUser($company, 'host', 'hostA@alpha.test', ['branch_id' => $branchA->id]);
        $hostB   = $this->makeUser($company, 'host', 'hostB@alpha.test', ['branch_id' => $branchB->id]);
        $noBranch = $this->makeUser($company, 'host', 'nobranch@alpha.test');

        $this->sendEmergency($company, $ceo, 'Branch-specific emergency.', 'branch', $branchA->id);

        $this->assertDatabaseHas('system_notifications', ['recipient_id' => $hostA->id, 'is_read' => 0]);
        $this->assertDatabaseMissing('system_notifications', ['recipient_id' => $hostB->id]);
        $this->assertDatabaseMissing('system_notifications', ['recipient_id' => $noBranch->id]);
    }

    public function test_recipient_sees_emergency_notification_in_dashboard_api(): void
    {
        $company = $this->makeCompany('Alpha Corp');

        $ceo  = $this->makeUser($company, 'ceo', 'ceo@alpha.test');
        $host = $this->makeUser($company, 'host', 'host@alpha.test');

        $this->sendEmergency($company, $ceo, 'EMERGENCY: Code red.');

        $this->actingAs($host);

        $response = $this->getJson('/api/notifications');

        $response->assertOk();
        $response->assertJson(['unread_count' => 1]);
        $response->assertJsonCount(1, 'notifications');
        $response->assertJsonFragment([
            'message'   => 'EMERGENCY: Code red.',
            'is_read'   => false,
            'event'     => 'CEO Broadcast [CRITICAL]',
        ]);

        $notification = SystemNotification::where('recipient_type', 'user')
            ->where('recipient_id', $host->id)
            ->first();

        $this->assertNotNull($notification);
        $this->assertFalse($notification->is_read);
    }

    public function test_recipient_opening_notification_marks_it_read(): void
    {
        $company = $this->makeCompany('Alpha Corp');

        $ceo  = $this->makeUser($company, 'ceo', 'ceo@alpha.test');
        $host = $this->makeUser($company, 'host', 'host@alpha.test');

        $this->sendEmergency($company, $ceo, 'EMERGENCY: Code red.');

        $notification = SystemNotification::where('recipient_type', 'user')
            ->where('recipient_id', $host->id)
            ->first();

        $this->assertNotNull($notification);
        $this->assertFalse($notification->is_read);

        $this->actingAs($host);

        $this->postJson("/api/notifications/{$notification->id}/read")
            ->assertOk()
            ->assertJson(['success' => true, 'unread_count' => 0]);

        $notification->refresh();

        $this->assertTrue($notification->is_read);
        $this->assertNotNull($notification->read_at);

        // The count drops to zero, and the notification stays visible (now read)
        // so the emergency message remains in the user's dashboard history.
        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJson(['unread_count' => 0])
            ->assertJsonCount(1, 'notifications')
            ->assertJsonFragment([
                'id'      => $notification->id,
                'is_read' => true,
            ]);
    }

    public function test_emergency_notification_persists_after_user_logs_out_and_back_in(): void
    {
        $company = $this->makeCompany('Alpha Corp');

        $ceo  = $this->makeUser($company, 'ceo', 'ceo@alpha.test');
        $host = $this->makeUser($company, 'host', 'host@alpha.test');

        $this->sendEmergency($company, $ceo, 'EMERGENCY: Persist this.');

        // The notification lives in the database — it survives a logout/login cycle.
        $stored = SystemNotification::where('recipient_type', 'user')
            ->where('recipient_id', $host->id)
            ->where('is_read', false)
            ->first();

        $this->assertNotNull($stored);

        $this->actingAs($host);

        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJson(['unread_count' => 1]);
    }
}
