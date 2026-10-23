<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Invoice extends Model
{
    protected $table = 'HOA_DON';

    protected $primaryKey = 'ma_hoa_don';

    public $timestamps = false;

    protected $fillable = [
        'ma_phien', 'ma_don_hang', 'tam_tinh', 'giam_gia', 'tong_thanh_toan',
        'trang_thai', 'tao_luc', 'thanh_toan_luc', 'phien_ban', 'so_tien_that_thu',
    ];

    protected $casts = [
        'tam_tinh' => 'decimal:2',
        'giam_gia' => 'decimal:2',
        'tong_thanh_toan' => 'decimal:2',
        'trang_thai' => InvoiceStatus::class,
        'tao_luc' => 'datetime',
        'thanh_toan_luc' => 'datetime',
        'phien_ban' => 'integer',
        'so_tien_that_thu' => 'decimal:2',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(ServiceSession::class, 'ma_phien', 'ma_phien');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'ma_don_hang', 'ma_don_hang');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(InvoiceVersion::class, 'ma_hoa_don', 'ma_hoa_don')->orderBy('phien_ban');
    }

    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class, 'ma_hoa_don', 'ma_hoa_don');
    }
}
