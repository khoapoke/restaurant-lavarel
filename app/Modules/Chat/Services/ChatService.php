<?php

namespace App\Modules\Chat\Services;

use App\Modules\Auth\Models\Account;
use App\Modules\Chat\Models\ChatMessage;
use App\Modules\Chat\Models\ChatSession;
use App\Modules\Core\Enums\ChatSenderType;
use App\Modules\Core\Enums\ChatSessionStatus;
use App\Modules\Core\Enums\RoleType;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ChatService
{
    protected GeminiChatbotService $geminiService;

    public function __construct(GeminiChatbotService $geminiService)
    {
        $this->geminiService = $geminiService;
    }

    /**
     * Lấy danh sách các phiên chat của khách hàng (CHT-01).
     */
    public function getCustomerSessions(Account $customer, ?string $status = null): LengthAwarePaginator
    {
        $query = ChatSession::where('ma_tai_khoan', $customer->ma_tai_khoan)
            ->with(['latestMessage', 'staff.userProfile'])
            ->orderBy('ma_phien_chat', 'desc');

        if ($status && $enumStatus = ChatSessionStatus::fromName($status)) {
            $query->where('trang_thai', $enumStatus);
        }

        return $query->paginate(15);
    }

    /**
     * Khởi tạo phiên chat mới (CHT-02).
     */
    public function createSession(Account $customer, ?string $title = null, ?string $initialMessage = null): ChatSession
    {
        return DB::transaction(function () use ($customer, $title, $initialMessage) {
            $session = ChatSession::create([
                'ma_tai_khoan' => $customer->ma_tai_khoan,
                'trang_thai' => ChatSessionStatus::BOT,
                'tieu_de' => $title ?: ($initialMessage ? mb_substr($initialMessage, 0, 50) : 'Hỗ trợ khách hàng'),
                'thoi_gian_tao' => now(),
            ]);

            if (!empty($initialMessage)) {
                // 1. Lưu tin nhắn đầu tiên của khách
                ChatMessage::create([
                    'ma_phien_chat' => $session->ma_phien_chat,
                    'loai_nguoi_gui' => ChatSenderType::CUSTOMER,
                    'ma_tai_khoan' => $customer->ma_tai_khoan,
                    'noi_dung' => $initialMessage,
                    'thoi_gian_gui' => now(),
                ]);

                // 2. Kích hoạt Bot trả lời tự động
                $reply = $this->geminiService->generateReply($initialMessage, $customer);
                ChatMessage::create([
                    'ma_phien_chat' => $session->ma_phien_chat,
                    'loai_nguoi_gui' => ChatSenderType::BOT,
                    'ma_tai_khoan' => null,
                    'noi_dung' => $reply,
                    'thoi_gian_gui' => now(),
                ]);
            } else {
                // Mở phiên chat tiêu chuẩn: Hiển thị ngay lời chào mặc định từ backend, không tốn request AI
                ChatMessage::create([
                    'ma_phien_chat' => $session->ma_phien_chat,
                    'loai_nguoi_gui' => ChatSenderType::BOT,
                    'ma_tai_khoan' => null,
                    'noi_dung' => 'Em là chatbot của nhà hàng T-ResMK, em có thể giúp gì cho anh/chị nhỉ',
                    'thoi_gian_gui' => now(),
                ]);
            }

            return $session->load(['messages.sender.userProfile', 'staff.userProfile']);
        });
    }

    /**
     * Lấy chi tiết phiên chat và lịch sử tin nhắn (CHT-03).
     */
    public function getSessionDetail(int $sessionId, Account $user): ChatSession
    {
        $session = ChatSession::with(['messages.sender.userProfile', 'staff.userProfile', 'customer.userProfile'])
            ->findOrFail($sessionId);

        // Khách hàng chỉ được xem phiên của chính mình
        if ($user->role?->ten_vai_tro === RoleType::CUSTOMER->value && $session->ma_tai_khoan !== $user->ma_tai_khoan) {
            throw new HttpException(403, 'Bạn không có quyền truy cập vào phiên chat này.');
        }

        return $session;
    }

    /**
     * Khách hàng gửi tin nhắn vào phiên chat (CHT-04).
     */
    public function sendCustomerMessage(int $sessionId, Account $customer, string $content): array
    {
        $session = ChatSession::findOrFail($sessionId);

        if ($session->ma_tai_khoan !== $customer->ma_tai_khoan) {
            throw new HttpException(403, 'Bạn không có quyền gửi tin nhắn vào phiên chat này.');
        }

        if ($session->trang_thai === ChatSessionStatus::CLOSED) {
            throw new HttpException(400, 'Phiên chat này đã kết thúc, không thể gửi thêm tin nhắn.');
        }

        // 1. Thu thập ngữ cảnh lịch sử hội thoại gần nhất TRƯỚC KHI lưu tin nhắn mới
        // (Tránh lỗi gửi lặp lại 2 lần tin nhắn khách sang Google Gemini)
        $recentMessages = [];
        if ($session->trang_thai === ChatSessionStatus::BOT) {
            $recentMessages = ChatMessage::where('ma_phien_chat', $session->ma_phien_chat)
                ->orderBy('thoi_gian_gui', 'desc')
                ->take(6)
                ->get()
                ->reverse()
                ->map(fn ($m) => [
                    'senderType' => $m->loai_nguoi_gui->value,
                    'content' => $m->noi_dung,
                ])
                ->values()
                ->toArray();
        }

        // 2. Lưu tin nhắn của khách vào DB
        $customerMsg = ChatMessage::create([
            'ma_phien_chat' => $session->ma_phien_chat,
            'loai_nguoi_gui' => ChatSenderType::CUSTOMER,
            'ma_tai_khoan' => $customer->ma_tai_khoan,
            'noi_dung' => $content,
            'thoi_gian_gui' => now(),
        ]);

        $botReply = null;

        // 3. Nếu đang ở chế độ BOT -> Gọi Gemini API HOÀN TOÀN NGOÀI DB Transaction
        // (Tránh lock database connection trong suốt thời gian gọi external HTTP API)
        if ($session->trang_thai === ChatSessionStatus::BOT) {
            $replyText = $this->geminiService->generateReply($content, $customer, $recentMessages);

            $botReply = ChatMessage::create([
                'ma_phien_chat' => $session->ma_phien_chat,
                'loai_nguoi_gui' => ChatSenderType::BOT,
                'ma_tai_khoan' => null,
                'noi_dung' => $replyText,
                'thoi_gian_gui' => now(),
            ]);
        }

        return [
            'customerMessage' => $customerMsg->load('sender.userProfile'),
            'botReply' => $botReply,
            'session' => $session,
        ];
    }

    /**
     * Khách hàng yêu cầu chuyển giao sang nhân viên hỗ trợ (CHT-05).
     */
    public function requestStaff(int $sessionId, Account $customer): ChatSession
    {
        $session = ChatSession::findOrFail($sessionId);

        if ($session->ma_tai_khoan !== $customer->ma_tai_khoan) {
            throw new HttpException(403, 'Bạn không có quyền thao tác trên phiên chat này.');
        }

        if ($session->trang_thai === ChatSessionStatus::CLOSED) {
            throw new HttpException(400, 'Phiên chat đã đóng, không thể yêu cầu nhân viên.');
        }

        if ($session->trang_thai === ChatSessionStatus::STAFF_CONNECTED) {
            return $session;
        }

        return DB::transaction(function () use ($session) {
            $session->trang_thai = ChatSessionStatus::WAITING_STAFF;
            $session->save();

            // Tin nhắn thông báo hệ thống
            ChatMessage::create([
                'ma_phien_chat' => $session->ma_phien_chat,
                'loai_nguoi_gui' => ChatSenderType::BOT,
                'ma_tai_khoan' => null,
                'noi_dung' => '🔔 Yêu cầu của bạn đã được chuyển đến nhân viên trực chat. Đội ngũ CSKH sẽ phản hồi trong giây lát...',
                'thoi_gian_gui' => now(),
            ]);

            return $session->load(['messages.sender.userProfile', 'staff.userProfile']);
        });
    }

    /**
     * Danh sách phiên chat tổng hợp dành cho Nhân viên / Admin với bộ lọc đa năng (CHT-10).
     * Hỗ trợ:
     * - status: WAITING_STAFF, STAFF_CONNECTED, CLOSED, BOT...
     * - assigned_to_me: true (lọc riêng các phiên đang phục vụ của chính nhân viên này)
     * - search: tìm theo tên/SĐT khách hàng hoặc tiêu đề phiên
     */
    public function getStaffSessions(Account $staff, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = ChatSession::with(['latestMessage', 'customer.userProfile', 'staff.userProfile'])
            ->orderBy('ma_phien_chat', 'desc');

        if (!empty($filters['status'])) {
            $enumStatus = ChatSessionStatus::fromName($filters['status']);
            if ($enumStatus) {
                $query->where('trang_thai', $enumStatus);
            }
        }

        if (!empty($filters['assigned_to_me']) && filter_var($filters['assigned_to_me'], FILTER_VALIDATE_BOOLEAN)) {
            $query->where('ma_nhan_vien', $staff->ma_tai_khoan);
        }

        if (!empty($filters['search'])) {
            $search = '%' . trim($filters['search']) . '%';
            $query->where(function ($q) use ($search) {
                $q->where('tieu_de', 'LIKE', $search)
                    ->orWhereHas('customer.userProfile', function ($sub) use ($search) {
                        $sub->where('ho_ten', 'LIKE', $search)
                            ->orWhere('so_dien_thoai', 'LIKE', $search);
                    });
            });
        }

        return $query->paginate($perPage);
    }

    /**
     * Danh sách phiên chat đang chờ nhân viên tiếp nhận (CHT-06).
     */
    public function getPendingStaffSessions(int $perPage = 15): LengthAwarePaginator
    {
        return ChatSession::where('trang_thai', ChatSessionStatus::WAITING_STAFF)
            ->with(['latestMessage', 'customer.userProfile'])
            ->orderBy('ma_phien_chat', 'asc')
            ->paginate($perPage);
    }

    /**
     * Nhân viên nhận xử lý phiên chat (CHT-07).
     */
    public function assignStaff(int $sessionId, Account $staff): ChatSession
    {
        $session = ChatSession::findOrFail($sessionId);

        if ($session->trang_thai === ChatSessionStatus::CLOSED) {
            throw new HttpException(400, 'Phiên chat này đã kết thúc.');
        }

        return DB::transaction(function () use ($session, $staff) {
            $session->ma_nhan_vien = $staff->ma_tai_khoan;
            $session->trang_thai = ChatSessionStatus::STAFF_CONNECTED;
            $session->save();

            $staffName = $staff->userProfile?->ho_ten ?? $staff->ten_dang_nhap;

            ChatMessage::create([
                'ma_phien_chat' => $session->ma_phien_chat,
                'loai_nguoi_gui' => ChatSenderType::STAFF,
                'ma_tai_khoan' => $staff->ma_tai_khoan,
                'noi_dung' => "Xin chào quý khách! Em là {$staffName} - chuyên viên hỗ trợ của Nhà Hàng T-ResMK. Em có thể hỗ trợ gì cho mình ạ?",
                'thoi_gian_gui' => now(),
            ]);

            return $session->load(['messages.sender.userProfile', 'staff.userProfile', 'customer.userProfile']);
        });
    }

    /**
     * Nhân viên gửi tin nhắn trực tiếp cho khách (CHT-08).
     */
    public function sendStaffMessage(int $sessionId, Account $staff, string $content): ChatMessage
    {
        $session = ChatSession::findOrFail($sessionId);

        if ($session->trang_thai === ChatSessionStatus::CLOSED) {
            throw new HttpException(400, 'Phiên chat này đã kết thúc, không thể gửi thêm tin nhắn.');
        }

        return DB::transaction(function () use ($session, $staff, $content) {
            // Nếu phiên chưa gán nhân viên, tự động gán
            if ($session->trang_thai !== ChatSessionStatus::STAFF_CONNECTED) {
                $session->trang_thai = ChatSessionStatus::STAFF_CONNECTED;
                $session->ma_nhan_vien = $staff->ma_tai_khoan;
                $session->save();
            }

            return ChatMessage::create([
                'ma_phien_chat' => $session->ma_phien_chat,
                'loai_nguoi_gui' => ChatSenderType::STAFF,
                'ma_tai_khoan' => $staff->ma_tai_khoan,
                'noi_dung' => $content,
                'thoi_gian_gui' => now(),
            ])->load('sender.userProfile');
        });
    }

    /**
     * Lấy phiên chat đang hoạt động gần nhất của khách hàng (kèm lịch sử tin nhắn).
     */
    public function getActiveCustomerSession(Account $customer): ?ChatSession
    {
        return ChatSession::where('ma_tai_khoan', $customer->ma_tai_khoan)
            ->whereIn('trang_thai', [
                ChatSessionStatus::BOT,
                ChatSessionStatus::WAITING_STAFF,
                ChatSessionStatus::STAFF_CONNECTED,
            ])
            ->with(['messages.sender.userProfile', 'staff.userProfile', 'customer.userProfile'])
            ->latest('thoi_gian_tao')
            ->first();
    }

    /**
     * Đóng phiên chat (CHT-09).
     */
    public function closeSession(int $sessionId, Account $user): ChatSession
    {
        $session = ChatSession::findOrFail($sessionId);

        // Khách hàng chỉ được đóng phiên của chính mình
        if ($user->role?->ten_vai_tro === RoleType::CUSTOMER->value && $session->ma_tai_khoan !== $user->ma_tai_khoan) {
            throw new HttpException(403, 'Bạn không có quyền đóng phiên chat này.');
        }

        if ($session->trang_thai === ChatSessionStatus::CLOSED) {
            return $session;
        }

        return DB::transaction(function () use ($session) {
            $session->trang_thai = ChatSessionStatus::CLOSED;
            $session->thoi_gian_dong = now();
            $session->save();

            ChatMessage::create([
                'ma_phien_chat' => $session->ma_phien_chat,
                'loai_nguoi_gui' => ChatSenderType::BOT,
                'ma_tai_khoan' => null,
                'noi_dung' => '🔒 Phiên hỗ trợ đã kết thúc. Cảm ơn quý khách đã tin tưởng và đồng hành cùng Nhà Hàng T-ResMK!',
                'thoi_gian_gui' => now(),
            ]);

            return $session->load(['latestMessage', 'staff.userProfile']);
        });
    }
}

