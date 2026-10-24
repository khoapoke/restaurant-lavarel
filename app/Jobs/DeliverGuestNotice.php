<?php

namespace App\Jobs;

use App\Mail\GuestNoticeMail;
use App\Models\NoticeDelivery;
use App\Services\Shared\GuestCommunicationService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

class DeliverGuestNotice implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 40;

    public int $uniqueFor = 90;

    public function __construct(public readonly int $deliveryId)
    {
        $this->onConnection('database')->onQueue(config('communications.queue'))->afterCommit();
    }

    public function uniqueId(): string
    {
        return 'notice:'.$this->deliveryId;
    }

    public function handle(GuestCommunicationService $communications): void
    {
        Cache::lock('notice-send:'.$this->deliveryId, 60)->get(function () use ($communications): void {
            $delivery = NoticeDelivery::with('notice.account')->find($this->deliveryId);
            if (! $delivery || ! in_array($delivery->status, ['PENDING', 'RETRY'], true) || $delivery->available_at->isFuture() || ! config('communications.email_enabled')) {
                return;
            }
            $notice = $delivery->notice;
            // Hạn tính từ lúc tạo thông báo; retry hay bật lại email sau thời gian dài không gia hạn.
            if (now()->gte($delivery->expires_at ?? $communications->emailExpiresAt($notice))) {
                $delivery->update(['status' => 'SKIPPED', 'error_code' => 'EMAIL_EXPIRED']);

                return;
            }
            if ($delivery->recipient !== $notice->account?->email) {
                $delivery->update(['status' => 'SKIPPED', 'error_code' => 'RECIPIENT_CHANGED']);

                return;
            }
            if (! $communications->deliverable($notice) || ! filter_var($delivery->recipient, FILTER_VALIDATE_EMAIL)) {
                $delivery->update(['status' => 'SKIPPED', 'error_code' => 'NO_LONGER_APPLICABLE']);

                return;
            }
            if (in_array(config('mail.default'), ['log', 'array'], true) && ! app()->runningUnitTests()) {
                $delivery->update(['status' => 'FAILED', 'error_code' => 'REAL_MAILER_REQUIRED']);

                return;
            }
            $delivery->update(['status' => 'SENDING', 'attempts' => $delivery->attempts + 1, 'error_code' => null]);
            try {
                $recipient = app()->isProduction() ? $delivery->recipient : (config('communications.test_recipient') ?: $delivery->recipient);
                $sent = Mail::to($recipient)->send(new GuestNoticeMail($notice, $communications->receiptUpdate($notice)));
                if (! $sent && ! Mail::isFake()) {
                    $delivery->update(['status' => 'SKIPPED', 'error_code' => 'MAIL_CANCELLED']);

                    return;
                }
                $simulated = in_array(config('mail.default'), ['array', 'log'], true);
                $delivery->update(['status' => $simulated ? 'SIMULATED' : 'ACCEPTED', 'accepted_at' => $simulated ? null : now(), 'message_id' => $sent?->getMessageId()]);
            } catch (Throwable $exception) {
                [$errorCode, $smtpCode] = $this->classify($exception);
                $retry = $errorCode === 'SMTP_TEMPORARY_FAILURE' && $delivery->attempts < 3;
                $delivery->update([
                    'status' => $retry ? 'RETRY' : 'FAILED', 'error_code' => $errorCode,
                    'available_at' => now()->addMinutes(2 ** $delivery->attempts),
                    ...(GuestCommunicationService::schemaReady() ? ['dispatched_at' => null] : []),
                ]);
                // Không ghi message của exception: có thể chứa tên đăng nhập SMTP, địa chỉ hoặc nội dung máy chủ trả về.
                Log::warning('communications.email_failed', [
                    'deliveryId' => $delivery->id, 'errorCode' => $errorCode, 'smtpCode' => $smtpCode,
                    'attempts' => $delivery->attempts, 'exception' => class_basename($exception),
                ]);
            }
        });
    }

    /**
     * Phân loại theo mã phản hồi SMTP mà Symfony Mailer gắn vào exception (`getCode()` của
     * UnexpectedResponseException / TransportException khi xác thực). Chỉ 4xx — máy chủ trả lời rõ là
     * chưa nhận thư, thử lại sau — mới được tự gửi lại. Timeout, mất kết nối (mã 0) có thể xảy ra sau
     * khi máy chủ đã nhận DATA nên không đoán là chưa gửi; 5xx là bị từ chối. Cả hai cần quản trị kiểm tra.
     *
     * @return array{0: string, 1: int|null}
     */
    private function classify(Throwable $exception): array
    {
        if (! $exception instanceof TransportExceptionInterface) {
            return ['SEND_ERROR_REVIEW_REQUIRED', null];
        }
        $code = (int) $exception->getCode();

        return match (true) {
            $code >= 400 && $code < 500 => ['SMTP_TEMPORARY_FAILURE', $code],
            $code >= 500 && $code < 600 => ['SMTP_FAILURE_REVIEW_REQUIRED', $code],
            default => ['SEND_OUTCOME_UNKNOWN_REVIEW_REQUIRED', null],
        };
    }
}
