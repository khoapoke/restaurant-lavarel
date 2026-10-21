<?php

namespace App\Models;

use App\Enums\KitchenTicketSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class KitchenTicket extends Model
{
    protected $table = 'PHIEU_BEP';

    protected $primaryKey = 'ma_phieu_bep';

    public $timestamps = false;

    protected $fillable = ['ma_don_hang', 'ma_nhan_vien', 'trang_thai', 'gui_luc', 'nguon', 'ma_luot', 'idempotency_key', 'request_hash'];

    protected $casts = ['gui_luc' => 'datetime', 'nguon' => KitchenTicketSource::class];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'ma_don_hang', 'ma_don_hang');
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'ma_nhan_vien', 'ma_tai_khoan');
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(TableVisit::class, 'ma_luot', 'ma_luot');
    }

    public function items(): BelongsToMany
    {
        return $this->belongsToMany(
            OrderItem::class,
            'CHI_TIET_PHIEU_BEP',
            'ma_phieu_bep',
            'ma_chi_tiet',
            'ma_phieu_bep',
            'ma_chi_tiet'
        );
    }
}
