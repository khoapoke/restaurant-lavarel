<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Modules\Auth\Http\Requests\ForgotPasswordRequest;
use App\Modules\Auth\Http\Requests\LoginRequest;
use App\Modules\Auth\Http\Requests\RegisterRequest;
use App\Modules\Auth\Http\Requests\ResetPasswordRequest;
use App\Modules\Auth\Http\Resources\AccountResource;
use App\Modules\Auth\Services\AuthService;
use App\Modules\Core\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends BaseController
{
    protected AuthService $authService;

    public function __construct(AuthService $authService)
    {
        $this->authService = $authService;
    }

    /**
     * Customer registration endpoint (USR-01).
     *
     * @param RegisterRequest $request
     * @return JsonResponse
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $result = $this->authService->register($request->validated());

        return $this->successResponse([
            'accessToken' => $result['accessToken'],
            'tokenType' => $result['tokenType'],
            'account' => new AccountResource($result['account']),
        ], 'Account registered successfully', 201);
    }

    /**
     * User login endpoint (USR-02).
     *
     * @param LoginRequest $request
     * @return JsonResponse
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->authService->login($request->validated());

        return $this->successResponse([
            'accessToken' => $result['accessToken'],
            'tokenType' => $result['tokenType'],
            'expiresAt' => $result['expiresAt'],
            'account' => new AccountResource($result['account']),
        ], 'Login successful', 200);
    }

    /**
     * User logout endpoint (USR-03).
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->errorResponse('Unauthenticated or token is missing/invalid', 401);
        }

        $this->authService->logout($user);

        return $this->successResponse(null, 'Logged out successfully', 200);
    }

    /**
     * Request password reset OTP via email (USR-07).
     *
     * @param ForgotPasswordRequest $request
     * @return JsonResponse
     */
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $this->authService->forgotPassword($request->input('email'));

        return $this->successResponse(
            null,
            'Mã xác thực OTP đã được gửi về email của bạn. Vui lòng kiểm tra hộp thư.',
            200
        );
    }

    /**
     * Reset password with OTP (USR-08).
     *
     * @param ResetPasswordRequest $request
     * @return JsonResponse
     */
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $this->authService->resetPassword(
            $request->input('email'),
            $request->input('otp'),
            $request->input('password')
        );

        return $this->successResponse(
            null,
            'Đặt lại mật khẩu thành công. Vui lòng đăng nhập với mật khẩu mới.',
            200
        );
    }
}

