<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\QRToken;
use App\Models\VisitGroupMember;
use App\Models\VisitRequest;
use App\Models\Visitor;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicQrScanTest extends TestCase
{
    use RefreshDatabase;

    private function seedVisit(string $tag = ''): array
    {
        $company = Company::create(['company_name' => 'QR Corp' . $tag, 'status' => 'active']);

        $host = User::create([
            'name'       => 'QR Host',
            'email'      => "qrhost{$tag}@test.test",
            'password'   => 'password',
            'role'       => 'host',
            'company_id' => $company->company_id,
            'status'     => 'active',
        ]);

        $dept = Department::create(['name' => 'Engineering', 'company_id' => $company->company_id]);
        $host->update(['department_id' => $dept->id]);

        $visitor = Visitor::create([
            'name'           => 'QR Visitor',
            'email'          => "qrvisitor{$tag}@test.test",
            'id_document_no' => 'ID-QR-1' . $tag,
            'phone'          => '555123',
            'sex'            => 'female',
            'age'            => 30,
            'nationality'    => 'Local',
            'company_name'   => 'QR Corp',
            'company_position' => 'Engineer',
            'photo_url'      => 'photos/qr.png',
            'password'       => 'password',
            'company_id'     => $company->company_id,
        ]);

        $visit = VisitRequest::create([
            'visitor_id'     => $visitor->id,
            'host_id'        => $host->id,
            'department_id'  => $dept->id,
            'purpose'        => 'Site tour',
            'requested_time' => Carbon::now()->addDay()->startOfHour(),
            'duration'       => 60,
            'status'         => 'approved',
            'company_id'     => $company->company_id,
        ]);

        $token = 'VMS-TESTQR-' . $visit->id;
        QRToken::create([
            'visit_request_id' => $visit->id,
            'token_value'      => $token,
            'type'             => 'visit',
            'expires_at'       => now()->addHours(4),
            'status'           => 'active',
        ]);

        return compact('company', 'host', 'dept', 'visitor', 'visit', 'token');
    }

    public function test_scanning_entry_qr_renders_full_visitor_info_page(): void
    {
        $d = $this->seedVisit();

        $this->get('/qr/' . $d['token'])
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Public/QrScan')
                ->where('scan_status', 'valid')
                ->where('visitor_number', $d['token'])
                ->where('is_exit', false)
                ->where('visit.id', $d['visit']->id)
                ->where('visit.name', 'QR Visitor')
                ->where('visit.id_document_no', 'ID-QR-1')
                ->where('visit.phone', '555123')
                ->where('visit.email', 'qrvisitor@test.test')
                ->where('visit.host_name', 'QR Host')
                ->where('visit.purpose', 'Site tour')
                ->where('visit.status', 'approved')
                ->where('visit.company_name', 'QR Corp')
                ->where('visit.is_representative', false)
                ->where('visit.group_visit_id', null)
                ->where('visit.group_members_count', 0)
                ->where('visit.properties', [])
            );
    }

    public function test_scanning_exit_qr_marks_result_as_exit(): void
    {
        $d = $this->seedVisit();
        $d['visit']->update([
            'status'             => 'checked_in',
            'exit_qr_token'      => 'VMS-EXIT-TEST-' . $d['visit']->id,
            'exit_qr_expires_at' => now()->addHours(4),
        ]);

        $this->get('/qr/' . $d['visit']->exit_qr_token)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Public/QrScan')
                ->where('scan_status', 'valid')
                ->where('is_exit', true)
                ->where('visit.name', 'QR Visitor')
            );
    }

    public function test_scanning_group_member_qr_resolves_the_member(): void
    {
        $d = $this->seedVisit();
        $member = VisitGroupMember::create([
            'visit_request_id' => $d['visit']->id,
            'name'             => 'Member One',
            'id_document_no'   => 'ID-MEMBER-1',
            'gender'           => 'male',
            'position'         => 'Technician',
            'qr_token'         => 'VMS-GRP-TEST-' . $d['visit']->id,
            'qr_expires_at'    => now()->addHours(4),
        ]);

        \App\Models\VisitorProperty::create([
            'visit_request_id'         => $d['visit']->id,
            'visit_group_member_id'    => $member->id,
            'item_name'                => 'Laptop',
            'quantity'                 => 1,
            'serial_number'            => 'SN-001',
            'property_tag'             => 'TAG-1',
            'status'                   => 'checked_in',
            'checked_in_at'            => now(),
            'registered_by'            => $d['host']->id,
        ]);

        $this->get('/qr/' . $member->qr_token)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Public/QrScan')
                ->where('scan_status', 'valid')
                ->where('is_exit', false)
                ->where('visit.name', 'Member One')
                ->where('visit.id_document_no', 'ID-MEMBER-1')
                ->where('visit.company_position', 'Technician')
                ->where('visit.is_group_member', true)
                ->where('visit.group_member_id', $member->id)
                ->where('visit.group_visit_id', $d['visit']->id)
                ->where('visit.is_representative', false)
                ->where('visit.representative_name', 'QR Visitor')
                ->where('visit.group_members_count', 0)
                ->where('visit.properties.0.item_name', 'Laptop')
                ->where('visit.properties.0.serial_number', 'SN-001')
                ->where('visit.properties.0.property_tag', 'TAG-1')
            );
    }

    public function test_scanning_representative_qr_returns_group_context_and_properties(): void
    {
        $d = $this->seedVisit();
        $d['visit']->update(['visit_type' => 'company']);

        $member = VisitGroupMember::create([
            'visit_request_id' => $d['visit']->id,
            'name'             => 'Member One',
            'id_document_no'   => 'ID-MEMBER-1',
            'qr_token'         => 'VMS-GRP-TEST-' . $d['visit']->id,
            'qr_expires_at'    => now()->addHours(4),
        ]);

        \App\Models\VisitorProperty::create([
            'visit_request_id' => $d['visit']->id,
            'visitor_id'       => $d['visitor']->id,
            'item_name'        => 'Camera',
            'quantity'         => 2,
            'property_tag'     => 'CAM-1',
            'status'           => 'checked_in',
            'checked_in_at'    => now(),
            'registered_by'    => $d['host']->id,
        ]);

        \App\Models\VisitorProperty::create([
            'visit_request_id'      => $d['visit']->id,
            'visit_group_member_id' => $member->id,
            'item_name'             => 'Other Member Item',
            'quantity'              => 1,
            'status'                => 'checked_in',
            'checked_in_at'         => now(),
            'registered_by'         => $d['host']->id,
        ]);

        $this->get('/qr/' . $d['token'])
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Public/QrScan')
                ->where('scan_status', 'valid')
                ->where('visit.is_representative', true)
                ->where('visit.group_visit_id', $d['visit']->id)
                ->where('visit.group_members_count', 1)
                ->where('visit.properties.0.item_name', 'Camera')
                ->has('visit.properties', 1)
            );
    }

    public function test_scanning_unknown_qr_returns_not_found(): void
    {
        $this->get('/qr/VMS-NOT-A-REAL-TOKEN')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Public/QrScan')
                ->where('scan_status', 'not_found')
                ->where('visitor_number', 'VMS-NOT-A-REAL-TOKEN')
                ->missing('visit')
            );
    }

    public function test_malformed_qr_token_is_rejected_without_exposing_visitor_data(): void
    {
        $d = $this->seedVisit();

        $this->get('/qr/' . rawurlencode($d['token'] . ' <script>alert(1)'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Public/QrScan')
                ->where('scan_status', 'not_found')
                ->missing('visit')
            );
    }

    public function test_expired_qr_token_is_rejected_without_visitor_data(): void
    {
        $d = $this->seedVisit();

        QRToken::where('token_value', $d['token'])->update([
            'expires_at' => now()->subHour(),
            'status'     => QRToken::STATUS_EXPIRED,
        ]);

        $this->get('/qr/' . $d['token'])
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Public/QrScan')
                ->where('scan_status', 'expired')
                ->where('visitor_number', $d['token'])
                ->missing('visit')
            );
    }

    public function test_revoked_qr_token_is_rejected_without_visitor_data(): void
    {
        $d = $this->seedVisit();

        QRToken::where('token_value', $d['token'])->update([
            'revoked_at' => now(),
            'status'     => QRToken::STATUS_REVOKED,
        ]);

        $this->get('/qr/' . $d['token'])
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Public/QrScan')
                ->where('scan_status', 'revoked')
                ->where('visitor_number', $d['token'])
                ->missing('visit')
            );
    }

    public function test_checked_in_visitor_keeps_a_valid_pass_until_check_out(): void
    {
        $d = $this->seedVisit();
        $d['visit']->update(['status' => 'checked_in']);

        QRToken::where('token_value', $d['token'])->update([
            'expires_at' => now()->subHour(),
            'status'     => QRToken::STATUS_EXPIRED,
        ]);

        $this->get('/qr/' . $d['token'])
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Public/QrScan')
                ->where('scan_status', 'valid')
                ->where('visit.name', 'QR Visitor')
            );
    }

    public function test_each_token_resolves_only_its_own_visitor_record(): void
    {
        $a = $this->seedVisit();
        $b = $this->seedVisit('-2');

        $this->get('/qr/' . $a['token'])
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Public/QrScan')
                ->where('scan_status', 'valid')
                ->where('visit.id', $a['visit']->id)
                ->where('visit.id_document_no', 'ID-QR-1')
                ->where('visit.email', 'qrvisitor@test.test')
            );

        $this->get('/qr/' . $b['token'])
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Public/QrScan')
                ->where('scan_status', 'valid')
                ->where('visit.id', $b['visit']->id)
                ->where('visit.id_document_no', 'ID-QR-1-2')
                ->where('visit.email', 'qrvisitor-2@test.test')
            );
    }

    public function test_badge_still_renders_and_qr_encodes_scan_url(): void
    {
        $d = $this->seedVisit();

        $svg = \App\Services\QrBadgeService::generateVisitBadge($d['visit']);
        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('data:image', $svg);

        $payload = \App\Services\QrBadgeService::scanPayload($d['token']);

        // The QR link is produced by the centralized URL service, never from
        // the current request root (which is what pointed QR codes at localhost).
        $this->assertSame(\App\Services\QrUrlService::generate($d['token']), $payload);
        $this->assertStringStartsWith(rtrim(\App\Services\QrUrlService::baseUrl(), '/'), $payload);
        $this->assertStringContainsString('/qr/', $payload);
        $this->assertStringNotContainsString('localhost', $payload);

        // The badge still shows the visitor number as its visible token label.
        $this->assertStringContainsString($d['token'], $svg);
    }
}