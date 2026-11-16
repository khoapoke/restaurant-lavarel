<?php

namespace App\Modules\Chat\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChatMessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $senderName = match ($this->loai_nguoi_gui?->value) {
            'BOT' => 'Chatbot T-ResMK',
            'STAFF' => $this->sender?->userProfile?->ho_ten ?? ($this->sender?->ten_dang_nhap ?? 'Nhân viên nhà hàng'),
            default => $this->sender?->userProfile?->ho_ten ?? ($this->sender?->ten_dang_nhap ?? 'Khách hàng'),
        };

        return [
            'messageId' => $this->ma_tin_nhan,
            'sessionId' => $this->ma_phien_chat,
            'senderType' => $this->loai_nguoi_gui?->value,
            'senderId' => $this->ma_tai_khoan,
            'senderName' => $senderName,
            'content' => $this->noi_dung,
            'metadata' => $this->du_lieu_dinh_kem,
            'createdAt' => $this->thoi_gian_gui?->toIso8601String(),
        ];
    }
}

