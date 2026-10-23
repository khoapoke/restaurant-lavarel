<?php

namespace App\Models;

use App\Enums\ReservationStatus;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Reservation extends Model
{
    protected $table = 'DAT_BAN';

    protected $primaryKey = 'ma_dat_ban';

    public $timestamps = false;

    protected $fillable = [
        'thoi_gian',
        'so_luong_khach',
        'trang_thai',
        'ghi_chu',
        'ma_tai_khoan',
        'ma_ban',
        'ghi_chu_noi_bo',
        'gia_han_den',
        'ly_do_gia_han',
        'ma_nguoi_gia_han',
        'xu_ly_luc',
        'ma_nguoi_xu_ly',
        'ly_do_xu_ly',
    ];

    protected $casts = [
        'thoi_gian' => 'datetime',
        'so_luong_khach' => 'integer',
        'trang_thai' => ReservationStatus::class,
        'gia_han_den' => 'datetime',
        'xu_ly_luc' => 'datetime',
    ];

    // Accessors / Mutators
    protected function reservationId(): Attribute
    {
        return Attribute::make(get: fn () => $this->ma_dat_ban);
    }

    protected function reservationTime(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->thoi_gian,
            set: fn ($value) => ['thoi_gian' => $value]
        );
    }

    protected function guestCount(): Attribute
    {
        return Attribute::make(
            get: fn () => (int) $this->so_luong_khach,
            set: fn (int $value) => ['so_luong_khach' => $value]
        );
    }

    protected function status(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->trang_thai,
            set: fn (ReservationStatus|string $value) => ['trang_thai' => $value]
        );
    }

    protected function note(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->ghi_chu,
            set: fn (?string $value) => ['ghi_chu' => $value]
        );
    }

    protected function accountId(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->ma_tai_khoan,
            set: fn ($value) => ['ma_tai_khoan' => $value]
        );
    }

    protected function tableId(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->ma_ban,
            set: fn ($value) => ['ma_ban' => $value]
        );
    }

    // Relationships
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'ma_tai_khoan', 'ma_tai_khoan');
    }

    public function diningTable(): BelongsTo
    {
        return $this->belongsTo(DiningTable::class, 'ma_ban', 'ma_ban');
    }

    public function handledBy(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'ma_nguoi_xu_ly', 'ma_tai_khoan');
    }

    public function extendedBy(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'ma_nguoi_gia_han', 'ma_tai_khoan');
    }

    public function serviceSessions(): HasMany
    {
        return $this->hasMany(ServiceSession::class, 'ma_dat_ban', 'ma_dat_ban');
    }
}
