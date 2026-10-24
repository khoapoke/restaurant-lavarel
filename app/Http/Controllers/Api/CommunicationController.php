<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\GuestNoticeMail;
use App\Models\CustomerNotice;
use App\Models\SupportConversation;
use App\Services\Shared\GuestCommunicationService;
use App\Services\Shared\SupportChatService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CommunicationController extends Controller
{
    use ApiResponse;

    public function notices(Request $request, GuestCommunicationService $communications): JsonResponse
    {
        $query = CustomerNotice::where('account_id', $request->user()->getKey());
        $page = (clone $query)->orderByDesc('id')->paginate(20);
        $feedback = DB::table('meal_feedback')->whereIn('notice_id', collect($page->items())->pluck('id'))->get(['notice_id', 'rating', 'comment'])->keyBy('notice_id');

        return $this->successResponse([
            'items' => collect($page->items())->map(fn ($notice): array => [
                'noticeId' => $notice->id, 'kind' => $notice->kind, 'title' => $notice->title,
                'body' => $notice->body, 'path' => $notice->path, 'readAt' => $notice->read_at?->toIso8601String(),
                'createdAt' => $notice->created_at->toIso8601String(), 'receipt' => $notice->kind === 'PAYMENT_RECEIPT' ? $this->receipt($notice, $communications) : null,
                'feedback' => $feedback->get($notice->id),
            ]),
            'unreadCount' => $query->whereNull('read_at')->count(),
            'pagination' => ['currentPage' => $page->currentPage(), 'totalPages' => $page->lastPage()],
        ]);
    }

    /** Biên nhận lịch sử (các dòng cùng công thức với email) và tình trạng hoàn tiền hiện tại tách riêng. */
    private function receipt(CustomerNotice $notice, GuestCommunicationService $communications): array
    {
        $update = $communications->receiptUpdate($notice);

        return [
            'paidAt' => $notice->snapshot['paidAt'] ?? null, 'rows' => GuestNoticeMail::receiptRows($notice->snapshot ?? []),
            'update' => $update, 'updateText' => GuestNoticeMail::receiptUpdateText($update),
        ];
    }

    public function read(Request $request, int $noticeId): JsonResponse
    {
        $notice = CustomerNotice::where('account_id', $request->user()->getKey())->findOrFail($noticeId);
        $notice->update(['read_at' => $notice->read_at ?? now()]);

        return $this->successResponse(null, 'Đã đánh dấu đã đọc.');
    }

    public function readAll(Request $request): JsonResponse
    {
        CustomerNotice::where('account_id', $request->user()->getKey())->whereNull('read_at')->update(['read_at' => now()]);

        return $this->successResponse(null, 'Đã đọc tất cả thông báo.');
    }

    public function feedback(Request $request, int $noticeId): JsonResponse
    {
        $data = $request->validate(['rating' => ['required', 'integer', 'between:1,5'], 'comment' => ['nullable', 'string', 'max:1000']]);
        $notice = CustomerNotice::where('account_id', $request->user()->getKey())->where('kind', 'FEEDBACK_REQUEST')->findOrFail($noticeId);
        DB::transaction(function () use ($notice, $data): void {
            CustomerNotice::whereKey($notice->id)->lockForUpdate()->firstOrFail();
            DB::table('meal_feedback')->updateOrInsert(['notice_id' => $notice->id], [
                'rating' => $data['rating'], 'comment' => $data['comment'] ?? null, 'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        return $this->successResponse(null, 'Mộc đã nhận đánh giá. Cảm ơn bạn!');
    }

    public function current(Request $request, SupportChatService $chat): JsonResponse
    {
        $conversation = SupportConversation::where('active_account_id', $request->user()->getKey())->first();
        if (! $conversation) {
            return $this->successResponse(['conversation' => null]);
        }
        $snapshot = $chat->snapshot($conversation);
        $chat->markSeen($conversation, $request->user(), $snapshot);

        return $this->successResponse(['conversation' => $snapshot]);
    }

    public function open(Request $request, SupportChatService $chat): JsonResponse
    {
        return $this->successResponse(['conversation' => $chat->snapshot($chat->open($request->user()))], 'Đã mở hội thoại.', 201);
    }

    public function show(Request $request, int $conversationId, SupportChatService $chat): JsonResponse
    {
        $request->validate(['before' => ['nullable', 'integer', 'min:1']]);
        $conversation = $chat->owned($request->user(), $conversationId);
        $snapshot = $chat->snapshot($conversation, false, $request->integer('before') ?: null);
        if (! $request->filled('before')) {
            $chat->markSeen($conversation, $request->user(), $snapshot);
        }

        return $this->successResponse(['conversation' => $snapshot]);
    }

    public function message(Request $request, int $conversationId, SupportChatService $chat): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:1500', 'not_regex:/^\s*$/u'], 'clientKey' => ['required', 'uuid']]);
        $conversation = $chat->owned($request->user(), $conversationId);
        $message = $chat->send($conversation, $request->user(), trim($data['body']), $data['clientKey']);
        $chat->dispatchPendingReply($message->id);

        return $this->successResponse(['conversation' => $chat->snapshot($conversation->fresh())], 'Đã gửi tin nhắn.');
    }

    public function action(Request $request, int $conversationId, SupportChatService $chat): JsonResponse
    {
        $data = $request->validate(['action' => ['required', 'in:handoff,close']]);
        $conversation = $chat->owned($request->user(), $conversationId);

        return $this->successResponse(['conversation' => $chat->snapshot($chat->transition($conversation, $request->user(), $data['action']))]);
    }
}
