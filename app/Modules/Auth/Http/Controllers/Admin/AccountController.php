<?php

namespace App\Modules\Auth\Http\Controllers\Admin;

use App\Modules\Auth\Http\Resources\Admin\AdminAccountResource;
use App\Modules\Auth\Services\Admin\AccountService;
use App\Modules\Core\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountController extends BaseController
{
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
     * Get account detail by ID (ADM-02).
     *
     * @param int $accountId
     * @return JsonResponse
     */
    public function show(int $accountId): JsonResponse
    {
        $account = $this->accountService->getAccountDetail($accountId);

        return $this->successResponse(
            new AdminAccountResource($account),
            'Account detail retrieved successfully',
            200
        );
    }

    /**
     * Create staff account (ADM-03).
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function storeStaff(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'username' => 'required|string|min:3|max:50|unique:TAI_KHOAN,ten_dang_nhap',
            'password' => 'required|string|min:6|confirmed',
            'email' => 'required|email|max:100|unique:TAI_KHOAN,email',
            'fullName' => 'required|string|max:100',
            'phoneNumber' => 'required|string|regex:/^0[0-9]{9}$/',
            'birthDate' => 'nullable|date',
            'address' => 'nullable|string|max:255',
        ]);

        $account = $this->accountService->createStaffAccount($validated);

        return $this->successResponse(
            new AdminAccountResource($account),
            'Staff account created successfully',
            201
        );
    }

    /**
     * Update account info (ADM-04).
     *
     * @param Request $request
     * @param int $accountId
     * @return JsonResponse
     */
    public function update(Request $request, int $accountId): JsonResponse
    {
        $validated = $request->validate([
            'fullName' => 'nullable|string|max:100',
            'phoneNumber' => 'nullable|string|regex:/^0[0-9]{9}$/',
            'birthDate' => 'nullable|date',
            'address' => 'nullable|string|max:255',
            'email' => "nullable|email|max:100|unique:TAI_KHOAN,email,{$accountId},ma_tai_khoan",
            'roleId' => 'nullable|exists:VAI_TRO,ma_vai_tro',
            'status' => 'nullable|string|in:ACTIVE,LOCKED',
        ]);

        $account = $this->accountService->updateAccount($accountId, $validated);

        return $this->successResponse(
            new AdminAccountResource($account),
            'Account updated successfully',
            200
        );
    }

    /**
     * Update status (lock/unlock) of an account (ADM-05).
     *
     * @param Request $request
     * @param int $accountId
     * @return JsonResponse
     */
    public function updateStatus(Request $request, int $accountId): JsonResponse
    {
        $request->validate([
            'status' => 'required|string|in:ACTIVE,LOCKED,Hoạt động,Bị khóa',
        ]);

        $account = $this->accountService->updateStatus(
            $request->user(),
            $accountId,
            $request->input('status')
        );

        return $this->successResponse(
            new AdminAccountResource($account),
            'Account status updated successfully',
            200
        );
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

