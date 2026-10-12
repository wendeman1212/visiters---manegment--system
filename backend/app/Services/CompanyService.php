<?php

namespace App\Services;

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class CompanyService
{
    public function createCompany(array $data): Company
    {
        return DB::transaction(function () use ($data) {
            $isActive = (bool) ($data['is_active'] ?? true);

            $company = Company::create([
                'company_name' => $data['company_name'],
                'code'         => $data['code'] ?? null,
                'industry'     => $data['industry'] ?? null,
                'description'  => $data['description'] ?? null,
                'phone'        => $data['phone'] ?? null,
                'email'        => $data['email'] ?? null,
                'website'      => $data['website'] ?? null,
                'is_active'    => $isActive,
                'status'       => $isActive ? 'active' : 'inactive',
            ]);

            AuditLogger::log('company_created', 'companies', $company->company_id, null, $company->toArray(), $company->company_id);

            if (!empty($data['ceo_id'])) {
                $this->assignRole($company, (int) $data['ceo_id'], 'ceo');
            } elseif (!empty($data['ceo_email'])) {
                $userId = $this->createRoleUser($company, $data, 'ceo');
                $this->assignRole($company, $userId, 'ceo');
            }
            if (!empty($data['admin_id'])) {
                $this->assignRole($company, (int) $data['admin_id'], 'admin');
            } elseif (!empty($data['admin_email'])) {
                $userId = $this->createRoleUser($company, $data, 'admin');
                $this->assignRole($company, $userId, 'admin');
            }

            return $company;
        });
    }

    private function createRoleUser(Company $company, array $data, string $role): int
    {
        $prefix = $role === 'ceo' ? 'ceo' : 'admin';
        $user = User::create([
            'name'        => trim(($data["{$prefix}_first_name"] ?? '') . ' ' . ($data["{$prefix}_last_name"] ?? '')),
            'first_name'  => $data["{$prefix}_first_name"] ?? null,
            'last_name'   => $data["{$prefix}_last_name"] ?? null,
            'email'       => $data["{$prefix}_email"],
            'phone'       => $data["{$prefix}_phone"] ?? null,
            'sex'         => $data["{$prefix}_gender"] ?? null,
            'age'         => $data["{$prefix}_age"] ?? null,
            'national_id' => $data["{$prefix}_national_id"] ?? null,
            'position'    => $data["{$prefix}_position"] ?? null,
            'password'    => Hash::make($data["{$prefix}_password"]),
            'role'        => $role,
            'company_id'  => $company->company_id,
            'status'      => 'active',
        ]);

        return $user->id;
    }

    public function updateCompany(Company $company, array $data): Company
    {
        $before   = $company->toArray();
        $isActive = (bool) ($data['is_active'] ?? $company->is_active);

        $company->update([
            'company_name' => $data['company_name'],
            'code'         => $data['code'] ?? null,
            'industry'     => $data['industry'] ?? null,
            'description'  => $data['description'] ?? null,
            'phone'        => $data['phone'] ?? null,
            'email'        => $data['email'] ?? null,
            'website'      => $data['website'] ?? null,
            'is_active'    => $isActive,
            'status'       => $isActive ? 'active' : 'inactive',
        ]);

        AuditLogger::log('company_updated', 'companies', $company->company_id, $before, $company->toArray(), $company->company_id);

        if (array_key_exists('ceo_id', $data)) {
            $this->syncRole($company, $data['ceo_id'] ? (int) $data['ceo_id'] : null, 'ceo');
        } elseif (!empty($data['ceo_email'])) {
            $userId = $this->createRoleUser($company, $data, 'ceo');
            $this->assignRole($company, $userId, 'ceo');
        }
        if (array_key_exists('admin_id', $data)) {
            $this->syncRole($company, $data['admin_id'] ? (int) $data['admin_id'] : null, 'admin');
        } elseif (!empty($data['admin_email'])) {
            $userId = $this->createRoleUser($company, $data, 'admin');
            $this->assignRole($company, $userId, 'admin');
        }

        return $company;
    }

    /**
     * Assign (or replace) a CEO / Company Admin for a company.
     * Sets the user's primary role and company, and records the pivot row.
     */
    public function assignRole(Company $company, int $userId, string $roleName): void
    {
        $user = User::withoutGlobalScopes()->findOrFail($userId);

        $user->update([
            'role'       => $roleName,
            'company_id' => $company->company_id,
            'status'     => $user->status === 'pending' ? 'active' : $user->status,
        ]);

        DB::table('company_user')->where('company_id', $company->company_id)
            ->where('role_name', $roleName)
            ->delete();

        DB::table('company_user')->updateOrInsert(
            ['company_id' => $company->company_id, 'user_id' => $user->id],
            [
                'role_name'  => $roleName,
                'role_id'    => null,
                'is_active'  => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        AuditLogger::log("company_{$roleName}_assigned", 'users', $user->id, null, [
            'role'         => $roleName,
            'company_id'   => $company->company_id,
            'company_name' => $company->company_name,
        ], $company->company_id);
    }

    /**
     * Replace the user holding a role for a company (null = unassign).
     */
    public function syncRole(Company $company, ?int $userId, string $roleName): void
    {
        DB::table('company_user')->where('company_id', $company->company_id)
            ->where('role_name', $roleName)
            ->delete();

        if ($userId) {
            $this->assignRole($company, $userId, $roleName);
        }
    }

    public function setActive(Company $company, bool $isActive): void
    {
        $company->update([
            'is_active' => $isActive,
            'status'    => $isActive ? 'active' : 'inactive',
        ]);

        AuditLogger::log('company_status_update', 'companies', $company->company_id, null, ['status' => $company->status], $company->company_id);
    }

    public function deleteCompany(Company $company): void
    {
        AuditLogger::log('company_deleted', 'companies', $company->company_id, $company->toArray(), null, $company->company_id);
        $company->delete();
    }
}
