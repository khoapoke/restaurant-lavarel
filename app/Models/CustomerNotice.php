<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerNotice extends Model
{
    protected $fillable = ['account_id', 'event_key', 'kind', 'title', 'body', 'path', 'entity_type', 'entity_id', 'snapshot', 'read_at', 'limit_key'];

    protected $casts = ['snapshot' => 'array', 'read_at' => 'datetime'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id', 'ma_tai_khoan');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(NoticeDelivery::class, 'notice_id');
    }
}
