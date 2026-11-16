<?php

namespace App\Modules\Chat\Models;

use App\Modules\Auth\Models\Account;
use App\Modules\Core\Enums\ChatSessionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ChatSession extends Model
{
    protected $table = 'PHIEN_CHAT';
    protected $primaryKey = 'ma_phien_chat';
    public $timestamps = false;

    protected $fillable = [
        'ma_tai_khoan',
        'ma_nhan_vien',
        'trang_thai',
        'tieu_de',
        'thoi_gian_tao',
        'thoi_gian_dong',
    ];

    protected $casts = [
        'ma_phien_chat' => 'integer',
        'ma_tai_khoan' => 'integer',
        'ma_nhan_vien' => 'integer',
        'trang_thai' => ChatSessionStatus::class,
        'thoi_gian_tao' => 'datetime',
        'thoi_gian_dong' => 'datetime',
    ];

    /**
     * Khách hàng sở hữu phiên chat.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'ma_tai_khoan', 'ma_tai_khoan');
    }

    /**
     * Nhân viên đang tiếp nhận phiên chat.
     */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'ma_nhan_vien', 'ma_tai_khoan');
    }

    /**
     * Danh sách các tin nhắn trong phiên chat.
     */
    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'ma_phien_chat', 'ma_phien_chat')
            ->orderBy('thoi_gian_gui', 'asc');
    }

    /**
     * Tin nhắn mới nhất của phiên chat.
     */
    public function latestMessage(): HasOne
    {
        return $this->hasOne(ChatMessage::class, 'ma_phien_chat', 'ma_phien_chat')
            ->latestOfMany('thoi_gian_gui');
    }
}
