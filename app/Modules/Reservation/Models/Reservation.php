<?php

namespace App\Modules\Reservation\Models;

use App\Modules\Auth\Models\Account;
use App\Modules\Core\Enums\ReservationStatus;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        'tien_coc',
        'da_thanh_toan_coc',
        'han_chot_coc',
    ];

    protected $casts = [
        'thoi_gian' => 'datetime',
        'so_luong_khach' => 'integer',
        'trang_thai' => ReservationStatus::class,
        'tien_coc' => 'float',
        'da_thanh_toan_coc' => 'boolean',
        'han_chot_coc' => 'datetime',
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

    protected function depositAmount(): Attribute
    {
        return Attribute::make(
            get: fn () => (float) ($this->tien_coc ?? 0),
            set: fn ($value) => ['tien_coc' => $value]
        );
    }

    protected function isDepositPaid(): Attribute
    {
        return Attribute::make(
            get: fn () => (bool) $this->da_thanh_toan_coc,
            set: fn ($value) => ['da_thanh_toan_coc' => $value]
        );
    }

    protected function depositDeadline(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->han_chot_coc,
            set: fn ($value) => ['han_chot_coc' => $value]
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
}

