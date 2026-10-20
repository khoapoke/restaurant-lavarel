<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class CartItem extends Pivot
{
    protected $table = 'MON_AN_GIO_HANG';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'ma_gio_hang',
        'ma_mon_an',
        'so_luong',
    ];

    protected $casts = [
        'so_luong' => 'integer',
    ];

    // Accessors / Mutators
    protected function cartId(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->ma_gio_hang,
            set: fn ($value) => ['ma_gio_hang' => $value]
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

    // Relationships
    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class, 'ma_gio_hang', 'ma_gio_hang');
    }

    public function dish(): BelongsTo
    {
        return $this->belongsTo(Dish::class, 'ma_mon_an', 'ma_mon_an');
    }
}
