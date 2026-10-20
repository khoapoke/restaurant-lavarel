<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateReservationStatusRequest;
use App\Http\Requests\Admin\UpdateTimeSlotsRequest;
use App\Http\Resources\Admin\AdminReservationResource;
use App\Services\Admin\ReservationService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ReservationController extends Controller
{
    use ApiResponse;

    protected ReservationService $reservationService;

    public function __construct(ReservationService $reservationService)
    {
        $this->reservationService = $reservationService;
    }

    /**
     * Get reservation time slots configuration (BKG-07).
     *
     * @return JsonResponse
     */
    public function getTimeSlots(): JsonResponse
    {
        $slots = $this->reservationService->getTimeSlots();

        return $this->successResponse(
            $slots,
            'Reservation time slots retrieved successfully',
            Response::HTTP_OK
        );
    }

    /**
     * Update reservation time slots configuration (BKG-07).
     *
     * @param UpdateTimeSlotsRequest $request
     * @return JsonResponse
     */
    public function updateTimeSlots(UpdateTimeSlotsRequest $request): JsonResponse
    {
        $slots = $this->reservationService->updateTimeSlots($request->validated());

        return $this->successResponse(
            $slots,
            'Reservation time slots updated successfully',
            Response::HTTP_OK
        );
    }

    /**
     * Get paginated reservation list for Admin (BKG-08).
     *
     * @param Request $request
     * @return JsonResponse
     */
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
        ], 'Reservations retrieved successfully', Response::HTTP_OK);
    }

    /**
     * Get reservation detail by ID.
     *
     * @param int $reservationId
     * @return JsonResponse
     */
    public function show(int $reservationId): JsonResponse
    {
        $reservation = $this->reservationService->getReservation($reservationId);

        return $this->successResponse(
            new AdminReservationResource($reservation),
            'Reservation details retrieved successfully',
            Response::HTTP_OK
        );
    }

    /**
     * Update reservation status (CONFIRMED, REJECTED, CANCELLED, COMPLETED) (BKG-09 -> BKG-12).
     *
     * @param UpdateReservationStatusRequest $request
     * @param int $reservationId
     * @return JsonResponse
     */
    public function updateStatus(UpdateReservationStatusRequest $request, int $reservationId): JsonResponse
    {
        $targetStatus = $request->input('targetStatus');
        $adminNote = $request->input('adminNote');

        $result = $this->reservationService->updateStatus($reservationId, $targetStatus, $adminNote);

        $message = ($targetStatus === 'CONFIRMED' || $targetStatus === 'Đã xác nhận')
            ? 'Reservation confirmed successfully'
            : 'Reservation status updated successfully';

        return $this->successResponse(
            $result,
            $message,
            Response::HTTP_OK
        );
    }
}
