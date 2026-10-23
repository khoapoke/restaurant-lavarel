<?php

namespace App\Models;

use App\Enums\ApprovalStatus;
use App\Enums\ApprovalType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Yêu cầu STAFF gửi để ADMIN duyệt (YEU_CAU_DUYET). */
class ApprovalRequest extends Model
{
    protected $table = 'YEU_CAU_DUYET';

    protected $primaryKey = 'ma_yeu_cau_duyet';

    public $timestamps = false;

    protected $fillable = [
        'loai', 'trang_thai', 'doi_tuong', 'ma_doi_tuong', 'ma_phien', 'du_lieu', 'ly_do',
        'ma_nguoi_yeu_cau', 'ma_nguoi_duyet', 'ghi_chu_duyet', 'tao_luc', 'xu_ly_luc',
    ];

    protected $casts = [
        'loai' => ApprovalType::class,
        'trang_thai' => ApprovalStatus::class,
        'du_lieu' => 'array',
        'tao_luc' => 'datetime',
        'xu_ly_luc' => 'datetime',
    ];

    public function requester(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'ma_nguoi_yeu_cau', 'ma_tai_khoan');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'ma_nguoi_duyet', 'ma_tai_khoan');
    }

    public function serviceSession(): BelongsTo
    {
        return $this->belongsTo(ServiceSession::class, 'ma_phien', 'ma_phien');
    }
}
