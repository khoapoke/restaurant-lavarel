<?php

namespace Database\Seeders;

use App\Modules\Auth\Models\Account;
use App\Modules\Chat\Models\ChatMessage;
use App\Modules\Chat\Models\ChatSession;
use App\Modules\Core\Enums\ChatSenderType;
use App\Modules\Core\Enums\ChatSessionStatus;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class ChatSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * Bảng PHIEN_CHAT & TIN_NHAN_CHAT (Tier 2 - Dữ liệu hội thoại mẫu).
     */
    public function run(): void
    {
        $accKh1 = Account::where('ten_dang_nhap', 'kh1')->first();
        $accKh2 = Account::where('ten_dang_nhap', 'kh2')->first();
        $accStaff = Account::where('ten_dang_nhap', 'staff1')->first();

        if (! $accKh1 || ! $accKh2) {
            return;
        }

        // 1. Phiên 1: Khách hàng kh1 trò chuyện với Trợ lý ảo BOT
        $sessionBot = ChatSession::firstOrCreate(
            [
                'ma_tai_khoan' => $accKh1->ma_tai_khoan,
                'tieu_de' => 'Tư vấn món ăn tối nay',
            ],
            [
                'trang_thai' => ChatSessionStatus::BOT,
                'thoi_gian_tao' => Carbon::now()->subHours(2),
            ]
        );

        ChatMessage::firstOrCreate(
            [
                'ma_phien_chat' => $sessionBot->ma_phien_chat,
                'noi_dung' => 'Dạ em chào anh Lê Hoàng Hùng! Em là trợ lý ảo của Nhà Hàng Hương Quê. Em có thể tư vấn món ngon hoặc hỗ trợ đặt bàn cho mình hôm nay ạ?',
            ],
            [
                'loai_nguoi_gui' => ChatSenderType::BOT,
                'thoi_gian_gui' => Carbon::now()->subHours(2),
            ]
        );

        ChatMessage::firstOrCreate(
            [
                'ma_phien_chat' => $sessionBot->ma_phien_chat,
                'noi_dung' => 'Quán có món nước nào thanh mát giải nhiệt không bạn?',
            ],
            [
                'loai_nguoi_gui' => ChatSenderType::CUSTOMER,
                'ma_tai_khoan' => $accKh1->ma_tai_khoan,
                'thoi_gian_gui' => Carbon::now()->subHours(2)->addMinutes(1),
            ]
        );

        ChatMessage::firstOrCreate(
            [
                'ma_phien_chat' => $sessionBot->ma_phien_chat,
                'noi_dung' => 'Dạ quán có Trà đào cam sả thanh mát (38k) và Cà phê sữa đá pha phin (29k) rất được yêu thích ạ!',
            ],
            [
                'loai_nguoi_gui' => ChatSenderType::BOT,
                'thoi_gian_gui' => Carbon::now()->subHours(2)->addMinutes(2),
            ]
        );

        // 2. Phiên 2: Khách hàng kh2 yêu cầu nhân viên hỗ trợ (WAITING_STAFF)
        $sessionStaff = ChatSession::firstOrCreate(
            [
                'ma_tai_khoan' => $accKh2->ma_tai_khoan,
                'tieu_de' => 'Hỗ trợ thay đổi món ăn',
            ],
            [
                'trang_thai' => ChatSessionStatus::WAITING_STAFF,
                'thoi_gian_tao' => Carbon::now()->subMinutes(10),
            ]
        );

        ChatMessage::firstOrCreate(
            [
                'ma_phien_chat' => $sessionStaff->ma_phien_chat,
                'noi_dung' => 'Mình vừa đặt món chay nhưng muốn dặn bếp không bỏ hành boa-rô, có thể hỗ trợ mình không?',
            ],
            [
                'loai_nguoi_gui' => ChatSenderType::CUSTOMER,
                'ma_tai_khoan' => $accKh2->ma_tai_khoan,
                'thoi_gian_gui' => Carbon::now()->subMinutes(10),
            ]
        );

        ChatMessage::firstOrCreate(
            [
                'ma_phien_chat' => $sessionStaff->ma_phien_chat,
                'noi_dung' => '🔔 Yêu cầu của bạn đã được chuyển đến nhân viên trực chat. Đội ngũ CSKH sẽ phản hồi trong giây lát...',
            ],
            [
                'loai_nguoi_gui' => ChatSenderType::BOT,
                'thoi_gian_gui' => Carbon::now()->subMinutes(9),
            ]
        );
    }
}

