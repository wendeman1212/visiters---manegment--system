<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\SystemIntegration;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 0. Seed Companies first so all records below can be company-scoped
        $this->call(CompanySeeder::class);
        $company = \App\Models\Company::firstOrCreate(
            ['company_name' => 'Default Organization'],
            ['status' => 'active']
        );

        // 1. Create Departments
        $depts = [
            ['name' => 'Executive Office',        'capacity_limit' => 10],
            ['name' => 'Human Resources',          'capacity_limit' => 30],
            ['name' => 'Information Technology',   'capacity_limit' => 40],
            ['name' => 'Finance & Accounting',     'capacity_limit' => 20],
            ['name' => 'Security & Operations',    'capacity_limit' => 15],
        ];

        $departmentIds = [];
        foreach ($depts as $dept) {
            $created = \App\Models\Department::create($dept + ['company_id' => $company->company_id]);
            $departmentIds[$dept['name']] = $created->id;
        }

        // 2. Create CEO
        $ceo = User::create([
            'name'                    => 'CEO User',
            'email'                   => 'ceo@example.com',
            'phone'                   => '+1234567890',
            'role'                    => 'ceo',
            'status'                  => 'active',
            'department_id'           => $departmentIds['Executive Office'],
            'password'                => bcrypt('password'),
            'two_factor_confirmed_at' => now(),
            'company_id'              => $company->company_id,
        ]);

        // 3. Create Admin
        $admin = User::create([
            'name'                    => 'Admin User',
            'email'                   => 'admin@example.com',
            'phone'                   => '+1234567891',
            'role'                    => 'admin',
            'status'                  => 'active',
            'approved_by'             => $ceo->id,
            'department_id'           => $departmentIds['Human Resources'],
            'password'                => bcrypt('password'),
            'two_factor_confirmed_at' => now(),
            'company_id'              => $company->company_id,
        ]);

        // 4. Create Host (Employee) — also handles Security Guard duties
        $host = User::create([
            'name'          => 'David IT Host',
            'email'         => 'host@example.com',
            'phone'         => '+1234567894',
            'role'          => 'host',
            'status'        => 'active',
            'approved_by'   => $admin->id,
            'department_id' => $departmentIds['Information Technology'],
            'password'      => bcrypt('password'),
            'company_id'    => $company->company_id,
        ]);

        // 4b. Create Security Guard
        $securityGuard = User::create([
            'name'          => 'Security Guard',
            'email'         => 'security@example.com',
            'phone'         => '+1234567895',
            'role'          => 'security_guard',
            'status'        => 'active',
            'approved_by'   => $admin->id,
            'department_id' => $departmentIds['Security & Operations'],
            'password'      => bcrypt('password'),
            'company_id'    => $company->company_id,
        ]);

        // 4c. Create Second Host
        $host2 = User::create([
            'name'          => 'Sara HR Host',
            'email'         => 'host2@example.com',
            'phone'         => '+1234567896',
            'role'          => 'host',
            'status'        => 'active',
            'approved_by'   => $admin->id,
            'department_id' => $departmentIds['Human Resources'],
            'password'      => bcrypt('password'),
            'company_id'    => $company->company_id,
        ]);

        // 5. Create Test Visitor
        $visitor = \App\Models\Visitor::create([
            'name'           => 'Alice Visitor',
            'email'          => 'visitor@example.com',
            'phone'          => '+1999888777',
            'id_document_no' => 'PASS123456',
            'password'       => bcrypt('password'),
            'company_id'     => $company->company_id,
        ]);

        // 6. Create Blacklist Entry
        \App\Models\Blacklist::create([
            'name'           => 'Banned Person',
            'id_document_no' => 'BANNED123',
            'reason'         => 'Previous security violation and package tampering.',
            'added_by'       => $admin->id,
        ]);

        // 7. Ensure Email & SMS integrations are active
        SystemIntegration::updateOrCreate(
            ['type' => 'email'],
            ['name' => 'Email (SMTP)', 'status' => 'active', 'config' => json_encode(['provider' => 'smtp']), 'last_checked_at' => now()]
        );
        SystemIntegration::updateOrCreate(
            ['type' => 'sms'],
            ['name' => 'SMS Gateway', 'status' => 'active', 'config' => json_encode(['provider' => 'webhook', 'webhook_url' => '']), 'last_checked_at' => now()]
        );
    }
}
