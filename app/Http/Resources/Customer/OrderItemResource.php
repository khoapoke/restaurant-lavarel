<?php

namespace App\Http\Resources\Customer;

use App\Enums\ItemVoidReason;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Dòng món cho khách/admin. Không trả ghi chú nội bộ của việc hủy món; chỉ nhãn lý do.
 */
class OrderItemResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $quantity = (int) ($this->pivot?->so_luong ?? $this->so_luong ?? 0);
        $billedRaw = $this->pivot?->so_luong_tinh_tien ?? $this->so_luong_tinh_tien ?? null;
        $billedQuantity = $billedRaw === null ? $quantity : (int) $billedRaw;
        $unitPrice = (float) ($this->pivot?->don_gia ?? $this->don_gia ?? $this->price ?? 0);
        $kitchenStatus = $this->pivot?->trang_thai_bep ?? $this->trang_thai_bep ?? null;
        $kitchenStatus = $kitchenStatus instanceof \BackedEnum ? $kitchenStatus->value : $kitchenStatus;
        $voidReason = $this->pivot?->loai_ly_do_huy ?? $this->loai_ly_do_huy ?? null;
        $voidReason = $voidReason instanceof ItemVoidReason ? $voidReason : ItemVoidReason::tryFrom((string) $voidReason);

        $lineId = $this->pivot?->ma_chi_tiet ?? $this->ma_chi_tiet ?? null;

        return [
            'itemId' => $lineId !== null ? (int) $lineId : null,
            'dishId' => (int) ($this->dishId ?? $this->ma_mon_an),
            'dishName' => $this->pivot?->ten_mon_snapshot ?? $this->dishName ?? $this->ten_mon_an,
            'quantity' => $quantity,
            'billedQuantity' => $billedQuantity,
            'unitPrice' => $unitPrice,
            'subTotal' => round($billedQuantity * $unitPrice, 2),
            'kitchenStatus' => $kitchenStatus,
            'cancelled' => in_array($kitchenStatus, ['CANCELLED', 'REJECTED'], true),
            'cancelReason' => $voidReason?->label(),
        ];
    }
}
