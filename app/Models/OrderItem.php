<?php

namespace App\Models;

use App\Enums\KitchenItemStatus;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
    ];

    protected $casts = [
        'so_luong' => 'integer',
        'don_gia' => 'decimal:2',
        'trang_thai_bep' => KitchenItemStatus::class,
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

    public function dish(): BelongsTo
    {
        return $this->belongsTo(Dish::class, 'ma_mon_an', 'ma_mon_an');
    }
}
