<?php

namespace Tests\Feature;

use App\Modules\Auth\Models\Account;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\UserProfile;
use App\Modules\Chat\Models\ChatMessage;
use App\Modules\Chat\Models\ChatSession;
use App\Modules\Core\Enums\AccountStatus;
use App\Modules\Core\Enums\ChatSenderType;
use App\Modules\Core\Enums\ChatSessionStatus;
use App\Modules\Core\Enums\RoleType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ChatModuleTest extends TestCase
{
    use RefreshDatabase;

    protected Account $customer1;
    protected Account $customer2;
    protected Account $staffUser;
    protected string $tokenCustomer1;
    protected string $tokenCustomer2;
    protected string $tokenStaff;

    protected function setUp(): void
    {
        parent::setUp();

        $roleAdmin = Role::firstOrCreate(['ten_vai_tro' => RoleType::ADMIN->value]);
        $roleStaff = Role::firstOrCreate(['ten_vai_tro' => RoleType::STAFF->value]);
        $roleCustomer = Role::firstOrCreate(['ten_vai_tro' => RoleType::CUSTOMER->value]);

        $profileKh1 = UserProfile::create(['ho_ten' => 'Khách Hàng 1', 'so_dien_thoai' => '0901111111']);
        $profileKh2 = UserProfile::create(['ho_ten' => 'Khách Hàng 2', 'so_dien_thoai' => '0902222222']);
        $profileStaff = UserProfile::create(['ho_ten' => 'Nhân Viên 1', 'so_dien_thoai' => '0903333333']);

        $this->customer1 = Account::create([
            'ten_dang_nhap' => 'khach1',
            'email' => 'khach1@test.com',
            'mat_khau' => Hash::make('Password@123'),
            'trang_thai' => AccountStatus::ACTIVE,
            'ma_nguoi_dung' => $profileKh1->ma_nguoi_dung,
            'ma_vai_tro' => $roleCustomer->ma_vai_tro,
        ]);

        $this->customer2 = Account::create([
            'ten_dang_nhap' => 'khach2',
            'email' => 'khach2@test.com',
            'mat_khau' => Hash::make('Password@123'),
            'trang_thai' => AccountStatus::ACTIVE,
            'ma_nguoi_dung' => $profileKh2->ma_nguoi_dung,
            'ma_vai_tro' => $roleCustomer->ma_vai_tro,
        ]);

        $this->staffUser = Account::create([
            'ten_dang_nhap' => 'nhanvien1',
            'email' => 'staff1@test.com',
            'mat_khau' => Hash::make('Password@123'),
            'trang_thai' => AccountStatus::ACTIVE,
            'ma_nguoi_dung' => $profileStaff->ma_nguoi_dung,
            'ma_vai_tro' => $roleStaff->ma_vai_tro,
        ]);

        $this->tokenCustomer1 = $this->customer1->createToken('test_token')->plainTextToken;
        $this->tokenCustomer2 = $this->customer2->createToken('test_token')->plainTextToken;
        $this->tokenStaff = $this->staffUser->createToken('test_token')->plainTextToken;
    }

    /**
     * Test CHT-02: Customer can create chat session and receive greeting from bot without initialMessage.
     */
    public function test_customer_can_create_chat_session(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenCustomer1)
            ->postJson('/api/v1/customer/chat/sessions', [
                'title' => 'Tư vấn ăn tối',
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'code' => 201,
            ])
            ->assertJsonPath('data.title', 'Tư vấn ăn tối')
            ->assertJsonPath('data.status', 'BOT')
            ->assertJsonPath('data.messages.0.senderType', 'BOT')
            ->assertJsonPath('data.messages.0.content', 'Em là chatbot của nhà hàng T-ResMK, em có thể giúp gì cho anh/chị nhỉ');

        $this->assertDatabaseHas('PHIEN_CHAT', [
            'ma_tai_khoan' => $this->customer1->ma_tai_khoan,
            'tieu_de' => 'Tư vấn ăn tối',
            'trang_thai' => 'BOT',
        ]);

        $this->assertDatabaseHas('TIN_NHAN_CHAT', [
            'loai_nguoi_gui' => 'BOT',
            'noi_dung' => 'Em là chatbot của nhà hàng T-ResMK, em có thể giúp gì cho anh/chị nhỉ',
        ]);
    }

    /**
     * Test CHT-01: Customer can list their chat sessions.
     */
    public function test_customer_can_list_chat_sessions(): void
    {
        ChatSession::create([
            'ma_tai_khoan' => $this->customer1->ma_tai_khoan,
            'tieu_de' => 'Phiên 1',
            'trang_thai' => ChatSessionStatus::BOT,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenCustomer1)
            ->getJson('/api/v1/customer/chat/sessions');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'code' => 200,
            ])
            ->assertJsonPath('data.data.0.title', 'Phiên 1');
    }

    /**
     * Test CHT-00: Customer can get active session with full messages or null if none.
     */
    public function test_customer_can_get_active_session(): void
    {
        $session = ChatSession::create([
            'ma_tai_khoan' => $this->customer1->ma_tai_khoan,
            'tieu_de' => 'Phiên đang hoạt động',
            'trang_thai' => ChatSessionStatus::BOT,
            'thoi_gian_tao' => now(),
        ]);

        $res = $this->withHeader('Authorization', 'Bearer ' . $this->tokenCustomer1)
            ->getJson('/api/v1/customer/chat/sessions/active');

        $res->assertStatus(200)
            ->assertJsonPath('data.sessionId', $session->ma_phien_chat)
            ->assertJsonPath('data.status', 'BOT');

        // Khi khách đóng phiên của chính mình
        $closeRes = $this->withHeader('Authorization', 'Bearer ' . $this->tokenCustomer1)
            ->postJson('/api/v1/customer/chat/sessions/' . $session->ma_phien_chat . '/close');

        $closeRes->assertStatus(200)
            ->assertJsonPath('data.status', 'CLOSED');

        // Gọi lại active phải ra null
        $activeNullRes = $this->withHeader('Authorization', 'Bearer ' . $this->tokenCustomer1)
            ->getJson('/api/v1/customer/chat/sessions/active');

        $activeNullRes->assertStatus(200)
            ->assertJsonPath('data', null);
    }

    /**
     * Test CHT-03: Customer cannot view another customer's session (403).
     */
    public function test_customer_cannot_view_other_customer_session(): void
    {
        $session = ChatSession::create([
            'ma_tai_khoan' => $this->customer2->ma_tai_khoan,
            'tieu_de' => 'Phiên của khách 2',
            'trang_thai' => ChatSessionStatus::BOT,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenCustomer1)
            ->getJson('/api/v1/customer/chat/sessions/' . $session->ma_phien_chat);

        $response->assertStatus(403);
    }

    /**
     * Test CHT-04: Customer sends message in BOT mode and gets bot reply.
     */
    public function test_customer_send_message_in_bot_mode_triggers_bot_reply(): void
    {
        $session = ChatSession::create([
            'ma_tai_khoan' => $this->customer1->ma_tai_khoan,
            'tieu_de' => 'Tư vấn món',
            'trang_thai' => ChatSessionStatus::BOT,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenCustomer1)
            ->postJson('/api/v1/customer/chat/sessions/' . $session->ma_phien_chat . '/messages', [
                'content' => 'Quán có món phở bò không?',
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'code' => 201,
            ])
            ->assertJsonPath('data.sentMessage.content', 'Quán có món phở bò không?')
            ->assertJsonPath('data.botReply.senderType', 'BOT');

        $this->assertDatabaseHas('TIN_NHAN_CHAT', [
            'ma_phien_chat' => $session->ma_phien_chat,
            'loai_nguoi_gui' => 'CUSTOMER',
            'noi_dung' => 'Quán có món phở bò không?',
        ]);
    }

    /**
     * Test CHT-05: Customer requests staff support.
     */
    public function test_customer_can_request_staff_support(): void
    {
        $session = ChatSession::create([
            'ma_tai_khoan' => $this->customer1->ma_tai_khoan,
            'tieu_de' => 'Yêu cầu nhân viên',
            'trang_thai' => ChatSessionStatus::BOT,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenCustomer1)
            ->postJson('/api/v1/customer/chat/sessions/' . $session->ma_phien_chat . '/request-staff');

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'WAITING_STAFF');

        $this->assertDatabaseHas('PHIEN_CHAT', [
            'ma_phien_chat' => $session->ma_phien_chat,
            'trang_thai' => 'WAITING_STAFF',
        ]);
    }

    /**
     * Test CHT-06 & CHT-07: Staff views pending sessions and assigns self.
     */
    public function test_staff_can_view_pending_and_assign_session(): void
    {
        $session = ChatSession::create([
            'ma_tai_khoan' => $this->customer1->ma_tai_khoan,
            'tieu_de' => 'Chờ hỗ trợ',
            'trang_thai' => ChatSessionStatus::WAITING_STAFF,
        ]);

        // 1. Staff xem danh sách pending
        $pendingRes = $this->withHeader('Authorization', 'Bearer ' . $this->tokenStaff)
            ->getJson('/api/v1/staff/chat/pending');

        $pendingRes->assertStatus(200)
            ->assertJsonPath('data.data.0.sessionId', $session->ma_phien_chat);

        // 2. Staff nhận xử lý phiên chat
        $assignRes = $this->withHeader('Authorization', 'Bearer ' . $this->tokenStaff)
            ->postJson('/api/v1/staff/chat/sessions/' . $session->ma_phien_chat . '/assign');

        $assignRes->assertStatus(200)
            ->assertJsonPath('data.status', 'STAFF_CONNECTED')
            ->assertJsonPath('data.staff.staffId', $this->staffUser->ma_tai_khoan);

        $this->assertDatabaseHas('PHIEN_CHAT', [
            'ma_phien_chat' => $session->ma_phien_chat,
            'trang_thai' => 'STAFF_CONNECTED',
            'ma_nhan_vien' => $this->staffUser->ma_tai_khoan,
        ]);
    }

    /**
     * Test CHT-08: Staff sends direct message to customer.
     */
    public function test_staff_can_send_message_to_customer(): void
    {
        $session = ChatSession::create([
            'ma_tai_khoan' => $this->customer1->ma_tai_khoan,
            'ma_nhan_vien' => $this->staffUser->ma_tai_khoan,
            'tieu_de' => 'Hỗ trợ trực tiếp',
            'trang_thai' => ChatSessionStatus::STAFF_CONNECTED,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenStaff)
            ->postJson('/api/v1/staff/chat/sessions/' . $session->ma_phien_chat . '/messages', [
                'content' => 'Chào anh Hùng, em đã liên hệ bếp đổi món cho mình rồi ạ!',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.senderType', 'STAFF')
            ->assertJsonPath('data.content', 'Chào anh Hùng, em đã liên hệ bếp đổi món cho mình rồi ạ!');

        $this->assertDatabaseHas('TIN_NHAN_CHAT', [
            'ma_phien_chat' => $session->ma_phien_chat,
            'loai_nguoi_gui' => 'STAFF',
            'ma_tai_khoan' => $this->staffUser->ma_tai_khoan,
        ]);
    }

    /**
     * Test CHT-09: Staff closes session and subsequent messages fail with 400.
     */
    public function test_staff_can_close_session_and_prevent_further_messages(): void
    {
        $session = ChatSession::create([
            'ma_tai_khoan' => $this->customer1->ma_tai_khoan,
            'ma_nhan_vien' => $this->staffUser->ma_tai_khoan,
            'tieu_de' => 'Phiên sắp đóng',
            'trang_thai' => ChatSessionStatus::STAFF_CONNECTED,
        ]);

        // Đóng phiên
        $closeRes = $this->withHeader('Authorization', 'Bearer ' . $this->tokenStaff)
            ->postJson('/api/v1/staff/chat/sessions/' . $session->ma_phien_chat . '/close');

        $closeRes->assertStatus(200)
            ->assertJsonPath('data.status', 'CLOSED');

        $this->app['auth']->forgetGuards();

        // Gửi tin nhắn vào phiên đã đóng phải báo lỗi 400
        $sendRes = $this->withHeader('Authorization', 'Bearer ' . $this->tokenCustomer1)
            ->postJson('/api/v1/customer/chat/sessions/' . $session->ma_phien_chat . '/messages', [
                'content' => 'Alo còn đó không em?',
            ]);

        $sendRes->assertStatus(400);
    }

    /**
     * Test RBAC: Customer cannot access staff chat endpoints (403).
     */
    public function test_customer_cannot_access_staff_chat_endpoints(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenCustomer1)
            ->getJson('/api/v1/staff/chat/pending');

        $response->assertStatus(403);
    }

    /**
     * Test CHT-10: Staff can list sessions with filters (status, assigned_to_me).
     */
    public function test_staff_can_list_sessions_with_filters(): void
    {
        // 1 phiên đang trả lời dở (STAFF_CONNECTED)
        ChatSession::create([
            'ma_tai_khoan' => $this->customer1->ma_tai_khoan,
            'ma_nhan_vien' => $this->staffUser->ma_tai_khoan,
            'tieu_de' => 'Phiên đang trả lời dở',
            'trang_thai' => ChatSessionStatus::STAFF_CONNECTED,
        ]);

        // 1 phiên đã đóng (CLOSED)
        ChatSession::create([
            'ma_tai_khoan' => $this->customer2->ma_tai_khoan,
            'ma_nhan_vien' => $this->staffUser->ma_tai_khoan,
            'tieu_de' => 'Phiên đã đóng',
            'trang_thai' => ChatSessionStatus::CLOSED,
        ]);

        // Lọc các phiên đang trả lời (STAFF_CONNECTED)
        $resConnected = $this->withHeader('Authorization', 'Bearer ' . $this->tokenStaff)
            ->getJson('/api/v1/staff/chat/sessions?status=STAFF_CONNECTED&assigned_to_me=true');

        $resConnected->assertStatus(200)
            ->assertJsonPath('data.data.0.status', 'STAFF_CONNECTED');

        // Lọc các phiên đã đóng (CLOSED)
        $resClosed = $this->withHeader('Authorization', 'Bearer ' . $this->tokenStaff)
            ->getJson('/api/v1/staff/chat/sessions?status=CLOSED');

        $resClosed->assertStatus(200)
            ->assertJsonPath('data.data.0.status', 'CLOSED');
    }

    /**
     * Test CHT-11: Staff can view session detail and full message history.
     */
    public function test_staff_can_view_session_detail_with_messages(): void
    {
        $session = ChatSession::create([
            'ma_tai_khoan' => $this->customer1->ma_tai_khoan,
            'ma_nhan_vien' => $this->staffUser->ma_tai_khoan,
            'tieu_de' => 'Chi tiết phiên',
            'trang_thai' => ChatSessionStatus::STAFF_CONNECTED,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenStaff)
            ->getJson('/api/v1/staff/chat/sessions/' . $session->ma_phien_chat);

        $response->assertStatus(200)
            ->assertJsonPath('data.sessionId', $session->ma_phien_chat)
            ->assertJsonPath('data.status', 'STAFF_CONNECTED');
    }
}
