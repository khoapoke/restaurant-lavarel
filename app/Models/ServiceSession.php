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
        'so_luong_khach',
        'trang_thai',
        'mo_luc',
        'dong_luc',
        'phien_ban',
        'idempotency_key',
        'request_hash',
    ];

    protected $casts = [
        'so_luong_khach' => 'integer',
        'trang_thai' => PosSessionStatus::class,
        'mo_luc' => 'datetime',
        'dong_luc' => 'datetime',
        'phien_ban' => 'integer',
    ];

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'ma_nhan_vien', 'ma_tai_khoan');
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

    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class, 'ma_phien', 'ma_phien');
    }
}
