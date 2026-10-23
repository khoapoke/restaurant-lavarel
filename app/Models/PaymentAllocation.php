<?php

namespace App\Models;

use App\Enums\AllocationType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Phần tiền của một giao dịch được áp vào đơn (âm khi hoàn từ phần đã áp). */
class PaymentAllocation extends Model
{
    protected $table = 'PHAN_BO_THANH_TOAN';

    protected $primaryKey = 'ma_phan_bo';

    public $timestamps = false;

    protected $fillable = [
        'ma_giao_dich_tien', 'ma_don_hang', 'ma_hoa_don', 'phien_ban_hoa_don', 'so_tien', 'loai',
        'ma_hoan_tien', 'ma_nguoi_thuc_hien', 'tao_luc',
    ];

    protected $casts = [
        'so_tien' => 'decimal:2',
        'loai' => AllocationType::class,
        'phien_ban_hoa_don' => 'integer',
        'tao_luc' => 'datetime',
    ];

    public function moneyTransaction(): BelongsTo
    {
        return $this->belongsTo(MoneyTransaction::class, 'ma_giao_dich_tien', 'ma_giao_dich_tien');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'ma_don_hang', 'ma_don_hang');
    }
}
