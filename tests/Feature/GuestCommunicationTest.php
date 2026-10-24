<?php

namespace Tests\Feature;

use App\Enums\FulfillmentType;
use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PosSessionStatus;
use App\Enums\ReservationStatus;
use App\Jobs\DeliverGuestNotice;
use App\Mail\GuestNoticeMail;
use App\Models\Account;
use App\Models\CustomerNotice;
use App\Models\DiningTable;
use App\Models\NoticeDelivery;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\ServiceSession;
use App\Services\Shared\GuestCommunicationService;
use App\Services\Shared\SupportChatService;
use Database\Seeders\RestaurantSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Once;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Tests\TestCase;

class GuestCommunicationTest extends TestCase
{
    use RefreshDatabase;

    private function customer(string $name = 'kh1'): Account
    {
        if (! Account::exists()) {
            config(['communications.enabled' => false]);
            $this->seed(RestaurantSeeder::class);
            config(['communications.enabled' => true]);
        }

        return Account::where('ten_dang_nhap', $name)->firstOrFail();
    }

    private function reservation(ReservationStatus $status = ReservationStatus::PENDING): Reservation
    {
        $customer = $this->customer();

        return Reservation::create([
            'ma_tai_khoan' => $customer->getKey(), 'ma_ban' => DiningTable::firstOrFail()->getKey(),
            'thoi_gian' => now()->addDay()->setTime(19, 0), 'so_luong_khach' => 2, 'trang_thai' => $status,
            'ghi_chu_noi_bo' => 'INTERNAL_SECRET_DO_NOT_SEND',
        ]);
    }

    private function notice(string $key = 'test:notice'): CustomerNotice
    {
        return app(GuestCommunicationService::class)->record($this->customer(), $key, 'TEST', 'Tiêu đề', 'Thông tin của bạn.', '/orders');
    }

    public function test_pending_request_does_not_claim_a_confirmed_table_and_internal_notes_stay_private(): void
    {
        $this->reservation();
        Sanctum::actingAs($this->customer());

        $response = $this->getJson('/api/v1/customer/notifications')->assertOk()->assertJsonPath('data.unreadCount', 1);
        $this->assertStringContainsString('chưa phải xác nhận giữ bàn', $response->json('data.items.0.body'));
        $this->assertStringNotContainsString('INTERNAL_SECRET_DO_NOT_SEND', $response->getContent());
    }

    public function test_confirmation_creates_a_separate_notice_and_internal_only_edits_do_not(): void
    {
        $reservation = $this->reservation();
        $reservation->update(['trang_thai' => ReservationStatus::CONFIRMED]);
        $reservation->update(['ghi_chu_noi_bo' => 'ANOTHER_INTERNAL_NOTE']);

        $this->assertDatabaseCount('customer_notices', 2);
        $this->assertDatabaseHas('customer_notices', ['title' => 'Đặt bàn đã được xác nhận']);
        $this->assertDatabaseCount('notice_deliveries', 2);
    }

    public function test_notice_and_email_outbox_rollback_with_the_booking(): void
    {
        $this->customer();
        DB::beginTransaction();
        $this->reservation();
        $this->assertDatabaseCount('customer_notices', 1);
        DB::rollBack();

        $this->assertDatabaseCount('customer_notices', 0);
        $this->assertDatabaseCount('notice_deliveries', 0);
    }

    public function test_duplicate_event_has_one_inbox_entry_and_one_email(): void
    {
        $this->notice();
        $this->notice();

        $this->assertDatabaseCount('customer_notices', 1);
        $this->assertDatabaseCount('notice_deliveries', 1);
    }

    public function test_customer_can_read_only_their_own_notices(): void
    {
        $notice = $this->notice();
        Sanctum::actingAs($this->customer('kh2'));

        $this->getJson('/api/v1/customer/notifications')->assertOk()->assertJsonCount(0, 'data.items');
        $this->postJson('/api/v1/customer/notifications/'.$notice->id.'/read')->assertNotFound();
        $this->assertNull($notice->fresh()->read_at);
    }

    public function test_read_all_updates_only_the_current_account(): void
    {
        $this->notice();
        $other = app(GuestCommunicationService::class)->record($this->customer('kh2'), 'other', 'TEST', 'Khác', 'Nội dung', '/orders');
        Sanctum::actingAs($this->customer());

        $this->postJson('/api/v1/customer/notifications/read-all')->assertOk();
        $this->getJson('/api/v1/customer/notifications')->assertJsonPath('data.unreadCount', 0);
        $this->assertNull($other->fresh()->read_at);
    }

    public function test_unauthenticated_notifications_request_returns_401(): void
    {
        $this->getJson('/api/v1/customer/notifications')->assertUnauthorized();
    }

    public function test_customer_cannot_read_delivery_operations(): void
    {
        Sanctum::actingAs($this->customer());

        $this->getJson('/api/v1/admin/communications/deliveries')->assertForbidden();
    }

