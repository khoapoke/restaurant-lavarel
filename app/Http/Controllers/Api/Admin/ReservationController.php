<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateReservationStatusRequest;
use App\Http\Resources\Admin\AdminReservationResource;
use App\Services\Admin\ReservationService;
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
            $data['adminNote'] ?? null
        );

        return $this->successResponse(new AdminReservationResource($reservation), 'Reservation status updated successfully');
    }
}
