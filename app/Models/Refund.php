<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ghi nhận đã hoàn tiền (ứng dụng không tự chuyển tiền). */
class Refund extends Model
{
    protected $table = 'HOAN_TIEN';

    protected $primaryKey = 'ma_hoan_tien';

    public $timestamps = false;

    protected $fillable = [
        'ma_giao_dich_tien', 'ma_don_hang', 'so_tien', 'so_tien_tu_phan_bo', 'phuong_thuc', 'ma_chung_tu',
        'ly_do', 'ma_nguoi_thuc_hien', 'hoan_luc', 'ghi_nhan_luc', 'la_du_lieu_lich_su', 'loai_hoan', 'khoa_yeu_cau',
    ];

    /** Hoàn phần tiền nhà hàng đang nợ khách (trả dư, đơn hủy, đơn giảm sau thanh toán). */
    public const TYPE_REFUND = 'REFUND';

    /** Bồi hoàn/giảm trừ cho khách trên đơn còn hiệu lực: làm giảm khoản phải thu của đơn. */
    public const TYPE_COMPENSATION = 'COMPENSATION';

    protected $casts = [
        'so_tien' => 'decimal:2',
        'so_tien_tu_phan_bo' => 'decimal:2',
        'hoan_luc' => 'datetime',
        'ghi_nhan_luc' => 'datetime',
        'la_du_lieu_lich_su' => 'boolean',
    ];

    public function moneyTransaction(): BelongsTo
    {
        return $this->belongsTo(MoneyTransaction::class, 'ma_giao_dich_tien', 'ma_giao_dich_tien');
    }

    public function refundedBy(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'ma_nguoi_thuc_hien', 'ma_tai_khoan');
    }
}
