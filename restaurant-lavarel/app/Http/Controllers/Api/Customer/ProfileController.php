<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\UpdateProfileRequest;
use App\Http\Resources\Customer\CustomerProfileResource;
use App\Services\Customer\ProfileService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    use ApiResponse;

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
}
