<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPrivileges
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string $privilege): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
                'error' => 'User not authenticated'
            ], 401);
        }

        if (!$user->hasPrivilege($privilege)) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
                'error' => 'Insufficient privileges to access this resource'
            ], 403);
        }

        return $next($request);
    }
}