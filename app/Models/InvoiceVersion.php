<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use Illuminate\Database\Eloquent\Model;

/** Lịch sử phiên bản hóa đơn: mỗi lần lập lại sau khi hủy là một phiên bản mới. */
class InvoiceVersion extends Model
{
    protected $table = 'HOA_DON_PHIEN_BAN';

    protected $primaryKey = 'ma_phien_ban_hoa_don';

    public $timestamps = false;

    protected $fillable = ['ma_hoa_don', 'phien_ban', 'tong_thanh_toan', 'trang_thai', 'tao_luc', 'huy_luc', 'ly_do_huy', 'ma_nguoi_huy'];

    protected $casts = [
        'phien_ban' => 'integer',
        'tong_thanh_toan' => 'decimal:2',
        'trang_thai' => InvoiceStatus::class,
        'tao_luc' => 'datetime',
        'huy_luc' => 'datetime',
    ];
}
