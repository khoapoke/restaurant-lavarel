<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Services\Customer\PaymentService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    use ApiResponse;

    protected PaymentService $paymentService;

    public function __construct(PaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
    }

    /**
     * Generate VietQR code for order payment (PAY-01).
     */
    public function generateQr(Request $request, int $orderId): JsonResponse
    {
        $account = $request->user();
        $qrData = $this->paymentService->generateQr((int) $account->ma_tai_khoan, $orderId);

        return $this->successResponse($qrData, 'QR code generated successfully', 200);
    }

    /**
     * Get payment transaction status (PAY-02).
     */
    public function getStatus(Request $request, int $orderId): JsonResponse
    {
        $account = $request->user();
        $statusData = $this->paymentService->getPaymentStatus((int) $account->ma_tai_khoan, $orderId);

        return $this->successResponse($statusData, 'Payment status retrieved successfully', 200);
    }

    /**
     * Retry payment when expired or failed (PAY-03).
     */
    public function retry(Request $request, int $orderId): JsonResponse
    {
        $account = $request->user();
        $retryData = $this->paymentService->retryPayment((int) $account->ma_tai_khoan, $orderId);

        return $this->successResponse($retryData, 'Payment retry initiated successfully', 200);
    }

    /**
     * Webhook callback from payment gateway (VietQR/SePay/PayOS).
     */
    public function handleCallback(Request $request): JsonResponse
    {
        $signature = $request->header('x-signature') ?? $request->header('signature');
        $result = $this->paymentService->processWebhook($request->all(), $request->getContent(), $signature);
        $transaction = $result['transaction'];

        return $this->successResponse([
            'transactionId' => (int) $transaction->ma_giao_dich_tien,
            'orderId' => $transaction->ma_don_hang_muc_tieu ? (int) $transaction->ma_don_hang_muc_tieu : null,
            'duplicateCallback' => $result['duplicateCallback'],
            'appliedAmount' => $result['applied'],
            'reconciliation' => $transaction->trang_thai_doi_soat->name,
            'flag' => $transaction->canh_bao?->name,
            'status' => $result['applied'] > 0 && $transaction->trang_thai_doi_soat->name === 'MATCHED' ? 'APPLIED' : ($result['duplicateCallback'] ? 'DUPLICATE_CALLBACK' : 'RECORDED_FOR_REVIEW'),
        ], 'Payment webhook recorded', 200);
    }
}
