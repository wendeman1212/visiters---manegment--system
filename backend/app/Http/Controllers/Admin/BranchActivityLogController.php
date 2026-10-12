<?php

namespace App\Http\Controllers\Admin;

use App\Models\AuditLog;
use App\Models\Branch;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class BranchActivityLogController extends Controller
{
    public function index(Request $request, Branch $branch)
    {
        if (!auth()->user()->isAdmin() && auth()->user()->branch_id != $branch->id) {
            abort(403);
        }

        $query = AuditLog::where('branch_id', $branch->id)->with('actor');

        if ($request->filled('user_id')) {
            $query->where('actor_id', $request->user_id);
        }
        if ($request->filled('action')) {
            $query->where('action', $request->action);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $logs = $query->orderBy('created_at', 'desc')->paginate(20);

        return inertia('Admin/Branches/ActivityLog', [
            'branch' => $branch,
            'logs' => $logs,
            'filters' => $request->all(),
        ]);
    }
}
