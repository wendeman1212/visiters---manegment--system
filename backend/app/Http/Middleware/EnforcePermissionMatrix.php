<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnforcePermissionMatrix
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  $module The module being accessed (e.g. users, departments, approvals, blacklist, reports)
     * @param  string  $action The action type (e.g. view, full, override, scan, request)
     */
    public function handle(Request $request, Closure $next, string $module, string $action = 'view'): Response
    {
        $role = null;

        if (Auth::guard('web')->check()) {
            $role = Auth::guard('web')->user()->role;
        } elseif (Auth::guard('visitor')->check()) {
            $role = 'visitor';
        }

        if (!$role) {
            return response()->json([
                'code' => 'UNAUTHENTICATED',
                'message' => 'Unauthenticated access to module: ' . $module
            ], 401);
        }

        $matrix = [
            'dashboard' => [
                'system_admin' => ['view', 'full'],
                'ceo'     => ['view', 'full'],
                'admin'   => ['view', 'full'],
                'host'    => ['view', 'full'],
                'security_guard' => ['view', 'full'],
                'visitor' => ['view', 'full'],
            ],
            'users' => [
                'system_admin' => ['view', 'full'],
                'ceo'     => ['view'],
                'admin'   => ['view', 'full'],
                'host'    => [],
                'security_guard' => [],
                'visitor' => [],
            ],
            'departments' => [
                'system_admin' => ['view', 'full'],
                'ceo'     => ['view'],
                'admin'   => ['view', 'full'],
                'host'    => [],
                'security_guard' => [],
                'visitor' => [],
            ],
            'visit_approval' => [
                'system_admin' => ['view', 'override'],
                'ceo'     => [],
                'admin'   => ['override', 'escalation', 'view'],
                'host'    => ['approve', 'scan', 'view'],
                'security_guard' => ['scan', 'view'],
                'visitor' => ['request', 'view'],
            ],
            'qr_scan' => [
                'system_admin' => ['view'],
                'ceo'     => [],
                'admin'   => ['view'],
                'host'    => ['scan', 'view'],
                'security_guard' => ['scan', 'view'],
                'visitor' => [],
            ],
            'blacklist' => [
                'system_admin' => ['view', 'full'],
                'ceo'     => ['view'],
                'admin'   => ['view', 'full'],
                'host'    => ['view'],
                'security_guard' => ['view'],
                'visitor' => [],
            ],
            'reports' => [
                'system_admin' => ['enterprise-wide', 'view'],
                'ceo'     => ['enterprise-wide', 'view'],
                'admin'   => ['enterprise-wide', 'view'],
                'host'    => ['own-visits', 'own-shift', 'view'],
                'security_guard' => ['own-shift', 'view'],
                'visitor' => ['own-history', 'view'],
            ],
            'security' => [
                'system_admin' => ['view'],
                'ceo'     => ['view'],
                'admin'   => ['view', 'dashboard'],
                'security_manager' => ['view', 'dashboard'],
                'host'    => [],
                'security_guard' => ['view', 'dashboard', 'full'],
                'visitor' => [],
            ],
            'companies' => [
                'system_admin' => ['view', 'full'],
                'ceo'     => [],
                'admin'   => ['view', 'full'],
                'host'    => [],
                'security_guard' => [],
                'visitor' => [],
            ],
        ];

        // Check if role is allowed to perform action in this module
        if (!isset($matrix[$module][$role]) || !in_array($action, $matrix[$module][$role])) {
            // Return appropriate response depending on request format
            if ($request->expectsJson() || $request->wantsJson()) {
                return response()->json([
                    'code' => 'PERMISSION_DENIED',
                    'message' => "Your role '{$role}' is not authorized to perform '{$action}' on module '{$module}'."
                ], 403);
            }
            
            // Redirect back with an error or abort
            abort(403, "Unauthorized access.");
        }

        return $next($request);
    }
}
