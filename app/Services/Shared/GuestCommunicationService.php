<?php

namespace App\Services\Shared;

use App\Enums\AccountStatus;
use App\Enums\FulfillmentStatus;
use App\Enums\FulfillmentType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Models\Account;
use App\Models\CustomerNotice;
use App\Models\NoticeDelivery;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\SupportConversation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class GuestCommunicationService
{
    public function __construct(private readonly ReservationSchedule $schedule) {}

    /**
     * Chuyển tiếp: code mới có thể chạy trước khi DB áp migration 2026_10_23_233204 (môi trường dev đang chạy).
     * Khi thiếu cột mới, không ghi các cột đó và dispatcher tạm dừng thay vì làm rollback nghiệp vụ đặt bàn/đơn.
     * Ghi nhớ trong vòng đời request/lệnh; worker chạy lâu cần khởi động lại sau khi migrate.
     */
    public static function schemaReady(): bool
    {
        return once(fn (): bool => Schema::hasColumns('notice_deliveries', ['expires_at', 'dispatched_at'])
            && Schema::hasColumn('customer_notices', 'limit_key') && Schema::hasColumn('support_messages', 'ai_dispatched_at'));
    }

    /** Có việc sẵn sàng chạy nhưng worker chưa nhận; áp dụng cho cả HTTP lẫn scheduler. */
    public function queueStalled(): bool
    {
        return DB::table(config('queue.connections.database.table', 'jobs'))
            ->where('queue', config('communications.queue'))->whereNull('reserved_at')
            ->where('available_at', '<=', now()->subMinutes(config('communications.stalled_queue_minutes'))->getTimestamp())
            ->exists();
    }

    public function record(Account $account, string $key, string $kind, string $title, string $body, string $path, ?string $entityType = null, ?int $entityId = null, array $snapshot = []): CustomerNotice
    {
        return DB::transaction(function () use ($account, $key, $kind, $title, $body, $path, $entityType, $entityId, $snapshot): CustomerNotice {
            $notice = CustomerNotice::firstOrCreate(['event_key' => $key], [
                'account_id' => $account->getKey(), 'kind' => $kind, 'title' => $title,
                'body' => $body, 'path' => $path, 'entity_type' => $entityType,
                'entity_id' => $entityId, 'snapshot' => $snapshot,
            ]);
            if ($notice->wasRecentlyCreated) {
                $this->queueEmail($notice, $account);
            }

            return $notice;
        });
    }

    private function queueEmail(CustomerNotice $notice, Account $account): void
    {
        if (filter_var($account->email, FILTER_VALIDATE_EMAIL)) {
            NoticeDelivery::create([
                'notice_id' => $notice->id, 'recipient' => $account->email, 'available_at' => now(), 'status' => 'PENDING',
                ...(self::schemaReady() ? ['expires_at' => $this->emailExpiresAt($notice)] : []),
            ]);
        }
    }

    /**
     * Hạn gửi email tự động của một thông báo, tính từ lúc tạo thông báo; không phụ thuộc lần thử nên
     * retry không bao giờ kéo dài tuổi thư. Nhắc lịch hết hạn đúng giờ hẹn.
     */
    public function emailExpiresAt(CustomerNotice $notice): Carbon
    {
        if ($notice->kind === 'RESERVATION_REMINDER' && isset($notice->snapshot['time'])) {
            return Carbon::parse($notice->snapshot['time']);
        }
        $status = $notice->snapshot['status'] ?? null;
        $group = match ($notice->kind) {
            'RESERVATION_UPDATED' => in_array($status, ['CANCELLED', 'REJECTED', 'EXPIRED', 'NO_SHOW'], true) ? 'closure' : 'update',
            'ORDER_UPDATED' => $status === 'CANCELLED' || ($notice->snapshot['fulfillment'] ?? null) === FulfillmentStatus::DELIVERY_FAILED->value ? 'closure' : 'update',
            'SUPPORT_REPLY' => 'support_reply',
            'FEEDBACK_REQUEST' => 'feedback',
            'PAYMENT_RECEIPT' => 'receipt',
            default => 'default',
        };
        $minutes = config('communications.email_ttl_minutes');

        return ($notice->created_at ?? now())->copy()->addMinutes((int) ($minutes[$group] ?? $minutes['default']));
    }

    public function changed(Model $model, bool $created): void
    {
        if ($model instanceof Reservation) {
            $this->reservation($model, $created);
        } elseif ($model instanceof Order) {
            $this->order($model, $created);
        } elseif ($model instanceof Payment && $this->settled($model, $created)) {
            $order = $model->order;
            $account = $order ? $this->orderCustomer($order) : null;
            if ($account) {
                $paidAt = $model->thanh_toan_luc ?? now();
                $this->record($account, 'payment:'.$model->getKey().':'.$model->communication_revision, 'PAYMENT_RECEIPT',
                    'Mộc đã ghi nhận thanh toán', 'Mộc đã ghi nhận thanh toán đủ cho đơn #'.$order->getKey().' lúc '.$paidAt->format('H:i d/m/Y').'. Đây là biên nhận của nhà hàng tại thời điểm thu tiền, không phải hóa đơn thuế.',
                    $order->loai_phuc_vu === FulfillmentType::DINE_IN ? '/reservations' : '/orders?orderId='.$order->getKey(),
                    'order', $order->getKey(), $this->receiptSnapshot($order, $model, $paidAt));
            }
        }
    }

    /**
     * Biên nhận khi khoản phải thu vừa được thu đủ: PAID, hoặc thu đủ phần còn lại sau bồi hoàn
     * (sổ tiền ghi PARTIALLY_REFUNDED cho trường hợp này, ADR-028).
     */
    private function settled(Payment $payment, bool $created): bool
    {
        if ($payment->trang_thai === PaymentStatus::PAID) {
            return $created || $payment->wasChanged('trang_thai');
        }
        $previous = $created ? null : $payment->getOriginal('trang_thai');
        $order = $payment->order;

        return $payment->trang_thai === PaymentStatus::PARTIALLY_REFUNDED && $order
            && ($created || in_array($previous, [PaymentStatus::UNPAID, PaymentStatus::PARTIALLY_PAID, PaymentStatus::FAILED], true))
            && $order->trang_thai !== OrderStatus::CANCELLED
            && (float) ($order->so_tien_giam_tru ?? 0) > 0
            && (float) $payment->so_tien_da_thu + 0.009 >= $order->payableAmount();
    }

    /**
     * Snapshot biên nhận tại thời điểm thu tiền: tiền món + phí giao − giảm trừ/bồi hoàn (+ điều chỉnh khác)
     * = tổng phải thu. Mọi số lấy từ mô hình tiền hiện có (DON_HANG, THANH_TOAN) và không sửa lại sau này.
     *
     * @return array{receiptVersion: int, paidAt: string, lines: list<array{name: string, quantity: int, amount: float}>, itemsSubtotal: float, deliveryFee: float, compensation: float, otherAdjustment: float, total: float, amountPaid: float, amountRefunded: float}
     */
    public function receiptSnapshot(Order $order, Payment $payment, Carbon $paidAt): array
    {
        $lines = $order->items()->with('dish')->get()->filter(fn ($item) => $item->billedAmount() > 0)
            ->map(fn ($item): array => ['name' => $item->ten_mon_snapshot ?? $item->dish?->ten_mon_an ?? 'Món ăn', 'quantity' => (int) ($item->so_luong_tinh_tien ?? $item->so_luong), 'amount' => $item->billedAmount()])->values()->all();
        $itemsSubtotal = round(array_sum(array_column($lines, 'amount')), 2);
        $deliveryFee = round((float) $order->phi_giao_hang, 2);
        $compensation = round((float) ($order->so_tien_giam_tru ?? 0), 2);
        $total = $order->payableAmount();
        $otherAdjustment = round($total - ($itemsSubtotal + $deliveryFee - $compensation), 2);

        return [
            'receiptVersion' => 2, 'paidAt' => $paidAt->toIso8601String(), 'lines' => $lines,
            'itemsSubtotal' => $itemsSubtotal, 'deliveryFee' => $deliveryFee, 'compensation' => $compensation,
            'otherAdjustment' => abs($otherAdjustment) < 0.01 ? 0.0 : $otherAdjustment, 'total' => $total,
            'amountPaid' => round((float) $payment->so_tien_da_thu, 2), 'amountRefunded' => round((float) $payment->so_tien_da_hoan, 2),
        ];
    }

    /**
     * Tình trạng hiện tại, tách khỏi biên nhận lịch sử: chỉ có khi sau thời điểm thu đã hoàn tiền, bồi hoàn
     * hoặc hủy đơn. Bồi hoàn là một phần của số đã hoàn (không cộng hai lần). Đọc từ sổ tiền đã đồng bộ.
     *
     * @return array{asOf: string, refundedAfterReceipt: float, compensationAfterReceipt: float, orderCancelled: bool}|null
     */
    public function receiptUpdate(CustomerNotice $notice): ?array
    {
        if ($notice->kind !== 'PAYMENT_RECEIPT') {
            return null;
        }
        $order = Order::with('payment')->find($notice->entity_id);
        if (! $order) {
            return null;
        }
        $refunded = round((float) ($order->payment?->so_tien_da_hoan ?? 0) - (float) ($notice->snapshot['amountRefunded'] ?? 0), 2);
        $compensation = round((float) ($order->so_tien_giam_tru ?? 0) - (float) ($notice->snapshot['compensation'] ?? 0), 2);
        $cancelled = $order->trang_thai === OrderStatus::CANCELLED;
        if ($refunded < 0.01 && $compensation < 0.01 && ! $cancelled) {
            return null;
        }

        return ['asOf' => now()->toIso8601String(), 'refundedAfterReceipt' => max(0.0, $refunded), 'compensationAfterReceipt' => max(0.0, $compensation), 'orderCancelled' => $cancelled];
    }

    private function reservation(Reservation $reservation, bool $created): void
    {
        if (! $created && ! $reservation->wasChanged(['trang_thai', 'thoi_gian', 'so_luong_khach', 'ma_ban', 'gia_han_den'])) {
            return;
        }
        $title = $created ? 'Mộc đã nhận yêu cầu đặt bàn' : match ($reservation->trang_thai) {
            ReservationStatus::CONFIRMED => 'Đặt bàn đã được xác nhận',
            ReservationStatus::REJECTED => 'Yêu cầu đặt bàn chưa được chấp nhận',
            ReservationStatus::CANCELLED => 'Lượt đặt bàn đã được hủy',
            ReservationStatus::EXPIRED => 'Yêu cầu đặt bàn đã hết hạn',
            ReservationStatus::NO_SHOW => 'Lượt đặt bàn được ghi nhận không đến',
            ReservationStatus::COMPLETED => 'Cảm ơn bạn đã dùng bữa tại Mộc',
            default => 'Thông tin đặt bàn đã thay đổi',
        };
        if (! $created && ! $reservation->wasChanged('trang_thai')) {
            $title = 'Thông tin đặt bàn đã thay đổi';
        }
        $body = 'Lượt #'.$reservation->getKey().' · '.$reservation->thoi_gian->format('H:i d/m/Y').' · '.$reservation->so_luong_khach.' khách.';
        if ($reservation->trang_thai === ReservationStatus::PENDING) {
            $body .= ' Yêu cầu đang chờ nhà hàng duyệt, chưa phải xác nhận giữ bàn.';
        } elseif ($reservation->trang_thai === ReservationStatus::CONFIRMED) {
            $body .= ' Mộc giữ chỗ tới '.$this->schedule->holdUntil($reservation)->format('H:i').'. Nếu đến trễ, vui lòng liên hệ nhân viên.';
        }
        $account = $reservation->account;
        if ($account?->roleCode() === 'CUSTOMER') {
            $this->record($account, 'reservation:'.$reservation->getKey().':'.$reservation->communication_revision,
                'RESERVATION_UPDATED', $title, $body, '/reservations?reservationId='.$reservation->getKey(), 'reservation', $reservation->getKey(),
                ['time' => $reservation->thoi_gian->toIso8601String(), 'status' => $reservation->trang_thai->name]);
        }
    }

    private function order(Order $order, bool $created): void
    {
        if ($order->loai_phuc_vu === FulfillmentType::DINE_IN || (! $created && ! $order->wasChanged(['trang_thai', 'trang_thai_giao_nhan']))) {
            return;
        }
        $title = $created ? 'Mộc đã nhận đơn hàng của bạn' : match (true) {
            $order->trang_thai === OrderStatus::CANCELLED => 'Đơn hàng đã được hủy',
            $order->trang_thai_giao_nhan === FulfillmentStatus::DELIVERY_FAILED => 'Giao hàng chưa thành công',
            $order->trang_thai_giao_nhan === FulfillmentStatus::OUT_FOR_DELIVERY => 'Đơn hàng đang được giao',
            $order->trang_thai === OrderStatus::COMPLETED => 'Đơn hàng đã được bàn giao',
            $order->trang_thai === OrderStatus::CONFIRMED => 'Mộc đã xác nhận đơn hàng',
            $order->trang_thai === OrderStatus::READY && $order->loai_phuc_vu === FulfillmentType::PICKUP => 'Món đã sẵn sàng, mời bạn tới lấy',
            default => null,
        };
        if (! $title) {
            return;
        }
        $account = $this->orderCustomer($order);
        if ($account) {
            $this->record($account, 'order:'.$order->getKey().':'.$order->communication_revision, 'ORDER_UPDATED', $title,
                'Đơn #'.$order->getKey().' · '.($order->loai_phuc_vu === FulfillmentType::PICKUP ? 'Tự đến lấy' : 'Giao tận nơi').'. Xem chi tiết để theo dõi trạng thái và thanh toán.',
                '/orders?orderId='.$order->getKey(), 'order', $order->getKey(),
                ['status' => $order->trang_thai->name, 'fulfillment' => $order->trang_thai_giao_nhan?->value]);
        }
    }

    public function orderCustomer(Order $order): ?Account
    {
        $account = $order->account;
        if ($account?->roleCode() === 'CUSTOMER') {
            return $account;
        }

        return $order->loai_phuc_vu === FulfillmentType::DINE_IN ? $order->serviceSession?->reservation?->account : null;
    }

    public function reminders(): void
    {
        Reservation::where('trang_thai', ReservationStatus::CONFIRMED->value)
            ->whereBetween('thoi_gian', [now(), now()->addMinutes(config('communications.reminder_minutes'))])
            ->with('account')->chunkById(100, function ($reservations): void {
                foreach ($reservations as $reservation) {
                    if ($reservation->account && ! $this->schedule->isSeated($reservation)) {
                        $this->record($reservation->account, 'reminder:'.$reservation->getKey().':'.$reservation->thoi_gian->timestamp,
                            'RESERVATION_REMINDER', 'Sắp tới giờ hẹn tại Mộc',
                            'Lượt #'.$reservation->getKey().' · '.$reservation->thoi_gian->format('H:i d/m/Y').' · '.$reservation->so_luong_khach.' khách. Mộc giữ chỗ tới '.$this->schedule->holdUntil($reservation)->format('H:i').'.',
                            '/reservations?reservationId='.$reservation->getKey(), 'reservation', $reservation->getKey(),
                            ['time' => $reservation->thoi_gian->toIso8601String()]);
                    }
                }
            }, 'ma_dat_ban');
    }

    public function deliverable(CustomerNotice $notice): bool
    {
        if ($notice->account?->trang_thai !== AccountStatus::ACTIVE) {
            return false;
        }
        if ($notice->kind === 'RESERVATION_UPDATED') {
            $reservation = Reservation::find($notice->entity_id);
            if (! $reservation || $reservation->trang_thai->name !== ($notice->snapshot['status'] ?? null)
                || $reservation->thoi_gian->toIso8601String() !== ($notice->snapshot['time'] ?? null)) {
                return false;
            }
            if ($reservation->trang_thai->isActive() && ! $this->schedule->stillHolds($reservation)) {
                return false;
            }
        }
        if ($notice->kind === 'ORDER_UPDATED') {
            $order = Order::find($notice->entity_id);
            if (! $order || $order->trang_thai->name !== ($notice->snapshot['status'] ?? null)
                || $order->trang_thai_giao_nhan?->value !== ($notice->snapshot['fulfillment'] ?? null)) {
                return false;
            }
        }
        if ($notice->kind === 'FEEDBACK_REQUEST' && ! $this->completed($notice)) {
            return false;
        }
        if ($notice->kind === 'SUPPORT_REPLY') {
            $conversation = SupportConversation::find($notice->entity_id);
            if (! $conversation || ! $conversation->messages()->where('sender', 'STAFF')->where('id', '>', $conversation->customer_seen_message_id)->exists()) {
                return false;
            }
        }
        if ($notice->kind === 'RESERVATION_REMINDER') {
            $reservation = Reservation::find($notice->entity_id);

            return $reservation && $reservation->trang_thai === ReservationStatus::CONFIRMED
                && $reservation->thoi_gian->isFuture()
                && $reservation->thoi_gian->toIso8601String() === ($notice->snapshot['time'] ?? null)
                && ! $this->schedule->isSeated($reservation);
        }

        return true;
    }

    /**
     * Mời đánh giá sau bữa ăn: tối đa một lời mời mỗi bữa (khóa sự kiện theo bữa) và tối đa một lời
     * mời/ngày/tài khoản (khóa giới hạn duy nhất). Bị giới hạn ngày thì không ghi gì; bữa đó có thể được
     * mời ở lượt quét sau nếu còn trong khung thời gian.
     */
    public function feedbackInvitations(): void
    {
        CustomerNotice::with('account')->whereIn('kind', ['RESERVATION_UPDATED', 'ORDER_UPDATED'])
            ->whereBetween('created_at', [now()->subDay(), now()->subMinutes(config('communications.feedback_delay_minutes'))])
            ->where('snapshot->status', 'COMPLETED')->orderBy('id')->chunkById(100, function ($notices): void {
                foreach ($notices as $notice) {
                    if (! $notice->account || ! $this->completed($notice)) {
                        continue;
                    }
                    $meal = $this->mealKey($notice);
                    if ($meal && ! CustomerNotice::where('event_key', 'feedback:'.$meal)->exists()) {
                        $this->inviteFeedback($notice, $meal);
                    }
                }
            });
    }

    /** Bữa tại bàn định danh theo phiên POS (lượt đặt và đơn POS cùng phiên là một bữa); đơn online theo đơn. */
    public function mealKey(CustomerNotice $notice): ?string
    {
        if ($notice->entity_type === 'reservation') {
            $sessionId = Reservation::find($notice->entity_id)?->serviceSessions()->min('ma_phien');

            return $sessionId ? 'session:'.$sessionId : 'reservation:'.$notice->entity_id;
        }
        $order = $notice->entity_type === 'order' ? Order::find($notice->entity_id) : null;
        if (! $order) {
            return null;
        }

        return $order->loai_phuc_vu === FulfillmentType::DINE_IN && $order->ma_phien ? 'session:'.$order->ma_phien : 'order:'.$order->getKey();
    }

    private function inviteFeedback(CustomerNotice $source, string $meal): ?CustomerNotice
    {
        try {
            return DB::transaction(function () use ($source, $meal): ?CustomerNotice {
                Account::whereKey($source->account_id)->lockForUpdate()->firstOrFail();
                $invitations = CustomerNotice::where('account_id', $source->account_id)->where('kind', 'FEEDBACK_REQUEST');
                if ((clone $invitations)->whereBetween('created_at', [now()->startOfDay(), now()->endOfDay()])->exists()) {
                    return null;
                }
                // Dữ liệu trước nâng cấp chưa có limit_key/snapshot.meal: quy về bữa từ entity gốc,
                // không đổi notice_id để giữ nguyên phản hồi, biên nhận gửi mail và lịch sử đã đọc.
                foreach ((clone $invitations)->whereNull('limit_key')->cursor() as $legacy) {
                    if ($this->mealKey($legacy) === $meal) {
                        return null;
                    }
                }
                $notice = CustomerNotice::create([
                    'account_id' => $source->account_id, 'event_key' => 'feedback:'.$meal,
                    'limit_key' => 'feedback-day:'.$source->account_id.':'.now()->toDateString(),
                    'kind' => 'FEEDBACK_REQUEST', 'title' => 'Bữa ăn của bạn tại Mộc thế nào?',
                    'body' => 'Cảm ơn bạn đã chọn Mộc. Một đánh giá ngắn của bạn giúp nhà hàng chăm chút những bữa ăn tiếp theo.',
                    'path' => '/notifications', 'entity_type' => $source->entity_type, 'entity_id' => $source->entity_id,
                    'snapshot' => ['meal' => $meal],
                ]);
                $this->queueEmail($notice, $source->account);

                return $notice;
            });
        } catch (UniqueConstraintViolationException) {
            // Bữa này đã được mời, hoặc tài khoản đã nhận lời mời hôm nay: không ghi gì thêm.
            return null;
        }
    }

    public function completed(CustomerNotice $notice): bool
    {
        return match ($notice->entity_type) {
            'reservation' => Reservation::whereKey($notice->entity_id)->where('ma_tai_khoan', $notice->account_id)->where('trang_thai', ReservationStatus::COMPLETED->value)->exists(),
            'order' => Order::whereKey($notice->entity_id)->where('ma_tai_khoan', $notice->account_id)->where('trang_thai', OrderStatus::COMPLETED->value)->exists(),
            default => false,
        };
    }

    public function unreadSupportReplies(): void
    {
        SupportConversation::with('account')->where('last_message_at', '<=', now()->subSeconds(30))
            ->whereHas('messages', fn ($query) => $query->where('sender', 'STAFF')->whereColumn('support_messages.id', '>', 'support_conversations.customer_seen_message_id'))
            ->orderByDesc('last_message_at')->limit(100)->get()->each(function ($conversation): void {
                if ($conversation->account) {
                    $lastReplyId = $conversation->messages()->where('sender', 'STAFF')->max('id');
                    $lastNotice = CustomerNotice::where('kind', 'SUPPORT_REPLY')->where('entity_type', 'conversation')->where('entity_id', $conversation->id)->orderByDesc('id')->first();
                    if ($lastNotice && ($lastNotice->snapshot['lastMessageId'] ?? 0) >= $lastReplyId) {
                        return;
                    }
                    $this->record($conversation->account, 'chat:'.$conversation->id.':'.now()->format('YmdH'), 'SUPPORT_REPLY',
                        'Nhân viên Mộc đã trả lời bạn', 'Bạn có tin nhắn chưa đọc từ đội ngũ Mộc. Mở cuộc trò chuyện để xem nội dung.', '/?support='.$conversation->id, 'conversation', $conversation->id, ['lastMessageId' => $lastReplyId]);
                }
            });
    }
}
