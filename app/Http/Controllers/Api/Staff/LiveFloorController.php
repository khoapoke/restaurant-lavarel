<?php

namespace App\Http\Controllers\Api\Staff;

use App\Enums\ServiceRequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\AcceptCheckInRequest;
use App\Http\Requests\Staff\RejectCheckInRequest;
use App\Http\Resources\Staff\CheckInQueueResource;
use App\Http\Resources\Staff\PosSessionResource;
use App\Http\Resources\Staff\ServiceRequestResource;
use App\Models\DiningTable;
use App\Models\ServiceRequest;
use App\Models\ServiceSession;
use App\Models\TableVisit;
use App\Services\Dining\CheckInService;
use App\Services\Dining\ServiceRequestService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Live Dining operations queue for the floor team: QR check-ins and service requests.
 */
class LiveFloorController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly CheckInService $checkIns,
        private readonly ServiceRequestService $serviceRequests,
    ) {}

    public function queue(): JsonResponse
    {
        $checkIns = $this->checkIns->pending();
        $requests = $this->serviceRequests->openQueue();

        return $this->successResponse([
            'syncedAt' => now()->toIso8601String(),
            'counts' => [
                'checkIns' => $checkIns->count(),
                'newRequests' => $requests->where('trang_thai', ServiceRequestStatus::NEW)->count(),
                'acknowledgedRequests' => $requests->where('trang_thai', ServiceRequestStatus::ACKNOWLEDGED)->count(),
                'paymentRequests' => $requests->filter(fn (ServiceRequest $request) => $request->loai->isPriority())->count(),
            ],
            'checkIns' => CheckInQueueResource::collection($checkIns),
            'serviceRequests' => ServiceRequestResource::collection($requests),
        ], 'Live floor queue retrieved successfully');
    }

    public function acceptCheckIn(AcceptCheckInRequest $request, TableVisit $visit): JsonResponse
    {
        return $this->successResponse(
            new PosSessionResource($this->checkIns->accept($visit, $request->user(), $request->validated())),
            'Check-in accepted and table opened'
        );
    }

    public function rejectCheckIn(RejectCheckInRequest $request, TableVisit $visit): JsonResponse
    {
        return $this->successResponse(
            new CheckInQueueResource($this->checkIns->reject($visit, $request->user(), $request->validated('reason'))),
            'Check-in request declined'
        );
    }

    public function acknowledge(Request $request, ServiceRequest $serviceRequest): JsonResponse
    {
        return $this->successResponse(
            new ServiceRequestResource($this->serviceRequests->acknowledge($serviceRequest, $request->user())),
            'Service request acknowledged'
        );
    }

    public function complete(Request $request, ServiceRequest $serviceRequest): JsonResponse
    {
        return $this->successResponse(
            new ServiceRequestResource($this->serviceRequests->complete($serviceRequest, $request->user())),
            'Service request completed'
        );
    }

    public function rotateJoinCode(ServiceSession $session): JsonResponse
    {
        return $this->successResponse(
            new PosSessionResource($this->checkIns->rotateJoinCode($session)),
            'Table code regenerated'
        );
    }

    public function tableQr(DiningTable $table): JsonResponse
    {
        return $this->successResponse([
            'tableId' => (int) $table->ma_ban,
            'tableName' => $table->ten_ban,
            'token' => $table->ma_qr,
            'url' => $table->qrUrl(),
            'issuedAt' => $table->ma_qr_tao_luc?->toIso8601String(),
        ], 'Table QR link retrieved successfully');
    }
}
