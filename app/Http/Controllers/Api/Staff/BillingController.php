<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\ClosePosSessionRequest;
use App\Http\Requests\Staff\CreatePaymentRequest;
use App\Http\Requests\Staff\ReasonOnlyRequest;
use App\Http\Resources\Staff\InvoiceResource;
use App\Http\Resources\Staff\PosSessionResource;
use App\Models\Invoice;
use App\Models\ServiceSession;
use App\Services\Staff\BillingService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly BillingService $billingService) {}

    public function createInvoice(Request $request, ServiceSession $session): JsonResponse
    {
        $invoice = $this->billingService->createInvoice($session, (int) $request->user()->ma_tai_khoan);

        return $this->successResponse(new InvoiceResource($invoice), 'Invoice ready', 201);
    }

    public function reopen(Request $request, ServiceSession $session): JsonResponse
    {
        return $this->successResponse(
            new PosSessionResource($this->billingService->reopen($session, $request->user(), $request->input('reason'))),
            'Invoice voided. The session is open for more orders.'
        );
    }

    public function pay(CreatePaymentRequest $request, Invoice $invoice): JsonResponse
    {
        $this->billingService->pay($invoice, $request->validated(), (int) $request->user()->ma_tai_khoan);

        return $this->successResponse(
            new InvoiceResource($invoice->fresh('payment')),
            'Payment recorded successfully',
            201
        );
    }

    /** ADMIN kết thúc phiên thất thu (khách bỏ đi); STAFF dùng yêu cầu duyệt SESSION_WRITE_OFF. */
    public function finishWithoutCharge(ReasonOnlyRequest $request, ServiceSession $session): JsonResponse
    {
        return $this->successResponse(
            new PosSessionResource($this->billingService->finishWithoutCharge($session, $request->user(), $request->validated('note'))),
            'Session finished without charge'
        );
    }

    public function writeOff(ReasonOnlyRequest $request, ServiceSession $session): JsonResponse
    {
        return $this->successResponse(
            new PosSessionResource($this->billingService->writeOff($session, $request->user(), $request->validated('note'))),
            'Session closed as unpaid'
        );
    }

    public function close(ClosePosSessionRequest $request, ServiceSession $session): JsonResponse
    {
        $closed = $this->billingService->close($session, (int) $request->validated('expectedVersion'), $request->user());

        return $this->successResponse(new PosSessionResource($closed), 'POS session closed successfully');
    }
}
