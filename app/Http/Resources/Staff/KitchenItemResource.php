<?php

namespace App\Http\Resources\Staff;

use App\Enums\KitchenTicketSource;
use App\Enums\OrderChannel;
use App\Services\Staff\KitchenService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class KitchenItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $session = $this->order?->serviceSession;
        $ticket = $this->relationLoaded('tickets') ? $this->tickets->first() : null;
        $isOnline = $this->order && $this->order->kenh !== OrderChannel::POS;
        $sentAt = $ticket?->gui_luc ?? $this->order?->ngay_dat_hang;

        return [
            'itemId' => (int) $this->ma_chi_tiet,
            'orderId' => (int) $this->ma_don_hang,
            'sessionId' => $session ? (int) $session->ma_phien : null,
            'tableNames' => $session?->tables?->pluck('ten_ban')->values() ?? [],
            'dishName' => $this->ten_mon_snapshot,
            'quantity' => (int) $this->so_luong,
            'note' => $this->ghi_chu,
            'status' => $this->trang_thai_bep->name,
            'imageUrl' => $this->dish?->hinh_anh,
            'orderedAt' => $this->order?->ngay_dat_hang?->toIso8601String(),
            'orderStatus' => $this->order?->trang_thai?->name,
            'channel' => $this->order?->kenh?->name,
            'fulfillmentType' => $this->order?->loai_phuc_vu?->name,
            'orderNote' => $this->order?->ghi_chu,
            'recipientName' => $this->order?->ten_nguoi_nhan,
            'scheduledAt' => $this->order?->thoi_gian_nhan_du_kien?->toIso8601String(),
            'source' => $isOnline ? 'ONLINE' : ($ticket?->nguon ?? KitchenTicketSource::POS)->name,
            'ticketId' => $ticket ? (int) $ticket->ma_phieu_bep : null,
            'sentAt' => $sentAt?->toIso8601String(),
            'statusChangedAt' => $this->bep_cap_nhat_luc?->toIso8601String(),
            'previousStatus' => $this->trang_thai_bep_truoc,
            'canUndo' => app(KitchenService::class)->canUndo($this->resource),
            'undoUntil' => $this->bep_cap_nhat_luc?->copy()->addSeconds((int) config('restaurant.kitchen.undo_window_seconds', 120))->toIso8601String(),
            'lineType' => $this->loai_dong?->name ?? 'NORMAL',
            'originalItemId' => $this->ma_chi_tiet_goc ? (int) $this->ma_chi_tiet_goc : null,
            'voidReason' => $this->loai_ly_do_huy?->name,
            'voidNote' => $this->ly_do_huy,
            'isAsap' => $isOnline && ! $this->order?->thoi_gian_nhan_du_kien,
        ];
    }
}
