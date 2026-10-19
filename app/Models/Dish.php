<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Dish extends Model
{
    protected $table = 'MON_AN';
    protected $primaryKey = 'ma_mon_an';
    public $timestamps = false;

    protected $fillable = [
        'ten_mon_an',
        'gia_tien',
        'mo_ta',
        'hinh_anh',
        'ma_danh_muc',
        'nguyen_lieu',
        'chat_di_ung',
        'the_thoi_tiet',
        'con_ban',
        'hien_thi',
        'da_xoa',
    ];

    protected $casts = [
        'gia_tien' => 'decimal:2',
        'nguyen_lieu' => 'array',
        'chat_di_ung' => 'array',
        'the_thoi_tiet' => 'array',
        'con_ban' => 'boolean',
        'hien_thi' => 'boolean',
        'da_xoa' => 'boolean',
    ];

    // Accessors / Mutators
    protected function dishId(): Attribute
    {
        return Attribute::make(get: fn () => $this->ma_mon_an);
    }

    protected function dishName(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->ten_mon_an,
            set: fn (string $value) => ['ten_mon_an' => $value]
        );
    }

    protected function price(): Attribute
    {
        return Attribute::make(
            get: fn () => (float) $this->gia_tien,
            set: fn ($value) => ['gia_tien' => $value]
        );
    }

    protected function description(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->mo_ta,
            set: fn (?string $value) => ['mo_ta' => $value]
        );
    }

    protected function imageUrl(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->hinh_anh,
            set: fn (?string $value) => ['hinh_anh' => $value]
        );
    }

    protected function categoryId(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->ma_danh_muc,
            set: fn ($value) => ['ma_danh_muc' => $value]
        );
    }

    protected function ingredients(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->nguyen_lieu ?? [],
            set: fn ($value) => ['nguyen_lieu' => $value]
        );
    }

    protected function allergens(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->chat_di_ung ?? [],
            set: fn ($value) => ['chat_di_ung' => $value]
        );
    }

    protected function weatherTags(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->the_thoi_tiet ?? [],
            set: fn ($value) => ['the_thoi_tiet' => $value]
        );
    }

    protected function isAvailable(): Attribute
    {
        return Attribute::make(
            get: fn () => (bool) ($this->con_ban ?? true),
            set: fn ($value) => ['con_ban' => (bool) $value]
        );
    }

    protected function isVisible(): Attribute
    {
        return Attribute::make(
            get: fn () => (bool) ($this->hien_thi ?? true),
            set: fn ($value) => ['hien_thi' => (bool) $value]
        );
    }

    protected function isDeleted(): Attribute
    {
        return Attribute::make(
            get: fn () => (bool) ($this->da_xoa ?? false),
            set: fn ($value) => ['da_xoa' => (bool) $value]
        );
    }

    // Scopes
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('da_xoa', false)
            ->where('hien_thi', true)
            ->where('con_ban', true);
    }

    // Relationships
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'ma_danh_muc', 'ma_danh_muc');
    }

    public function carts(): BelongsToMany
    {
        return $this->belongsToMany(
            Cart::class,
            'MON_AN_GIO_HANG',
            'ma_mon_an',
            'ma_gio_hang',
            'ma_mon_an',
            'ma_gio_hang'
        )->using(CartItem::class)->withPivot(['so_luong']);
    }

    public function orders(): BelongsToMany
    {
        return $this->belongsToMany(
            Order::class,
            'CHI_TIET_DON_HANG',
            'ma_mon_an',
            'ma_don_hang',
            'ma_mon_an',
            'ma_don_hang'
        )->using(OrderItem::class)->withPivot(['so_luong', 'don_gia']);
    }
}
