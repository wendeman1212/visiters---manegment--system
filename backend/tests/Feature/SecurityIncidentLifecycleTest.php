<?php

namespace Tests\Feature;

use App\Models\Blacklist;
use App\Models\Company;
use App\Models\Incident;
use App\Models\IncidentAuditTrail;
use App\Models\Location;
use App\Models\QRToken;
use App\Models\VisitGroupMember;
use App\Models\VisitRequest;
use App\Models\Visitor;
use App\Models\User;
use App\Services\SecurityIncidentService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityIncidentLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function seedCompanyAndGuard(string $suffix = ''): array
    {
        $company = Company::firstOrCreate(
            ['company_name' => 'Acme Security' . ($suffix ? " {$suffix}" : '')],
            ['status'       => 'active']
        );

        $location = Location::firstOrCreate(
            ['name'      => 'Main Entrance' . ($suffix ? " {$suffix}" : ''),
             'company_id'=> $company->company_id],
            ['is_active' => true]
        );

        $guard = User::firstOrCreate(
            ['email'      => 'guard@acme.test' . ($suffix ? ".{$suffix}" : '')],
            [
                'name'       => 'Gate Guard' . ($suffix ? " {$suffix}" : ''),
                'password'   => 'password',
                'role'       => 'security_guard',
                'company_id' => $company->company_id,
                'location_id'=> $location->id,
                'status'     => 'active',
            ]
        );

        return compact('company', 'location', 'guard');
    }

    private function seedVisit(array $extra = []): array
    {
        $d = $this->seedCompanyAndGuard();

        $host = User::firstOrCreate(
            ['email'      => 'host@acme.test'],
            [
                'name'       => 'Host Person',
                'password'   => 'password',
                'role'       => 'host',
                'company_id' => $d['company']->company_id,
                'location_id'=> $d['location']->id,
                'status'     => 'active',
            ]
        );

        $visitor = Visitor::firstOrCreate(
            ['email' => 'visitor@acme.test'],
            [
                'name'           => 'Test Visitor',
                'id_document_no' => 'ID-VISITOR-001',
                'phone'          => '555-0000',
                'password'       => 'password',
                'company_id'     => $d['company']->company_id,
            ]
        );

        $visit = VisitRequest::create(array_merge([
            'visitor_id'     => $visitor->id,
            'host_id'        => $host->id,
            'purpose'        => 'Facility Tour',
            'requested_time' => Carbon::now()->subHour(),
            'duration'       => 60,
            'status'         => 'approved',
            'company_id'     => $d['company']->company_id,
            'location_id'    => $d['location']->id,
            'branch_id'      => null,
            'visit_type'     => 'individual',
        ], $extra));

        QRToken::create([
            'visit_request_id' => $visit->id,
            'token_value'      => 'VMS-TESTTOKEN-' . $visit->id,
            'type'             => 'visit',
            'expires_at'       => Carbon::now()->addHours(2),
            'status'           => 'active',
        ]);

        return array_merge($d, compact('host', 'visitor', 'visit'));
    }

    /** @test */
    public function test_scanning_invalid_qr_creates_an_incident(): void
    {
        $d = $this->seedVisit();
        $this->actingAs($d['guard']);

        $response = $this->post('/security/scan', ['token' => 'VMS-TOTALLY-FAKE-12345']);

        $response->assertJson(['success' => false]);
        $this->assertDatabaseHas('incidents', [
            'type'     => Incident::TYPE_FAKE_QR,
            'severity' => 'high',
            'status'   => Incident::STATUS_OPEN,
            'scan_token' => 'VMS-TOTALLY-FAKE-12345',
        ]);
    }

    /** @test */
    public function test_repeated_invalid_scans_are_deduplicated(): void
    {
        $d = $this->seedVisit();
        $this->actingAs($d['guard']);

        $token = 'VMS-FAKE-DUPE-' . now()->timestamp;

        $this->post('/security/scan', ['token' => $token]);
        $this->post('/security/scan', ['token' => $token]);
        $this->post('/security/scan', ['token' => $token]);

        $incidents = Incident::withoutGlobalScopes()
            ->where('scan_token', $token)
            ->get();

        $this->assertCount(1, $incidents);
        $this->assertSame(3, $incidents->first()->attempt_count);

        $this->assertDatabaseHas('incident_audit_trail', [
            'action' => 'incident_created',
            'incident_id' => $incidents->first()->id,
        ]);
        $this->assertDatabaseHas('incident_audit_trail', [
            'action' => 'reoccurrence_detected',
            'incident_id' => $incidents->first()->id,
        ]);
        $this->assertDatabaseHas('incident_audit_trail', [
            'action' => 'reoccurrence_detected',
            'incident_id' => $incidents->first()->id,
        ]);
    }

    /** @test */
    public function test_different_tokens_create_separate_incidents(): void
    {
        $d = $this->seedVisit();
        $this->actingAs($d['guard']);

        $this->post('/security/scan', ['token' => 'VMS-FAKE-A']);
        $this->post('/security/scan', ['token' => 'VMS-FAKE-B']);

        $incidents = Incident::withoutGlobalScopes()
            ->whereIn('scan_token', ['VMS-FAKE-A', 'VMS-FAKE-B'])
            ->get();

        $this->assertCount(2, $incidents);
    }

    /** @test */
    public function test_full_qr_incident_lifecycle(): void
    {
        $d = $this->seedVisit();
        $this->actingAs($d['guard']);

        // 1. Scan invalid QR → incident created
        $this->post('/security/scan', ['token' => 'VMS-FREEDOM-PASS-123']);

        $incident = Incident::withoutGlobalScopes()
            ->where('scan_token', 'VMS-FREEDOM-PASS-123')
            ->first();

        $this->assertNotNull($incident);
        $this->assertSame(Incident::STATUS_OPEN, $incident->status);

        // 2. Guard investigates
        $this->post("/security/incidents/{$incident->id}/investigate", ['notes' => 'Reviewed camera footage.']);

        $incident->refresh();
        $this->assertSame(Incident::STATUS_INVESTIGATING, $incident->status);
        $this->assertNotNull($incident->auditTrail()->where('action', 'investigate')->first());

        // 3. Security manager resolves
        $manager = User::create([
            'name'       => 'Security Manager',
            'email'      => 'mgr@acme.test',
            'password'   => 'password',
            'role'       => 'security_manager',
            'company_id' => $d['company']->company_id,
            'status'     => 'active',
        ]);
        $this->actingAs($manager);

        $this->post("/security/incidents/{$incident->id}/resolve", [
            'resolution_reason'  => Incident::RESOLUTION_REASON_FALSE_POSITIVE,
            'resolution_notes'   => 'Legitimate visitor with unregistered QR.',
        ]);

        $incident->refresh();
        $this->assertSame(Incident::STATUS_RESOLVED, $incident->status);
        $this->assertNotNull($incident->resolved_at);
        $this->assertSame($manager->id, $incident->resolved_by);
        $this->assertSame(Incident::RESOLUTION_REASON_FALSE_POSITIVE, $incident->resolution_reason);
        $this->assertNotNull($incident->auditTrail()->where('action', 'resolve')->first());
    }

    /** @test */
    public function test_security_guard_can_investigate_but_not_resolve(): void
    {
        $d = $this->seedVisit();
        $this->actingAs($d['guard']);

        $incident = Incident::withoutGlobalScopes()->create([
            'type'         => Incident::TYPE_SECURITY_ALERT,
            'severity'     => Incident::SEVERITY_HIGH,
            'status'       => Incident::STATUS_OPEN,
            'title'        => 'Test Alert',
            'description'  => 'Security alert test',
            'reported_by'  => $d['guard']->id,
            'company_id'   => $d['company']->company_id,
            'location_id'  => $d['location']->id,
        ]);

        // Guard can investigate
        $this->post("/security/incidents/{$incident->id}/investigate", ['notes' => 'Checking.'])
            ->assertOk();
        $incident->refresh();
        $this->assertSame(Incident::STATUS_INVESTIGATING, $incident->status);

        // Guard cannot resolve
        $this->post("/security/incidents/{$incident->id}/resolve", [
            'resolution_reason' => 'closed',
        ])->assertStatus(403);

        $incident->refresh();
        $this->assertSame(Incident::STATUS_INVESTIGATING, $incident->status);
    }

    /** @test */
    public function test_admin_can_resolve_incident(): void
    {
        $d = $this->seedCompanyAndGuard();

        $admin = User::create([
            'name'       => 'Admin User',
            'email'      => 'admin@acme.test',
            'password'   => 'password',
            'role'       => 'admin',
            'company_id' => $d['company']->company_id,
            'status'     => 'active',
        ]);
        $this->actingAs($admin);

        $incident = Incident::withoutGlobalScopes()->create([
            'type'         => Incident::TYPE_BLACKLIST_MATCH,
            'severity'     => Incident::SEVERITY_CRITICAL,
            'status'       => Incident::STATUS_INVESTIGATING,
            'title'        => 'Blacklist match',
            'description'  => 'A visitor was blacklisted.',
            'reported_by'  => $admin->id,
            'company_id'   => $d['company']->company_id,
            'location_id'  => $d['location']->id,
        ]);

        $this->post("/security/incidents/{$incident->id}/resolve", [
            'resolution_reason'  => Incident::RESOLUTION_REASON_CLOSED,
            'resolution_action'  => 'Escalated to legal.',
            'resolution_notes'   => 'Case closed by legal team.',
        ])->assertOk();

        $incident->refresh();
        $this->assertSame(Incident::STATUS_RESOLVED, $incident->status);
        $this->assertSame($admin->id, $incident->resolved_by);
        $this->assertSame('Escalated to legal.', $incident->resolution_action);
    }

    /** @test */
    public function test_incident_data_endpoint_returns_paginated_incidents(): void
    {
        $d = $this->seedVisit();
        $this->actingAs($d['guard']);

        for ($i = 0; $i < 5; $i++) {
            $this->post('/security/scan', ['token' => 'VMS-TEST-' . $i . now()->timestamp]);
        }

        $response = $this->get('/security/incidents/data');
        $response->assertOk();
        $data = $response->json();

        $this->assertArrayHasKey('incidents', $data);
        $this->assertArrayHasKey('pagination', $data);
        $this->assertArrayHasKey('total', $data);
        $this->assertCount(5, $data['incidents']);
        $this->assertSame(5, $data['total']);
    }

    /** @test */
    public function test_incident_counts_endpoint(): void
    {
        $d = $this->seedVisit();
        $this->actingAs($d['guard']);

        $this->post('/security/scan', ['token' => 'VMS-COUNT-A']);
        $this->post('/security/scan', ['token' => 'VMS-COUNT-B']);

        $response = $this->get('/security/incidents/counts');
        $response->assertOk();
        $data = $response->json();

        $this->assertSame(2, $data['total_open']);
        $this->assertSame(2, $data['by_status']['open']);
        $this->assertArrayHasKey('by_severity', $data);
        $this->assertArrayHasKey('by_type', $data);
    }

    /** @test */
    public function test_incident_detail_endpoint_returns_audit_trail(): void
    {
        $d = $this->seedVisit();
        $this->actingAs($d['guard']);

        $this->post('/security/scan', ['token' => 'VMS-DETAIL-123']);

        $incident = Incident::withoutGlobalScopes()
            ->where('scan_token', 'VMS-DETAIL-123')
            ->first();

        $response = $this->get("/security/incidents/{$incident->id}");
        $response->assertOk();
        $data = $response->json();

        $this->assertSame($incident->id, $data['id']);
        $this->assertSame(Incident::TYPE_FAKE_QR, $data['type']);
        $this->assertSame('Fake / Invalid QR Code', $data['type_label']);
        $this->assertIsArray($data['audit_trail']);
        $this->assertGreaterThanOrEqual(1, count($data['audit_trail']));
    }

    /** @test */
    public function test_incident_filters_by_status(): void
    {
        $d = $this->seedVisit();
        $this->actingAs($d['guard']);

        $openIncident = SecurityIncidentService::recordIncident([
            'type'        => Incident::TYPE_SECURITY_ALERT,
            'severity'    => Incident::SEVERITY_LOW,
            'title'       => 'Open incident',
            'description' => 'For filtering',
            'scan_token'  => 'VMS-FILTER-OPEN',
            'company_id'  => $d['company']->company_id,
            'location_id' => $d['location']->id,
            'checkpoint_id' => $d['location']->id,
        ], $d['guard']);

        // Dismiss the incident
        SecurityIncidentService::dismiss($openIncident, 'False alarm', $d['guard']);
        $openIncident->refresh();

        $response = $this->get('/security/incidents/data?status=dismissed');
        $data = $response->json();

        $found = collect($data['incidents'])->firstWhere('id', $openIncident->id);
        $this->assertNotNull($found);
        $this->assertSame(Incident::STATUS_DISMISSED, $found['status']);
    }

    /** @test */
    public function test_property_incident_creates_during_checkout(): void
    {
        $d = $this->seedVisit();
        $this->actingAs($d['guard']);

        // Check in the visitor
        $this->post("/security/visits/{$d['visit']->id}/checkin")->assertSessionHas('success');

        // Register property
        $this->post('/security/register-property', [
            'visit_request_id' => $d['visit']->id,
            'items' => [
                ['item_name' => 'Laptop', 'quantity' => 1, 'serial_number' => 'SN-001', 'property_tag' => 'TAG-001'],
            ],
        ]);

        // Attempt checkout without returning the property
        $this->post("/security/visits/{$d['visit']->id}/checkout", [
            'qr_token' => $d['visit']->qrToken->token_value,
            'returned_items' => [],
        ]);

        $this->assertDatabaseHas('incidents', [
            'type'  => Incident::TYPE_MISSING_PROPERTY,
            'status'=> Incident::STATUS_OPEN,
        ]);
    }

    /** @test */
    public function test_revoked_qr_creates_revoked_incident(): void
    {
        $d = $this->seedVisit();

        // Revoke the QR token
        $d['visit']->qrToken->update(['revoked_at' => now()]);

        $this->actingAs($d['guard']);

        $response = $this->post('/security/scan', ['token' => $d['visit']->qrToken->token_value]);

        $response->assertJson(['success' => false, 'qr_status' => 'revoked']);

        $this->assertDatabaseHas('incidents', [
            'type'      => Incident::TYPE_REVOKED_QR,
            'scan_token'=> $d['visit']->qrToken->token_value,
            'related_visit_id'  => $d['visit']->id,
            'related_visitor_id'=> $d['visit']->visitor_id,
        ]);
    }

    /** @test */
    public function test_used_qr_creates_used_incident(): void
    {
        $d = $this->seedVisit();
        $tokenValue = $d['visit']->qrToken->token_value;

        // Check in the visitor
        $this->actingAs($d['guard']);
        $this->post("/security/visits/{$d['visit']->id}/checkin")->assertSessionHas('success');

        // Check out the visitor — this consumes the QR token
        $this->post("/security/visits/{$d['visit']->id}/checkout", [
            'qr_token' => $tokenValue,
            'returned_items' => [],
        ]);

        // Now scan the used token again → should create a used_qr incident
        $this->post('/security/scan', ['token' => $tokenValue]);

        $this->assertDatabaseHas('incidents', [
            'type'      => Incident::TYPE_USED_QR,
            'scan_token'=> $tokenValue,
            'related_visit_id'  => $d['visit']->id,
            'related_visitor_id'=> $d['visit']->visitor_id,
        ]);
    }

    /** @test */
    public function test_search_filter_works(): void
    {
        $d = $this->seedVisit();
        $this->actingAs($d['guard']);

        SecurityIncidentService::recordIncident([
            'type'        => Incident::TYPE_SECURITY_ALERT,
            'severity'    => Incident::SEVERITY_LOW,
            'title'       => 'Suspicious Package Found',
            'description' => 'A suspicious package was found near reception.',
            'scan_token'  => 'VMS-SEARCH-1',
            'company_id'  => $d['company']->company_id,
            'location_id' => $d['location']->id,
            'checkpoint_id' => $d['location']->id,
        ], $d['guard']);

        $response = $this->get('/security/incidents/data?search=suspicious');
        $data = $response->json();

        $this->assertCount(1, $data['incidents']);
        $this->assertSame('Suspicious Package Found', $data['incidents'][0]['title']);
    }

    /** @test */
    public function test_incident_is_scoped_to_company(): void
    {
        $d1 = $this->seedVisit();

        $company2 = Company::create([
            'company_name' => 'Other Corp',
            'status'       => 'active',
        ]);

        $location2 = Location::create([
            'name'      => 'Side Entrance',
            'company_id'=> $company2->company_id,
            'is_active' => true,
        ]);

        $guard2 = User::create([
            'name'       => 'Other Guard',
            'email'      => 'guard2@other.test',
            'password'   => 'password',
            'role'       => 'security_guard',
            'company_id' => $company2->company_id,
            'location_id'=> $location2->id,
            'status'     => 'active',
        ]);

        Incident::withoutGlobalScopes()->create([
            'type'        => Incident::TYPE_BLACKLIST_MATCH,
            'severity'    => Incident::SEVERITY_CRITICAL,
            'status'      => Incident::STATUS_OPEN,
            'title'       => 'Company 1 incident',
            'description' => 'Should only be visible to company 1',
            'scan_token'  => 'VMS-COMPANY-1',
            'reported_by' => $d1['guard']->id,
            'company_id'  => $d1['company']->company_id,
            'location_id' => $d1['location']->id,
            'checkpoint_id' => $d1['location']->id,
        ]);

        Incident::withoutGlobalScopes()->create([
            'type'        => Incident::TYPE_BLACKLIST_MATCH,
            'severity'    => Incident::SEVERITY_HIGH,
            'status'      => Incident::STATUS_OPEN,
            'title'       => 'Company 2 incident',
            'description' => 'Should only be visible to company 2',
            'scan_token'  => 'VMS-COMPANY-2',
            'reported_by' => $guard2->id,
            'company_id'  => $company2->company_id,
            'location_id' => $location2->id,
            'checkpoint_id' => $location2->id,
        ]);

        // Guard 2 should only see their company's incidents
        $this->actingAs($guard2);
        $response = $this->get('/security/incidents/data');
        $data = $response->json();

        $this->assertCount(1, $data['incidents']);
        $this->assertSame('Company 2 incident', $data['incidents'][0]['title']);
    }

    /** @test */
    public function test_incident_detail_respects_company_scope(): void
    {
        $d1 = $this->seedVisit();
        $d2 = $this->seedCompanyAndGuard('2');

        // Create an incident in company 2
        $incident2 = Incident::withoutGlobalScopes()->create([
            'type'        => Incident::TYPE_SECURITY_ALERT,
            'severity'    => Incident::SEVERITY_LOW,
            'status'      => Incident::STATUS_OPEN,
            'title'       => 'Company 2 secret',
            'description' => 'Should be invisible to company 1',
            'reported_by' => $d2['guard']->id,
            'company_id'  => $d2['company']->company_id,
            'location_id' => $d2['location']->id,
            'checkpoint_id' => $d2['location']->id,
        ]);

        // Guard 1 tries to access company 2's incident → 404
        $this->actingAs($d1['guard']);
        $this->get("/security/incidents/{$incident2->id}")
            ->assertStatus(404);
    }

    /** @test */
    public function test_audit_trail_created_on_record_incident(): void
    {
        $d = $this->seedVisit();
        $this->actingAs($d['guard']);

        $this->post('/security/scan', ['token' => 'VMS-AUDIT-001']);

        $incident = Incident::withoutGlobalScopes()
            ->where('scan_token', 'VMS-AUDIT-001')
            ->first();

        $this->assertDatabaseCount('incident_audit_trail', 1);
        $this->assertDatabaseHas('incident_audit_trail', [
            'incident_id' => $incident->id,
            'action'      => 'incident_created',
            'to_status'   => 'open',
        ]);
    }
}
