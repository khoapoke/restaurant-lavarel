<?php

namespace App\Http\Middleware;

use App\Enums\RoleType;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    /**
     * Handle an incoming request.
     * Check if authenticated account has the specified role(s).
     *
     * @param  \Closure(Request): (Response)  $next
     * @param  string  ...$roles
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'code' => 401,
                'message' => 'Unauthenticated or token is missing/invalid',
                'errors' => null,
            ], 401);
        }

        // Map role name string to role ID
        $allowedRoleIds = [];
        foreach ($roles as $role) {
            $upper = strtoupper(trim($role));
            if ($upper === 'ADMIN' || $upper === RoleType::ADMIN->value) {
                $allowedRoleIds[] = 1;
            } elseif ($upper === 'STAFF' || $upper === RoleType::STAFF->value) {
                $allowedRoleIds[] = 2;
            } elseif ($upper === 'CUSTOMER' || $upper === RoleType::CUSTOMER->value) {
                $allowedRoleIds[] = 3;
            } elseif (is_numeric($role)) {
                $allowedRoleIds[] = (int) $role;
            }
        }

        if (!in_array((int) $user->ma_vai_tro, $allowedRoleIds, true)) {
            return response()->json([
                'success' => false,
                'code' => 403,
                'message' => 'Access denied. You do not have permission to perform this action.',
                'errors' => null,
            ], 403);
        }

        return $next($request);
    }
}
