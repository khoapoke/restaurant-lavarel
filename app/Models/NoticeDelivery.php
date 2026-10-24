<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NoticeDelivery extends Model
{
    protected $fillable = ['notice_id', 'channel', 'recipient', 'status', 'attempts', 'error_code', 'message_id', 'available_at', 'accepted_at', 'expires_at', 'dispatched_at'];

    protected $casts = ['available_at' => 'datetime', 'accepted_at' => 'datetime', 'expires_at' => 'datetime', 'dispatched_at' => 'datetime', 'attempts' => 'integer'];

    protected $hidden = ['recipient'];

    public function notice(): BelongsTo
    {
        return $this->belongsTo(CustomerNotice::class, 'notice_id');
    }
}
