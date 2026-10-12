<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\RescheduleRequest;
use App\Models\VisitRequest;
use App\Models\Visitor;
use App\Models\VisitorBadge;
use App\Models\User;
use App\Services\QRTokenService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rescheduling must work for both visitors and hosting users, before and after
 * approval, without mutating the visit's approval status or its QR validity.
 */
class RescheduleWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function seedData(string $status = 'approved'): array
    {
        $company = Company::create(['company_name' => 'Reschedule Corp', 'status' => 'active']);
        $dept    = Department::create(['name' => 'Reception', 'company_id' => $company->company_id]);

        $host = User::create([
            'name' => 'Primary Host', 'email' => 'host@resched.test', 'password' => 'password',
            'role' => 'host', 'company_id' => $company->company_id, 'department_id' => $dept->id, 'status' => 'active',
        ]);
        $alternate = User::create([
            'name' => 'Alternate Host', 'email' => 'alt@resched.test', 'password' => 'password',
            'role' => 'host', 'company_id' => $company->company_id, 'department_id' => $dept->id, 'status' => 'active',
        ]);
        $otherHost = User::create([
            'name' => 'Unrelated Host', 'email' => 'other@resched.test', 'password' => 'password',
            'role' => 'host', 'company_id' => $company->company_id, 'department_id' => $dept->id, 'status' => 'active',
        ]);
        $admin = User::create([
            'name' => 'Company Admin', 'email' => 'admin@resched.test', 'password' => 'password',
            'role' => 'admin', 'company_id' => $company->company_id, 'status' => 'active',
        ]);
        $visitor = Visitor::create([
            'name' => 'Reschedule Visitor', 'email' => 'visitor@resched.test', 'id_document_no' => 'ID-RS-1',
            'phone' => '555000', 'password' => 'password', 'company_id' => $company->company_id,
        ]);

        $visit = VisitRequest::create([
            'visitor_id'     => $visitor->id,
            'host_id'        => $host->id,
            'alternate_host_id' => $alternate->id,
            'department_id'  => $dept->id,
            'purpose'        => 'Design review',
            'visit_type'     => 'solo',
            'requested_time' => Carbon::now()->addDays(2)->setTime(10, 0),
            'duration'       => 60,
            'status'         => $status,
            'company_id'     => $company->company_id,
        ]);

        return compact('company', 'dept', 'host', 'alternate', 'otherHost', 'admin', 'visitor', 'visit');
    }

    private function payload(): array
    {
        return [
            'new_requested_time' => Carbon::now()->addDays(3)->setTime(14, 0)->format('Y-m-d\TH:i'),
            'new_duration'       => 90,
            'reason'             => 'Conflict with another meeting',
        ];
    }

    public function test_visitor_reschedule_creates_pending_request_without_changing_time(): void
    {
        $d = $this->seedData('approved');
        $original = $d['visit']->requested_time->format('Y-m-d H:i');

        $this->actingAs($d['visitor'], 'visitor')
            ->post("/visit-requests/{$d['visit']->id}/request-reschedule", $this->payload())
            ->assertSessionHas('success');

        $this->assertDatabaseHas('reschedule_requests', [
            'visit_request_id' => $d['visit']->id,
            'requested_by_role' => 'visitor',
            'status'           => 'pending',
        ]);

        // The visit itself must NOT move until a host approves.
        $this->assertSame($original, $d['visit']->fresh()->requested_time->format('Y-m-d H:i'));
    }

    public function test_host_approve_applies_time_preserves_status_and_refreshes_qr(): void
    {
        $d = $this->seedData('approved');
        QRTokenService::generate($d['visit']);
        $d['visit']->refresh();
        $oldToken = $d['visit']->qrToken->token_value;

        $reschedule = RescheduleRequest::create([
            'visit_request_id' => $d['visit']->id,
            'requested_by_visitor' => $d['visitor']->id,
            'requested_by_role' => 'visitor',
            'old_requested_time' => $d['visit']->requested_time,
            'old_duration' => $d['visit']->duration,
            'new_requested_time' => Carbon::now()->addDays(4)->setTime(9, 0),
            'new_duration' => 120,
            'reason' => 'Travel delay',
            'status' => 'pending',
        ]);

        $this->actingAs($d['host'])
            ->post("/reschedule-requests/{$reschedule->id}/review", ['action' => 'approve'])
            ->assertSessionHas('success');

        $fresh = $d['visit']->fresh();
        $this->assertSame(120, (int) $fresh->duration);
        $this->assertSame('approved', $fresh->status);
        $this->assertSame('09:00', $fresh->requested_time->format('H:i'));
        $this->assertSame('approved', $reschedule->fresh()->status);

        // QR token was reissued so its validity tracks the new time.
        $this->assertNotNull($fresh->qrToken);
        $this->assertNotSame($oldToken, $fresh->qrToken->token_value);
    }

    public function test_host_reject_keeps_original_time(): void
    {
        $d = $this->seedData('approved');
        $original = $d['visit']->requested_time->format('Y-m-d H:i');

        $reschedule = RescheduleRequest::create([
            'visit_request_id' => $d['visit']->id,
            'requested_by_visitor' => $d['visitor']->id,
            'requested_by_role' => 'visitor',
            'old_requested_time' => $d['visit']->requested_time,
            'old_duration' => $d['visit']->duration,
            'new_requested_time' => Carbon::now()->addDays(5)->setTime(11, 0),
            'new_duration' => 30,
            'status' => 'pending',
        ]);

        $this->actingAs($d['host'])
            ->post("/reschedule-requests/{$reschedule->id}/review", ['action' => 'reject', 'comments' => 'Not available'])
            ->assertSessionHas('success');

        $this->assertSame($original, $d['visit']->fresh()->requested_time->format('Y-m-d H:i'));
        $this->assertSame('rejected', $reschedule->fresh()->status);
    }

    public function test_unrelated_user_cannot_review_reschedule(): void
    {
        $d = $this->seedData('approved');

        $reschedule = RescheduleRequest::create([
            'visit_request_id' => $d['visit']->id,
            'requested_by_visitor' => $d['visitor']->id,
            'requested_by_role' => 'visitor',
            'old_requested_time' => $d['visit']->requested_time,
            'old_duration' => $d['visit']->duration,
            'new_requested_time' => Carbon::now()->addDays(4)->setTime(9, 0),
            'new_duration' => 60,
            'status' => 'pending',
        ]);

        $this->actingAs($d['otherHost'])
            ->post("/reschedule-requests/{$reschedule->id}/review", ['action' => 'approve'])
            ->assertForbidden();

        $this->assertSame('pending', $reschedule->fresh()->status);
    }

    public function test_host_request_reschedule_applies_immediately(): void
    {
        $d = $this->seedData('approved');
        $payload = $this->payload();

        $this->actingAs($d['host'])
            ->post("/visit-requests/{$d['visit']->id}/request-reschedule", $payload)
            ->assertSessionHas('success');

        $fresh = $d['visit']->fresh();
        $this->assertSame(90, (int) $fresh->duration);
        $this->assertSame('approved', $fresh->status);
        $this->assertSame('14:00', $fresh->requested_time->format('H:i'));
        $this->assertDatabaseHas('reschedule_requests', [
            'visit_request_id' => $d['visit']->id,
            'requested_by_role' => 'host',
            'status' => 'approved',
        ]);
    }

    public function test_admin_decision_reschedule_works_without_duration(): void
    {
        $d = $this->seedData('escalated');
        $d['visit']->host_id = $d['admin']->id;
        $d['visit']->escalated_to = $d['admin']->id;
        $d['visit']->save();

        $this->actingAs($d['admin'])
            ->post("/visit-requests/{$d['visit']->id}/decision", [
                'decision' => 'reschedule',
                'rescheduled_time' => Carbon::now()->addDays(6)->setTime(15, 0)->format('Y-m-d\TH:i'),
            ])->assertSessionHas('success');

        $fresh = $d['visit']->fresh();
        $this->assertSame('15:00', $fresh->requested_time->format('H:i'));
        $this->assertSame('escalated', $fresh->status);
        // Duration fell back to the existing value (no UI duration supplied).
        $this->assertSame(60, (int) $fresh->duration);
        $this->assertDatabaseHas('reschedule_requests', [
            'visit_request_id' => $d['visit']->id,
            'status' => 'approved',
        ]);
    }

    public function test_admin_update_time_syncs_badge_and_records_history(): void
    {
        $d = $this->seedData('approved');
        VisitorBadge::create([
            'visit_request_id' => $d['visit']->id,
            'visitor_name' => $d['visitor']->name,
            'host_name' => $d['host']->name,
            'department' => $d['dept']->name,
            'scheduled_time' => $d['visit']->requested_time->format('M d, Y H:i'),
            'duration' => '60 min',
            'issued_at' => Carbon::now(),
        ]);

        $this->actingAs($d['admin'])
            ->post("/admin/visit-requests/{$d['visit']->id}/update-time", [
                'requested_time' => Carbon::now()->addDays(7)->setTime(8, 30)->format('Y-m-d\TH:i'),
                'duration' => 45,
            ])->assertSessionHas('success');

        $fresh = $d['visit']->fresh();
        $this->assertSame('08:30', $fresh->requested_time->format('H:i'));
        $this->assertSame(45, (int) $fresh->duration);

        $badge = VisitorBadge::where('visit_request_id', $d['visit']->id)->first();
        $this->assertSame('45 min', $badge->duration);
        $this->assertStringContainsString('08:30', $badge->scheduled_time);

        $this->assertDatabaseHas('reschedule_requests', [
            'visit_request_id' => $d['visit']->id,
            'status' => 'approved',
        ]);
    }

    public function test_cancelling_visit_cancels_pending_reschedule(): void
    {
        $d = $this->seedData('approved');

        $reschedule = RescheduleRequest::create([
            'visit_request_id' => $d['visit']->id,
            'requested_by_visitor' => $d['visitor']->id,
            'requested_by_role' => 'visitor',
            'old_requested_time' => $d['visit']->requested_time,
            'old_duration' => $d['visit']->duration,
            'new_requested_time' => Carbon::now()->addDays(4)->setTime(9, 0),
            'new_duration' => 60,
            'status' => 'pending',
        ]);

        $this->actingAs($d['visitor'], 'visitor')
            ->post("/visit-requests/{$d['visit']->id}/cancel")
            ->assertSessionHas('success');

        $this->assertSame('cancelled', $d['visit']->fresh()->status);
        // Proves the widened status column accepts 'cancelled'.
        $this->assertSame('cancelled', $reschedule->fresh()->status);
    }
}
