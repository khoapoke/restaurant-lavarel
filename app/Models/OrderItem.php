<?php

namespace App\Models;

use App\Enums\ItemVoidReason;
use App\Enums\KitchenItemStatus;
use App\Enums\OrderLineType;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

class OrderItem extends Pivot
{
    protected $table = 'CHI_TIET_DON_HANG';

    protected $primaryKey = 'ma_chi_tiet';

    public $incrementing = true;

    public $timestamps = false;

    protected $fillable = [
        'ma_don_hang',
        'ma_mon_an',
        'ten_mon_snapshot',
        'so_luong',
        'don_gia',
        'ghi_chu',
        'trang_thai_bep',
        'client_request_id',
        'bep_cap_nhat_luc',
        'so_luong_tinh_tien',
        'loai_dong',
        'ma_chi_tiet_goc',
        'loai_ly_do_huy',
        'ly_do_huy',
        'mien_tien',
        'ma_nguoi_huy',
        'ma_nguoi_mien_tien',
        'huy_luc',
        'trang_thai_bep_truoc',
        'ma_nguoi_cap_nhat_bep',
    ];

    protected $casts = [
        'so_luong' => 'integer',
        'don_gia' => 'decimal:2',
        'trang_thai_bep' => KitchenItemStatus::class,
        'bep_cap_nhat_luc' => 'datetime',
        'so_luong_tinh_tien' => 'integer',
        'loai_dong' => OrderLineType::class,
        'loai_ly_do_huy' => ItemVoidReason::class,
        'mien_tien' => 'boolean',
        'huy_luc' => 'datetime',
    ];

    // Accessors / Mutators
    protected function orderId(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->ma_don_hang,
            set: fn ($value) => ['ma_don_hang' => $value]
        );
    }

    protected function dishId(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->ma_mon_an,
            set: fn ($value) => ['ma_mon_an' => $value]
        );
    }

    protected function quantity(): Attribute
    {
        return Attribute::make(
            get: fn () => (int) $this->so_luong,
            set: fn (int $value) => ['so_luong' => $value]
        );
    }

    protected function unitPrice(): Attribute
    {
        return Attribute::make(
            get: fn () => (float) $this->don_gia,
            set: fn ($value) => ['don_gia' => $value]
        );
    }

    // Relationships
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'ma_don_hang', 'ma_don_hang');
    }

    /** Số tiền dòng này đang tính vào đơn. */
    public function billedAmount(): float
    {
        return round((float) $this->don_gia * (int) ($this->so_luong_tinh_tien ?? $this->so_luong), 2);
    }

    public function original(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'ma_chi_tiet_goc', 'ma_chi_tiet');
    }

    public function tickets(): BelongsToMany
    {
        return $this->belongsToMany(
            KitchenTicket::class,
            'CHI_TIET_PHIEU_BEP',
            'ma_chi_tiet',
            'ma_phieu_bep',
            'ma_chi_tiet',
            'ma_phieu_bep'
        );
    }

    public function dish(): BelongsTo
    {
        return $this->belongsTo(Dish::class, 'ma_mon_an', 'ma_mon_an');
    }
}
