<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\ClosePosSessionRequest;
use App\Http\Requests\Staff\CreatePaymentRequest;
use App\Http\Resources\Staff\InvoiceResource;
use App\Http\Resources\Staff\PosSessionResource;
use App\Models\Invoice;
use App\Models\ServiceSession;
use App\Services\Staff\BillingService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class BillingController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly BillingService $billingService) {}

    public function createInvoice(ServiceSession $session): JsonResponse
    {
        $invoice = $this->billingService->createInvoice($session);

        return $this->successResponse(new InvoiceResource($invoice), 'Invoice ready', 201);
    }

    public function reopen(ServiceSession $session): JsonResponse
    {
        return $this->successResponse(
            new PosSessionResource($this->billingService->reopen($session)),
            'Invoice voided. The session is open for more orders.'
        );
    }

    public function pay(CreatePaymentRequest $request, Invoice $invoice): JsonResponse
    {
        $this->billingService->pay($invoice, $request->validated());

        return $this->successResponse(
            new InvoiceResource($invoice->fresh('payment')),
            'Payment recorded successfully',
            201
        );
    }

    public function close(ClosePosSessionRequest $request, ServiceSession $session): JsonResponse
    {
        $closed = $this->billingService->close($session, (int) $request->validated('expectedVersion'));

        return $this->successResponse(new PosSessionResource($closed), 'POS session closed successfully');
    }
}