    public function test_reminders_are_deduplicated_and_do_not_survive_cancellation(): void
    {
        $this->travelTo(now()->setTime(12, 0));
        $reservation = $this->reservation(ReservationStatus::CONFIRMED);
        $reservation->update(['thoi_gian' => now()->addMinutes(30)]);
        $service = app(GuestCommunicationService::class);
        $service->reminders();
        $service->reminders();
        $reminder = CustomerNotice::where('kind', 'RESERVATION_REMINDER')->sole();
        $reservation->update(['trang_thai' => ReservationStatus::CANCELLED]);
        config(['communications.email_enabled' => true]);
        Mail::fake();

        (new DeliverGuestNotice($reminder->deliveries()->sole()->id))->handle($service);

        $this->assertDatabaseHas('notice_deliveries', ['notice_id' => $reminder->id, 'status' => 'SKIPPED']);
        Mail::assertNothingSent();
    }

    public function test_a_rescheduled_booking_invalidates_the_old_reminder(): void
    {
        $this->travelTo(now()->setTime(12, 0));
        $reservation = $this->reservation(ReservationStatus::CONFIRMED);
        $reservation->update(['thoi_gian' => now()->addMinutes(30)]);
        $service = app(GuestCommunicationService::class);
        $service->reminders();
        $notice = CustomerNotice::where('kind', 'RESERVATION_REMINDER')->sole();
        $reservation->update(['thoi_gian' => now()->addHours(3)]);

        $this->assertFalse($service->deliverable($notice));
    }

    public function test_successful_delivery_is_not_sent_again_and_array_mail_is_not_reported_delivered(): void
    {
        $notice = $this->notice();
        $delivery = $notice->deliveries()->sole();
        config(['communications.email_enabled' => true, 'mail.default' => 'array']);
        Mail::fake();
        $job = new DeliverGuestNotice($delivery->id);

        $job->handle(app(GuestCommunicationService::class));
        $job->handle(app(GuestCommunicationService::class));

        Mail::assertSent(GuestNoticeMail::class, 1);
        $this->assertSame('SIMULATED', $delivery->fresh()->status);
        $this->assertNull($delivery->fresh()->accepted_at);
        $this->assertSame(1, $delivery->fresh()->attempts);
    }

    public function test_email_disabled_never_sends_or_marks_accepted(): void
    {
        $notice = $this->notice();
        config(['communications.email_enabled' => false]);
        Mail::fake();

        (new DeliverGuestNotice($notice->deliveries()->sole()->id))->handle(app(GuestCommunicationService::class));

        Mail::assertNothingSent();
        $this->assertSame('PENDING', $notice->deliveries()->sole()->status);
    }

