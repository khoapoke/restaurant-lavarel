<?php

namespace App\Modules\Chat\Http\Controllers\Customer;

use App\Modules\Chat\Http\Requests\CreateChatSessionRequest;
use App\Modules\Chat\Http\Requests\SendMessageRequest;
use App\Modules\Chat\Http\Resources\ChatMessageResource;
use App\Modules\Chat\Http\Resources\ChatSessionResource;
use App\Modules\Chat\Services\ChatService;
use App\Modules\Core\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerChatController extends BaseController
{
    protected ChatService $chatService;

    public function __construct(ChatService $chatService)
    {
        $this->chatService = $chatService;
    }

    /**
     * Lấy danh sách phiên chat của khách hàng (CHT-01).
     */
    public function index(Request $request): JsonResponse
    {
        $sessions = $this->chatService->getCustomerSessions(
            $request->user(),
            $request->query('status')
        );

        return $this->successResponse(
            ChatSessionResource::collection($sessions)->response()->getData(true),
            'Customer chat sessions retrieved successfully',
            200
        );
    }

    /**
     * Lấy phiên chat đang hoạt động gần nhất của khách hàng (kèm lịch sử tin nhắn) (CHT-00).
     */
    public function active(Request $request): JsonResponse
    {
        $session = $this->chatService->getActiveCustomerSession($request->user());

        return $this->successResponse(
            $session ? new ChatSessionResource($session) : null,
            'Active chat session retrieved successfully',
            200
        );
    }

    /**
     * Khởi tạo phiên chat mới (CHT-02).
     */
    public function store(CreateChatSessionRequest $request): JsonResponse
    {
        $session = $this->chatService->createSession(
            $request->user(),
            $request->input('title'),
            $request->input('initialMessage')
        );

        return $this->successResponse(
            new ChatSessionResource($session),
            'Chat session initialized successfully',
            201
        );
    }

    /**
     * Xem chi tiết phiên chat và toàn bộ tin nhắn (CHT-03).
     */
    public function show(int $id, Request $request): JsonResponse
    {
        $session = $this->chatService->getSessionDetail($id, $request->user());

        return $this->successResponse(
            new ChatSessionResource($session),
            'Chat session details retrieved successfully',
            200
        );
    }

    /**
     * Gửi tin nhắn mới (Tự động kích hoạt Bot nếu đang ở chế độ BOT) (CHT-04).
     */
    public function sendMessage(int $id, SendMessageRequest $request): JsonResponse
    {
        $result = $this->chatService->sendCustomerMessage(
            $id,
            $request->user(),
            $request->input('content')
        );

        return $this->successResponse([
            'sentMessage' => new ChatMessageResource($result['customerMessage']),
            'botReply' => $result['botReply'] ? new ChatMessageResource($result['botReply']) : null,
            'session' => [
                'sessionId' => $result['session']->ma_phien_chat,
                'status' => $result['session']->trang_thai->value,
                'title' => $result['session']->tieu_de,
            ],
        ], 'Message sent successfully', 201);
    }

    /**
     * Yêu cầu chuyển giao sang nhân viên hỗ trợ (CHT-05).
     */
    public function requestStaff(int $id, Request $request): JsonResponse
    {
        $session = $this->chatService->requestStaff($id, $request->user());

        return $this->successResponse(
            new ChatSessionResource($session),
            'Staff support requested successfully',
            200
        );
    }

    /**
     * Khách hàng chủ động đóng phiên chat của mình (CHT-09C).
     */
    public function close(int $id, Request $request): JsonResponse
    {
        $session = $this->chatService->closeSession($id, $request->user());

        return $this->successResponse(
            new ChatSessionResource($session),
            'Chat session closed successfully',
            200
        );
    }
}

