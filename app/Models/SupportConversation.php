<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupportConversation extends Model
{
    protected $fillable = ['account_id', 'active_account_id', 'assigned_to', 'status', 'staff_seen_at', 'customer_seen_at', 'last_message_at', 'closed_at'];

    protected $casts = ['staff_seen_at' => 'datetime', 'customer_seen_at' => 'datetime', 'last_message_at' => 'datetime', 'closed_at' => 'datetime'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id', 'ma_tai_khoan');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'assigned_to', 'ma_tai_khoan');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportMessage::class, 'conversation_id');
    }
}
