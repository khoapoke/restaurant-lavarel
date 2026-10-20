<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReconcilePaymentRequest;
use App\Http\Requests\Admin\RefundPaymentRequest;
use App\Http\Resources\Admin\AdminPaymentResource;
use App\Http\Resources\Admin\CallbackLogResource;
use App\Services\Admin\PaymentService;
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
     * Tra cứu danh sách giao dịch thanh toán (PAY-04).
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $payments = $this->paymentService->getPayments($request->all());

        return $this->successResponse([
            'items' => AdminPaymentResource::collection($payments->items()),
            'pagination' => [
                'currentPage' => $payments->currentPage(),
                'perPage' => $payments->perPage(),
                'totalItems' => $payments->total(),
                'totalPages' => $payments->lastPage(),
            ],
        ], 'Payments retrieved successfully', 200);
    }

    /**
     * Đối soát số tiền thanh toán với tổng tiền đơn hàng (PAY-05).
     *
     * @param ReconcilePaymentRequest $request
     * @return JsonResponse
     */
    public function reconcile(ReconcilePaymentRequest $request): JsonResponse
    {
        $orderId = $request->input('orderId') ? (int) $request->input('orderId') : null;
        $paymentId = $request->input('paymentId') ? (int) $request->input('paymentId') : null;

        $result = $this->paymentService->reconcilePayments($orderId, $paymentId);

        $message = $orderId
            ? "Payment reconciliation completed for order #{$orderId}"
            : 'Payment reconciliation completed';

        return $this->successResponse($result, $message, 200);
    }

    /**
     * Ghi nhận hoàn tiền giao dịch (PAY-06).
     *
     * @param RefundPaymentRequest $request
     * @param int $paymentId
     * @return JsonResponse
     */
    public function refund(RefundPaymentRequest $request, int $paymentId): JsonResponse
    {
        $refundReason = (string) $request->input('refundReason');
        $result = $this->paymentService->refundPayment($paymentId, $refundReason);

        return $this->successResponse(
            $result,
            'Refund recorded successfully',
            200
        );
    }

    /**
     * Tra cứu logs webhook callback (PAY-07).
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function callbackLogs(Request $request): JsonResponse
    {
        $logs = $this->paymentService->getCallbackLogs($request->all());

        return $this->successResponse([
            'items' => CallbackLogResource::collection($logs->items()),
            'pagination' => [
                'currentPage' => $logs->currentPage(),
                'perPage' => $logs->perPage(),
                'totalItems' => $logs->total(),
                'totalPages' => $logs->lastPage(),
            ],
        ], 'Callback logs retrieved successfully', 200);
    }
}
