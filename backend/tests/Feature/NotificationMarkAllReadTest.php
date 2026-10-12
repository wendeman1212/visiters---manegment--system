<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\SystemNotification;
use App\Models\User;
use App\Models\Visitor;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationMarkAllReadTest extends TestCase
{
    use RefreshDatabase;

    private function makeCompany(string $name = 'Alpha Corp'): Company
    {
        return Company::create([
            'company_name' => $name,
            'status'       => 'active',
        ]);
    }

    private function makeUser(Company $company, string $role, string $email): User
    {
        return User::create([
            'name'       => $email,
            'email'      => $email,
            'password'   => 'password',
            'role'       => $role,
            'company_id' => $company->company_id,
            'status'     => 'active',
        ]);
    }

    private function makeVisitor(Company $company, string $email): Visitor
    {
        return Visitor::create([
            'name'           => 'Notif Visitor',
            'email'          => $email,
            'id_document_no' => 'ID-' . $email,
            'phone'          => '555900',
            'password'       => 'password',
            'company_id'     => $company->company_id,
        ]);
    }

    private function notify($recipientType, $recipientId, string $title, array $overrides = []): SystemNotification
    {
        return SystemNotification::create(array_merge([
            'event'          => $title,
            'recipient_type' => $recipientType,
            'recipient_id'   => $recipientId,
            'title'          => $title,
            'message'        => $title . ' body',
            'is_read'        => false,
        ], $overrides));
    }

    public function test_mark_all_read_marks_every_currently_unread_notification_as_read_at_once(): void
    {
        $company = $this->makeCompany();
        $host    = $this->makeUser($company, 'host', 'host@alpha.test');

        $n1 = $this->notify('user', $host->id, 'First unread');
        $n2 = $this->notify('user', $host->id, 'Second unread');
        $n3 = $this->notify('user', $host->id, 'Third unread');

        $this->actingAs($host);

        $this->postJson('/api/notifications/mark-all-read')
            ->assertOk()
            ->assertJson(['success' => true, 'unread_count' => 0]);

        foreach ([$n1, $n2, $n3] as $n) {
            $n->refresh();
            $this->assertTrue($n->is_read);
            $this->assertNotNull($n->read_at);
        }

        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJson(['unread_count' => 0])
            ->assertJsonCount(3, 'notifications');
    }

    public function test_mark_all_read_does_not_affect_already_read_notifications(): void
    {
        $company = $this->makeCompany();
        $host    = $this->makeUser($company, 'host', 'host@alpha.test');

        $alreadyReadAt = Carbon::now()->subHour();
        $alreadyRead = $this->notify('user', $host->id, 'Already read', [
            'is_read' => true,
            'read_at' => $alreadyReadAt,
        ]);
        $unread = $this->notify('user', $host->id, 'Still unread');

        $this->actingAs($host);

        $this->postJson('/api/notifications/mark-all-read')
            ->assertOk()
            ->assertJson(['success' => true, 'unread_count' => 0]);

        $alreadyRead->refresh();
        $unread->refresh();

        $this->assertTrue($alreadyRead->is_read);
        $this->assertSame($alreadyReadAt->timestamp, $alreadyRead->read_at->timestamp);
        $this->assertTrue($unread->is_read);
        $this->assertNotNull($unread->read_at);
    }

    public function test_mark_all_read_is_scoped_to_the_current_user(): void
    {
        $company = $this->makeCompany();
        $hostA   = $this->makeUser($company, 'host', 'a@alpha.test');
        $hostB   = $this->makeUser($company, 'host', 'b@alpha.test');

        $a1 = $this->notify('user', $hostA->id, 'A unread');
        $b1 = $this->notify('user', $hostB->id, 'B unread');
        $b2 = $this->notify('user', $hostB->id, 'B unread 2');

        $this->actingAs($hostA);

        $this->postJson('/api/notifications/mark-all-read')
            ->assertOk()
            ->assertJson(['success' => true, 'unread_count' => 0]);

        $a1->refresh();
        $b1->refresh();
        $b2->refresh();

        $this->assertTrue($a1->is_read);
        $this->assertFalse($b1->is_read);
        $this->assertFalse($b2->is_read);
    }

    public function test_mark_all_read_persists_after_logout_and_login(): void
    {
        $company = $this->makeCompany();
        $host    = $this->makeUser($company, 'host', 'host@alpha.test');

        $this->notify('user', $host->id, 'Persist me');
        $this->notify('user', $host->id, 'Persist me too');

        $this->actingAs($host);
        $this->postJson('/api/notifications/mark-all-read')
            ->assertOk()
            ->assertJson(['unread_count' => 0]);

        // The read state lives in the database, so logging back in and
        // re-fetching (a page refresh in the app) returns every notification
        // as read with a zero unread count — they never return to Unread.
        $this->actingAs($host);

        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJson(['unread_count' => 0])
            ->assertJsonCount(2, 'notifications');

        $rows = SystemNotification::for('user', $host->id)->get();
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertTrue($row->is_read);
            $this->assertNotNull($row->read_at);
        }
    }

    public function test_visitor_mark_all_read_marks_only_unread_and_persists(): void
    {
        $company = $this->makeCompany();
        $visitor = $this->makeVisitor($company, 'visitor@alpha.test');

        $n1 = $this->notify('visitor', $visitor->id, 'Visitor unread');
        $n2 = $this->notify('visitor', $visitor->id, 'Visitor unread 2');
        $read = $this->notify('visitor', $visitor->id, 'Visitor read', [
            'is_read' => true,
            'read_at' => Carbon::now()->subMinutes(5),
        ]);

        $this->actingAs($visitor, 'visitor');

        $this->postJson('/api/visitor/notifications/mark-all-read')
            ->assertOk()
            ->assertJson(['success' => true, 'unread_count' => 0]);

        $n1->refresh();
        $n2->refresh();
        $read->refresh();

        $this->assertTrue($n1->is_read);
        $this->assertTrue($n2->is_read);
        $this->assertNotNull($n1->read_at);
        $this->assertNotNull($n2->read_at);
        $this->assertTrue($read->is_read);

        // After a fresh login the visitor still sees all notifications as read.
        $this->actingAs($visitor, 'visitor');

        $this->getJson('/api/visitor/notifications')
            ->assertOk()
            ->assertJson(['unread_count' => 0])
            ->assertJsonCount(3, 'notifications')
            ->assertJsonFragment(['id' => $n1->id, 'is_read' => true]);
    }
}
