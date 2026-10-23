<?php

namespace App\Models;

use App\Enums\MoneyFlag;
use App\Enums\MoneySource;
use App\Enums\PaymentMethod;
use App\Enums\ReconciliationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Một khoản tiền thực nhận (GIAO_DICH_TIEN). Không bao giờ bị ghi đè: phân bổ và hoàn
 * tiền nằm ở bảng riêng.
 *
 * so_tien_da_phan_bo = tổng phân bổ ròng (đã trừ phần hoàn từ tiền đã áp vào đơn).
 * Chưa phân bổ = so_tien − so_tien_da_phan_bo − so_tien_da_hoan.
 */
class MoneyTransaction extends Model
{
    protected $table = 'GIAO_DICH_TIEN';

    protected $primaryKey = 'ma_giao_dich_tien';

    public $timestamps = false;

    protected $fillable = [
        'nguon', 'nha_cung_cap', 'tai_khoan_nhan', 'ma_tham_chieu', 'khoa_duy_nhat', 'idempotency_key',
        'phuong_thuc', 'so_tien', 'so_tien_da_phan_bo', 'so_tien_da_hoan', 'noi_dung',
        'ma_don_hang_muc_tieu', 'ma_hoa_don_muc_tieu', 'phien_ban_hoa_don', 'trang_thai_doi_soat',
        'canh_bao', 'ghi_chu_doi_soat', 'ma_nguoi_doi_soat', 'doi_soat_luc', 'nhan_luc', 'ghi_nhan_luc',
        'ma_nguoi_ghi_nhan', 'du_lieu_goc', 'la_du_lieu_lich_su',
    ];

    protected $casts = [
        'nguon' => MoneySource::class,
        'phuong_thuc' => PaymentMethod::class,
        'trang_thai_doi_soat' => ReconciliationStatus::class,
        'canh_bao' => MoneyFlag::class,
        'so_tien' => 'decimal:2',
        'so_tien_da_phan_bo' => 'decimal:2',
        'so_tien_da_hoan' => 'decimal:2',
        'phien_ban_hoa_don' => 'integer',
        'doi_soat_luc' => 'datetime',
        'nhan_luc' => 'datetime',
        'ghi_nhan_luc' => 'datetime',
        'la_du_lieu_lich_su' => 'boolean',
    ];

    /**
     * Tiền còn lại chưa áp vào đơn nào và chưa hoàn. so_tien_da_phan_bo là số ròng (đã trừ
     * phần hoàn rút từ tiền đã áp), nên chỉ cần trừ thêm tổng đã hoàn.
     */
    public function unallocatedAmount(): float
    {
        return round(max(0, (float) $this->so_tien - (float) $this->so_tien_da_phan_bo - (float) $this->so_tien_da_hoan), 2);
    }

    public function refundableAmount(): float
    {
        return round(max(0, (float) $this->so_tien - (float) $this->so_tien_da_hoan), 2);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class, 'ma_giao_dich_tien', 'ma_giao_dich_tien');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class, 'ma_giao_dich_tien', 'ma_giao_dich_tien');
    }

    public function targetOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'ma_don_hang_muc_tieu', 'ma_don_hang');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'ma_nguoi_ghi_nhan', 'ma_tai_khoan');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'ma_nguoi_doi_soat', 'ma_tai_khoan');
    }
}
