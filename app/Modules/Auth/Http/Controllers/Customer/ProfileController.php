<?php

namespace App\Modules\Auth\Http\Controllers\Customer;

use App\Modules\Auth\Http\Requests\Customer\UpdateProfileRequest;
use App\Modules\Auth\Http\Resources\Customer\CustomerProfileResource;
use App\Modules\Auth\Services\Customer\ProfileService;
use App\Modules\Core\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends BaseController
{
    protected ProfileService $profileService;

    public function __construct(ProfileService $profileService)
    {
        $this->profileService = $profileService;
    }

    /**
     * Get customer profile (USR-03).
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function show(Request $request): JsonResponse
    {
        $account = $this->profileService->getProfile($request->user());

        return $this->successResponse(
            new CustomerProfileResource($account),
            'Profile retrieved successfully',
            200
        );
    }

    /**
     * Update customer profile, addresses and allergies (USR-04, USR-05, USR-06).
     *
     * @param UpdateProfileRequest $request
     * @return JsonResponse
     */
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $account = $this->profileService->updateProfile($request->user(), $request->validated());
        $profile = $account->userProfile;

        return $this->successResponse([
            'accountId' => $account->accountId,
            'email' => $account->email,
            'fullName' => $profile?->fullName,
            'phoneNumber' => $profile?->phoneNumber,
            'defaultAddress' => $profile?->address,
            'allergies' => $profile?->allergies ?? [],
            'dietaryPreferences' => $profile?->dietaryPreferences ?? [],
        ], 'Profile updated successfully', 200);
    }

    /**
     * Change customer password (USR-06).
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'currentPassword' => 'required|string',
            'newPassword' => 'required|string|min:6|confirmed',
        ]);

        $this->profileService->changePassword(
            $request->user(),
            $request->input('currentPassword'),
            $request->input('newPassword')
        );

        return $this->successResponse(
            null,
            'Password changed successfully',
            200
        );
    }
}

