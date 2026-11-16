<?php

namespace App\Modules\Chat\Models;

use App\Modules\Auth\Models\Account;
use App\Modules\Core\Enums\ChatSenderType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatMessage extends Model
{
    protected $table = 'TIN_NHAN_CHAT';
    protected $primaryKey = 'ma_tin_nhan';
    public $timestamps = false;

    protected $fillable = [
        'ma_phien_chat',
        'loai_nguoi_gui',
        'ma_tai_khoan',
        'noi_dung',
        'du_lieu_dinh_kem',
        'thoi_gian_gui',
    ];

    protected $casts = [
        'ma_tin_nhan' => 'integer',
        'ma_phien_chat' => 'integer',
        'ma_tai_khoan' => 'integer',
        'loai_nguoi_gui' => ChatSenderType::class,
        'du_lieu_dinh_kem' => 'array',
        'thoi_gian_gui' => 'datetime',
    ];

    /**
     * Phiên chat chứa tin nhắn này.
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(ChatSession::class, 'ma_phien_chat', 'ma_phien_chat');
    }

    /**
     * Tài khoản người gửi (Khách hoặc Nhân viên, null nếu là BOT).
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'ma_tai_khoan', 'ma_tai_khoan');
    }
}
