<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UserProfile extends Model
{
    protected $table = 'NGUOI_DUNG';
    protected $primaryKey = 'ma_nguoi_dung';
    public $timestamps = false;

    protected $fillable = [
        'ho_ten',
        'ngay_sinh',
        'dia_chi',
        'so_dien_thoai',
        'di_ung',
        'che_do_an',
        'so_dia_chi',
    ];

    protected $casts = [
        'ngay_sinh' => 'date',
        'di_ung' => 'array',
        'che_do_an' => 'array',
        'so_dia_chi' => 'array',
    ];

    // Accessors / Mutators
    protected function userId(): Attribute
    {
        return Attribute::make(get: fn () => $this->ma_nguoi_dung);
    }

    protected function fullName(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->ho_ten,
            set: fn (?string $value) => ['ho_ten' => $value]
        );
    }

    protected function birthDate(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->ngay_sinh,
            set: fn ($value) => ['ngay_sinh' => $value]
        );
    }

    protected function address(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->dia_chi,
            set: fn (?string $value) => ['dia_chi' => $value]
        );
    }

    protected function phoneNumber(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->so_dien_thoai,
            set: fn (?string $value) => ['so_dien_thoai' => $value]
        );
    }

    protected function allergies(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->di_ung ?? [],
            set: fn ($value) => ['di_ung' => is_array($value) ? json_encode($value) : $value]
        );
    }

    protected function dietaryPreferences(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->che_do_an ?? [],
            set: fn ($value) => ['che_do_an' => is_array($value) ? json_encode($value) : $value]
        );
    }

    protected function savedAddresses(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->so_dia_chi ?? [],
            set: fn ($value) => ['so_dia_chi' => is_array($value) ? json_encode($value) : $value]
        );
    }

    // Relationships
    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class, 'ma_nguoi_dung', 'ma_nguoi_dung');
    }
}
