<?php

namespace App\Http\Middleware;

use App\Enums\AccountStatus;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckAccountActive
{
    /**
     * Handle an incoming request.
     * Check if user account is active. If locked, revoke tokens and return 403 Forbidden.
     *
     * @param  \Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user) {
            $isLocked = ($user->trang_thai === AccountStatus::LOCKED)
                || ($user->status === AccountStatus::LOCKED)
                || ($user->trang_thai === AccountStatus::LOCKED->value);

            if ($isLocked) {
                // Revoke all tokens immediately for locked account
                $user->tokens()->delete();

                return response()->json([
                    'success' => false,
                    'code' => 403,
                    'message' => 'Your account has been locked by Administrator',
                    'errors' => null,
                ], 403);
            }
        }

        return $next($request);
    }
}
