<?php

namespace App\Jobs;

use App\Enums\AccountStatus;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Services\Shared\GeminiAssistant;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;
use Throwable;

class GenerateSupportReply implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Mã lỗi tự đặt (message của RuntimeException) được phép ghi log; mọi lỗi khác chỉ ghi tên lớp. */
    private const KNOWN_FAILURES = ['AI_UNAVAILABLE', 'AI_QUOTA_REACHED', 'AI_STALE', 'AI_PROVIDER_UNAVAILABLE', 'AI_PROVIDER_QUOTA', 'AI_INVALID_RESPONSE', 'AI_TRUNCATED', 'AI_BLOCKED'];

    public int $tries = 1;

    public int $timeout = 40;

    public int $uniqueFor = 90;

    public function __construct(public readonly int $messageId)
    {
        $this->onConnection('database')->onQueue(config('communications.queue'))->afterCommit();
    }

    public function uniqueId(): string
    {
        return 'ai:'.$this->messageId;
    }

    public function handle(GeminiAssistant $assistant): void
    {
        $trigger = SupportMessage::find($this->messageId);
        if (! $trigger) {
            return;
        }
        Cache::lock('ai-conversation:'.$trigger->conversation_id, 60)->get(function () use ($assistant, $trigger): void {
            $trigger = $trigger->fresh();
            if (! $trigger) {
                return;
            }
            $conversation = $trigger->conversation;
            if ($conversation->account?->trang_thai !== AccountStatus::ACTIVE) {
                $trigger->update(['ai_status' => 'SKIPPED']);

                return;
            }
            if ($conversation->status !== 'AI' || $trigger->ai_status !== 'PENDING') {
                return;
            }
            if ($conversation->messages()->where('id', '<', $trigger->id)->whereIn('ai_status', ['PENDING', 'PROCESSING'])->exists()) {
                return;
            }
            $trigger->update(['ai_status' => 'PROCESSING']);
            try {
                $reply = $assistant->localReply($conversation, $trigger) ?? $this->generate($assistant, $conversation, $trigger);
            } catch (Throwable $exception) {
                $this->report($exception, $trigger);
                $reply = ['reply' => 'Trợ lý AI hiện chưa thể trả lời. Mộc đã chuyển câu hỏi tới nhân viên để hỗ trợ bạn; bạn có thể để lại thêm thông tin tại đây.', 'dishIds' => [], 'handoff' => true, 'tokens' => 0, 'source' => 'FALLBACK'];
            }
            $this->store($conversation, $trigger, $reply);
        });
    }

    /**
     * Chỉ trừ quota ngay trước khi gửi request tới Gemini, sau khi kiểm tra lại hội thoại vẫn do AI phụ trách
     * (thu hẹp cửa sổ gọi model sau khi nhân viên đã tiếp nhận). Request đã gửi đi thì không hủy được;
     * câu trả lời đến muộn bị bỏ trong `store()`.
     *
     * @return array{reply: string, dishIds: list<int>, handoff: bool, tokens: int, source: string}|null
     */
    private function generate(GeminiAssistant $assistant, SupportConversation $conversation, SupportMessage $trigger): ?array
    {
        if ($trigger->created_at->lt(now()->subMinutes(config('communications.ai.stale_after_minutes')))) {
            throw new RuntimeException('AI_STALE');
        }
        if (! $assistant->available()) {
            throw new RuntimeException('AI_UNAVAILABLE');
        }
        if (SupportConversation::whereKey($conversation->id)->value('status') !== 'AI' || $trigger->fresh()?->ai_status !== 'PROCESSING') {
            return null;
        }
        if (! $this->reserveQuota((int) $conversation->account_id)) {
            throw new RuntimeException('AI_QUOTA_REACHED');
        }

        return $assistant->generate($conversation, $trigger);
    }

    /** Kiểm tra và giữ chỗ quota tài khoản + toàn hệ thống trong cùng một khóa để các worker đồng thời không vượt hạn mức. */
    private function reserveQuota(int $accountId): bool
    {
        return Cache::lock('ai-daily-budget', 5)->block(2, function () use ($accountId): bool {
            $accountKey = 'ai-day:'.now()->toDateString().':'.$accountId;
            $globalKey = 'ai-day-global:'.now()->toDateString();
            if (RateLimiter::tooManyAttempts($accountKey, config('communications.ai.daily_account_limit')) || RateLimiter::tooManyAttempts($globalKey, config('communications.ai.daily_global_limit'))) {
                return false;
            }
            RateLimiter::hit($accountKey, 86400);
            RateLimiter::hit($globalKey, 86400);

            return true;
        });
    }

    /**
     * @param  array{reply: string, dishIds: list<int>, handoff: bool, tokens: int, source: string}|null  $reply
     */
    private function store(SupportConversation $conversation, SupportMessage $trigger, ?array $reply): void
    {
        DB::transaction(function () use ($conversation, $trigger, $reply): void {
            $current = SupportConversation::lockForUpdate()->find($conversation->id);
            if (! $reply || ! $current || $current->status !== 'AI' || $trigger->fresh()->ai_status !== 'PROCESSING') {
                $trigger->update(['ai_status' => 'SKIPPED']);

                return;
            }
            [$replyStatus, $triggerStatus] = match ($reply['source']) {
                'GEMINI' => ['GENERATED', 'DONE'],
                'LOCAL' => ['LOCAL', 'DONE_LOCAL'],
                default => ['FALLBACK', 'DONE_FALLBACK'],
            };
            $current->messages()->firstOrCreate(['reply_to' => $trigger->id], [
                'sender' => 'AI', 'body' => $reply['reply'], 'dish_ids' => $reply['dishIds'], 'tokens_used' => $reply['tokens'],
                'ai_status' => $replyStatus,
            ]);
            $trigger->update(['ai_status' => $triggerStatus]);
            $current->update(['last_message_at' => now(), 'status' => $reply['handoff'] ? 'WAITING' : 'AI']);
            if ($reply['handoff']) {
                $current->messages()->where('ai_status', 'PENDING')->update(['ai_status' => 'SKIPPED']);
            }
        });
    }

    /** Ghi mã lỗi vận hành; không ghi API key, request, nội dung chat hay phản hồi của nhà cung cấp. */
    private function report(Throwable $exception, SupportMessage $trigger): void
    {
        $code = match (true) {
            $exception instanceof RuntimeException && in_array($exception->getMessage(), self::KNOWN_FAILURES, true) => $exception->getMessage(),
            $exception instanceof ConnectionException => 'AI_PROVIDER_TIMEOUT',
            $exception instanceof LockTimeoutException => 'AI_BUDGET_LOCK_TIMEOUT',
            default => 'AI_UNEXPECTED_ERROR',
        };
        Log::warning('communications.ai_fallback', [
            'messageId' => $trigger->id, 'conversationId' => $trigger->conversation_id, 'code' => $code,
            'providerStatus' => in_array($code, ['AI_PROVIDER_UNAVAILABLE', 'AI_PROVIDER_QUOTA'], true) ? $exception->getCode() : null,
            'exception' => class_basename($exception),
        ]);
    }
}
