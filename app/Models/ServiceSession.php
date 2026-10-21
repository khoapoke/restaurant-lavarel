<?php

namespace App\Models;

use App\Enums\PosSessionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ServiceSession extends Model
{
    protected $table = 'PHIEN_PHUC_VU';

    protected $primaryKey = 'ma_phien';

    public $timestamps = false;

    protected $fillable = [
        'ma_nhan_vien',
        'ma_dat_ban',
        'so_luong_khach',
        'trang_thai',
        'mo_luc',
        'dong_luc',
        'ly_do_huy',
        'phien_ban',
        'idempotency_key',
        'request_hash',
    ];

    /** The table code is issued by the system only (ADR-022), never mass assigned. */
    protected $hidden = ['ma_tham_gia'];

    protected $casts = [
        'so_lan_sai_ma' => 'integer',
        'so_luong_khach' => 'integer',
        'trang_thai' => PosSessionStatus::class,
        'mo_luc' => 'datetime',
        'dong_luc' => 'datetime',
        'phien_ban' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (ServiceSession $session): void {
            if (empty($session->ma_tham_gia)) {
                $session->ma_tham_gia = self::newJoinCode();
            }
        });
    }

    public static function newJoinCode(): string
    {
        return str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
    }

    /** Issue a fresh table code and clear the failed-attempt lock. */
    public function rotateJoinCode(): string
    {
        $this->forceFill(['ma_tham_gia' => self::newJoinCode(), 'so_lan_sai_ma' => 0])->save();

        return $this->ma_tham_gia;
    }

    public function isJoinCodeLocked(): bool
    {
        return $this->so_lan_sai_ma >= (int) config('dining.join_code.max_failures', 5);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'ma_nhan_vien', 'ma_tai_khoan');
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class, 'ma_dat_ban', 'ma_dat_ban');
    }

    public function tables(): BelongsToMany
    {
        return $this->belongsToMany(
            DiningTable::class,
            'PHIEN_BAN',
            'ma_phien',
            'ma_ban',
            'ma_phien',
            'ma_ban'
        )->withPivot('gan_luc');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'ma_phien', 'ma_phien');
    }

    public function visits(): HasMany
    {
        return $this->hasMany(TableVisit::class, 'ma_phien', 'ma_phien');
    }

    public function serviceRequests(): HasMany
    {
        return $this->hasMany(ServiceRequest::class, 'ma_phien', 'ma_phien');
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class, 'ma_phien', 'ma_phien');
    }
}
