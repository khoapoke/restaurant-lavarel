<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AllocateTransactionRequest;
use App\Http\Requests\Admin\ConfirmPaymentRequest;
use App\Http\Requests\Admin\ReconcilePaymentsRequest;
use App\Http\Requests\Admin\RefundPaymentRequest;
use App\Http\Requests\Admin\ResolveTransactionRequest;
use App\Http\Resources\Admin\AdminPaymentResource;
use App\Http\Resources\Admin\MoneyTransactionResource;
use App\Services\Admin\PaymentService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

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

    public function transactions(Request $request): JsonResponse
    {
        $transactions = $this->paymentService->getTransactions($request->all());

        return $this->successResponse([
            'items' => MoneyTransactionResource::collection($transactions->items()),
            'pagination' => [
                'currentPage' => $transactions->currentPage(),
                'perPage' => $transactions->perPage(),
                'totalItems' => $transactions->total(),
                'totalPages' => $transactions->lastPage(),
            ],
        ], 'Money transactions retrieved successfully');
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
            $request->validated('note'),
            $request->validated('amount') !== null ? (float) $request->validated('amount') : null,
            $request->validated('receivedAt') ? Carbon::parse($request->validated('receivedAt')) : null,
        );

        return $this->successResponse(new AdminPaymentResource($payment), 'Bank transfer confirmed successfully');
    }

    public function refund(RefundPaymentRequest $request, int $paymentId): JsonResponse
    {
        $payment = $this->paymentService->refund($paymentId, $this->refundData($request), $request->user());

        return $this->successResponse(new AdminPaymentResource($payment), 'Refund recorded successfully');
    }

    public function refundTransaction(RefundPaymentRequest $request, int $transactionId): JsonResponse
    {
        return $this->successResponse(
            new MoneyTransactionResource($this->paymentService->refundTransaction($transactionId, $this->refundData($request), $request->user())),
            'Refund recorded successfully'
        );
    }

    public function allocateTransaction(AllocateTransactionRequest $request, int $transactionId): JsonResponse
    {
        $amount = $request->validated('amount');

        return $this->successResponse(
            new MoneyTransactionResource($this->paymentService->allocateTransaction(
                $transactionId,
                (int) $request->validated('orderId'),
                $request->user(),
                $amount !== null ? (float) $amount : null,
                $request->validated('note'),
            )),
            'Transaction applied to the order'
        );
    }

    public function resolveTransaction(ResolveTransactionRequest $request, int $transactionId): JsonResponse
    {
        return $this->successResponse(
            new MoneyTransactionResource($this->paymentService->resolveTransaction($transactionId, $request->user(), $request->validated('note'))),
            'Transaction marked as reconciled'
        );
    }

    public function callbackLogs(Request $request): JsonResponse
    {
        return $this->successResponse(
            MoneyTransactionResource::collection($this->paymentService->getCallbackLogs($request->integer('limit', 50))),
            'Payment callback logs retrieved successfully'
        );
    }

    /** @return array{amount: float, method: string, reference: string, reason: string, refundedAt: ?Carbon, type: string, idempotencyKey: ?string} */
    private function refundData(RefundPaymentRequest $request): array
    {
        return [
            'amount' => (float) $request->validated('amount'),
            'method' => $request->validated('method'),
            'reference' => $request->validated('referenceCode'),
            'reason' => $request->validated('refundReason'),
            'refundedAt' => $request->validated('refundedAt') ? Carbon::parse($request->validated('refundedAt')) : null,
            'type' => $request->validated('refundType') ?? 'REFUND',
            // Gửi lại cùng Idempotency-Key (mất phản hồi, bấm lại) trả về khoản hoàn đã ghi, không hoàn thêm.
            'idempotencyKey' => $request->header('Idempotency-Key'),
        ];
    }
}
