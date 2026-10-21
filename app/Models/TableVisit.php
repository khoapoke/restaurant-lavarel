<?php

namespace App\Models;

use App\Enums\TableVisitStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A guest device at a table (LUOT_KHACH_BAN). The plain visit token is only ever
 * returned once to the device; the database keeps its SHA-256 hash.
 */
class TableVisit extends Model
{
    protected $table = 'LUOT_KHACH_BAN';

    protected $primaryKey = 'ma_luot';

    public $timestamps = false;

    protected $fillable = [
        'ma_ban',
        'ma_phien',
        'token_hash',
        'ten_khach',
        'so_khach',
        'trang_thai',
        'ly_do_tu_choi',
        'ma_nhan_vien_xu_ly',
        'tao_luc',
        'xu_ly_luc',
        'truy_cap_luc',
    ];

    protected $hidden = ['token_hash'];

    protected $casts = [
        'so_khach' => 'integer',
        'trang_thai' => TableVisitStatus::class,
        'tao_luc' => 'datetime',
        'xu_ly_luc' => 'datetime',
        'truy_cap_luc' => 'datetime',
    ];

    public static function hashToken(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    public function diningTable(): BelongsTo
    {
        return $this->belongsTo(DiningTable::class, 'ma_ban', 'ma_ban');
    }

    public function serviceSession(): BelongsTo
    {
        return $this->belongsTo(ServiceSession::class, 'ma_phien', 'ma_phien');
    }

    public function handledBy(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'ma_nhan_vien_xu_ly', 'ma_tai_khoan');
    }

    public function serviceRequests(): HasMany
    {
        return $this->hasMany(ServiceRequest::class, 'ma_luot', 'ma_luot');
    }
}
