<?php

namespace Tests\Feature;

use App\Models\CheckInOutLog;
use App\Models\Company;
use App\Models\Department;
use App\Models\VisitGroupMember;
use App\Models\VisitRequest;
use App\Models\Visitor;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GroupCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private function seedGroupVisit(): array
    {
        $company = Company::create(['company_name' => 'Group Corp', 'status' => 'active']);

        $guard = User::create([
            'name'       => 'Gate Guard',
            'email'      => 'guard@group.test',
            'password'   => 'password',
            'role'       => 'security_guard',
            'company_id' => $company->company_id,
            'status'     => 'active',
        ]);

        $host = User::create([
            'name'       => 'Host One',
            'email'      => 'host@group.test',
            'password'   => 'password',
            'role'       => 'host',
            'company_id' => $company->company_id,
            'status'     => 'active',
        ]);

        $dept = Department::create(['name' => 'Engineering', 'company_id' => $company->company_id]);
        $host->update(['department_id' => $dept->id]);

        $visitor = Visitor::create([
            'name'           => 'Representative One',
            'email'          => 'rep@group.test',
            'id_document_no' => 'ID-REP-1',
            'phone'          => '333333',
            'password'       => 'password',
            'company_id'     => $company->company_id,
        ]);

        $visit = VisitRequest::create([
            'visitor_id'     => $visitor->id,
            'host_id'        => $host->id,
            'department_id'  => $dept->id,
            'purpose'        => 'Onboarding Group',
            'visit_type'     => 'company',
            'requested_time' => Carbon::now()->addDay()->startOfHour(),
            'duration'       => 60,
            'status'         => 'approved',
            'company_id'     => $company->company_id,
        ]);

        $member = VisitGroupMember::create([
            'visit_request_id' => $visit->id,
            'name'             => 'Group Member One',
            'id_document_no'   => 'ID-MEMBER-1',
        ]);

        return compact('company', 'guard', 'host', 'dept', 'visitor', 'visit', 'member');
    }

    public function test_representative_checkout_is_blocked_while_group_members_are_still_inside(): void
    {
        $d = $this->seedGroupVisit();
        $this->actingAs($d['guard']);

        // Representative arrives first
        $this->post("/security/visits/{$d['visit']->id}/checkin")->assertSessionHas('success');
        $this->assertSame('checked_in', $d['visit']->fresh()->status);

        // Group member arrives independently
        $this->post("/security/visits/{$d['visit']->id}/checkin", ['group_member_id' => $d['member']->id])
            ->assertSessionHas('success');
        $this->assertNotNull($d['member']->fresh()->checked_in_at);

        // Representative must NOT be able to check out while the member is inside
        $blocked = $this->post("/security/visits/{$d['visit']->id}/checkout")
            ->assertJson(['success' => false]);
        $this->assertStringContainsString('still on premises', $blocked->json('message'));
        $this->assertSame('checked_in', $d['visit']->fresh()->status);
        $this->assertNull(CheckInOutLog::where('visit_request_id', $d['visit']->id)
            ->whereNull('visit_group_member_id')->first()->fresh()->checkout_time);
    }

    public function test_member_can_checkout_before_representative_and_visit_stays_open(): void
    {
        $d = $this->seedGroupVisit();
        $this->actingAs($d['guard']);

        $this->post("/security/visits/{$d['visit']->id}/checkin")->assertSessionHas('success');
        $this->post("/security/visits/{$d['visit']->id}/checkin", ['group_member_id' => $d['member']->id])
            ->assertSessionHas('success');

        // Member departs independently
        $this->post("/security/visits/{$d['visit']->id}/checkout", ['group_member_id' => $d['member']->id])
            ->assertJson(['success' => true]);

        $this->assertNotNull($d['member']->fresh()->checked_out_at);
        // Representative is still inside, so the visit must remain open
        $this->assertSame('checked_in', $d['visit']->fresh()->status);
    }

    public function test_property_checkout_is_isolated_per_owner(): void
    {
        $d = $this->seedGroupVisit();
        $this->actingAs($d['guard']);

        $memberB = VisitGroupMember::create([
            'visit_request_id' => $d['visit']->id,
            'name'             => 'Group Member Two',
            'id_document_no'   => 'ID-MEMBER-2',
        ]);

        $d['visit']->update(['status' => 'approved']);
        $this->post("/security/visits/{$d['visit']->id}/checkin")->assertSessionHas('success');
        $this->post("/security/visits/{$d['visit']->id}/checkin", ['group_member_id' => $d['member']->id])
            ->assertSessionHas('success');
        $this->post("/security/visits/{$d['visit']->id}/checkin", ['group_member_id' => $memberB->id])
            ->assertSessionHas('success');

        // Representative registers a laptop; member A a USB drive; member B a headset.
        $this->post('/security/register-property', [
            'visit_request_id' => $d['visit']->id,
            'items' => [['item_name' => 'Laptop', 'serial_number' => 'SN-123']],
        ])->assertJson(['success' => true]);

        $this->post('/security/register-property', [
            'visit_request_id'      => $d['visit']->id,
            'visit_group_member_id' => $d['member']->id,
            'items'                 => [['item_name' => 'USB Drive', 'serial_number' => 'SN-87']],
        ])->assertJson(['success' => true]);

        $this->post('/security/register-property', [
            'visit_request_id'      => $d['visit']->id,
            'visit_group_member_id' => $memberB->id,
            'items'                 => [['item_name' => 'Headset', 'serial_number' => 'SN-42']],
        ])->assertJson(['success' => true]);

        // Ownership stamps: representative -> visitor_id, members -> member id only.
        $repProp = \App\Models\VisitorProperty::where('item_name', 'Laptop')->first();
        $memberAProp = \App\Models\VisitorProperty::where('item_name', 'USB Drive')->first();
        $memberBProp = \App\Models\VisitorProperty::where('item_name', 'Headset')->first();

        $this->assertSame($d['visitor']->id, $repProp->visitor_id);
        $this->assertNull($repProp->visit_group_member_id);

        $this->assertNull($memberAProp->visitor_id);
        $this->assertSame($d['member']->id, $memberAProp->visit_group_member_id);
        $this->assertSame($memberB->id, $memberBProp->visit_group_member_id);

        // Member A's property list contains ONLY their own USB drive.
        $list = $this->get("/security/visit/{$d['visit']->id}/properties?group_member_id={$d['member']->id}")
            ->assertOk();
        $names = collect($list->json('properties'))->pluck('item_name')->all();
        $this->assertSame(['USB Drive'], $names);

        // Verify-return for member A with ONLY the USB returned: no false missing
        // alarm for the representative's Laptop or member B's Headset.
        $this->post("/security/visits/{$d['visit']->id}/verify-return", [
            'group_member_id' => $d['member']->id,
            'returned_items'  => [['item_name' => 'USB Drive', 'serial_number' => 'SN-87']],
        ])->assertJson(['all_returned' => true, 'missing_items' => []]);

        // Member A can check out with their own item returned.
        $this->post("/security/visits/{$d['visit']->id}/checkout", [
            'group_member_id' => $d['member']->id,
            'returned_items'  => [['item_name' => 'USB Drive', 'serial_number' => 'SN-87']],
        ])->assertJson(['success' => true]);

        // The representative's Laptop and member B's Headset must remain checked_in.
        $this->assertSame('checked_in', $repProp->fresh()->status);
        $this->assertSame('checked_in', $memberBProp->fresh()->status);
        $this->assertSame('returned', $memberAProp->fresh()->status);
    }

    public function test_qr_remains_valid_until_checkout_then_consumed(): void
    {
        $d = $this->seedGroupVisit();
        \App\Services\QRTokenService::generate($d['visit']);
        \App\Services\QRTokenService::generateGroupMemberTokens($d['visit']);
        $this->actingAs($d['guard']);

        $this->post("/security/visits/{$d['visit']->id}/checkin")->assertSessionHas('success');
        $this->post("/security/visits/{$d['visit']->id}/checkin", ['group_member_id' => $d['member']->id])
            ->assertSessionHas('success');

        // QR is NOT consumed at check-in — it must stay valid for re-verification.
        $token = $d['visit']->qrToken;
        $this->assertSame('active', $token->fresh()->status);
        $this->assertNull($token->fresh()->used_at);
        $this->assertNull($d['member']->fresh()->qr_used_at);

        // Re-scanning the member's QR still validates while checked in.
        $this->post('/security/scan', ['token' => $d['member']->fresh()->qr_token])
            ->assertJson(['success' => true]);

        // Member checks out → their QR is consumed.
        $this->post("/security/visits/{$d['visit']->id}/checkout", ['group_member_id' => $d['member']->id])
            ->assertJson(['success' => true]);
        $this->assertNotNull($d['member']->fresh()->qr_used_at);

        // Representative checks out → the visit QR is consumed.
        $this->post("/security/visits/{$d['visit']->id}/checkout")
            ->assertJson(['success' => true]);
        $this->assertSame('used', $token->fresh()->status);
        $this->assertNotNull($token->fresh()->used_at);
    }

    public function test_representative_checkout_succeeds_after_all_members_have_departed(): void
    {
        $d = $this->seedGroupVisit();
        $this->actingAs($d['guard']);

        $this->post("/security/visits/{$d['visit']->id}/checkin")->assertSessionHas('success');
        $this->post("/security/visits/{$d['visit']->id}/checkin", ['group_member_id' => $d['member']->id])
            ->assertSessionHas('success');

        // Member departs first
        $this->post("/security/visits/{$d['visit']->id}/checkout", ['group_member_id' => $d['member']->id])
            ->assertJson(['success' => true]);
        $this->assertSame('checked_in', $d['visit']->fresh()->status);

        // Now the representative may complete their own check-out
        $this->post("/security/visits/{$d['visit']->id}/checkout")
            ->assertJson(['success' => true]);

        $this->assertSame('checked_out', $d['visit']->fresh()->status);
        $this->assertNotNull(CheckInOutLog::where('visit_request_id', $d['visit']->id)
            ->whereNull('visit_group_member_id')->first()->fresh()->checkout_time);
    }
}
