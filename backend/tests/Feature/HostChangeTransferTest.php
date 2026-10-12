<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\Escalation;
use App\Models\HostChangeRequest;
use App\Models\VisitRequest;
use App\Models\Visitor;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HostChangeTransferTest extends TestCase
{
    use RefreshDatabase;

    private function seedHostChange(): array
    {
        $company = Company::create(['company_name' => 'Host Change Corp', 'status' => 'active']);

        $deptA = Department::create(['name' => 'Support', 'company_id' => $company->company_id]);
        $deptB = Department::create(['name' => 'Engineering', 'company_id' => $company->company_id]);

        $admin = User::create([
            'name' => 'Review Admin', 'email' => 'review@hostchange.test',
            'password' => 'password', 'role' => 'admin',
            'company_id' => $company->company_id, 'status' => 'active',
        ]);

        $host = User::create([
            'name' => 'Original Host', 'email' => 'orig@hostchange.test',
            'password' => 'password', 'role' => 'host',
            'company_id' => $company->company_id, 'department_id' => $deptA->id, 'status' => 'active',
        ]);

        $newHost = User::create([
            'name' => 'New Host', 'email' => 'new@hostchange.test',
            'password' => 'password', 'role' => 'host',
            'company_id' => $company->company_id, 'department_id' => $deptB->id, 'status' => 'active',
        ]);

        $visitor = Visitor::create([
            'name' => 'Change Visitor', 'email' => 'visitor@hostchange.test',
            'id_document_no' => 'ID-HC-1', 'phone' => '555111',
            'password' => 'password', 'company_id' => $company->company_id,
        ]);

        $visit = VisitRequest::create([
            'visitor_id' => $visitor->id,
            'host_id' => $host->id,
            'alternate_host_id' => $newHost->id,
            'department_id' => $deptA->id,
            'purpose' => 'Interview',
            'visit_type' => 'solo',
            'requested_time' => Carbon::now()->addDay()->startOfHour(),
            'duration' => 60,
            'status' => 'approved',
            'company_id' => $company->company_id,
        ]);

        $changeRequest = HostChangeRequest::create([
            'visit_request_id' => $visit->id,
            'previous_host_id' => $host->id,
            'new_host_id' => $newHost->id,
            'new_department_id' => $deptB->id,
            'requested_by' => $admin->id,
            'requested_by_role' => 'admin',
            'reason' => 'Load balancing',
            'status' => 'pending',
        ]);

        return compact('company', 'deptA', 'deptB', 'admin', 'host', 'newHost', 'visitor', 'visit', 'changeRequest');
    }

    public function test_accepting_host_change_completes_full_transfer(): void
    {
        $d = $this->seedHostChange();
        $this->actingAs($d['admin']);

        $this->post("/host-change-requests/{$d['changeRequest']->id}/accept", [
            'review_notes' => 'Approved',
        ])->assertSessionHas('success');

        $visit = $d['visit']->fresh();

        // New responsible user + their department; stale alternate link cleared.
        $this->assertSame($d['newHost']->id, $visit->host_id);
        $this->assertSame($d['deptB']->id, $visit->department_id);
        $this->assertNull($visit->alternate_host_id);
        $this->assertSame('approved', $visit->status);

        // Old host loses the visit; new host gains it.
        $this->assertSame(0, VisitRequest::where('host_id', $d['host']->id)->count());
        $this->assertSame(1, VisitRequest::where('host_id', $d['newHost']->id)->count());

        // No duplication: same visit and visitor.
        $this->assertDatabaseCount('visit_requests', 1);
        $this->assertDatabaseCount('visitors', 1);
        $this->assertSame($d['visitor']->id, $visit->visitor_id);

        // Transfer history recorded like every other transfer path.
        $this->assertDatabaseHas('host_transfers', [
            'visit_request_id' => $d['visit']->id,
            'previous_host_id' => $d['host']->id,
            'new_host_id' => $d['newHost']->id,
            'transferred_by' => $d['admin']->id,
        ]);

        // Request marked accepted, and the new host is notified.
        $this->assertSame('accepted', $d['changeRequest']->fresh()->status);
        $this->assertNotNull($d['changeRequest']->fresh()->transferred_at);
        $this->assertDatabaseHas('system_notifications', [
            'recipient_type' => 'user',
            'recipient_id' => $d['newHost']->id,
            'title' => 'Host Change Accepted',
        ]);
    }

    public function test_accepting_host_change_hands_off_open_escalation(): void
    {
        $d = $this->seedHostChange();
        $d['visit']->update(['escalated_to' => $d['admin']->id, 'status' => 'escalated']);

        Escalation::create([
            'visit_request_id' => $d['visit']->id,
            'escalated_by' => $d['host']->id,
            'escalated_to' => $d['admin']->id,
            'reason' => 'Needs manager attention',
            'trigger_type' => 'manual',
        ]);

        $this->actingAs($d['admin']);
        $this->post("/host-change-requests/{$d['changeRequest']->id}/accept")
            ->assertSessionHas('success');

        $visit = $d['visit']->fresh();

        $this->assertSame($d['newHost']->id, $visit->host_id);
        $this->assertNull($visit->escalated_to);
        $this->assertNotNull(
            Escalation::where('visit_request_id', $d['visit']->id)->first()->resolved_at
        );
    }

    public function test_accepting_host_change_keeps_meeting_room(): void
    {
        $d = $this->seedHostChange();
        $d['changeRequest']->update(['meeting_room' => 'ROOM-9']);

        $this->actingAs($d['admin']);
        $this->post("/host-change-requests/{$d['changeRequest']->id}/accept")
            ->assertSessionHas('success');

        $this->assertSame('ROOM-9', $d['visit']->fresh()->meeting_room_id);
    }
}
