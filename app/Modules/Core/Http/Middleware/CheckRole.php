<?php

namespace App\Modules\Core\Http\Middleware;

use App\Modules\Core\Enums\RoleType;
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

        // Map role name string to role ID and names
        $allowedRoleIds = [];
        $allowedRoleNames = [];

        foreach ($roles as $role) {
            $upper = strtoupper(trim($role));
            if ($upper === 'ADMIN' || $upper === strtoupper(RoleType::ADMIN->value)) {
                $allowedRoleIds[] = 1;
                $allowedRoleNames[] = 'ADMIN';
                $allowedRoleNames[] = RoleType::ADMIN->value;
            } elseif ($upper === 'STAFF' || $upper === strtoupper(RoleType::STAFF->value)) {
                $allowedRoleIds[] = 2;
                $allowedRoleNames[] = 'STAFF';
                $allowedRoleNames[] = RoleType::STAFF->value;
            } elseif ($upper === 'CUSTOMER' || $upper === strtoupper(RoleType::CUSTOMER->value)) {
                $allowedRoleIds[] = 3;
                $allowedRoleNames[] = 'CUSTOMER';
                $allowedRoleNames[] = RoleType::CUSTOMER->value;
            } elseif (is_numeric($role)) {
                $allowedRoleIds[] = (int) $role;
            } else {
                $allowedRoleNames[] = $role;
            }
        }

        $userRoleId = (int) $user->ma_vai_tro;
        $matched = in_array($userRoleId, $allowedRoleIds, true);

        if (!$matched) {
            $userRole = $user->relationLoaded('role') ? $user->role : $user->role()->first();
            $userRoleTen = $userRole?->ten_vai_tro;
            $matchedRoleType = $userRoleTen ? RoleType::tryFrom($userRoleTen) : null;
            $userRoleConstant = $matchedRoleType ? $matchedRoleType->name : null;

            if ($userRoleTen && in_array($userRoleTen, $allowedRoleNames, true)) {
                $matched = true;
            } elseif ($userRoleConstant && in_array($userRoleConstant, $allowedRoleNames, true)) {
                $matched = true;
            }
        }

        if (!$matched) {
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
