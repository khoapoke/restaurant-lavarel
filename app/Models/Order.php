<?php

namespace App\Models;

use App\Enums\FulfillmentStatus;
use App\Enums\FulfillmentType;
use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    protected $table = 'DON_HANG';

    protected $primaryKey = 'ma_don_hang';

    public $timestamps = false;

    protected $fillable = [
        'ngay_dat_hang',
        'tong_tien',
        'phi_giao_hang',
        'trang_thai',
        'dia_chi_giao_hang',
        'ten_nguoi_nhan',
        'so_dien_thoai_nhan',
        'thoi_gian_nhan_du_kien',
        'ma_tai_khoan',
        'ma_phien',
        'kenh',
        'loai_phuc_vu',
        'trang_thai_giao_nhan',
        'ghi_chu',
        'phien_ban',
    ];

    protected $casts = [
        'ngay_dat_hang' => 'datetime',
        'tong_tien' => 'decimal:2',
        'phi_giao_hang' => 'decimal:2',
        'trang_thai' => OrderStatus::class,
        'kenh' => OrderChannel::class,
        'loai_phuc_vu' => FulfillmentType::class,
        'trang_thai_giao_nhan' => FulfillmentStatus::class,
        'thoi_gian_nhan_du_kien' => 'datetime',
        'phien_ban' => 'integer',
    ];

    // Accessors / Mutators
    protected function orderId(): Attribute
    {
        return Attribute::make(get: fn () => $this->ma_don_hang);
    }

    protected function orderDate(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->ngay_dat_hang,
            set: fn ($value) => ['ngay_dat_hang' => $value]
        );
    }

    protected function totalAmount(): Attribute
    {
        return Attribute::make(
            get: fn () => (float) $this->tong_tien,
            set: fn ($value) => ['tong_tien' => $value]
        );
    }

    protected function status(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->trang_thai,
            set: fn (OrderStatus|string $value) => ['trang_thai' => $value]
        );
    }

    protected function shippingAddress(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->dia_chi_giao_hang,
            set: fn (?string $value) => ['dia_chi_giao_hang' => $value]
        );
    }

    protected function accountId(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->ma_tai_khoan,
            set: fn ($value) => ['ma_tai_khoan' => $value]
        );
    }

    // Relationships
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'ma_tai_khoan', 'ma_tai_khoan');
    }

    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class, 'ma_don_hang', 'ma_don_hang');
    }

    public function dishes(): BelongsToMany
    {
        return $this->belongsToMany(
            Dish::class,
            'CHI_TIET_DON_HANG',
            'ma_don_hang',
            'ma_mon_an',
            'ma_don_hang',
            'ma_mon_an'
        )->using(OrderItem::class)->withPivot(['ma_chi_tiet', 'ten_mon_snapshot', 'so_luong', 'don_gia', 'ghi_chu', 'trang_thai_bep']);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'ma_don_hang', 'ma_don_hang');
    }

    public function serviceSession(): BelongsTo
    {
        return $this->belongsTo(ServiceSession::class, 'ma_phien', 'ma_phien');
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class, 'ma_don_hang', 'ma_don_hang');
    }
}
