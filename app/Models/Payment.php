<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $table = 'THANH_TOAN';
    protected $primaryKey = 'ma_thanh_toan';
    public $timestamps = false;

    protected $fillable = [
        'so_tien',
        'phuong_thuc',
        'trang_thai',
        'ma_don_hang',
        'ma_giao_dich',
        'log_callback',
        'ly_do_hoan_tien',
    ];

    protected $casts = [
        'so_tien' => 'decimal:2',
        'phuong_thuc' => PaymentMethod::class,
        'trang_thai' => PaymentStatus::class,
    ];

    // Accessors / Mutators
    protected function paymentId(): Attribute
    {
        return Attribute::make(get: fn () => $this->ma_thanh_toan);
    }

    protected function amount(): Attribute
    {
        return Attribute::make(
            get: fn () => (float) $this->so_tien,
            set: fn ($value) => ['so_tien' => $value]
        );
    }

    protected function paymentMethod(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->phuong_thuc,
            set: fn (PaymentMethod|string $value) => ['phuong_thuc' => $value]
        );
    }

    protected function status(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->trang_thai,
            set: fn (PaymentStatus|string $value) => ['trang_thai' => $value]
        );
    }

    protected function orderId(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->ma_don_hang,
            set: fn ($value) => ['ma_don_hang' => $value]
        );
    }

    protected function transactionCode(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->ma_giao_dich,
            set: fn (?string $value) => ['ma_giao_dich' => $value]
        );
    }

    protected function callbackLog(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->log_callback,
            set: fn (?string $value) => ['log_callback' => $value]
        );
    }

    protected function refundReason(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->ly_do_hoan_tien,
            set: fn (?string $value) => ['ly_do_hoan_tien' => $value]
        );
    }

    // Relationships
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'ma_don_hang', 'ma_don_hang');
    }
}
