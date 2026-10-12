<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class CompanySeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $ict = Company::firstOrCreate(
            ['company_name' => 'ICT Park'],
            ['status' => 'active']
        );

        $aai = Company::firstOrCreate(
            ['company_name' => 'AAI Institute'],
            ['status' => 'active']
        );

        $users = [
            ['Person A',     'ceo@ict.com',    'ceo',           $ict->company_id],
            ['Person B',     'admin@ict.com',  'admin',         $ict->company_id],
            ['Person C',     'ceo@aai.com',    'ceo',           $aai->company_id],
            ['Person D',     'admin@aai.com',  'admin',         $aai->company_id],
            ['System Admin', 'system@admin.com', 'system_admin', null],
        ];

        foreach ($users as [$name, $email, $role, $companyId]) {
            User::firstOrCreate(
                ['email' => $email],
                [
                    'name'       => $name,
                    'password'   => bcrypt('password'),
                    'role'       => $role,
                    'company_id' => $companyId,
                    'status'     => 'active',
                ]
            );
        }
    }
}
