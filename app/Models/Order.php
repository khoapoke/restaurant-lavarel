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
use Illuminate\Support\Carbon;

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
        'ma_nhan_hang',
        'san_sang_luc',
        'ban_giao_luc',
        'nguoi_nhan_thay',
        'loai_giao_that_bai',
        'ly_do_giao_that_bai',
        'giao_that_bai_luc',
        'so_lan_giao',
        'ly_do_huy',
        'quyet_dinh_tien_huy',
        'ma_nguoi_huy',
        'huy_luc',
        'so_tien_giam_tru',
    ];

    /** Đơn tự lấy luôn có mã nhận hàng 4 số để đối chiếu khi bàn giao (DLV-07). */
    protected static function booted(): void
    {
        static::creating(function (Order $order): void {
            if ($order->loai_phuc_vu === FulfillmentType::PICKUP && ! $order->ma_nhan_hang) {
                $order->ma_nhan_hang = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
            }
        });
    }

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
        'san_sang_luc' => 'datetime',
        'ban_giao_luc' => 'datetime',
        'giao_that_bai_luc' => 'datetime',
        'huy_luc' => 'datetime',
        'so_lan_giao' => 'integer',
        'so_tien_giam_tru' => 'decimal:2',
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
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'ma_nguoi_huy', 'ma_tai_khoan');
    }

    /**
     * Tóm tắt tiền của đơn từ bản tổng hợp THANH_TOAN (đồng bộ từ sổ cái), không truy vấn thêm.
     *
     * @return array{amountPaid: float, amountRefunded: float, outstanding: float, refundDue: float}
     */
    public function moneySummary(): array
    {
        $payment = $this->relationLoaded('payment') ? $this->payment : $this->payment()->first();
        $paid = round((float) ($payment?->so_tien_da_thu ?? 0), 2);
        $total = $this->payableAmount();
        $cancelled = $this->trang_thai === OrderStatus::CANCELLED;

        return [
            'amountPaid' => $paid,
            'amountRefunded' => round((float) ($payment?->so_tien_da_hoan ?? 0), 2),
            'outstanding' => $cancelled ? 0.0 : round(max(0, $total - $paid), 2),
            'refundDue' => $cancelled
                ? ($this->quyet_dinh_tien_huy === 'RETAINED' ? 0.0 : $paid)
                : round(max(0, $paid - $total), 2),
        ];
    }

    /** Mốc đơn hẹn được xuống bếp: giờ hẹn − thời gian chuẩn bị; null nếu không hẹn hoặc tại bàn. */
    public function kitchenReleaseAt(): ?Carbon
    {
        if ($this->kenh === OrderChannel::POS || ! $this->thoi_gian_nhan_du_kien) {
            return null;
        }

        return $this->thoi_gian_nhan_du_kien->copy()->subMinutes((int) config('restaurant.fulfillment.scheduled_prep_lead_minutes', 30));
    }

    /** Đơn hẹn chưa tới giờ xuống bếp và chưa được quản lý cho bắt đầu sớm. */
    public function awaitingKitchenRelease(): bool
    {
        return $this->trang_thai === OrderStatus::CONFIRMED && $this->kitchenReleaseAt()?->isFuture() === true;
    }

    /** Khoản phải thu của đơn: tổng đơn trừ giảm trừ sau thanh toán (bồi hoàn cho khách). */
    public function payableAmount(): float
    {
        return round(max(0, (float) $this->tong_tien - (float) ($this->so_tien_giam_tru ?? 0)), 2);
    }

    /** Còn sửa được người nhận/địa chỉ (chưa giao tài xế, chưa bàn giao, chưa kết thúc). */
    public function recipientEditable(): bool
    {
        return $this->loai_phuc_vu !== FulfillmentType::DINE_IN
            && ! in_array($this->trang_thai, [OrderStatus::CANCELLED, OrderStatus::COMPLETED], true)
            && in_array($this->trang_thai_giao_nhan, [FulfillmentStatus::PENDING, FulfillmentStatus::AWAITING_DRIVER, FulfillmentStatus::AWAITING_CUSTOMER, FulfillmentStatus::DELIVERY_FAILED], true);
    }

    /** Mốc giữ món cho khách tự lấy: muộn nhất của giờ sẵn sàng và giờ hẹn, cộng thời gian giữ. */
    public function pickupDeadline(): ?Carbon
    {
        if ($this->loai_phuc_vu !== FulfillmentType::PICKUP || ! $this->san_sang_luc) {
            return null;
        }

        $base = $this->thoi_gian_nhan_du_kien && $this->thoi_gian_nhan_du_kien->greaterThan($this->san_sang_luc)
            ? $this->thoi_gian_nhan_du_kien
            : $this->san_sang_luc;

        return $base->copy()->addMinutes((int) config('restaurant.fulfillment.pickup_overdue_minutes', 60));
    }

    public function pickupOverdue(): bool
    {
        return $this->trang_thai_giao_nhan === FulfillmentStatus::AWAITING_CUSTOMER
            && $this->pickupDeadline()?->isPast() === true;
    }

    /** Số phút món đã xong mà chưa bàn giao (tài xế/khách). */
    public function handoffWaitMinutes(): ?int
    {
        if (! $this->san_sang_luc || ! in_array($this->trang_thai_giao_nhan, [FulfillmentStatus::AWAITING_DRIVER, FulfillmentStatus::AWAITING_CUSTOMER, FulfillmentStatus::DELIVERY_FAILED], true)) {
            return null;
        }

        return (int) floor($this->san_sang_luc->diffInMinutes(now()));
    }

    public function driverWaitTooLong(): bool
    {
        return $this->trang_thai_giao_nhan === FulfillmentStatus::AWAITING_DRIVER
            && ($this->handoffWaitMinutes() ?? 0) >= (int) config('restaurant.fulfillment.delivery_handoff_warning_minutes', 20);
    }

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
        )->using(OrderItem::class)->withPivot(['ma_chi_tiet', 'ten_mon_snapshot', 'so_luong', 'so_luong_tinh_tien', 'don_gia', 'ghi_chu', 'trang_thai_bep', 'loai_ly_do_huy', 'loai_dong']);
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
