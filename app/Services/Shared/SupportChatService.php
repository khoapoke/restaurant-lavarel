<?php

namespace App\Services\Shared;

use App\Enums\AccountStatus;
use App\Jobs\GenerateSupportReply;
use App\Models\Account;
use App\Models\Dish;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use Closure;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SupportChatService
{
    use DetectsConcurrencyErrors;

    public function __construct(private readonly GuestCommunicationService $communications, private readonly AuditLogger $audit) {}

    public function open(Account $customer): SupportConversation
    {
        return DB::transaction(function () use ($customer): SupportConversation {
            Account::whereKey($customer->getKey())->lockForUpdate()->firstOrFail();
            $existing = SupportConversation::where('active_account_id', $customer->getKey())->first();
            if ($existing) {
                return $existing;
            }
            $conversation = SupportConversation::create(['account_id' => $customer->getKey(), 'active_account_id' => $customer->getKey(), 'last_message_at' => now(), 'status' => 'AI']);
            $conversation->messages()->create(['sender' => 'SYSTEM', 'body' => 'Chào bạn, mình là trợ lý AI của Mộc. Mình có thể tư vấn món và hướng dẫn đặt bàn. Bạn luôn có thể chọn gặp nhân viên. Không gửi mật khẩu, OTP hoặc thông tin thẻ vào chat.']);

            return $conversation;
        });
    }

    public function owned(Account $customer, int $id): SupportConversation
    {
        return SupportConversation::where('account_id', $customer->getKey())->findOrFail($id);
    }

    public function send(SupportConversation $conversation, Account $actor, string $body, string $key, bool $staff = false): SupportMessage
    {
        return $this->atomically(function () use ($conversation, $actor, $body, $key, $staff): SupportMessage {
            $conversation = SupportConversation::lockForUpdate()->findOrFail($conversation->id);
            if (! $staff && (int) $conversation->account_id !== (int) $actor->getKey()) {
                throw new HttpException(404, 'Conversation not found');
            }
            $sender = $staff ? 'STAFF' : 'CUSTOMER';
            $hash = hash('sha256', $actor->getKey().'|'.$body);
            $existing = $conversation->messages()->where('sender', $sender)->where('client_key', $key)->first();
            if ($existing) {
                if (! hash_equals($existing->request_hash, $hash)) {
                    throw new HttpException(409, 'Khóa gửi tin đã được dùng cho nội dung khác.');
                }

                return $existing;
            }
            if ($conversation->status === 'CLOSED') {
                throw new HttpException(409, 'Hội thoại đã kết thúc. Vui lòng mở cuộc trò chuyện mới.');
            }
            if ($staff && ($conversation->status !== 'HUMAN' || (int) $conversation->assigned_to !== (int) $actor->getKey())) {
                throw new HttpException(409, 'Bạn cần tiếp nhận hội thoại trước khi trả lời.');
            }
            if ($conversation->messages()->count() >= 500) {
                throw new HttpException(409, 'Hội thoại đã đạt giới hạn. Hãy kết thúc và mở hội thoại mới.');
            }
            $aiPending = ! $staff && $conversation->status === 'AI';
            $message = $conversation->messages()->create([
                'sender' => $sender, 'sender_id' => $actor->getKey(), 'body' => $body,
                'client_key' => $key, 'request_hash' => $hash,
                'ai_status' => $aiPending ? 'PENDING' : null,
            ]);
            $conversation->update(['last_message_at' => now()]);
            if ($staff && (! $conversation->customer_seen_at || $conversation->customer_seen_at->lt(now()->subSeconds(30)))) {
                $this->communications->record($conversation->account, 'chat:'.$conversation->id.':'.now()->format('YmdH'), 'SUPPORT_REPLY',
                    'Nhân viên Mộc đã trả lời bạn', 'Bạn có tin nhắn mới từ đội ngũ Mộc. Mở cuộc trò chuyện để xem nội dung.', '/?support='.$conversation->id, 'conversation', $conversation->id, ['lastMessageId' => $message->id]);
            }

            return $message;
        });
    }

    /** HTTP và scheduler cùng giữ lease bền vững; retry một tin không tạo thêm job khi worker dừng. */
    public function dispatchPendingReply(int $messageId): void
    {
        if (! config('communications.enabled') || ! GuestCommunicationService::schemaReady() || $this->communications->queueStalled()) {
            return;
        }
        $lease = now()->subMinutes(config('communications.dispatch_lease_minutes'));
        $claimed = SupportMessage::whereKey($messageId)->where('ai_status', 'PENDING')
            ->whereHas('conversation', fn ($query) => $query->where('status', 'AI'))
            ->where(fn ($query) => $query->whereNull('ai_dispatched_at')->orWhere('ai_dispatched_at', '<', $lease))
            ->update(['ai_dispatched_at' => now()]);
        if ($claimed === 1) {
            GenerateSupportReply::dispatch($messageId);
        }
    }

    /**
     * Chuyển trạng thái hội thoại dưới khóa dòng: handoff/close (khách), claim/close/release (nhân viên),
     * reassign/takeover (ADMIN). Nhả/chuyển/tiếp quản bắt buộc lý do và ghi nhật ký. Về hàng chờ không bật lại AI.
     *
     * @param  array{reason?: string|null, assigneeId?: int|null}  $input
     */
    public function transition(SupportConversation $conversation, Account $actor, string $action, array $input = []): SupportConversation
    {
        return $this->atomically(function () use ($conversation, $actor, $action, $input): SupportConversation {
            $conversation = SupportConversation::lockForUpdate()->findOrFail($conversation->id);
            if ($conversation->status === 'CLOSED') {
                return $conversation;
            }
            $staff = $actor->roleCode() !== 'CUSTOMER';
            if (! $staff && (int) $conversation->account_id !== (int) $actor->getKey()) {
                throw new HttpException(404, 'Conversation not found');
            }
            $reason = trim((string) ($input['reason'] ?? ''));
            $previousAssignee = $conversation->assigned_to;
            $auditAction = null;
            if ($action === 'handoff') {
                if (in_array($conversation->status, ['WAITING', 'HUMAN'], true)) {
                    return $conversation;
                }
                $conversation->status = 'WAITING';
                $text = 'Đã chuyển yêu cầu tới nhân viên. Bạn có thể để lại nội dung; khi có người tiếp nhận, Mộc sẽ trả lời tại đây. Đây không phải xác nhận nhân viên đang trực.';
            } elseif ($action === 'claim' && $staff) {
                // Người phụ trách cũ đã bị khóa (dữ liệu trước khi có tự nhả) không giữ được hội thoại.
                if ($conversation->assigned_to && (int) $conversation->assigned_to !== (int) $actor->getKey() && $conversation->assignee?->trang_thai === AccountStatus::ACTIVE) {
                    throw new HttpException(409, 'Hội thoại đang được nhân viên khác xử lý.');
                }
                if ($conversation->status === 'HUMAN' && (int) $conversation->assigned_to === (int) $actor->getKey()) {
                    return $conversation;
                }
                $conversation->status = 'HUMAN';
                $conversation->assigned_to = $actor->getKey();
                $text = 'Nhân viên đã tiếp nhận. Trợ lý AI tạm dừng trả lời.';
            } elseif ($action === 'release' && $staff) {
                if ($conversation->status !== 'HUMAN') {
                    throw new HttpException(409, 'Chỉ trả về hàng chờ khi hội thoại đang có nhân viên phụ trách.');
                }
                if (! $actor->isAdmin() && (int) $conversation->assigned_to !== (int) $actor->getKey()) {
                    throw new HttpException(403, 'Chỉ người phụ trách hoặc quản lý được trả hội thoại về hàng chờ.');
                }
                $this->requireReason($reason);
                $conversation->status = 'WAITING';
                $conversation->assigned_to = null;
                $auditAction = 'SUPPORT_RELEASED';
                $text = 'Nhân viên phụ trách đã chuyển hội thoại về hàng chờ. Một nhân viên khác của Mộc sẽ tiếp nhận; lịch sử trò chuyện vẫn được giữ nguyên.';
            } elseif (in_array($action, ['reassign', 'takeover'], true) && $staff) {
                if (! $actor->isAdmin()) {
                    throw new HttpException(403, 'Chỉ quản lý được chuyển người phụ trách hoặc tiếp quản hội thoại.');
                }
                if (! in_array($conversation->status, ['WAITING', 'HUMAN'], true)) {
                    throw new HttpException(409, 'Chỉ chuyển người phụ trách khi hội thoại đang chờ hoặc đang có nhân viên hỗ trợ.');
                }
                $this->requireReason($reason);
                $target = $this->activeAgent($action === 'takeover' ? (int) $actor->getKey() : (int) ($input['assigneeId'] ?? 0));
                if ($conversation->status === 'HUMAN' && (int) $conversation->assigned_to === (int) $target->getKey()) {
                    throw new HttpException(409, 'Nhân viên này đang là người phụ trách hội thoại.');
                }
                $conversation->status = 'HUMAN';
                $conversation->assigned_to = $target->getKey();
                $auditAction = $action === 'takeover' ? 'SUPPORT_TAKEN_OVER' : 'SUPPORT_REASSIGNED';
                $text = $action === 'takeover'
                    ? 'Quản lý của Mộc đã tiếp quản hội thoại để hỗ trợ bạn. Lịch sử trò chuyện vẫn được giữ nguyên.'
                    : 'Hội thoại đã được chuyển cho một nhân viên khác của Mộc. Lịch sử trò chuyện vẫn được giữ nguyên.';
            } elseif ($action === 'close') {
                if ($staff && ! $actor->isAdmin() && (int) $conversation->assigned_to !== (int) $actor->getKey()) {
                    throw new HttpException(403, 'Chỉ người phụ trách hoặc quản lý được kết thúc hội thoại.');
                }
                $conversation->status = 'CLOSED';
                $conversation->active_account_id = null;
                $conversation->closed_at = now();
                $text = 'Hội thoại đã kết thúc. Cảm ơn bạn đã trò chuyện cùng Mộc.';
            } else {
                throw new HttpException(422, 'Thao tác không hợp lệ.');
            }
            $conversation->last_message_at = now();
            $conversation->save();
            $conversation->messages()->whereIn('ai_status', ['PENDING', 'PROCESSING'])->update(['ai_status' => 'SKIPPED']);
            $conversation->messages()->create(['sender' => 'SYSTEM', 'body' => $text]);
            if ($auditAction) {
                $this->audit->record($actor, $auditAction, 'SupportConversation', $conversation->id, $reason,
                    ['assignedTo' => $previousAssignee], ['assignedTo' => $conversation->assigned_to, 'status' => $conversation->status]);
            }

            return $conversation;
        });
    }

    /**
     * Nhân viên bị khóa: mọi hội thoại đang phụ trách trở về hàng chờ (không bật lại AI), giữ lịch sử,
     * báo rõ cho khách và ghi nhật ký theo người khóa.
     */
    public function releaseAssignmentsOf(Account $agent, Account $actor): int
    {
        $released = 0;
        SupportConversation::where('assigned_to', $agent->getKey())->where('status', 'HUMAN')->pluck('id')
            ->each(function (int $id) use ($agent, $actor, &$released): void {
                $released += $this->atomically(function () use ($id, $agent, $actor): int {
                    $conversation = SupportConversation::lockForUpdate()->find($id);
                    if (! $conversation || $conversation->status !== 'HUMAN' || (int) $conversation->assigned_to !== (int) $agent->getKey()) {
                        return 0;
                    }
                    $conversation->update(['status' => 'WAITING', 'assigned_to' => null, 'last_message_at' => now()]);
                    $conversation->messages()->create(['sender' => 'SYSTEM', 'body' => 'Nhân viên phụ trách hiện không thể tiếp tục hỗ trợ. Hội thoại đã trở về hàng chờ; một nhân viên khác của Mộc sẽ tiếp nhận và lịch sử trò chuyện vẫn được giữ nguyên.']);
                    $this->audit->record($actor, 'SUPPORT_RELEASED_ON_LOCK', 'SupportConversation', $conversation->id,
                        'Tài khoản nhân viên phụ trách bị khóa', ['assignedTo' => $agent->getKey()], ['assignedTo' => null, 'status' => 'WAITING']);

                    return 1;
                });
            });

        return $released;
    }

    /** Người nhận mới phải là STAFF/ADMIN đang hoạt động; khóa dòng để không gán cho tài khoản vừa bị khóa. */
    private function activeAgent(int $accountId): Account
    {
        $agent = $accountId ? Account::whereKey($accountId)->lockForUpdate()->first() : null;
        if (! $agent || $agent->roleCode() === 'CUSTOMER' || $agent->trang_thai !== AccountStatus::ACTIVE) {
            throw new HttpException(422, 'Người nhận phải là nhân viên hoặc quản lý đang hoạt động.');
        }

        return $agent;
    }

    private function requireReason(string $reason): void
    {
        if (mb_strlen($reason) < 5) {
            throw new HttpException(422, 'Vui lòng nhập lý do (tối thiểu 5 ký tự).');
        }
    }

    /**
     * Transaction có thử lại khi CSDL báo tranh chấp (SQLite "database is locked", deadlock MySQL);
     * hết lượt thử thì trả 409 rõ ràng thay vì 500. Mỗi lượt đọc lại dòng dưới khóa nên không mất quyền phụ trách.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function atomically(Closure $callback): mixed
    {
        try {
            return DB::transaction($callback, 3);
        } catch (QueryException|DeadlockException $exception) {
            // Trong transaction lồng nhau Laravel không thử lại mà ném DeadlockException.
            if ($this->causedByConcurrencyError($exception)) {
                throw new HttpException(409, 'Hội thoại đang được cập nhật bởi thao tác khác. Vui lòng tải lại và thử lại.');
            }
            throw $exception;
        }
    }

    public function snapshot(SupportConversation $conversation, bool $staff = false, ?int $before = null): array
    {
        $messages = $conversation->messages()->when($before, fn ($q) => $q->where('id', '<', $before))->orderByDesc('id')->limit(60)->get()->reverse()->values();
        $dishIds = $messages->flatMap(fn ($message) => $message->dish_ids ?? [])->unique();
        $dishes = Dish::publiclyListed()->where('con_ban', true)->whereIn('ma_mon_an', $dishIds)->get()->keyBy('ma_mon_an');

        return [
            'conversationId' => $conversation->id, 'status' => $conversation->status,
            'assignedName' => $conversation->assignee?->displayName(),
            'assignedTo' => $staff ? $conversation->assigned_to : null,
            'isMine' => $staff && (int) $conversation->assigned_to === (int) auth()->id(),
            'customerName' => $staff ? $conversation->account?->displayName() : null,
            'pendingAi' => $conversation->status === 'AI' && $conversation->messages()->whereIn('ai_status', ['PENDING', 'PROCESSING'])->exists(),
            'hasOlder' => $messages->isNotEmpty() && $conversation->messages()->where('id', '<', $messages->first()->id)->exists(),
            'messages' => $messages->map(fn ($message): array => [
                'messageId' => $message->id, 'sender' => $message->sender, 'body' => $message->body,
                'clientKey' => $message->client_key, 'createdAt' => $message->created_at->toIso8601String(),
                'dishes' => collect($message->dish_ids ?? [])->map(fn ($id) => $dishes->get($id))->filter()->map(fn ($dish): array => [
                    'dishId' => $dish->getKey(), 'dishName' => $dish->ten_mon_an, 'price' => (float) $dish->gia_tien,
                    'imageUrl' => $dish->hinh_anh, 'allergens' => $dish->chat_di_ung ?? [],
                ])->values(),
            ]),
        ];
    }

    public function markSeen(SupportConversation $conversation, Account $actor, array $snapshot, bool $staff = false): void
    {
        if ($staff && (int) $conversation->assigned_to !== (int) $actor->getKey()) {
            return;
        }
        if (! $staff && (int) $conversation->account_id !== (int) $actor->getKey()) {
            throw new HttpException(404, 'Conversation not found');
        }
        $prefix = $staff ? 'staff' : 'customer';
        $lastId = (int) $snapshot['messages']->max('messageId');
        SupportConversation::whereKey($conversation->id)->where($prefix.'_seen_message_id', '<', $lastId)->update([$prefix.'_seen_message_id' => $lastId]);
        $conversation->update([$prefix.'_seen_at' => now()]);
    }
}
