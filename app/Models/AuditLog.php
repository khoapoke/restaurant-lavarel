<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Nhật ký thao tác nhạy cảm (chỉ ghi thêm qua AuditLogger). */
class AuditLog extends Model
{
    protected $table = 'NHAT_KY_THAO_TAC';

    protected $primaryKey = 'ma_nhat_ky';

    public $timestamps = false;

    protected $fillable = ['ma_tai_khoan', 'vai_tro', 'hanh_dong', 'doi_tuong', 'ma_doi_tuong', 'ly_do', 'truoc', 'sau', 'tao_luc'];

    protected $casts = [
        'truoc' => 'array',
        'sau' => 'array',
        'tao_luc' => 'datetime',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'ma_tai_khoan', 'ma_tai_khoan');
    }
}
