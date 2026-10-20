<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\CreateReservationRequest;
use App\Http\Requests\Customer\ReservationAvailabilityRequest;
use App\Http\Requests\Customer\UpdateReservationRequest;
use App\Http\Resources\Customer\ReservationResource;
use App\Services\Customer\ReservationService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReservationController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ReservationService $reservationService) {}

    public function availableTables(ReservationAvailabilityRequest $request): JsonResponse
    {
        $tables = $this->reservationService->getAvailableTables($request->validated());

        return $this->successResponse([
            'items' => $tables->map(fn ($table): array => [
                'tableId' => (int) $table->ma_ban,
                'tableName' => $table->ten_ban,
                'capacity' => (int) $table->suc_chua,
                'location' => $table->vi_tri,
            ])->values(),
        ], 'Available tables retrieved successfully');
    }

    public function index(Request $request): JsonResponse
    {
        $reservations = $this->reservationService->getCustomerReservations(
            (int) $request->user()->ma_tai_khoan,
            $request->integer('perPage', 10)
        );

        return $this->successResponse([
            'items' => ReservationResource::collection($reservations->items()),
            'pagination' => [
                'currentPage' => $reservations->currentPage(),
                'perPage' => $reservations->perPage(),
                'totalItems' => $reservations->total(),
                'totalPages' => $reservations->lastPage(),
            ],
        ], 'Reservations retrieved successfully');
    }

    public function store(CreateReservationRequest $request): JsonResponse
    {
        $reservation = $this->reservationService->create(
            (int) $request->user()->ma_tai_khoan,
            $request->validated()
        );

        return $this->successResponse(new ReservationResource($reservation), 'Reservation created successfully', 201);
    }

    public function update(UpdateReservationRequest $request, int $reservationId): JsonResponse
    {
        $reservation = $this->reservationService->update(
            (int) $request->user()->ma_tai_khoan,
            $reservationId,
            $request->validated()
        );

        return $this->successResponse(new ReservationResource($reservation), 'Reservation updated successfully');
    }

    public function cancel(Request $request, int $reservationId): JsonResponse
    {
        $reservation = $this->reservationService->cancel(
            (int) $request->user()->ma_tai_khoan,
            $reservationId
        );

        return $this->successResponse(new ReservationResource($reservation), 'Reservation cancelled successfully');
    }
}
