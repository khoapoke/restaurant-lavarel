<?php

namespace App\Modules\Payment\Http\Controllers\Admin;

use App\Modules\Core\Http\Controllers\BaseController;
use App\Modules\Payment\Http\Requests\Admin\ReconcilePaymentRequest;
use App\Modules\Payment\Http\Requests\Admin\RefundPaymentRequest;
use App\Modules\Payment\Http\Resources\Admin\AdminPaymentResource;
use App\Modules\Payment\Http\Resources\Admin\CallbackLogResource;
use App\Modules\Payment\Services\Admin\PaymentService;
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

    /**
     * Xác nhận thanh toán thành công thủ công bởi nhân viên / quản lý (PAY-03).
     *
     * @param Request $request
     * @param int $paymentId
     * @return JsonResponse
     */
    public function confirm(Request $request, int $paymentId): JsonResponse
    {
        $note = $request->input('note');
        $staffId = $request->user() ? (int) $request->user()->ma_tai_khoan : null;

        $result = $this->paymentService->confirmPayment($paymentId, $note, $staffId);

        return $this->successResponse(
            $result,
            'Payment confirmed successfully by staff',
            200
        );
    }
}


