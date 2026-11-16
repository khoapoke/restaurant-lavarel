<?php

namespace App\Modules\Chat\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChatSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'sessionId' => $this->ma_phien_chat,
            'title' => $this->tieu_de,
            'status' => $this->trang_thai?->value,
            'customerId' => $this->ma_tai_khoan,
            'customerName' => $this->customer?->userProfile?->ho_ten ?? ($this->customer?->ten_dang_nhap ?? 'Khách hàng'),
            'staff' => $this->staff ? [
                'staffId' => $this->staff->ma_tai_khoan,
                'fullName' => $this->staff->userProfile?->ho_ten ?? $this->staff->ten_dang_nhap,
            ] : null,
            'createdAt' => $this->thoi_gian_tao?->toIso8601String(),
            'closedAt' => $this->thoi_gian_dong?->toIso8601String(),
            'latestMessage' => $this->latestMessage ? new ChatMessageResource($this->latestMessage) : null,
            'messages' => ChatMessageResource::collection($this->whenLoaded('messages')),
        ];
    }
}

