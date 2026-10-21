<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ConfirmPaymentRequest;
use App\Http\Requests\Admin\ReconcilePaymentsRequest;
use App\Http\Requests\Admin\RefundPaymentRequest;
use App\Http\Resources\Admin\AdminPaymentResource;
use App\Services\Admin\PaymentService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly PaymentService $paymentService) {}

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
        ], 'Payments retrieved successfully');
    }

    public function reconcile(ReconcilePaymentsRequest $request): JsonResponse
    {
        return $this->successResponse(
            $this->paymentService->reconcile($request->validated('paymentIds') ?? []),
            'Payment reconciliation completed'
        );
    }

    public function confirm(ConfirmPaymentRequest $request, int $paymentId): JsonResponse
    {
        $payment = $this->paymentService->confirmTransfer(
            $paymentId,
            $request->user(),
            $request->validated('transactionCode'),
            $request->validated('note')
        );

        return $this->successResponse(new AdminPaymentResource($payment), 'Bank transfer confirmed successfully');
    }

    public function refund(RefundPaymentRequest $request, int $paymentId): JsonResponse
    {
        $payment = $this->paymentService->refund($paymentId, $request->validated('refundReason'));

        return $this->successResponse(new AdminPaymentResource($payment), 'Refund recorded successfully');
    }

    public function callbackLogs(Request $request): JsonResponse
    {
        return $this->successResponse(
            AdminPaymentResource::collection($this->paymentService->getCallbackLogs($request->integer('limit', 50))),
            'Payment callback logs retrieved successfully'
        );
    }
}
