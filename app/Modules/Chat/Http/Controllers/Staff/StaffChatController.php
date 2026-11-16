<?php

namespace App\Modules\Chat\Http\Controllers\Staff;

use App\Modules\Chat\Http\Requests\SendMessageRequest;
use App\Modules\Chat\Http\Resources\ChatMessageResource;
use App\Modules\Chat\Http\Resources\ChatSessionResource;
use App\Modules\Chat\Services\ChatService;
use App\Modules\Core\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StaffChatController extends BaseController
{
    protected ChatService $chatService;

    public function __construct(ChatService $chatService)
    {
        $this->chatService = $chatService;
    }

    /**
     * Danh sách phiên chat tổng hợp cho Nhân viên / Admin (CHT-10).
     * Hỗ trợ query: status (WAITING_STAFF, STAFF_CONNECTED, CLOSED, BOT), assigned_to_me (bool), search, perPage.
     */
    public function index(Request $request): JsonResponse
    {
        $sessions = $this->chatService->getStaffSessions(
            $request->user(),
            $request->only(['status', 'assigned_to_me', 'search']),
            (int) $request->query('perPage', 15)
        );

        return $this->successResponse(
            ChatSessionResource::collection($sessions)->response()->getData(true),
            'Staff chat sessions retrieved successfully',
            200
        );
    }

    /**
     * Xem chi tiết phiên chat và toàn bộ tin nhắn dành cho Nhân viên / Admin (CHT-11).
     */
    public function show(int $id, Request $request): JsonResponse
    {
        $session = $this->chatService->getSessionDetail($id, $request->user());

        return $this->successResponse(
            new ChatSessionResource($session),
            'Staff chat session details retrieved successfully',
            200
        );
    }

    /**
     * Danh sách các phiên chat đang chờ nhân viên tiếp nhận (CHT-06).
     */
    public function pending(Request $request): JsonResponse
    {
        $sessions = $this->chatService->getPendingStaffSessions(
            (int) $request->query('perPage', 15)
        );

        return $this->successResponse(
            ChatSessionResource::collection($sessions)->response()->getData(true),
            'Pending chat sessions retrieved successfully',
            200
        );
    }

    /**
     * Nhân viên nhận xử lý phiên chat (CHT-07).
     */
    public function assign(int $id, Request $request): JsonResponse
    {
        $session = $this->chatService->assignStaff($id, $request->user());

        return $this->successResponse(
            new ChatSessionResource($session),
            'Chat session assigned successfully',
            200
        );
    }

    /**
     * Nhân viên gửi tin nhắn trực tiếp cho khách (CHT-08).
     */
    public function sendMessage(int $id, SendMessageRequest $request): JsonResponse
    {
        $message = $this->chatService->sendStaffMessage(
            $id,
            $request->user(),
            $request->input('content')
        );

        return $this->successResponse(
            new ChatMessageResource($message),
            'Staff message sent successfully',
            201
        );
    }

    /**
     * Kết thúc và đóng phiên hỗ trợ khách hàng (CHT-09).
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

