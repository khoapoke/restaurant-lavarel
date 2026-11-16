<?php

namespace App\Modules\Payment\Http\Controllers\Customer;

use App\Modules\Core\Http\Controllers\BaseController;
use App\Modules\Payment\Services\Customer\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends BaseController
{

    protected PaymentService $paymentService;

    public function __construct(PaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
    }

    /**
     * Generate VietQR code for order payment (PAY-01).
     *
     * @param Request $request
     * @param int $orderId
     * @return JsonResponse
     */
    public function generateQr(Request $request, int $orderId): JsonResponse
    {
        $account = $request->user();
        $qrData = $this->paymentService->generateQr((int) $account->ma_tai_khoan, $orderId);

        return $this->successResponse($qrData, 'QR code generated successfully', 200);
    }

    /**
     * Get payment transaction status (PAY-02).
     *
     * @param Request $request
     * @param int $orderId
     * @return JsonResponse
     */
    public function getStatus(Request $request, int $orderId): JsonResponse
    {
        $account = $request->user();
        $statusData = $this->paymentService->getPaymentStatus((int) $account->ma_tai_khoan, $orderId);

        return $this->successResponse($statusData, 'Payment status retrieved successfully', 200);
    }

    /**
     * Retry payment when expired or failed (PAY-03).
     *
     * @param Request $request
     * @param int $orderId
     * @return JsonResponse
     */
    public function retry(Request $request, int $orderId): JsonResponse
    {
        $account = $request->user();
        $retryData = $this->paymentService->retryPayment((int) $account->ma_tai_khoan, $orderId);

        return $this->successResponse($retryData, 'Payment retry initiated successfully', 200);
    }

    /**
     * Mock payment success for customer order (dev/test).
     *
     * @param Request $request
     * @param int $orderId
     * @return JsonResponse
     */
    public function mockPayment(Request $request, int $orderId): JsonResponse
    {
        $account = $request->user();
        $result = $this->paymentService->mockPaymentSuccess((int) $account->ma_tai_khoan, $orderId);

        return $this->successResponse($result, 'Payment confirmed successfully (mocked)', 200);
    }

    /**
     * Webhook callback from payment gateway (VietQR/SePay/PayOS).
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function handleCallback(Request $request): JsonResponse
    {
        $signature = $request->header('x-signature') ?? $request->header('signature');
        $result = $this->paymentService->processWebhook($request->all(), $signature);

        if ($result instanceof \App\Modules\Payment\Models\Payment) {
            return $this->successResponse([
                'orderId' => (int) $result->ma_don_hang,
                'status' => 'PAID',
            ], 'Payment webhook processed successfully', 200);
        }

        return $this->successResponse(
            $result,
            'Reservation deposit webhook processed successfully',
            200
        );
    }
}


