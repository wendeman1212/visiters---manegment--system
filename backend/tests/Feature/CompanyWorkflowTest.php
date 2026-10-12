<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CompanyWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function createSystemAdmin(): User
    {
        return User::create([
            'name'  => 'Platform Owner',
            'email' => 'system@admin.test',
            'password' => 'password',
            'role'  => 'system_admin',
            'status' => 'active',
        ]);
    }

    public function test_system_admin_can_create_company_with_ceo_and_admin(): void
    {
        $this->actingAs($this->createSystemAdmin());

        $response = $this->post('/admin/companies', [
            'company_name' => 'Acme Corp',
            'industry'     => 'Technology',
            'phone'        => '123-456',
            'email'        => 'info@acme.test',
            'website'      => 'https://acme.test',
            'status'       => 'active',
            'ceo_name'     => 'Jane Doe',
            'ceo_email'    => 'ceo@acme.test',
            'admin_name'   => 'Bob Smith',
            'admin_email'  => 'admin@acme.test',
        ]);

        $response->assertRedirect(route('admin.companies.index'));

        $company = Company::where('company_name', 'Acme Corp')->first();
        $this->assertNotNull($company);
        $this->assertSame('active', $company->status);

        $ceo = User::where('email', 'ceo@acme.test')->first();
        $admin = User::where('email', 'admin@acme.test')->first();

        $this->assertNotNull($ceo);
        $this->assertSame('ceo', $ceo->role);
        $this->assertSame($company->company_id, $ceo->company_id);
        $this->assertSame('active', $ceo->status);

        $this->assertNotNull($admin);
        $this->assertSame('admin', $admin->role);
        $this->assertSame($company->company_id, $admin->company_id);
        $this->assertSame('active', $admin->status);

        $this->assertDatabaseHas('company_user', [
            'company_id' => $company->company_id,
            'user_id'    => $ceo->id,
            'role_name'  => 'ceo',
            'is_active'  => true,
        ]);
        $this->assertDatabaseHas('company_user', [
            'company_id' => $company->company_id,
            'user_id'    => $admin->id,
            'role_name'  => 'admin',
            'is_active'  => true,
        ]);
    }

    public function test_assign_creates_or_reassigns_company_admin(): void
    {
        $this->actingAs($this->createSystemAdmin());

        $company = Company::create([
            'company_name' => 'Globex',
            'status'       => 'active',
        ]);

        $response = $this->post("/admin/companies/{$company->company_id}/assign", [
            'role'  => 'admin',
            'name'  => 'Carol Adams',
            'email' => 'carol@globex.test',
        ]);

        $response->assertRedirect(route('admin.companies.index'));

        $admin = User::where('email', 'carol@globex.test')->first();
        $this->assertNotNull($admin);
        $this->assertSame('admin', $admin->role);
        $this->assertSame($company->company_id, $admin->company_id);

        $this->assertDatabaseHas('company_user', [
            'company_id' => $company->company_id,
            'user_id'    => $admin->id,
            'role_name'  => 'admin',
        ]);

        $company->refresh();
        $assigned = $company->users()->where('email', 'carol@globex.test')->first();
        $this->assertNotNull($assigned);
        $this->assertSame('admin', $assigned->role);
    }

    public function test_toggle_status_deactivates_and_reactivates_company(): void
    {
        $this->actingAs($this->createSystemAdmin());

        $company = Company::create([
            'company_name' => 'Initech',
            'status'       => 'active',
        ]);

        $this->post("/admin/companies/{$company->company_id}/status")
            ->assertRedirect(route('admin.companies.index'));

        $this->assertSame('inactive', $company->fresh()->status);

        $this->post("/admin/companies/{$company->company_id}/status")
            ->assertRedirect(route('admin.companies.index'));

        $this->assertSame('active', $company->fresh()->status);
    }

    public function test_deactivated_company_blocks_staff_login(): void
    {
        $company = Company::create([
            'company_name' => 'Umbrella',
            'status'       => 'inactive',
        ]);

        $ceo = User::create([
            'name'       => 'Alice',
            'email'      => 'alice@umbrella.test',
            'password'   => 'password',
            'role'       => 'ceo',
            'company_id' => $company->company_id,
            'status'     => 'active',
        ]);

        $response = $this->post('/login', [
            'email'    => $ceo->email,
            'password' => 'password',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_active_company_staff_can_login(): void
    {
        $company = Company::create([
            'company_name' => 'Stark Industries',
            'status'       => 'active',
        ]);

        $ceo = User::create([
            'name'       => 'Pepper',
            'email'      => 'pepper@stark.test',
            'password'   => 'password',
            'role'       => 'ceo',
            'company_id' => $company->company_id,
            'status'     => 'active',
        ]);

        $response = $this->post('/login', [
            'email'    => $ceo->email,
            'password' => 'password',
        ]);

        $response->assertRedirect('/ceo/dashboard');
        $this->assertAuthenticated();
    }

    public function test_company_admin_can_manage_companies(): void
    {
        $company = Company::create([
            'company_name' => 'Hooli',
            'status'       => 'active',
        ]);

        $admin = User::create([
            'name'       => 'Gavin',
            'email'      => 'gavin@hooli.test',
            'password'   => 'password',
            'role'       => 'admin',
            'company_id' => $company->company_id,
            'status'     => 'active',
        ]);

        $this->actingAs($admin);

        $this->get('/admin/companies')->assertOk();
        $this->get('/admin/companies/create')->assertOk();
        $this->post('/admin/companies', [
            'company_name' => 'Nope',
            'status'       => 'active',
        ])->assertRedirect(route('admin.companies.index'));
        $this->post("/admin/companies/{$company->company_id}/status")->assertRedirect(route('admin.companies.index'));
    }

    public function test_index_lists_companies_with_ceo_and_admin_details(): void
    {
        $this->actingAs($this->createSystemAdmin());

        $company = Company::create([
            'company_name' => 'Wayne Enterprises',
            'status'       => 'active',
        ]);

        User::create([
            'name'       => 'Bruce',
            'email'      => 'bruce@wayne.test',
            'password'   => 'password',
            'role'       => 'ceo',
            'company_id' => $company->company_id,
            'status'     => 'active',
        ]);

        $response = $this->get('/admin/companies');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Companies/Index')
            ->has('companies', 2)
            ->where('companies.0.company_name', 'Default Organization')
            ->where('companies.1.company_name', 'Wayne Enterprises')
            ->where('companies.1.ceo_name', 'Bruce')
            ->where('companies.1.ceo_email', 'bruce@wayne.test')
        );
    }

    public function test_company_data_retained_after_deactivation(): void
    {
        $company = Company::create([
            'company_name' => 'Cyberdyne',
            'status'       => 'active',
        ]);

        $ceo = User::create([
            'name'       => 'Sarah',
            'email'      => 'sarah@cyberdyne.test',
            'password'   => 'password',
            'role'       => 'ceo',
            'company_id' => $company->company_id,
            'status'     => 'active',
        ]);

        DB::table('company_user')->insert([
            'company_id' => $company->company_id,
            'user_id'    => $ceo->id,
            'role_name'  => 'ceo',
            'is_active'  => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $company->update(['status' => 'inactive']);

        $this->assertDatabaseHas('companies', ['company_id' => $company->company_id, 'status' => 'inactive']);
        $this->assertDatabaseHas('users', ['id' => $ceo->id, 'company_id' => $company->company_id]);
        $this->assertDatabaseHas('company_user', [
            'company_id' => $company->company_id,
            'user_id'    => $ceo->id,
        ]);
    }
}
