<?php

namespace App\Http\Controllers\Admin;

use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Models\Location;
use App\Models\Department;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function index()
    {
        $users = User::with(['department', 'branch', 'location'])->get();
        return inertia('Admin/Users/Index', ['users' => $users]);
    }

    public function create()
    {
        $branches = Branch::where('is_active', true)->get();
        $locations = Location::all();
        $departments = Department::all();
        $companies = Company::where('status', 'active')->get();
        $roles = ['admin', 'manager', 'host', 'security_guard', 'ceo'];
        return inertia('Admin/Users/Create', compact('branches', 'locations', 'departments', 'companies', 'roles'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users',
            'password' => 'required|min:8',
            'company_id' => 'nullable|exists:companies,company_id',
            'location_id' => 'nullable|exists:locations,id',
            'branch_id' => 'nullable|exists:branches,id',
            'department_id' => 'nullable|exists:departments,id',
            'role' => 'required|in:admin,manager,host,security_guard,ceo',
        ]);

        User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'company_id' => $validated['company_id'],
            'location_id' => $validated['location_id'],
            'branch_id' => $validated['branch_id'],
            'department_id' => $validated['department_id'],
            'role' => $validated['role'],
        ]);

        return redirect()->route('admin.users.index')->with('success', 'User created.');
    }

    public function edit(User $user)
    {
        $branches = Branch::where('is_active', true)->get();
        $locations = Location::all();
        $departments = Department::all();
        $companies = Company::where('status', 'active')->get();
        $roles = ['admin', 'manager', 'host', 'security_guard', 'ceo'];
        return inertia('Admin/Users/Edit', compact('user', 'branches', 'locations', 'departments', 'companies', 'roles'));
    }

    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,' . $user->id,
            'password' => 'nullable|min:8',
            'company_id' => 'nullable|exists:companies,company_id',
            'location_id' => 'nullable|exists:locations,id',
            'branch_id' => 'nullable|exists:branches,id',
            'department_id' => 'nullable|exists:departments,id',
            'role' => 'required|in:admin,manager,host,security_guard,ceo',
        ]);

        $data = [
            'name' => $validated['name'],
            'email' => $validated['email'],
            'company_id' => $validated['company_id'],
            'location_id' => $validated['location_id'],
            'branch_id' => $validated['branch_id'],
            'department_id' => $validated['department_id'],
            'role' => $validated['role'],
        ];

        if ($validated['password']) {
            $data['password'] = Hash::make($validated['password']);
        }

        $user->update($data);

        return redirect()->route('admin.users.index')->with('success', 'User updated.');
    }

    public function destroy(User $user)
    {
        $user->delete();
        return redirect()->route('admin.users.index')->with('success', 'User deleted.');
    }
}
