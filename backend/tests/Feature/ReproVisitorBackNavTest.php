<?php

namespace Tests\Feature;

use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReproVisitorBackNavTest extends TestCase
{
    use RefreshDatabase;

    public function test_visitor_login_to_page_and_back_to_dashboard(): void
    {
        $visitor = Visitor::create([
            'name'           => 'Jane Visitor',
            'first_name'     => 'Jane',
            'last_name'      => 'Visitor',
            'email'          => 'jane@example.com',
            'phone'          => '0991112223',
            'id_document_no' => '123456',
            'age'            => 30,
            'sex'            => 'female',
            'password'       => 'password',
        ]);

        // Visitor Login
        $this->post('/login', ['email' => $visitor->email, 'password' => 'password'])
            ->assertRedirect('/visitor/dashboard');
        $this->assertAuthenticated('visitor');

        // Visitor Page (a section of the Visitor Dashboard)
        $this->get('/visitor/section/profile')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Dashboard/Visitor'));

        // Back to Dashboard — must return the correct Visitor Dashboard (200, not 403)
        $this->get('/visitor/dashboard')
            ->assertOk()
            ->assertDontSee('Forbidden')
            ->assertInertia(fn ($page) => $page->component('Dashboard/Visitor'));
    }
}
