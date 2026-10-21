<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\ServiceRequestStatus;
use App\Enums\ServiceRequestType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A live request from a table to the floor team (YEU_CAU_PHUC_VU).
 */
class ServiceRequest extends Model
{
    protected $table = 'YEU_CAU_PHUC_VU';

    protected $primaryKey = 'ma_yeu_cau';

    public $timestamps = false;

    protected $fillable = [
        'ma_phien',
        'ma_ban',
        'ma_luot',
        'loai',
        'ghi_chu',
        'phuong_thuc_mong_muon',
        'trang_thai',
        'ma_nhan_vien_xu_ly',
        'tao_luc',
        'nhan_luc',
        'hoan_thanh_luc',
        'huy_luc',
    ];

    protected $casts = [
        'loai' => ServiceRequestType::class,
        'trang_thai' => ServiceRequestStatus::class,
        'phuong_thuc_mong_muon' => PaymentMethod::class,
        'tao_luc' => 'datetime',
        'nhan_luc' => 'datetime',
        'hoan_thanh_luc' => 'datetime',
        'huy_luc' => 'datetime',
    ];

    public function serviceSession(): BelongsTo
    {
        return $this->belongsTo(ServiceSession::class, 'ma_phien', 'ma_phien');
    }

    public function diningTable(): BelongsTo
    {
        return $this->belongsTo(DiningTable::class, 'ma_ban', 'ma_ban');
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(TableVisit::class, 'ma_luot', 'ma_luot');
    }

    public function handledBy(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'ma_nhan_vien_xu_ly', 'ma_tai_khoan');
    }
}