    public function test_email_escapes_untrusted_titles_body_and_receipt_items(): void
    {
        $notice = new CustomerNotice(['title' => '<script>title</script>', 'body' => '<img src=x onerror=alert(1)>', 'path' => '/orders', 'snapshot' => [
            'lines' => [['name' => '<script>dish</script>', 'quantity' => 1, 'amount' => 45000]], 'total' => 45000,
        ]]);

        $html = (new GuestNoticeMail($notice))->render();

        $this->assertStringContainsString('&lt;script&gt;title', $html);
        $this->assertStringContainsString('&lt;img', $html);
        $this->assertStringContainsString('&lt;script&gt;dish', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function test_invalid_email_still_gets_an_inbox_notice_but_no_delivery(): void
    {
        $account = $this->customer();
        $account->update(['email' => null]);

        $this->notice();

        $this->assertDatabaseCount('customer_notices', 1);
        $this->assertDatabaseCount('notice_deliveries', 0);
    }

    public function test_feedback_is_owner_scoped_and_rating_is_validated(): void
    {
        $account = $this->customer();
        $notice = app(GuestCommunicationService::class)->record($account, 'feedback-test', 'FEEDBACK_REQUEST', 'Đánh giá', 'Cảm ơn', '/notifications');
        Sanctum::actingAs($account);

        $this->postJson('/api/v1/customer/notifications/'.$notice->id.'/feedback', ['rating' => 6])->assertUnprocessable();
        $this->postJson('/api/v1/customer/notifications/'.$notice->id.'/feedback', ['rating' => 5, 'comment' => 'Rất hài lòng'])->assertOk();

        $this->assertDatabaseHas('meal_feedback', ['notice_id' => $notice->id, 'rating' => 5]);
    }

    public function test_transient_smtp_failure_is_retried_with_bounded_backoff_without_exposing_error(): void
    {
        $notice = $this->notice();
        $delivery = $notice->deliveries()->sole();
        config(['communications.email_enabled' => true, 'mail.default' => 'smtp']);
        Mail::shouldReceive('to')->once()->andThrow(new UnexpectedResponseException('Expected response code "250" but got code "421", with message "421 4.7.0 Try again later secret-value".', 421));

        (new DeliverGuestNotice($delivery->id))->handle(app(GuestCommunicationService::class));

        $this->assertSame('RETRY', $delivery->fresh()->status);
        $this->assertSame('SMTP_TEMPORARY_FAILURE', $delivery->fresh()->error_code);
        $this->assertTrue($delivery->fresh()->available_at->isFuture());
        $this->assertStringNotContainsString('secret-value', $delivery->fresh()->toJson());
    }

    public function test_non_transient_smtp_failure_requires_review_and_does_not_store_or_log_password(): void
    {
        $notice = $this->notice();
        $delivery = $notice->deliveries()->sole();
        config(['communications.email_enabled' => true, 'mail.default' => 'smtp']);
        Log::spy();
        Mail::shouldReceive('to')->once()->andThrow(new TransportException('Failed to authenticate on SMTP server with username "owner@example.invalid" password=secret-value.', 535));

        (new DeliverGuestNotice($delivery->id))->handle(app(GuestCommunicationService::class));

        $this->assertSame('FAILED', $delivery->fresh()->status);
        $this->assertSame('SMTP_FAILURE_REVIEW_REQUIRED', $delivery->fresh()->error_code);
        $this->assertStringNotContainsString('secret-value', $delivery->fresh()->toJson());
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => $message === 'communications.email_failed'
            && $context['smtpCode'] === 535 && ! str_contains(json_encode($context), 'secret-value') && ! str_contains(json_encode($context), 'owner@'));
    }

    public function test_timeout_is_never_auto_retried_whatever_port_appears_in_the_error(): void
    {
        config(['communications.email_enabled' => true, 'mail.default' => 'smtp']);
        $ssl = $this->notice('smtp:465');
        $tls = $this->notice('smtp:587');
        Mail::shouldReceive('to')->twice()->andThrowExceptions([
            new TransportException('Connection to "ssl://smtp.gmail.com:465" timed out.'),
            new TransportException('Connection to "smtp.gmail.com:587" has been closed unexpectedly.'),
        ]);

        (new DeliverGuestNotice($ssl->deliveries()->sole()->id))->handle(app(GuestCommunicationService::class));
        (new DeliverGuestNotice($tls->deliveries()->sole()->id))->handle(app(GuestCommunicationService::class));

        foreach ([$ssl, $tls] as $notice) {
            $this->assertSame(['FAILED', 'SEND_OUTCOME_UNKNOWN_REVIEW_REQUIRED'], [$notice->deliveries()->sole()->status, $notice->deliveries()->sole()->error_code]);
        }
    }

    public function test_only_smtp_reply_codes_decide_retry_not_numbers_in_the_text(): void
    {
        config(['communications.email_enabled' => true, 'mail.default' => 'smtp']);
        $authBusy = $this->notice('smtp:454');
        $plain = $this->notice('smtp:plain');
        Mail::shouldReceive('to')->twice()->andThrowExceptions([
            new TransportException('Failed to authenticate on SMTP server.', 454),
            new \RuntimeException('421 temporary looking text from a non-SMTP failure'),
        ]);

        (new DeliverGuestNotice($authBusy->deliveries()->sole()->id))->handle(app(GuestCommunicationService::class));
        (new DeliverGuestNotice($plain->deliveries()->sole()->id))->handle(app(GuestCommunicationService::class));

        $this->assertSame('RETRY', $authBusy->deliveries()->sole()->status);
        $this->assertSame(['FAILED', 'SEND_ERROR_REVIEW_REQUIRED'], [$plain->deliveries()->sole()->status, $plain->deliveries()->sole()->error_code]);
    }

    public function test_superseded_confirmation_is_not_emailed_after_booking_is_cancelled(): void
    {
        $reservation = $this->reservation(ReservationStatus::CONFIRMED);
        $notice = CustomerNotice::sole();
        $reservation->update(['trang_thai' => ReservationStatus::CANCELLED]);

        $this->assertFalse(app(GuestCommunicationService::class)->deliverable($notice));
    }

    public function test_post_meal_feedback_is_delayed_and_limited_to_one_invitation_per_account_per_day(): void
    {
        $this->travelTo(now()->setTime(12, 0));
        $this->reservation(ReservationStatus::COMPLETED);
        $service = app(GuestCommunicationService::class);
        $service->feedbackInvitations();
        $this->assertSame(0, CustomerNotice::where('kind', 'FEEDBACK_REQUEST')->count());
        $this->travel(3)->hours();

        $service->feedbackInvitations();
        $service->feedbackInvitations();

        $this->assertSame(1, CustomerNotice::where('kind', 'FEEDBACK_REQUEST')->count());
    }

    public function test_demo_email_override_never_sends_to_the_sample_customer_address(): void
    {
        $notice = $this->notice();
        config(['communications.email_enabled' => true, 'communications.test_recipient' => 'safe-inbox@example.invalid']);
        Mail::fake();

        (new DeliverGuestNotice($notice->deliveries()->sole()->id))->handle(app(GuestCommunicationService::class));

        Mail::assertSent(GuestNoticeMail::class, fn ($mail) => $mail->hasTo('safe-inbox@example.invalid') && ! $mail->hasTo($notice->account->email));
    }

    public function test_booking_deep_link_is_scoped_to_the_current_customer(): void
    {
        $reservation = $this->reservation();
        Sanctum::actingAs($this->customer('kh2'));

        $this->getJson('/api/v1/customer/reservations/'.$reservation->getKey())->assertNotFound();
    }

    public function test_admin_retry_requires_acknowledgement_and_a_reason_and_is_audited(): void
    {
        $delivery = $this->notice()->deliveries()->sole();
        $delivery->update(['status' => 'FAILED', 'error_code' => 'SEND_OUTCOME_UNKNOWN_REVIEW_REQUIRED']);
        Sanctum::actingAs(Account::where('ten_dang_nhap', 'admin')->firstOrFail());

        $this->postJson('/api/v1/admin/communications/deliveries/'.$delivery->id.'/retry', ['reason' => 'Đã kiểm tra và cần gửi lại'])->assertUnprocessable();
        $this->postJson('/api/v1/admin/communications/deliveries/'.$delivery->id.'/retry', ['reason' => 'Đã kiểm tra và cần gửi lại', 'acknowledgeDuplicateRisk' => true])->assertOk();

        $this->assertSame('PENDING', $delivery->fresh()->status);
        $this->assertDatabaseHas('NHAT_KY_THAO_TAC', ['hanh_dong' => 'EMAIL_RETRY_REQUESTED', 'ma_doi_tuong' => $delivery->id]);
    }

    public function test_third_transient_failure_is_terminal_instead_of_retrying_forever(): void
    {
        $delivery = $this->notice()->deliveries()->sole();
        $delivery->update(['attempts' => 2]);
        config(['communications.email_enabled' => true, 'mail.default' => 'smtp']);
        Mail::shouldReceive('to')->once()->andThrow(new UnexpectedResponseException('Expected response code "250" but got code "451".', 451));

        (new DeliverGuestNotice($delivery->id))->handle(app(GuestCommunicationService::class));

        $this->assertSame('FAILED', $delivery->fresh()->status);
        $this->assertSame(3, $delivery->fresh()->attempts);
    }

    public function test_paid_order_receipt_snapshots_lines_without_internal_notes(): void
    {
        $account = $this->customer();
        $order = $account->orders()->with('payment')->whereHas('payment')->firstOrFail();
        $payment = $order->payment;
        $payment->trang_thai = PaymentStatus::UNPAID;
        $payment->saveQuietly();

        $payment->update(['trang_thai' => PaymentStatus::PAID]);

        $notice = CustomerNotice::where('kind', 'PAYMENT_RECEIPT')->sole();
        $this->assertNotEmpty($notice->snapshot['lines']);
        $this->assertSame($order->payableAmount(), (float) $notice->snapshot['total']);
        $this->assertStringNotContainsString('ghi_chu_noi_bo', $notice->toJson());
    }

    public function test_changing_email_before_delivery_does_not_send_private_information_to_old_address(): void
    {
        $notice = $this->notice();
        $notice->account->update(['email' => 'changed@example.invalid']);
        config(['communications.email_enabled' => true]);
        Mail::fake();

        (new DeliverGuestNotice($notice->deliveries()->sole()->id))->handle(app(GuestCommunicationService::class));

        Mail::assertNothingSent();
        $this->assertSame('RECIPIENT_CHANGED', $notice->deliveries()->sole()->error_code);
    }

    private function deliver(CustomerNotice $notice): void
    {
        (new DeliverGuestNotice($notice->deliveries()->sole()->id))->handle(app(GuestCommunicationService::class));
    }

    /** @return array{0: Order, 1: Payment, 2: CustomerNotice} */
    private function paidReceipt(): array
    {
        $order = $this->customer()->orders()->with('payment')->whereHas('payment')->where('trang_thai', '!=', OrderStatus::CANCELLED->value)->firstOrFail();
        $payment = $order->payment;
        $payment->forceFill(['trang_thai' => PaymentStatus::UNPAID, 'so_tien_da_thu' => 0, 'so_tien_da_hoan' => 0])->saveQuietly();
        $payment->update(['trang_thai' => PaymentStatus::PAID, 'so_tien_da_thu' => $order->payableAmount(), 'thanh_toan_luc' => now()]);

        return [$order->fresh(), $payment->fresh(), CustomerNotice::where('kind', 'PAYMENT_RECEIPT')->sole()];
    }

    public function test_update_email_is_sent_one_second_before_its_two_hour_window_closes_and_skipped_at_the_limit(): void
    {
        $this->freezeSecond();
        $this->reservation();
        Reservation::create([
            'ma_tai_khoan' => $this->customer()->getKey(), 'ma_ban' => DiningTable::firstOrFail()->getKey(),
            'thoi_gian' => now()->addDays(2)->setTime(19, 0), 'so_luong_khach' => 2, 'trang_thai' => ReservationStatus::PENDING,
        ]);
        [$early, $late] = CustomerNotice::orderBy('id')->get()->all();
        $this->assertTrue($early->deliveries()->sole()->expires_at->equalTo(now()->addHours(2)));
        config(['communications.email_enabled' => true]);
        Mail::fake();

        $this->travel(7199)->seconds();
        $this->deliver($early);
        $this->travel(1)->seconds();
        $this->deliver($late);

        Mail::assertSent(GuestNoticeMail::class, 1);
        $this->assertSame('SIMULATED', $early->deliveries()->sole()->status);
        $this->assertSame(['SKIPPED', 'EMAIL_EXPIRED'], [$late->deliveries()->sole()->status, $late->deliveries()->sole()->error_code]);
    }

    public function test_each_email_kind_gets_its_configured_sending_window(): void
    {
        $this->freezeSecond();
        $service = app(GuestCommunicationService::class);
        $cases = [
            ['RESERVATION_UPDATED', ['status' => 'CONFIRMED'], 120],
            ['RESERVATION_UPDATED', ['status' => 'NO_SHOW'], 1440],
            ['RESERVATION_UPDATED', ['status' => 'REJECTED'], 1440],
            ['ORDER_UPDATED', ['status' => 'CONFIRMED', 'fulfillment' => 'PENDING'], 120],
            ['ORDER_UPDATED', ['status' => 'CANCELLED', 'fulfillment' => null], 1440],
            ['ORDER_UPDATED', ['status' => 'PREPARING', 'fulfillment' => 'DELIVERY_FAILED'], 1440],
            ['SUPPORT_REPLY', [], 1440],
            ['FEEDBACK_REQUEST', [], 2880],
            ['PAYMENT_RECEIPT', [], 10080],
        ];

        foreach ($cases as $index => [$kind, $snapshot, $minutes]) {
            $notice = $service->record($this->customer(), 'ttl:'.$index, $kind, 'Tiêu đề', 'Nội dung', '/orders', null, null, $snapshot);
            $this->assertTrue(now()->addMinutes($minutes)->equalTo($notice->deliveries()->sole()->expires_at), $kind.' '.json_encode($snapshot));
        }
        config(['communications.email_ttl_minutes.feedback' => 60]);
        $this->assertTrue(now()->addHour()->equalTo($service->emailExpiresAt(new CustomerNotice(['kind' => 'FEEDBACK_REQUEST']))));
    }

    public function test_reminder_email_window_ends_at_the_appointment_time(): void
    {
        $this->travelTo(now()->setTime(12, 0));
        $reservation = $this->reservation(ReservationStatus::CONFIRMED);
        $reservation->update(['thoi_gian' => now()->addMinutes(30)]);
        app(GuestCommunicationService::class)->reminders();
        $reminder = CustomerNotice::where('kind', 'RESERVATION_REMINDER')->sole();
        config(['communications.email_enabled' => true]);
        Mail::fake();

        $this->assertTrue($reminder->deliveries()->sole()->expires_at->equalTo($reservation->fresh()->thoi_gian));
        $this->travel(31)->minutes();
        $this->deliver($reminder);

        Mail::assertNothingSent();
        $this->assertSame('EMAIL_EXPIRED', $reminder->deliveries()->sole()->error_code);
    }

    public function test_emails_left_ten_days_in_the_outbox_are_skipped_when_email_is_enabled_but_inbox_is_kept(): void
    {
        $this->travelTo(now()->subDays(10));
        $reservation = $this->reservation(ReservationStatus::CONFIRMED);
        $reservation->update(['trang_thai' => ReservationStatus::CANCELLED]);
        $cancelled = CustomerNotice::where('title', 'Lượt đặt bàn đã được hủy')->sole();
        $legacy = $this->notice('legacy:no-expiry');
        $legacy->deliveries()->update(['expires_at' => null]);
        $this->travelBack();
        config(['communications.email_enabled' => true]);
        Mail::fake();

        $this->artisan('dining:communications:dispatch')->assertSuccessful();
        $this->deliver($legacy);

        Mail::assertNothingSent();
        $this->assertSame(0, DB::table('jobs')->count(), 'Thư quá hạn không được đưa vào hàng đợi');
        $this->assertSame(['EMAIL_EXPIRED'], NoticeDelivery::whereIn('notice_id', [$cancelled->id])->pluck('error_code')->unique()->values()->all());
        $this->assertSame('EMAIL_EXPIRED', $legacy->deliveries()->sole()->error_code);
        $this->assertSame(3, CustomerNotice::count());
    }

    public function test_automatic_retries_and_admin_retry_never_extend_the_email_window(): void
    {
        $this->freezeSecond();
        $this->reservation();
        $notice = CustomerNotice::sole();
        $delivery = $notice->deliveries()->sole();
        config(['communications.email_enabled' => true, 'mail.default' => 'smtp']);
        Mail::shouldReceive('to')->twice()->andThrow(new UnexpectedResponseException('Expected response code "250" but got code "421".', 421));

        $this->deliver($notice);
        $this->travel(119)->minutes();
        $this->deliver($notice);
        $this->assertSame(['RETRY', 2], [$delivery->fresh()->status, $delivery->fresh()->attempts]);
        $this->travel(5)->minutes();
        $this->deliver($notice);

        $this->assertSame(['SKIPPED', 'EMAIL_EXPIRED', 2], [$delivery->fresh()->status, $delivery->fresh()->error_code, $delivery->fresh()->attempts]);
        $delivery->update(['status' => 'FAILED', 'error_code' => 'SEND_OUTCOME_UNKNOWN_REVIEW_REQUIRED']);
        Sanctum::actingAs(Account::where('ten_dang_nhap', 'admin')->firstOrFail());
        $this->postJson('/api/v1/admin/communications/deliveries/'.$delivery->id.'/retry', ['reason' => 'Đã kiểm tra hộp thư khách', 'acknowledgeDuplicateRisk' => true])->assertConflict();
        $this->assertSame('FAILED', $delivery->fresh()->status);
    }

    public function test_worker_outage_does_not_flood_the_queue_and_a_lost_job_is_requeued_once_after_the_lease(): void
    {
        $this->freezeSecond();
        $this->notice('queue:a');
        $this->notice('queue:b');
        config(['communications.email_enabled' => true]);

        for ($minute = 0; $minute < 60; $minute++) {
            $this->artisan('dining:communications:dispatch')->assertSuccessful();
            $this->travel(1)->minutes();
        }
        $this->assertSame(2, DB::table('jobs')->where('queue', 'communications')->count());

        DB::table('jobs')->delete();
        $this->artisan('dining:communications:dispatch')->assertSuccessful();
        $this->artisan('dining:communications:dispatch')->assertSuccessful();

        $this->assertSame(2, DB::table('jobs')->where('queue', 'communications')->count());
    }

    public function test_same_meal_gets_one_feedback_invitation_even_across_two_days(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 23)->setTime(10, 0));
        $reservation = $this->reservation(ReservationStatus::COMPLETED);
        $service = app(GuestCommunicationService::class);

