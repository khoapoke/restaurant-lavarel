<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\ExtendReservationRequest;
use App\Http\Requests\Staff\ReasonOnlyRequest;
use App\Http\Resources\Admin\AdminReservationResource;
use App\Models\Reservation;
use App\Services\Admin\ReservationService;
use App\Services\Staff\ReservationDeskService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

/** Khách đặt bàn đến trễ trên sơ đồ POS: gia hạn giữ chỗ hoặc đánh dấu không đến (STAFF). */
class ReservationDeskController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ReservationDeskService $desk,
        private readonly ReservationService $reservations,
    ) {}

    public function extend(ExtendReservationRequest $request, Reservation $reservation): JsonResponse
    {
        $this->desk->extend($reservation, $request->user(), (int) $request->validated('minutes'), $request->validated('reason'));

        return $this->successResponse(new AdminReservationResource($this->reservations->getReservation((int) $reservation->ma_dat_ban)), 'Reservation hold extended');
    }

    public function noShow(ReasonOnlyRequest $request, Reservation $reservation): JsonResponse
    {
        $this->desk->markNoShow($reservation, $request->user(), $request->validated('note'));

        return $this->successResponse(new AdminReservationResource($this->reservations->getReservation((int) $reservation->ma_dat_ban)), 'Reservation marked as no-show');
    }
}
