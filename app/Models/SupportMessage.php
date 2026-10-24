<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportMessage extends Model
{
    protected $fillable = ['conversation_id', 'sender', 'sender_id', 'body', 'client_key', 'request_hash', 'reply_to', 'dish_ids', 'ai_status', 'tokens_used', 'ai_dispatched_at'];

    protected $casts = ['dish_ids' => 'array', 'tokens_used' => 'integer', 'ai_dispatched_at' => 'datetime'];

    protected $hidden = ['request_hash'];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(SupportConversation::class, 'conversation_id');
    }
}
