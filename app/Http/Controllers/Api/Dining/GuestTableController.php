<?php

namespace App\Http\Controllers\Api\Dining;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dining\CheckInRequest;
use App\Http\Requests\Dining\JoinTableRequest;
use App\Services\Dining\GuestAccessService;
use App\Services\Dining\LiveStateService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public Live Dining entry point behind the table QR code (no customer account).
 * The device pass travels in the `X-Dine-Visit` header.
 */
class GuestTableController extends Controller
{
    use ApiResponse;

    public const VISIT_HEADER = 'X-Dine-Visit';

    public function __construct(
        private readonly GuestAccessService $guestAccess,
        private readonly LiveStateService $liveState,
    ) {}

    public function show(Request $request, string $tableToken): JsonResponse
    {
        $table = $this->guestAccess->resolveTable($tableToken);
        $visit = $this->guestAccess->findVisit($table, $request->header(self::VISIT_HEADER));
        $since = $request->query('since');

        return $this->successResponse(
            $this->liveState->snapshot($table, $visit, is_string($since) ? $since : null),
            'Table state retrieved successfully'
        );
    }

    public function checkIn(CheckInRequest $request, string $tableToken): JsonResponse
    {
        $table = $this->guestAccess->resolveTable($tableToken);
        $result = $this->guestAccess->requestCheckIn($table, $request->validated('guestName'), (int) $request->validated('guestCount'));

        return $this->successResponse([
            'visitToken' => $result['token'],
            'live' => $this->liveState->snapshot($table, $result['visit']),
        ], 'Check-in request sent to staff', 201);
    }

    public function join(JoinTableRequest $request, string $tableToken): JsonResponse
    {
        $table = $this->guestAccess->resolveTable($tableToken);
        $current = $this->guestAccess->findVisit($table, $request->header(self::VISIT_HEADER));
        $result = $this->guestAccess->join($table, $current, $request->validated('guestName'), $request->validated('joinCode'));

        return $this->successResponse([
            'visitToken' => $result['token'],
            'live' => $this->liveState->snapshot($table, $result['visit']),
        ], 'Joined the table session', $result['token'] ? 201 : 200);
    }

    public function cancelCheckIn(Request $request, string $tableToken): JsonResponse
    {
        $table = $this->guestAccess->resolveTable($tableToken);
        $visit = $this->guestAccess->requireVisit($table, $request->header(self::VISIT_HEADER));
        $visit = $this->guestAccess->cancelCheckIn($visit);

        return $this->successResponse([
            'live' => $this->liveState->snapshot($table, $visit),
        ], 'Check-in request cancelled');
    }
}
