<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\CreateReservationRequest;
use App\Http\Requests\Customer\FindAvailableTablesRequest;
use App\Http\Requests\Customer\UpdateReservationRequest;
use App\Http\Resources\Customer\ReservationResource;
use App\Http\Resources\Customer\TableResource;
use App\Services\Customer\ReservationService;
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
     * Find available tables by date, time, and guest count (BKG-01).
     *
     * @param FindAvailableTablesRequest $request
     * @return JsonResponse
     */
    public function availableTables(FindAvailableTablesRequest $request): JsonResponse
    {
        $tables = $this->reservationService->findAvailableTables(
            $request->input('date'),
            $request->input('time'),
            (int) $request->input('guestCount')
        );

        return $this->successResponse(
            TableResource::collection($tables),
            'Available tables retrieved successfully',
            Response::HTTP_OK
        );
    }

    /**
     * Get reservation history for customer.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $account = $request->user();
        $reservations = $this->reservationService->getCustomerReservations(
            (int) $account->ma_tai_khoan,
            $request->all()
        );

        return $this->successResponse([
            'items' => ReservationResource::collection($reservations->items()),
            'pagination' => [
                'currentPage' => $reservations->currentPage(),
                'perPage' => $reservations->perPage(),
                'totalItems' => $reservations->total(),
                'totalPages' => $reservations->lastPage(),
            ],
        ], 'Reservations retrieved successfully', Response::HTTP_OK);
    }

    /**
     * Get reservation details by ID.
     *
     * @param Request $request
     * @param int $reservationId
     * @return JsonResponse
     */
    public function show(Request $request, int $reservationId): JsonResponse
    {
        $account = $request->user();
        $reservation = $this->reservationService->getCustomerReservation(
            (int) $account->ma_tai_khoan,
            $reservationId
        );

        return $this->successResponse(
            new ReservationResource($reservation),
            'Reservation details retrieved successfully',
            Response::HTTP_OK
        );
    }

    /**
     * Create a new reservation (BKG-02).
     *
     * @param CreateReservationRequest $request
     * @return JsonResponse
     */
    public function store(CreateReservationRequest $request): JsonResponse
    {
        $account = $request->user();
        $reservation = $this->reservationService->createReservation(
            (int) $account->ma_tai_khoan,
            $request->validated()
        );

        return $this->successResponse(
            new ReservationResource($reservation),
            'Reservation created successfully',
            Response::HTTP_CREATED
        );
    }

    /**
     * Modify an existing reservation (BKG-03).
     *
     * @param UpdateReservationRequest $request
     * @param int $reservationId
     * @return JsonResponse
     */
    public function update(UpdateReservationRequest $request, int $reservationId): JsonResponse
    {
        $account = $request->user();
        $reservation = $this->reservationService->updateReservation(
            (int) $account->ma_tai_khoan,
            $reservationId,
            $request->validated()
        );

        return $this->successResponse(
            new ReservationResource($reservation),
            'Reservation updated successfully',
            Response::HTTP_OK
        );
    }

    /**
     * Cancel an existing reservation (BKG-04).
     *
     * @param Request $request
     * @param int $reservationId
     * @return JsonResponse
     */
    public function cancel(Request $request, int $reservationId): JsonResponse
    {
        $account = $request->user();
        $reservation = $this->reservationService->cancelReservation(
            (int) $account->ma_tai_khoan,
            $reservationId
        );

        return $this->successResponse([
            'reservationId' => (int) $reservation->reservationId,
            'status' => 'CANCELLED',
        ], 'Reservation cancelled successfully', Response::HTTP_OK);
    }
}
