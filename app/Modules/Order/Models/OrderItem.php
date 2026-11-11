<?php

namespace App\Modules\Order\Models;

use App\Modules\Menu\Models\Dish;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class OrderItem extends Pivot
{
    protected $table = 'CHI_TIET_DON_HANG';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'ma_don_hang',
        'ma_mon_an',
        'so_luong',
        'don_gia',
    ];

    protected $casts = [
        'so_luong' => 'integer',
        'don_gia' => 'decimal:2',
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

