<?php

namespace App\Http\Controllers\Api\Dining;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dining\RaiseServiceRequestRequest;
use App\Services\Dining\GuestAccessService;
use App\Services\Dining\LiveStateService;
use App\Services\Dining\ServiceRequestService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GuestServiceRequestController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly GuestAccessService $guestAccess,
        private readonly ServiceRequestService $serviceRequests,
        private readonly LiveStateService $liveState,
    ) {}

    public function store(RaiseServiceRequestRequest $request, string $tableToken): JsonResponse
    {
        $table = $this->guestAccess->resolveTable($tableToken);
        $visit = $this->guestAccess->requireVisit($table, $request->header(GuestTableController::VISIT_HEADER));
        $result = $this->serviceRequests->raise($table, $visit, $request->validated());

        return $this->successResponse([
            'requestId' => (int) $result['request']->ma_yeu_cau,
            'created' => $result['created'],
            'live' => $this->liveState->snapshot($table, $visit),
        ], $result['created'] ? 'Service request sent' : 'This request is already on its way', $result['created'] ? 201 : 200);
    }

    public function cancel(Request $request, string $tableToken, int $requestId): JsonResponse
    {
        $table = $this->guestAccess->resolveTable($tableToken);
        $visit = $this->guestAccess->requireVisit($table, $request->header(GuestTableController::VISIT_HEADER));
        $this->serviceRequests->cancelByGuest($visit, $requestId);

        return $this->successResponse([
            'live' => $this->liveState->snapshot($table, $visit),
        ], 'Service request cancelled');
    }
}
