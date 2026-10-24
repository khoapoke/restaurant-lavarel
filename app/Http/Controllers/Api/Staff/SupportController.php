<?php

namespace App\Http\Controllers\Api\Staff;

use App\Enums\AccountStatus;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\NoticeDelivery;
use App\Models\SupportConversation;
use App\Services\Shared\AuditLogger;
use App\Services\Shared\GuestCommunicationService;
use App\Services\Shared\SupportChatService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SupportController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['status' => ['nullable', 'in:WAITING,HUMAN,CLOSED,AI']]);
        $query = SupportConversation::with(['account.userProfile', 'assignee.userProfile'])->withCount([
            'messages as new_messages' => fn ($q) => $q->where('sender', 'CUSTOMER')->whereColumn('support_messages.id', '>', 'support_conversations.staff_seen_message_id'),
        ]);
        if (isset($data['status'])) {
            $query->where('status', $data['status']);
        } else {
            $query->whereIn('status', ['WAITING', 'HUMAN']);
        }
        $page = $query->orderByDesc('last_message_at')->orderByDesc('id')->paginate(30);

        return $this->successResponse([
            'items' => collect($page->items())->map(fn ($conversation): array => [
                'conversationId' => $conversation->id, 'customerName' => $conversation->account?->displayName(),
                'status' => $conversation->status, 'assignedName' => $conversation->assignee?->displayName(),
                'isMine' => (int) $conversation->assigned_to === (int) $request->user()->getKey(),
                'unreadCount' => $conversation->new_messages, 'lastMessageAt' => $conversation->last_message_at->toIso8601String(),
            ]),
            'waitingCount' => SupportConversation::where('status', 'WAITING')->count(),
            'attentionCount' => SupportConversation::where(function ($query) use ($request): void {
                $query->where('status', 'WAITING')->orWhere(fn ($mine) => $mine->where('status', 'HUMAN')->where('assigned_to', $request->user()->getKey())
                    ->whereHas('messages', fn ($messages) => $messages->where('sender', 'CUSTOMER')->whereColumn('support_messages.id', '>', 'support_conversations.staff_seen_message_id')));
            })->count(),
            'pagination' => ['currentPage' => $page->currentPage(), 'totalPages' => $page->lastPage()],
        ]);
    }

    public function show(Request $request, SupportConversation $conversation, SupportChatService $chat): JsonResponse
    {
        $request->validate(['before' => ['nullable', 'integer', 'min:1']]);
        $snapshot = $chat->snapshot($conversation, true, $request->integer('before') ?: null);
        if (! $request->filled('before')) {
            $chat->markSeen($conversation, $request->user(), $snapshot, true);
        }

        return $this->successResponse(['conversation' => $snapshot]);
    }

    public function message(Request $request, SupportConversation $conversation, SupportChatService $chat): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:1500', 'not_regex:/^\s*$/u'], 'clientKey' => ['required', 'uuid']]);
        $chat->send($conversation, $request->user(), trim($data['body']), $data['clientKey'], true);

        return $this->successResponse(['conversation' => $chat->snapshot($conversation->fresh(), true)]);
    }

    public function action(Request $request, SupportConversation $conversation, SupportChatService $chat): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:claim,close,release,reassign,takeover'],
            'reason' => ['required_if:action,release,reassign,takeover', 'nullable', 'string', 'min:5', 'max:500'],
            'assigneeId' => ['required_if:action,reassign', 'nullable', 'integer'],
        ]);

        return $this->successResponse(['conversation' => $chat->snapshot($chat->transition($conversation, $request->user(), $data['action'], $data), true)]);
    }

    /** Nhân viên/quản lý đang hoạt động có thể nhận hội thoại được chuyển (chỉ ADMIN). */
    public function assignees(): JsonResponse
    {
        return $this->successResponse(['items' => Account::with('userProfile')->where('trang_thai', AccountStatus::ACTIVE->value)
            ->get()->filter(fn (Account $account): bool => $account->roleCode() !== 'CUSTOMER')
            ->map(fn (Account $account): array => ['accountId' => $account->getKey(), 'name' => $account->displayName(), 'role' => $account->roleCode()])
            ->values()]);
    }

    public function deliveries(Request $request): JsonResponse
    {
        $request->validate(['status' => ['nullable', 'in:PENDING,RETRY,SENDING,ACCEPTED,SKIPPED,FAILED,SIMULATED']]);
        $page = NoticeDelivery::with('notice')->when($request->filled('status'), fn ($query) => $query->where('status', $request->input('status')))->orderByDesc('id')->paginate(30);

        return $this->successResponse([
            'emailEnabled' => (bool) config('communications.email_enabled'), 'mailDriver' => config('mail.default'),
            'testMode' => ! app()->isProduction() && (bool) config('communications.test_recipient'),
            'feedback' => DB::table('meal_feedback')->orderByDesc('id')->limit(10)->get(['rating', 'comment', 'created_at']),
            'items' => collect($page->items())->map(fn ($delivery): array => [
                'deliveryId' => $delivery->id, 'title' => $delivery->notice?->title, 'status' => $delivery->status,
                'attempts' => $delivery->attempts, 'errorCode' => $delivery->error_code,
                'createdAt' => $delivery->created_at->toIso8601String(), 'acceptedAt' => $delivery->accepted_at?->toIso8601String(),
                'expiresAt' => $delivery->expires_at?->toIso8601String(),
            ]),
            'pagination' => ['currentPage' => $page->currentPage(), 'totalPages' => $page->lastPage()],
        ]);
    }

    public function retryDelivery(Request $request, NoticeDelivery $delivery, GuestCommunicationService $communications): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:500'], 'acknowledgeDuplicateRisk' => ['required', 'accepted']]);
        DB::transaction(function () use ($request, $delivery, $data, $communications): void {
            $locked = NoticeDelivery::with('notice')->lockForUpdate()->findOrFail($delivery->id);
            abort_unless($locked->status === 'FAILED', 409, 'Chỉ gửi lại email lỗi sau khi đã kiểm tra kết quả gửi trước đó.');
            // Gửi lại không gia hạn tuổi thư: quá hạn thì thông báo vẫn có trong ứng dụng, email không gửi nữa.
            abort_if(now()->gte($locked->expires_at ?? $communications->emailExpiresAt($locked->notice)), 409, 'Email đã quá hạn gửi; thông báo vẫn có trong ứng dụng của khách.');
            $before = ['status' => $locked->status, 'attempts' => $locked->attempts, 'errorCode' => $locked->error_code];
            $locked->update(['status' => 'PENDING', 'available_at' => now(), 'error_code' => null, ...(GuestCommunicationService::schemaReady() ? ['dispatched_at' => null] : [])]);
            app(AuditLogger::class)->record($request->user(), 'EMAIL_RETRY_REQUESTED', 'NoticeDelivery', $locked->id, $data['reason'], $before, ['status' => 'PENDING']);
        });

        return $this->successResponse(null, 'Đã đưa email vào hàng chờ gửi lại.');
    }
}