        $this->travelTo(now()->setTime(12, 30));
        $service->feedbackInvitations();
        $this->travelTo(now()->addDay()->setTime(1, 0));
        $service->feedbackInvitations();

        $invitation = CustomerNotice::where('kind', 'FEEDBACK_REQUEST')->sole();
        $this->assertSame('reservation:'.$reservation->getKey(), $invitation->snapshot['meal']);
        $this->assertSame(1, $invitation->deliveries()->count());
    }

    public function test_daily_cap_does_not_mark_a_second_meal_as_invited_so_it_is_invited_the_next_day(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 23)->setTime(10, 0));
        $first = $this->reservation(ReservationStatus::COMPLETED);
        $this->travelTo(now()->setTime(11, 0));
        $second = $this->reservation(ReservationStatus::COMPLETED);
        $service = app(GuestCommunicationService::class);

        $this->travelTo(now()->setTime(13, 30));
        $service->feedbackInvitations();
        $service->feedbackInvitations();
        $this->assertSame([$first->getKey()], CustomerNotice::where('kind', 'FEEDBACK_REQUEST')->pluck('entity_id')->map(fn ($id) => (int) $id)->all());

        $this->travelTo(now()->addDay()->setTime(9, 0));
        $service->feedbackInvitations();

        $this->assertSame([$first->getKey(), $second->getKey()], CustomerNotice::where('kind', 'FEEDBACK_REQUEST')->orderBy('id')->pluck('entity_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame(2, CustomerNotice::where('kind', 'FEEDBACK_REQUEST')->distinct()->count('limit_key'));
    }

    public function test_legacy_feedback_is_not_sent_again_for_the_same_meal_on_either_day(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 23)->setTime(10, 0));
        $reservation = $this->reservation(ReservationStatus::COMPLETED);
        $service = app(GuestCommunicationService::class);
        $this->travelTo(now()->setTime(13, 0));
        $legacy = $service->record($this->customer(), 'feedback:'.$this->customer()->getKey().':2026-10-23', 'FEEDBACK_REQUEST', 'Đánh giá', 'Cảm ơn', '/notifications', 'reservation', $reservation->getKey());
        DB::table('meal_feedback')->insert(['notice_id' => $legacy->id, 'rating' => 5, 'comment' => 'Giữ nguyên đánh giá cũ', 'created_at' => now(), 'updated_at' => now()]);

        $service->feedbackInvitations();
        $this->travelTo(now()->addDay()->setTime(1, 0));
        $service->feedbackInvitations();

        $this->assertSame([$legacy->id], CustomerNotice::where('kind', 'FEEDBACK_REQUEST')->pluck('id')->all());
        $this->assertSame(1, $legacy->deliveries()->count());
        $this->assertDatabaseHas('meal_feedback', ['notice_id' => $legacy->id, 'rating' => 5, 'comment' => 'Giữ nguyên đánh giá cũ']);
    }

    public function test_legacy_invitation_uses_the_daily_cap_but_does_not_consume_another_meal(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 23)->setTime(10, 0));
        $first = $this->reservation(ReservationStatus::COMPLETED);
        $second = $this->reservation(ReservationStatus::COMPLETED);
        $service = app(GuestCommunicationService::class);
        $this->travelTo(now()->setTime(13, 0));
        $legacy = $service->record($this->customer(), 'feedback:'.$this->customer()->getKey().':2026-10-23', 'FEEDBACK_REQUEST', 'Đánh giá', 'Cảm ơn', '/notifications', 'reservation', $first->getKey());

        $service->feedbackInvitations();
        $this->assertSame([$legacy->id], CustomerNotice::where('kind', 'FEEDBACK_REQUEST')->pluck('id')->all());
        $this->travelTo(now()->addDay()->setTime(1, 0));
        $service->feedbackInvitations();

        $this->assertSame([$first->getKey(), $second->getKey()], CustomerNotice::where('kind', 'FEEDBACK_REQUEST')->orderBy('id')->pluck('entity_id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_legacy_booking_feedback_also_covers_the_linked_pos_order(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 23)->setTime(10, 0));
        $reservation = $this->reservation(ReservationStatus::COMPLETED);
        $session = ServiceSession::create([
            'ma_nhan_vien' => $this->customer('staff1')->getKey(), 'ma_dat_ban' => $reservation->getKey(), 'so_luong_khach' => 2,
            'trang_thai' => PosSessionStatus::CLOSED, 'idempotency_key' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64),
        ]);
        $order = Order::create([
            'ngay_dat_hang' => now(), 'tong_tien' => 90000, 'trang_thai' => OrderStatus::COMPLETED, 'ma_tai_khoan' => $this->customer()->getKey(),
            'ma_phien' => $session->ma_phien, 'kenh' => OrderChannel::POS, 'loai_phuc_vu' => FulfillmentType::DINE_IN, 'phien_ban' => 1,
        ]);
        $service = app(GuestCommunicationService::class);
        $service->record($this->customer(), 'order:'.$order->getKey().':pos', 'ORDER_UPDATED', 'Đơn tại bàn', 'Đã phục vụ', '/reservations', 'order', $order->getKey(), ['status' => 'COMPLETED', 'fulfillment' => null]);
        $legacy = $service->record($this->customer(), 'feedback:'.$this->customer()->getKey().':2026-10-23', 'FEEDBACK_REQUEST', 'Đánh giá', 'Cảm ơn', '/notifications', 'reservation', $reservation->getKey());

        $this->travelTo(now()->addDay()->setTime(1, 0));
        $service->feedbackInvitations();

        $this->assertSame([$legacy->id], CustomerNotice::where('kind', 'FEEDBACK_REQUEST')->pluck('id')->all());
    }

    public function test_legacy_feedback_for_another_customer_does_not_block_a_new_invitation(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 23)->setTime(10, 0));
        $reservation = $this->reservation(ReservationStatus::COMPLETED);
        $service = app(GuestCommunicationService::class);
        $this->travelTo(now()->setTime(13, 0));
        $service->record($this->customer('kh2'), 'feedback:other-account:2026-10-23', 'FEEDBACK_REQUEST', 'Đánh giá', 'Cảm ơn', '/notifications', 'reservation', $reservation->getKey());

        $service->feedbackInvitations();

        $this->assertSame('feedback:reservation:'.$reservation->getKey(), CustomerNotice::where('kind', 'FEEDBACK_REQUEST')->where('account_id', $this->customer()->getKey())->sole()->event_key);
    }

    public function test_feedback_limits_are_enforced_by_unique_keys_not_only_by_the_existence_check(): void
    {
        $account = $this->customer();
        CustomerNotice::create(['account_id' => $account->getKey(), 'event_key' => 'feedback:order:1', 'limit_key' => 'feedback-day:'.$account->getKey().':'.now()->toDateString(), 'kind' => 'FEEDBACK_REQUEST', 'title' => 'A', 'body' => 'B', 'path' => '/notifications']);

        $this->expectException(UniqueConstraintViolationException::class);
        CustomerNotice::create(['account_id' => $account->getKey(), 'event_key' => 'feedback:order:2', 'limit_key' => 'feedback-day:'.$account->getKey().':'.now()->toDateString(), 'kind' => 'FEEDBACK_REQUEST', 'title' => 'A', 'body' => 'B', 'path' => '/notifications']);
    }

    public function test_booking_and_pos_order_of_one_dining_session_are_one_meal(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 23)->setTime(10, 0));
        $reservation = $this->reservation(ReservationStatus::COMPLETED);
        $session = ServiceSession::create([
            'ma_nhan_vien' => $this->customer('staff1')->getKey(), 'ma_dat_ban' => $reservation->getKey(), 'so_luong_khach' => 2,
            'trang_thai' => PosSessionStatus::CLOSED, 'idempotency_key' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64),
        ]);
        $order = Order::create([
            'ngay_dat_hang' => now(), 'tong_tien' => 90000, 'trang_thai' => OrderStatus::COMPLETED, 'ma_tai_khoan' => $this->customer()->getKey(),
            'ma_phien' => $session->ma_phien, 'kenh' => OrderChannel::POS, 'loai_phuc_vu' => FulfillmentType::DINE_IN, 'phien_ban' => 1,
        ]);
        app(GuestCommunicationService::class)->record($this->customer(), 'order:'.$order->getKey().':pos', 'ORDER_UPDATED', 'Đơn tại bàn', 'Đã phục vụ', '/reservations', 'order', $order->getKey(), ['status' => 'COMPLETED', 'fulfillment' => null]);
        $service = app(GuestCommunicationService::class);

        $this->travelTo(now()->setTime(13, 0));
        $service->feedbackInvitations();
        $this->travelTo(now()->addDay()->setTime(9, 0));
        $service->feedbackInvitations();

        $this->assertSame('session:'.$session->ma_phien, CustomerNotice::where('kind', 'FEEDBACK_REQUEST')->sole()->snapshot['meal']);
    }

    public function test_receipt_rows_explain_items_fee_and_compensation_and_add_up_to_the_total(): void
    {
        $order = $this->customer()->orders()->with('payment')->whereHas('payment')->where('trang_thai', '!=', OrderStatus::CANCELLED->value)->firstOrFail();
        $order->forceFill(['so_tien_giam_tru' => 20000])->saveQuietly();
        $payment = $order->payment;
        $payment->forceFill(['trang_thai' => PaymentStatus::UNPAID, 'so_tien_da_thu' => 0, 'so_tien_da_hoan' => 0])->saveQuietly();

        // Sổ tiền ghi PARTIALLY_REFUNDED khi khách trả đủ phần phải thu còn lại sau bồi hoàn (ADR-028).
        $payment->update(['trang_thai' => PaymentStatus::PARTIALLY_REFUNDED, 'so_tien_da_thu' => $order->fresh()->payableAmount()]);

        $snapshot = CustomerNotice::where('kind', 'PAYMENT_RECEIPT')->sole()->snapshot;
        $this->assertEqualsWithDelta(array_sum(array_column($snapshot['lines'], 'amount')), $snapshot['itemsSubtotal'], 0.001);
        $this->assertEqualsWithDelta($snapshot['total'], $snapshot['itemsSubtotal'] + $snapshot['deliveryFee'] - $snapshot['compensation'] + $snapshot['otherAdjustment'], 0.001);
        $this->assertEqualsWithDelta((float) $order->tong_tien - 20000, $snapshot['total'], 0.001);
        $rows = collect(GuestNoticeMail::receiptRows($snapshot));
        $this->assertSame(-20000.0, $rows->firstWhere('label', 'Giảm trừ / bồi hoàn')['amount']);
        $this->assertSame((float) $snapshot['total'], $rows->firstWhere('kind', 'total')['amount']);
    }

    public function test_receipt_refunded_before_sending_keeps_its_historical_snapshot_and_tells_the_guest(): void
    {
        [$order, $payment, $notice] = $this->paidReceipt();
        $historical = $notice->snapshot;
        $payment->update(['trang_thai' => PaymentStatus::PARTIALLY_REFUNDED, 'so_tien_da_hoan' => 30000, 'so_tien_da_thu' => $order->payableAmount() - 30000]);
        config(['communications.email_enabled' => true]);
        Mail::fake();

        $this->deliver($notice);

        Mail::assertSent(GuestNoticeMail::class, function (GuestNoticeMail $mail): bool {
            $html = $mail->render();

            return str_contains($html, 'Mộc đã hoàn 30.000 đ cho đơn này') && str_contains($html, 'giữ nguyên số liệu tại thời điểm thu tiền')
                && str_contains((string) $mail->subject, 'có cập nhật sau thanh toán');
        });
        $this->assertSame($historical, $notice->fresh()->snapshot);
        $this->assertSame(1, CustomerNotice::where('kind', 'PAYMENT_RECEIPT')->count());
        Sanctum::actingAs($this->customer());
        $receipt = collect($this->getJson('/api/v1/customer/notifications')->assertOk()->json('data.items'))->firstWhere('kind', 'PAYMENT_RECEIPT')['receipt'];
        $this->assertEquals(GuestNoticeMail::receiptRows($historical), $receipt['rows']);
        $this->assertEquals(30000, $receipt['update']['refundedAfterReceipt']);
        $this->assertStringContainsString('giữ nguyên số liệu tại thời điểm thu tiền', $receipt['updateText']);
    }

    public function test_bookings_chat_and_scheduler_keep_working_before_the_new_migration_is_applied(): void
    {
        $this->customer();
        $migration = require database_path('migrations/2026_10_23_233204_harden_guest_communications_delivery_and_feedback.php');
        $migration->down();
        Once::flush();
        try {
            $this->reservation();
            $this->artisan('dining:communications:dispatch')->assertSuccessful();
            $chat = app(SupportChatService::class);
            $conversation = $chat->open($this->customer());
            $message = $chat->send($conversation, $this->customer(), 'Xin chào Mộc', (string) Str::uuid());
            $chat->dispatchPendingReply($message->id);

            $this->assertDatabaseCount('customer_notices', 1);
            $this->assertDatabaseCount('notice_deliveries', 1);
            $this->assertSame('PENDING', $conversation->messages()->where('sender', 'CUSTOMER')->sole()->ai_status);
            $this->assertDatabaseCount('jobs', 0);
        } finally {
            $migration->up();
            Once::flush();
        }
    }

    public function test_compensation_after_payment_is_reported_as_part_of_the_refund_not_twice(): void
    {
        [$order, $payment, $notice] = $this->paidReceipt();
        $order->forceFill(['so_tien_giam_tru' => 20000])->saveQuietly();
        $payment->forceFill(['so_tien_da_hoan' => 20000])->saveQuietly();

        $text = GuestNoticeMail::receiptUpdateText(app(GuestCommunicationService::class)->receiptUpdate($notice));

        $this->assertStringContainsString('Mộc đã hoàn 20.000 đ cho đơn này, trong đó 20.000 đ là bồi hoàn.', $text);
        $this->assertStringNotContainsString('40.000', $text);
    }
}
