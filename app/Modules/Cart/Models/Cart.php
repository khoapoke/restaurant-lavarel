<?php

namespace App\Modules\Cart\Models;

use App\Modules\Auth\Models\Account;
use App\Modules\Menu\Models\Dish;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cart extends Model
{
    protected $table = 'GIO_HANG';
    protected $primaryKey = 'ma_gio_hang';
    public $timestamps = false;

    protected $fillable = [
        'ngay_tao',
        'ma_tai_khoan',
    ];

    protected $casts = [
        'ngay_tao' => 'datetime',
    ];

    // Accessors / Mutators
    protected function cartId(): Attribute
    {
        return Attribute::make(get: fn () => $this->ma_gio_hang);
    }

    protected function createdAt(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->ngay_tao,
            set: fn ($value) => ['ngay_tao' => $value]
        );
    }

    protected function accountId(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->ma_tai_khoan,
            set: fn ($value) => ['ma_tai_khoan' => $value]
        );
    }

    // Relationships
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'ma_tai_khoan', 'ma_tai_khoan');
    }

    public function dishes(): BelongsToMany
    {
        return $this->belongsToMany(
            Dish::class,
            'MON_AN_GIO_HANG',
            'ma_gio_hang',
            'ma_mon_an',
            'ma_gio_hang',
            'ma_mon_an'
        )->using(CartItem::class)->withPivot(['so_luong']);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class, 'ma_gio_hang', 'ma_gio_hang');
    }
}

