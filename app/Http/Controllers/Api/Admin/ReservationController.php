<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateReservationRequest;
use App\Http\Requests\Admin\UpdateReservationStatusRequest;
use App\Http\Resources\Admin\AdminReservationResource;
use App\Http\Resources\Shared\AuditLogResource;
use App\Services\Admin\ReservationService;
use App\Services\Shared\AuditLogger;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReservationController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ReservationService $reservationService) {}

    public function index(Request $request): JsonResponse
    {
        $reservations = $this->reservationService->getReservations($request->all());

        return $this->successResponse([
            'items' => AdminReservationResource::collection($reservations->items()),
            'pagination' => [
                'currentPage' => $reservations->currentPage(),
                'perPage' => $reservations->perPage(),
                'totalItems' => $reservations->total(),
                'totalPages' => $reservations->lastPage(),
            ],
        ], 'Reservations retrieved successfully');
    }

    public function updateStatus(UpdateReservationStatusRequest $request, int $reservationId): JsonResponse
    {
        $data = $request->validated();
        $reservation = $this->reservationService->updateStatus(
            $reservationId,
            $data['targetStatus'],
            $data['reason'] ?? $data['adminNote'] ?? null,
            $request->user(),
            $data['internalNote'] ?? null
        );

        return $this->successResponse(new AdminReservationResource($reservation), 'Reservation status updated successfully');
    }

    public function show(int $reservationId): JsonResponse
    {
        return $this->successResponse(new AdminReservationResource($this->reservationService->getReservation($reservationId)), 'Reservation retrieved successfully');
    }

    public function update(UpdateReservationRequest $request, int $reservationId): JsonResponse
    {
        $reservation = $this->reservationService->edit($reservationId, $request->validated(), $request->user());

        return $this->successResponse(new AdminReservationResource($reservation), 'Reservation updated successfully');
    }

    /** Lịch sử thao tác của lượt đặt (chỉ đọc). */
    public function history(int $reservationId): JsonResponse
    {
        $this->reservationService->getReservation($reservationId);

        return $this->successResponse(
            AuditLogResource::collection(app(AuditLogger::class)->forEntity('RESERVATION', $reservationId)),
            'Reservation history retrieved'
        );
    }
}
