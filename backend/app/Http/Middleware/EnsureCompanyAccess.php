<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureCompanyAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('web')->user();

        if (!$user || $user->hasRole('system_admin') || !$user->company_id) {
            return $next($request);
        }

        $route = $request->route();

        if (!$route) {
            return $next($request);
        }

        foreach ($route->parameters() as $param) {
            if ($param instanceof Model && method_exists($param, 'getCompanyId')) {
                if ($param->getCompanyId() != $user->company_id) {
                    abort(403, 'Unauthorized company access.');
                }
            }
        }

        return $next($request);
    }
}
