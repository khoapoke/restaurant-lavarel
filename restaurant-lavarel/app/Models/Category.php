<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    protected $table = 'DANH_MUC';
    protected $primaryKey = 'ma_danh_muc';
    public $timestamps = false;

    protected $fillable = [
        'ten_danh_muc',
        'mo_ta',
        'thu_tu_hien_thi',
        'hien_thi',
        'da_xoa',
    ];

    protected $casts = [
        'thu_tu_hien_thi' => 'integer',
        'hien_thi' => 'boolean',
        'da_xoa' => 'boolean',
    ];

    // Accessors / Mutators
    protected function categoryId(): Attribute
    {
        return Attribute::make(get: fn () => $this->ma_danh_muc);
    }

    protected function categoryName(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->ten_danh_muc,
            set: fn (string $value) => ['ten_danh_muc' => $value]
        );
    }

    protected function description(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->mo_ta,
            set: fn (?string $value) => ['mo_ta' => $value]
        );
    }

    protected function displayOrder(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->thu_tu_hien_thi ?? 0,
            set: fn ($value) => ['thu_tu_hien_thi' => (int) $value]
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

    // Relationships
    public function dishes(): HasMany
    {
        return $this->hasMany(Dish::class, 'ma_danh_muc', 'ma_danh_muc');
    }
}
