<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AdminAccountResource;
use App\Services\Admin\AccountService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    use ApiResponse;

    protected AccountService $accountService;

    public function __construct(AccountService $accountService)
    {
        $this->accountService = $accountService;
    }

    /**
     * Get paginated list of accounts with search and filters (USR-07, USR-08).
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $accounts = $this->accountService->getAccounts($request->all());

        return $this->successResponse([
            'items' => AdminAccountResource::collection($accounts->items()),
            'pagination' => [
                'currentPage' => $accounts->currentPage(),
                'perPage' => $accounts->perPage(),
                'totalItems' => $accounts->total(),
                'totalPages' => $accounts->lastPage(),
            ],
        ], 'Accounts retrieved successfully', 200);
    }

    /**
     * Lock an account and revoke active tokens (USR-09).
     *
     * @param Request $request
     * @param int $accountId
     * @return JsonResponse
     */
    public function lock(Request $request, int $accountId): JsonResponse
    {
        $account = $this->accountService->lockAccount($request->user(), $accountId);

        return $this->successResponse(
            new AdminAccountResource($account),
            'Account locked successfully',
            200
        );
    }

    /**
     * Unlock an account (USR-10).
     *
     * @param Request $request
     * @param int $accountId
     * @return JsonResponse
     */
    public function unlock(Request $request, int $accountId): JsonResponse
    {
        $account = $this->accountService->unlockAccount($accountId);

        return $this->successResponse(
            new AdminAccountResource($account),
            'Account unlocked successfully',
            200
        );
    }
}
