<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\User;
use App\Models\VisitRequest;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VisitDeliveryFlowTest extends TestCase
{
    use RefreshDatabase;

    private Company $companyA;
    private Branch $branchA;
    private Department $itDept;
    private Department $financeDept;
    private User $hostItA;
    private User $hostFinanceA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyA = Company::create(['company_name' => 'Alpha Corp', 'status' => 'active']);

        $this->branchA = Branch::create(['company_id' => $this->companyA->company_id, 'name' => 'HQ', 'slug' => 'alpha-hq', 'is_active' => true]);

        $this->itDept      = Department::create(['name' => 'Information Technology', 'company_id' => $this->companyA->company_id]);
        $this->financeDept = Department::create(['name' => 'Finance', 'company_id' => $this->companyA->company_id]);

        $this->hostItA      = $this->host('Host IT HQ', $this->itDept, $this->branchA);
        $this->hostFinanceA = $this->host('Host Finance HQ', $this->financeDept, $this->branchA);
    }

    private function host(string $name, Department $dept, ?Branch $branch): User
    {
        return User::create([
            'name'          => $name,
            'email'         => strtolower(str_replace([' ', "'"], '', $name)) . '@' . $dept->id . '.test',
            'password'      => 'password',
            'role'          => 'host',
            'status'        => 'active',
            'company_id'    => $dept->company_id,
            'department_id' => $dept->id,
            'branch_id'     => $branch?->id,
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'visitor_name'   => 'John Doe',
            'id_document_no' => '123456',
            'visitor_email'  => 'john@example.com',
            'visitor_phone'  => '123456789',
            'company_id'     => $this->companyA->company_id,
            'branch_id'      => $this->branchA->id,
            'purpose'        => 'Software support',
            'requested_time' => $this->nextWeekdayTime(),
            'duration'       => 60,
        ], $overrides);
    }

    private function nextWeekdayTime(): string
    {
        $time = Carbon::tomorrow();
        while ($time->isWeekend()) {
            $time->addDay();
        }
        return $time->setTime(10, 0)->format('Y-m-d\TH:i');
    }

    private function latestRequest(): VisitRequest
    {
        return VisitRequest::latest('id')->first();
    }

    public function test_visitor_submission_creates_notification_and_reaches_host_pending_list(): void
    {
        $this->post('/request-visit', $this->payload(['purpose' => 'Software support']))
            ->assertSessionHas('success');

        $request = $this->latestRequest();
        $this->assertSame('pending', $request->status);
        $this->assertSame($this->hostItA->id, $request->host_id);

        // Required DB notification/assignment record must exist for the host.
        $this->assertDatabaseHas('system_notifications', [
            'recipient_type' => 'user',
            'recipient_id'   => $this->hostItA->id,
            'event'          => 'Visit Requested',
            'is_read'        => 0,
        ]);

        // Host sees the request in the notification bell API as unread.
        $this->actingAs($this->hostItA)
            ->getJson('/api/notifications')
            ->assertOk()
            ->assertJson(['unread_count' => 1])
            ->assertJsonCount(1, 'notifications')
            ->assertJsonFragment(['event' => 'Visit Requested', 'is_read' => false]);

        // Host sees the request as a pending visit on their dashboard.
        $this->get('/host/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard/Host')
                ->has('visits', 1)
                ->where('visits.0.id', $request->id)
                ->where('visits.0.status', 'pending')
                ->where('visits.0.visitor.name', 'John Doe'));
    }

    public function test_host_can_approve_request_from_pending_list(): void
    {
        $this->post('/request-visit', $this->payload(['purpose' => 'Software support']));
        $request = $this->latestRequest();

        $this->actingAs($this->hostItA)
            ->post("/visit-requests/{$request->id}/host-decision", ['decision' => 'approve'])
            ->assertSessionHas('success');

        $this->assertSame('approved', $request->fresh()->status);
        $this->assertDatabaseHas('qr_tokens', ['visit_request_id' => $request->id]);
    }

    public function test_host_can_reject_request_with_comment(): void
    {
        $this->post('/request-visit', $this->payload(['purpose' => 'Software support']));
        $request = $this->latestRequest();

        $this->actingAs($this->hostItA)
            ->post("/visit-requests/{$request->id}/host-decision", [
                'decision' => 'reject',
                'comments' => 'Not available that day.',
            ])
            ->assertSessionHas('success');

        $this->assertSame('rejected', $request->fresh()->status);
    }

    public function test_request_without_branch_selection_still_reaches_branch_scoped_host(): void
    {
        // The visitor form allows omitting branch_id. The resolved host is
        // branch-scoped (BranchScope filters the host dashboard to their own
        // branch), so the request MUST inherit the host's branch or the host
        // will never see it.
        $this->post('/request-visit', $this->payload([
            'branch_id' => null,
            'purpose'   => 'Software support',
        ]))->assertSessionHas('success');

        $request = $this->latestRequest();
        $this->assertSame($this->hostItA->id, $request->host_id);
        $this->assertSame($this->branchA->id, $request->branch_id, 'Request must inherit the assigned host\'s branch.');

        $this->actingAs($this->hostItA)
            ->get('/host/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard/Host')
                ->has('visits', 1)
                ->where('visits.0.id', $request->id));
    }
}
