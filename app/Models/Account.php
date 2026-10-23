<?php

namespace App\Models;

use App\Enums\AccountStatus;
use App\Enums\RoleType;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class Account extends Authenticatable
{
    use HasApiTokens;

    protected $table = 'TAI_KHOAN';

    protected $primaryKey = 'ma_tai_khoan';

    public $timestamps = false;

    protected $fillable = [
        'ten_dang_nhap',
        'mat_khau',
        'email',
        'trang_thai',
        'ma_nguoi_dung',
        'ma_vai_tro',
    ];

    protected $hidden = [
        'mat_khau',
    ];

    protected $casts = [
        'trang_thai' => AccountStatus::class,
    ];

    // Bắt buộc ghi đè để Laravel Auth/Sanctum nhận diện cột mật khẩu 'mat_khau'
    public function getAuthPasswordName(): string
    {
        return 'mat_khau';
    }

    public function getAuthPassword(): string
    {
        return $this->mat_khau;
    }

    // Accessors / Mutators
    protected function accountId(): Attribute
    {
        return Attribute::make(get: fn () => $this->ma_tai_khoan);
    }

    protected function username(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->ten_dang_nhap,
            set: fn (string $value) => ['ten_dang_nhap' => $value]
        );
    }

    protected function status(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->trang_thai,
            set: fn (AccountStatus|string $value) => ['trang_thai' => $value]
        );
    }

    /** ADMIN (vai trò 1) — quản lý được duyệt ngoại lệ, miễn tiền, hoàn tiền, thất thu. */
    public function isAdmin(): bool
    {
        return (int) $this->ma_vai_tro === RoleType::ADMIN->id();
    }

    public function roleCode(): string
    {
        return match ((int) $this->ma_vai_tro) {
            RoleType::ADMIN->id() => 'ADMIN',
            RoleType::STAFF->id() => 'STAFF',
            default => 'CUSTOMER',
        };
    }

    public function displayName(): string
    {
        return $this->userProfile?->ho_ten ?? $this->ten_dang_nhap;
    }

    // Relationships
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'ma_vai_tro', 'ma_vai_tro');
    }

    public function userProfile(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'ma_nguoi_dung', 'ma_nguoi_dung');
    }

    public function cart(): HasOne
    {
        return $this->hasOne(Cart::class, 'ma_tai_khoan', 'ma_tai_khoan');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'ma_tai_khoan', 'ma_tai_khoan');
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class, 'ma_tai_khoan', 'ma_tai_khoan');
    }

    public function serviceSessions(): HasMany
    {
        return $this->hasMany(ServiceSession::class, 'ma_nhan_vien', 'ma_tai_khoan');
    }
}
