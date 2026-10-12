<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\HostTransfer;
use App\Models\SystemNotification;
use App\Models\VisitRequest;
use App\Models\Visitor;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VisitorTransferTest extends TestCase
{
    use RefreshDatabase;

    private function seedTransferData(string $recipientRole = 'admin', bool $branchLevel = false): array
    {
        $company = Company::create(['company_name' => 'Transfer Corp', 'status' => 'active']);

        $branch = null;
        if ($branchLevel) {
            $branch = \App\Models\Branch::create([
                'name'       => 'Branch One',
                'slug'       => 'branch-one',
                'company_id' => $company->company_id,
            ]);
        }

        $host = User::create([
            'name'       => 'Current Host',
            'email'      => 'host@transfer.test',
            'password'   => 'password',
            'role'       => 'host',
            'company_id' => $company->company_id,
            'status'     => 'active',
        ]);

        $recipient = User::create([
            'name'       => 'Recipient User',
            'email'      => 'recipient@transfer.test',
            'password'   => 'password',
            'role'       => $recipientRole,
            'company_id' => $company->company_id,
            'status'     => 'active',
        ]);

        $dept = Department::create(['name' => 'Sales', 'company_id' => $company->company_id]);
        $host->update(['department_id' => $dept->id]);
        $recipient->update(['department_id' => $dept->id]);

        if ($branchLevel) {
            $host->update(['branch_id' => $branch->id]);
        }

        $visitor = Visitor::create([
            'name'           => 'Transfer Visitor',
            'email'          => 'visitor@transfer.test',
            'id_document_no' => 'ID-TRANSFER-1',
            'phone'          => '555000',
            'password'       => 'password',
            'company_id'     => $company->company_id,
        ]);

        $visit = VisitRequest::create([
            'visitor_id'     => $visitor->id,
            'host_id'        => $host->id,
            'department_id'  => $dept->id,
            'branch_id'      => $branch?->id,
            'purpose'        => 'Negotiation',
            'visit_type'     => 'solo',
            'requested_time' => Carbon::now()->addDay()->startOfHour(),
            'duration'       => 60,
            'status'         => 'approved',
            'company_id'     => $company->company_id,
        ]);

        return compact('company', 'branch', 'host', 'recipient', 'dept', 'visitor', 'visit');
    }

    public function test_host_can_transfer_visitor_to_admin(): void
    {
        $d = $this->seedTransferData('admin');
        $this->actingAs($d['host']);

        $this->post("/visit-requests/{$d['visit']->id}/transfer", [
            'visit_request_id' => $d['visit']->id,
            'new_host_id'      => $d['recipient']->id,
            'reason'           => 'Manager will take over',
        ])->assertSessionHas('success');

        // New user becomes the current host; previous host loses the visit.
        $this->assertSame($d['recipient']->id, $d['visit']->fresh()->host_id);

        // No duplication: same visit and same visitor, history preserved.
        $this->assertDatabaseCount('visit_requests', 1);
        $this->assertDatabaseCount('visitors', 1);
        $this->assertSame($d['visitor']->id, $d['visit']->fresh()->visitor_id);
        $this->assertSame('Negotiation', $d['visit']->fresh()->purpose);
        $this->assertSame('approved', $d['visit']->fresh()->status);

        // Transfer log recorded with previous and new host.
        $transfer = HostTransfer::where('visit_request_id', $d['visit']->id)->first();
        $this->assertNotNull($transfer);
        $this->assertSame($d['host']->id, $transfer->previous_host_id);
        $this->assertSame($d['recipient']->id, $transfer->new_host_id);
        $this->assertSame($d['host']->id, $transfer->transferred_by);

        // Previous host, new user, and visitor all notified immediately.
        $this->assertDatabaseHas('system_notifications', [
            'recipient_type' => 'user',
            'recipient_id'   => $d['host']->id,
            'title'          => 'Host Transfer',
        ]);
        $this->assertDatabaseHas('system_notifications', [
            'recipient_type' => 'user',
            'recipient_id'   => $d['recipient']->id,
            'title'          => 'New Visit Assigned',
        ]);
        $this->assertDatabaseHas('system_notifications', [
            'recipient_type' => 'visitor',
            'recipient_id'   => $d['visitor']->id,
            'title'          => 'Host Changed',
        ]);
    }

    public function test_visitor_never_sees_transfer_notifications_but_host_admin_ceo_do(): void
    {
        $d = $this->seedTransferData('admin');
        $this->actingAs($d['host']);

        $this->post("/visit-requests/{$d['visit']->id}/transfer", [
            'visit_request_id' => $d['visit']->id,
            'new_host_id'      => $d['recipient']->id,
            'reason'           => 'Manager will take over',
        ])->assertSessionHas('success');

        // The transfer notification is still stored for the audit trail…
        $this->assertDatabaseHas('system_notifications', [
            'recipient_type' => 'visitor',
            'recipient_id'   => $d['visitor']->id,
            'title'          => 'Host Changed',
        ]);

        // …but never surfaced on the visitor dashboard.
        $visitorResponse = $this->actingAs($d['visitor'], 'visitor')
            ->get('/api/visitor/notifications')
            ->assertOk()
            ->json();

        $visitorTitles = collect($visitorResponse['notifications'])->pluck('title')->all();
        $this->assertNotContains('Host Changed', $visitorTitles);
        $this->assertSame(0, $visitorResponse['unread_count']);

        // Host/Admin/CEO keep full transfer visibility.
        $hostTitles = collect(
            \App\Services\NotificationService::getAll('user', $d['host']->id)
        )->pluck('title')->all();
        $recipientTitles = collect(
            \App\Services\NotificationService::getAll('user', $d['recipient']->id)
        )->pluck('title')->all();
        $this->assertContains('Host Transfer', $hostTitles);
        $this->assertContains('New Visit Assigned', $recipientTitles);
    }

    public function test_visitor_comments_are_stripped_of_internal_escalation_notes(): void
    {
        $d = $this->seedTransferData('admin');
        $d['visit']->update([
            'comments' => 'Please bring your ID | Escalated to Jane (Admin) by Bob on Aug 07, 2026 14:30',
        ]);

        // The full comment trail stays stored in the database…
        $this->assertStringContainsString(
            'Escalated to Jane (Admin) by Bob',
            $d['visit']->fresh()->comments
        );

        // …but the copy handed to the visitor is scrubbed of escalation notes.
        $this->assertSame(
            'Please bring your ID',
            \App\Services\NotificationService::sanitizeCommentsForVisitor($d['visit']->fresh()->comments)
        );

        // Purely internal notes are dropped entirely.
        $this->assertNull(
            \App\Services\NotificationService::sanitizeCommentsForVisitor(
                'Visitor did not show up (escalated visit).'
            )
        );
        $this->assertNull(
            \App\Services\NotificationService::sanitizeCommentsForVisitor(
                'Admin approved escalation. Returned to host for final confirmation.'
            )
        );

        // Visitor-facing comments pass through untouched.
        $this->assertSame('Rejected: no meeting room available', \App\Services\NotificationService::sanitizeCommentsForVisitor('Rejected: no meeting room available'));
        $this->assertNull(\App\Services\NotificationService::sanitizeCommentsForVisitor(null));
    }

    public function test_visitor_can_be_transferred_to_ceo_and_leaves_previous_host_list(): void
    {
        $d = $this->seedTransferData('ceo');
        $this->actingAs($d['host']);

        $this->post("/visit-requests/{$d['visit']->id}/transfer", [
            'visit_request_id' => $d['visit']->id,
            'new_host_id'      => $d['recipient']->id,
            'reason'           => 'Escalate to executive',
        ])->assertSessionHas('success');

        $this->assertSame($d['recipient']->id, $d['visit']->fresh()->host_id);

        // The visit no longer belongs to the previous host's active list.
        $this->assertSame(
            0,
            VisitRequest::where('host_id', $d['host']->id)->where('status', 'approved')->count()
        );
        $this->assertSame(
            1,
            VisitRequest::where('host_id', $d['recipient']->id)->where('status', 'approved')->count()
        );
    }

    public function test_transfer_to_inactive_or_non_host_role_user_is_rejected(): void
    {
        $d = $this->seedTransferData('receptionist');
        $d['recipient']->update(['status' => 'inactive']);
        $this->actingAs($d['host']);

        $this->post("/visit-requests/{$d['visit']->id}/transfer", [
            'visit_request_id' => $d['visit']->id,
            'new_host_id'      => $d['recipient']->id,
        ])->assertSessionHas('error');

        // Nothing changed.
        $this->assertSame($d['host']->id, $d['visit']->fresh()->host_id);
        $this->assertDatabaseCount('host_transfers', 0);
    }

    public function test_transfer_to_same_host_is_rejected(): void
    {
        $d = $this->seedTransferData('host');
        $this->actingAs($d['host']);

        $this->post("/visit-requests/{$d['visit']->id}/transfer", [
            'visit_request_id' => $d['visit']->id,
            'new_host_id'      => $d['host']->id,
        ])->assertSessionHas('error');

        $this->assertDatabaseCount('host_transfers', 0);
        $this->assertSame(0, SystemNotification::count());
    }

    public function test_branch_host_can_transfer_to_company_admin_of_same_company(): void
    {
        // Branch-level visit + branch host; recipient is a company-level admin
        // with no branch. The admin must still be eligible from any branch.
        $d = $this->seedTransferData('admin', true);
        $this->actingAs($d['host']);

        $this->post("/visit-requests/{$d['visit']->id}/transfer", [
            'visit_request_id' => $d['visit']->id,
            'new_host_id'      => $d['recipient']->id,
            'reason'           => 'Company admin takes over',
        ])->assertSessionHas('success');

        // The company admin becomes the visitor's current assigned user.
        $this->assertSame($d['recipient']->id, $d['visit']->fresh()->host_id);

        // No duplication, history preserved.
        $this->assertDatabaseCount('visit_requests', 1);
        $this->assertDatabaseCount('visitors', 1);
        $this->assertSame($d['visitor']->id, $d['visit']->fresh()->visitor_id);
        $this->assertSame('approved', $d['visit']->fresh()->status);

        // Transfer logged correctly.
        $this->assertDatabaseHas('host_transfers', [
            'visit_request_id' => $d['visit']->id,
            'previous_host_id' => $d['host']->id,
            'new_host_id'      => $d['recipient']->id,
        ]);
    }

    public function test_branch_host_cannot_transfer_to_admin_of_other_company(): void
    {
        $d = $this->seedTransferData('admin', true);

        $otherCompany = Company::create(['company_name' => 'Other Corp', 'status' => 'active']);
        $otherAdmin = User::create([
            'name'       => 'Foreign Admin',
            'email'      => 'foreign@other.test',
            'password'   => 'password',
            'role'       => 'admin',
            'company_id' => $otherCompany->company_id,
            'status'     => 'active',
        ]);

        $this->actingAs($d['host']);

        $this->post("/visit-requests/{$d['visit']->id}/transfer", [
            'visit_request_id' => $d['visit']->id,
            'new_host_id'      => $otherAdmin->id,
        ]);

        // Nothing changed: the visitor stays with the original host.
        $this->assertSame($d['host']->id, $d['visit']->fresh()->host_id);
        $this->assertDatabaseCount('host_transfers', 0);
    }
}
