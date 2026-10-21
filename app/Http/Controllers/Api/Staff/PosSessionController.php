<?php

namespace App\Http\Controllers\Api\Staff;

use App\Enums\TableStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\CancelPosSessionRequest;
use App\Http\Requests\Staff\OpenPosSessionRequest;
use App\Http\Resources\Staff\PosSessionResource;
use App\Http\Resources\Staff\PosTableResource;
use App\Models\DiningTable;
use App\Models\ServiceSession;
use App\Services\Staff\PosSessionService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class PosSessionController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly PosSessionService $posSessionService) {}

    public function floor(): JsonResponse
    {
        $tables = $this->posSessionService->getFloor();

        return $this->successResponse([
            'syncedAt' => now()->toIso8601String(),
            'summary' => [
                'total' => $tables->count(),
                'available' => $tables->where('trang_thai', TableStatus::AVAILABLE)->count(),
                'reserved' => $tables->where('trang_thai', TableStatus::RESERVED)->count(),
                'occupied' => $tables->where('trang_thai', TableStatus::OCCUPIED)->count(),
                'cleaning' => $tables->where('trang_thai', TableStatus::CLEANING)->count(),
                'maintenance' => $tables->where('trang_thai', TableStatus::MAINTENANCE)->count(),
            ],
            'items' => PosTableResource::collection($tables),
        ], 'POS floor retrieved successfully');
    }

    public function show(int $sessionId): JsonResponse
    {
        return $this->successResponse(
            new PosSessionResource($this->posSessionService->getSession($sessionId)),
            'POS session retrieved successfully'
        );
    }

    public function store(OpenPosSessionRequest $request): JsonResponse
    {
        $result = $this->posSessionService->open($request->user(), $request->validated());

        return $this->successResponse(
            new PosSessionResource($result['session']),
            $result['created'] ? 'POS session opened successfully' : 'Existing POS session returned',
            $result['created'] ? 201 : 200
        );
    }

    public function cancel(CancelPosSessionRequest $request, ServiceSession $session): JsonResponse
    {
        return $this->successResponse(
            new PosSessionResource($this->posSessionService->cancel($session, $request->validated('reason'))),
            'POS session cancelled successfully'
        );
    }

    public function completeCleaning(DiningTable $table): JsonResponse
    {
        return $this->successResponse(
            new PosTableResource($this->posSessionService->completeCleaning($table)),
            'Table cleaning completed successfully'
        );
    }
}
