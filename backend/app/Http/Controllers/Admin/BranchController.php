<?php

namespace App\Http\Controllers\Admin;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Location;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class BranchController extends Controller
{
    public function index()
    {
        $branches = Branch::with('location', 'company')->get();
        return inertia('Admin/Branches/Index', ['branches' => $branches]);
    }

    public function create()
    {
        $locations = Location::all();
        $companies = Company::orderBy('company_name')->get();
        return inertia('Admin/Branches/Create', ['locations' => $locations, 'companies' => $companies]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'location_id' => 'nullable|exists:locations,id',
            'name' => 'required|string|max:255|unique:branches,name',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
            'company_id' => 'nullable|exists:companies,company_id',
        ]);

        $admin = Auth::guard('web')->user();
        $companyId = $admin->hasRole('system_admin')
            ? ($request->company_id ?? Location::find($request->location_id)?->company_id)
            : $admin->company_id;

        $branch = Branch::create([
            'company_id' => $companyId,
            'location_id' => $request->location_id,
            'name' => $request->name,
            'slug' => Str::slug($request->name),
            'description' => $request->description,
            'is_active' => $request->is_active ?? true,
        ]);

        AuditLogger::log('branch_created', 'branches', $branch->id, null, $branch->toArray());

        return redirect()->route('admin.branches.index')->with('success', 'Branch created.');
    }

    public function edit(Branch $branch)
    {
        $locations = Location::all();
        $companies = Company::orderBy('company_name')->get();
        return inertia('Admin/Branches/Edit', ['branch' => $branch, 'locations' => $locations, 'companies' => $companies]);
    }

    public function update(Request $request, Branch $branch)
    {
        $request->validate([
            'location_id' => 'nullable|exists:locations,id',
            'name' => 'required|string|max:255|unique:branches,name,' . $branch->id,
            'description' => 'nullable|string',
            'is_active' => 'boolean',
            'company_id' => 'nullable|exists:companies,company_id',
        ]);

        $admin = Auth::guard('web')->user();
        $data = [
            'location_id' => $request->location_id,
            'name' => $request->name,
            'slug' => Str::slug($request->name),
            'description' => $request->description,
            'is_active' => $request->is_active,
        ];
        if ($admin->hasRole('system_admin')) {
            $data['company_id'] = $request->company_id ?? $branch->company_id;
        }

        $before = $branch->toArray();
        $branch->update($data);

        AuditLogger::log('branch_updated', 'branches', $branch->id, $before, $branch->toArray());

        return redirect()->route('admin.branches.index')->with('success', 'Branch updated.');
    }

    public function destroy(Branch $branch)
    {
        AuditLogger::log('branch_deleted', 'branches', $branch->id, $branch->toArray(), null);
        $branch->delete();
        return redirect()->route('admin.branches.index')->with('success', 'Branch deleted.');
    }

    /**
     * Toggle a branch between Active and Inactive.
     */
    public function toggleStatus(Branch $branch)
    {
        $before = $branch->toArray();
        $branch->is_active = !$branch->is_active;
        $branch->save();

        AuditLogger::log('branch_status_toggled', 'branches', $branch->id, $before, $branch->toArray());

        return back()->with('success', 'Branch status updated.');
    }
}
