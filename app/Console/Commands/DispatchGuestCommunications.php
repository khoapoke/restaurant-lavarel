<?php

namespace App\Console\Commands;

use App\Jobs\DeliverGuestNotice;
use App\Models\NoticeDelivery;
use App\Models\SupportMessage;
use App\Services\Shared\GuestCommunicationService;
use App\Services\Shared\SupportChatService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class DispatchGuestCommunications extends Command
{
    protected $signature = 'dining:communications:dispatch';

    protected $description = 'Tạo nhắc lịch và đưa outbox email / câu hỏi AI vào hàng đợi communications';

    public function handle(GuestCommunicationService $communications, SupportChatService $chat): int
    {
        if (! config('communications.enabled')) {
            return self::SUCCESS;
        }
        if (! GuestCommunicationService::schemaReady()) {
            Log::warning('communications.migration_pending', ['migration' => '2026_10_23_233204_harden_guest_communications_delivery_and_feedback']);
            $this->warn('Chưa áp migration communications mới: tạm dừng nhắc lịch, mời đánh giá và đưa email/AI vào hàng đợi.');

            return self::SUCCESS;
        }
        $communications->reminders();
        $communications->feedbackInvitations();
        $communications->unreadSupportReplies();
        // Dòng outbox tạo trước khi có hạn gửi: bổ sung hạn theo lúc tạo thông báo trước khi lọc quá hạn.
        NoticeDelivery::with('notice')->whereIn('status', ['PENDING', 'RETRY'])->whereNull('expires_at')->limit(500)->get()
            ->each(fn (NoticeDelivery $delivery) => $delivery->update(['expires_at' => $communications->emailExpiresAt($delivery->notice)]));
        NoticeDelivery::whereIn('status', ['PENDING', 'RETRY'])->whereNotNull('expires_at')->where('expires_at', '<=', now())
            ->update(['status' => 'SKIPPED', 'error_code' => 'EMAIL_EXPIRED', 'dispatched_at' => null]);
        NoticeDelivery::where('status', 'SENDING')->where('updated_at', '<', now()->subMinutes(5))
            ->update(['status' => 'FAILED', 'error_code' => 'SEND_OUTCOME_UNKNOWN_REVIEW_REQUIRED']);
        SupportMessage::where('ai_status', 'PROCESSING')->where('updated_at', '<', now()->subMinutes(5))
            ->update(['ai_status' => 'PENDING', 'ai_dispatched_at' => null]);

        if ($communications->queueStalled()) {
            // Worker không lấy việc: không đẩy thêm bản sao; việc đã có trong hàng đợi chạy khi worker hoạt động lại.
            Log::warning('communications.queue_stalled', ['queue' => config('communications.queue')]);
            $this->warn('Hàng đợi communications đang tồn việc cũ; chưa đưa thêm việc mới.');

            return self::SUCCESS;
        }
        $lease = now()->subMinutes(config('communications.dispatch_lease_minutes'));
        $unclaimed = fn ($query, string $column) => $query->where(fn ($q) => $q->whereNull($column)->orWhere($column, '<', $lease));
        if (config('communications.email_enabled')) {
            NoticeDelivery::whereIn('status', ['PENDING', 'RETRY'])->where('available_at', '<=', now())
                ->where(fn ($q) => $unclaimed($q, 'dispatched_at'))->orderBy('id')->limit(200)->pluck('id')
                ->each(function (int $id) use ($unclaimed): void {
                    $claimed = NoticeDelivery::whereKey($id)->whereIn('status', ['PENDING', 'RETRY'])
                        ->where(fn ($q) => $unclaimed($q, 'dispatched_at'))->update(['dispatched_at' => now()]);
                    if ($claimed === 1) {
                        DeliverGuestNotice::dispatch($id);
                    }
                });
        }
        SupportMessage::where('ai_status', 'PENDING')->whereHas('conversation', fn ($q) => $q->where('status', 'AI'))
            ->where(fn ($q) => $unclaimed($q, 'ai_dispatched_at'))->orderBy('id')->limit(100)->pluck('id')
            ->each(fn (int $id) => $chat->dispatchPendingReply($id));
        $this->info('Đã kiểm tra outbox. ACCEPTED chỉ nghĩa là máy chủ mail đã nhận, không phải khách đã đọc.');

        return self::SUCCESS;
    }
}
