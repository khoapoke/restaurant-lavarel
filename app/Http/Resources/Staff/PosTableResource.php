<?php

namespace App\Http\Resources\Staff;

use App\Enums\KitchenItemStatus;
use App\Enums\KitchenTicketSource;
use App\Enums\OrderStatus;
use App\Enums\PosSessionStatus;
use App\Enums\ServiceRequestStatus;
use App\Models\OrderItem;
use App\Models\ServiceSession;
use App\Services\Shared\ReservationSchedule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PosTableResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $activeSession = $this->serviceSessions
            ->first(fn ($session) => in_array($session->trang_thai, [PosSessionStatus::OPEN, PosSessionStatus::AWAITING_PAYMENT], true));
        $schedule = app(ReservationSchedule::class);
        $upcomingReservation = $this->relationLoaded('reservations')
            // Bỏ lượt đã quá mốc tự động không đến (lệnh quét sẽ đóng), ưu tiên lượt sớm nhất.
            ? $this->reservations->first(fn ($reservation) => $schedule->autoNoShowAt($reservation)->isFuture())
            : null;
        $nextReservation = $activeSession ? $schedule->nextReservation((int) $this->ma_ban, now()) : null;
        $pendingCheckIn = $this->relationLoaded('visits') ? $this->visits->first() : null;

        return [
            'tableId' => (int) $this->ma_ban,
            'tableName' => $this->ten_ban,
            'capacity' => (int) $this->suc_chua,
            'status' => $this->trang_thai->name,
            'location' => $this->vi_tri,
            'activeSession' => $activeSession ? [
                'sessionId' => (int) $activeSession->ma_phien,
                'status' => $activeSession->trang_thai->name,
                'guestCount' => (int) $activeSession->so_luong_khach,
                'openedAt' => $activeSession->mo_luc?->toIso8601String(),
                'version' => (int) $activeSession->phien_ban,
                'staffName' => $activeSession->staff?->userProfile?->ho_ten
                    ?? $activeSession->staff?->ten_dang_nhap,
                'reservationId' => $activeSession->ma_dat_ban ? (int) $activeSession->ma_dat_ban : null,
                'joinCode' => $activeSession->ma_tham_gia,
                'joinCodeLocked' => $activeSession->isJoinCodeLocked(),
                'guestName' => $activeSession->reservation?->account?->userProfile?->ho_ten,
                'tables' => $activeSession->relationLoaded('tables') ? $activeSession->tables->pluck('ten_ban')->values() : [],
                'expectedEndAt' => $activeSession->du_kien_ket_thuc_luc?->toIso8601String(),
                'overdue' => (bool) $activeSession->du_kien_ket_thuc_luc?->isPast(),
                'nextReservation' => $nextReservation ? [
                    'reservationId' => (int) $nextReservation->ma_dat_ban,
                    'reservationTime' => $nextReservation->thoi_gian->format('Y-m-d\TH:i:s'),
                    'mustLeaveBy' => $nextReservation->thoi_gian->copy()->subMinutes($schedule->cleaningMinutes())->toIso8601String(),
                    'atRisk' => $schedule->sessionBusyUntil($activeSession)->greaterThan($nextReservation->thoi_gian->copy()->subMinutes($schedule->cleaningMinutes())),
                ] : null,
                'live' => $this->liveStats($activeSession),
            ] : null,
            'pendingCheckIn' => $pendingCheckIn ? [
                'visitId' => (int) $pendingCheckIn->ma_luot,
                'guestName' => $pendingCheckIn->ten_khach,
                'guestCount' => $pendingCheckIn->so_khach,
                'requestedAt' => $pendingCheckIn->tao_luc?->toIso8601String(),
            ] : null,
            'upcomingReservation' => $upcomingReservation ? [
                'reservationId' => (int) $upcomingReservation->ma_dat_ban,
                'reservationTime' => $upcomingReservation->thoi_gian?->format('Y-m-d\TH:i:s'),
                'guestCount' => (int) $upcomingReservation->so_luong_khach,
                'note' => $upcomingReservation->ghi_chu,
                'customerName' => $upcomingReservation->account?->userProfile?->ho_ten
                    ?? $upcomingReservation->account?->ten_dang_nhap,
                'customerPhone' => $upcomingReservation->account?->userProfile?->so_dien_thoai,
                'isWithinSeatingWindow' => $schedule->withinSeatingWindow($upcomingReservation),
                'seatingOpensAt' => $schedule->seatingOpensAt($upcomingReservation)->toIso8601String(),
                'holdUntil' => $schedule->holdUntil($upcomingReservation)->toIso8601String(),
                'latestExtension' => $schedule->latestExtension($upcomingReservation)->toIso8601String(),
                'autoNoShowAt' => $schedule->autoNoShowAt($upcomingReservation)->toIso8601String(),
                'minutesLate' => $upcomingReservation->thoi_gian->isPast() ? (int) floor($upcomingReservation->thoi_gian->diffInMinutes(now())) : 0,
                'holdExpired' => $schedule->holdExpired($upcomingReservation),
                'canExtend' => ! $schedule->holdExpired($upcomingReservation) && $schedule->holdUntil($upcomingReservation)->lessThan($schedule->latestExtension($upcomingReservation)) && $upcomingReservation->thoi_gian->copy()->subMinutes(15)->isPast(),
                'canMarkNoShow' => $schedule->holdExpired($upcomingReservation),
                'extension' => $upcomingReservation->gia_han_den ? [
                    'until' => $upcomingReservation->gia_han_den->toIso8601String(),
                    'reason' => $upcomingReservation->ly_do_gia_han,
                    'by' => $upcomingReservation->extendedBy?->displayName(),
                ] : null,
            ] : null,
        ];
    }

    /**
     * Operational signals for the live floor card (only when the floor query loaded them).
     *
     * @return array<string, mixed>|null
     */
    private function liveStats(ServiceSession $session): ?array
    {
        if (! $session->relationLoaded('orders')) {
            return null;
        }

        $lateMinutes = (int) config('dining.kitchen.late_minutes', 20);
        $items = $session->orders
            ->reject(fn ($order) => $order->trang_thai === OrderStatus::CANCELLED)
            ->flatMap(fn ($order) => $order->items)
            ->reject(fn (OrderItem $item) => in_array($item->trang_thai_bep, [KitchenItemStatus::CANCELLED, KitchenItemStatus::REJECTED], true));
        $working = $items->filter(fn (OrderItem $item) => in_array($item->trang_thai_bep, [KitchenItemStatus::SENT, KitchenItemStatus::QUEUED, KitchenItemStatus::PREPARING], true));
        $sentAt = fn (OrderItem $item) => $item->tickets->first()?->gui_luc;
        $late = $working->filter(fn (OrderItem $item) => $sentAt($item)?->lt(now()->subMinutes($lateMinutes)));
        $requests = $session->relationLoaded('serviceRequests') ? $session->serviceRequests : collect();

        return [
            'subTotal' => round((float) $items->sum(fn (OrderItem $item) => $item->billedAmount()), 2),
            'itemCount' => (int) $items->where('trang_thai_bep', '!=', KitchenItemStatus::DRAFT)->sum('so_luong'),
            'draftCount' => (int) $items->where('trang_thai_bep', KitchenItemStatus::DRAFT)->sum('so_luong'),
            'pendingCount' => (int) $working->sum('so_luong'),
            'readyCount' => (int) $items->where('trang_thai_bep', KitchenItemStatus::READY)->sum('so_luong'),
            'lateCount' => (int) $late->sum('so_luong'),
            'oldestPendingSentAt' => $working->map($sentAt)->filter()->min()?->toIso8601String(),
            'qrRounds' => $items->flatMap(fn (OrderItem $item) => $item->tickets)
                ->filter(fn ($ticket) => $ticket->nguon === KitchenTicketSource::TABLE_QR)
                ->unique('ma_phieu_bep')
                ->count(),
            'openRequests' => $requests->count(),
            'newRequests' => $requests->where('trang_thai', ServiceRequestStatus::NEW)->count(),
            'paymentRequested' => $requests->contains(fn ($request) => $request->loai->isPriority()),
            'connectedGuests' => (int) ($session->connected_guests_count ?? 0),
        ];
    }
}
