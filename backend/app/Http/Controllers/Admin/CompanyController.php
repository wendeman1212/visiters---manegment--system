<?php

namespace App\Http\Controllers\Admin;

use App\Models\Company;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CompanyController extends Controller
{
    public function index()
    {
        $companies = Company::withCount(['users', 'departments', 'visitRequests'])
            ->get()
            ->map(function ($company) {
                $ceo   = $company->users()->where('role', 'ceo')->where('status', 'active')->first();
                $admin = $company->users()->where('role', 'admin')->where('status', 'active')->first();

                return [
                    'company_id'    => $company->company_id,
                    'company_name'  => $company->company_name,
                    'industry'      => $company->industry,
                    'phone'         => $company->phone,
                    'email'         => $company->email,
                    'website'       => $company->website,
                    'status'        => $company->status,
                    'users_count'   => $company->users_count,
                    'departments_count' => $company->departments_count,
                    'visit_requests_count' => $company->visit_requests_count,
                    'ceo_name'      => $ceo?->name,
                    'ceo_email'     => $ceo?->email,
                    'admin_name'    => $admin?->name,
                    'admin_email'   => $admin?->email,
                ];
            });

        return inertia('Admin/Companies/Index', ['companies' => $companies]);
    }

    public function create()
    {
        return inertia('Admin/Companies/Create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'company_name' => 'required|string|max:200|unique:companies,company_name',
            'industry'     => 'nullable|string|max:150',
            'phone'        => 'nullable|string|max:30',
            'email'        => 'nullable|email|max:150',
            'website'      => 'nullable|url|max:255',
            'status'       => 'required|in:active,inactive',
            'ceo_name'     => 'nullable|string|max:255',
            'ceo_email'    => 'nullable|email|max:255',
            'admin_name'   => 'nullable|string|max:255',
            'admin_email'  => 'nullable|email|max:255',
        ]);

        $company = Company::create([
            'company_name' => $validated['company_name'],
            'industry'     => $validated['industry'] ?? null,
            'phone'        => $validated['phone'] ?? null,
            'email'        => $validated['email'] ?? null,
            'website'      => $validated['website'] ?? null,
            'status'       => $validated['status'],
        ]);

        AuditLogger::log('company_create', 'companies', $company->company_id, null, [
            'company_name' => $company->company_name,
            'status'       => $company->status,
        ], $company->company_id);

        if (!empty($validated['ceo_name']) && !empty($validated['ceo_email'])) {
            $this->assignUser($company, 'ceo', $validated['ceo_name'], $validated['ceo_email']);
        }

        if (!empty($validated['admin_name']) && !empty($validated['admin_email'])) {
            $this->assignUser($company, 'admin', $validated['admin_name'], $validated['admin_email']);
        }

        return redirect()->route('admin.companies.index')->with('success', 'Company created.');
    }

    public function edit(Company $company)
    {
        $ceo   = $company->users()->where('role', 'ceo')->where('status', 'active')->first();
        $admin = $company->users()->where('role', 'admin')->where('status', 'active')->first();

        return inertia('Admin/Companies/Edit', [
            'company' => $company,
            'ceo'     => $ceo ? ['name' => $ceo->name, 'email' => $ceo->email] : null,
            'admin'   => $admin ? ['name' => $admin->name, 'email' => $admin->email] : null,
        ]);
    }

    public function update(Request $request, Company $company)
    {
        $validated = $request->validate([
            'company_name' => 'required|string|max:200|unique:companies,company_name,' . $company->company_id . ',company_id',
            'industry'     => 'nullable|string|max:150',
            'phone'        => 'nullable|string|max:30',
            'email'        => 'nullable|email|max:150',
            'website'      => 'nullable|url|max:255',
            'status'       => 'required|in:active,inactive',
            'ceo_name'     => 'nullable|string|max:255',
            'ceo_email'    => 'nullable|email|max:255',
            'admin_name'   => 'nullable|string|max:255',
            'admin_email'  => 'nullable|email|max:255',
        ]);

        $before = $company->toArray();
        $company->update([
            'company_name' => $validated['company_name'],
            'industry'     => $validated['industry'] ?? null,
            'phone'        => $validated['phone'] ?? null,
            'email'        => $validated['email'] ?? null,
            'website'      => $validated['website'] ?? null,
            'status'       => $validated['status'],
        ]);

        AuditLogger::log('company_update', 'companies', $company->company_id, $before, $company->toArray(), $company->company_id);

        if (!empty($validated['ceo_name']) && !empty($validated['ceo_email'])) {
            $this->assignUser($company, 'ceo', $validated['ceo_name'], $validated['ceo_email']);
        }

        if (!empty($validated['admin_name']) && !empty($validated['admin_email'])) {
            $this->assignUser($company, 'admin', $validated['admin_name'], $validated['admin_email']);
        }

        return redirect()->route('admin.companies.index')->with('success', 'Company updated.');
    }

    public function destroy(Company $company)
    {
        AuditLogger::log('company_delete', 'companies', $company->company_id, $company->toArray(), null, $company->company_id);

        $company->delete();

        return redirect()->route('admin.companies.index')->with('success', 'Company deleted.');
    }

    /**
     * Assign (or replace) a CEO / Company Admin for a company.
     * Creates the account if the email is not already registered.
     */
    public function assign(Request $request, Company $company)
    {
        $validated = $request->validate([
            'role'  => 'required|in:ceo,admin',
            'name'  => 'required|string|max:255',
            'email' => 'required|email|max:255',
        ]);

        $user = $this->assignUser($company, $validated['role'], $validated['name'], $validated['email']);

        return redirect()->route('admin.companies.index')->with('success', ucfirst($validated['role']) . ' assigned to ' . $company->company_name . '.');
    }

    /**
     * Activate / deactivate a company. Deactivated companies reject logins for their staff.
     */
    public function toggleStatus(Company $company)
    {
        $company->status = $company->status === 'active' ? 'inactive' : 'active';
        $company->save();

        AuditLogger::log('company_status_update', 'companies', $company->company_id, null, ['status' => $company->status], $company->company_id);

        return redirect()->route('admin.companies.index')->with('success', 'Company ' . ($company->status === 'active' ? 'activated' : 'deactivated') . '.');
    }

    /**
     * Shared logic: find-or-create the CEO/Admin user, scope them to the company
     * and record the assignment in the company_user pivot.
     */
    private function assignUser(Company $company, string $role, string $name, string $email): User
    {
        $user = User::withoutGlobalScopes()->where('email', $email)->first();

        if ($user) {
            $user->update([
                'role'       => $role,
                'company_id' => $company->company_id,
                'status'     => $user->status === 'pending' ? 'active' : $user->status,
            ]);
        } else {
            $user = User::create([
                'name'       => $name,
                'email'      => $email,
                'password'   => bcrypt('password'),
                'role'       => $role,
                'company_id' => $company->company_id,
                'status'     => 'active',
            ]);
        }

        DB::table('company_user')->updateOrInsert(
            ['company_id' => $company->company_id, 'user_id' => $user->id],
            [
                'role_name'  => $role,
                'is_active'  => true,
                'updated_at' => now(),
            ]
        );

        AuditLogger::log("company_{$role}_assign", 'users', $user->id, null, [
            'role'         => $role,
            'company_id'   => $company->company_id,
            'company_name' => $company->company_name,
        ], $company->company_id);

        return $user;
    }
}
