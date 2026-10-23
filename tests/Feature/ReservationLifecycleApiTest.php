<?php

namespace Tests\Feature;

use App\Enums\ApprovalType;
use App\Enums\PosSessionStatus;
use App\Enums\ReservationStatus;
use App\Enums\TableStatus;
use App\Models\Account;
use App\Models\DiningTable;
use App\Models\Reservation;
use App\Models\ServiceSession;
use App\Services\Staff\ApprovalService;
use App\Services\Staff\ReservationDeskService;
use Database\Seeders\RestaurantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Vòng đời đặt bàn theo khoảng thời gian thật (ADR-025): bàn trống, giới hạn, hết hạn,
 * khách trễ / gia hạn / không đến, nhường bàn, cửa sổ nhận khách và lệnh quét chạy trễ.
 */
class ReservationLifecycleApiTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, string> */
    private array $tokens = [];

    private DiningTable $table;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setTime(12, 0));
        $this->seed(RestaurantSeeder::class);
        $this->table = DiningTable::create(['ten_ban' => 'Bàn Thử Đặt Chỗ', 'suc_chua' => 4, 'trang_thai' => TableStatus::AVAILABLE, 'vi_tri' => 'Khu thử nghiệm']);
    }

    private function as(string $username): static
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->tokens[$username] ??= $this->postJson('/api/v1/auth/login', [
            'username' => $username,
            'password' => $username === 'kh2' ? 'Password@123' : '123',
        ])->assertOk()->json('data.accessToken');
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->tokens[$username]);
    }

    private function freshLogin(): void
    {
        $this->tokens = [];
    }

    private function confirmed(Carbon $at, int $guests = 2, ?DiningTable $table = null): Reservation
    {
        return Reservation::create([
            'thoi_gian' => $at,
            'so_luong_khach' => $guests,
            'trang_thai' => ReservationStatus::CONFIRMED,
            'ghi_chu' => 'Lượt thử',
            'ma_tai_khoan' => Account::where('ten_dang_nhap', 'kh2')->value('ma_tai_khoan'),
            'ma_ban' => ($table ?? $this->table)->ma_ban,
        ]);
    }

    private function book(string $time, int $guests = 2, ?string $note = null, ?DiningTable $table = null): TestResponse
    {
        return $this->as('kh1')->postJson('/api/v1/customer/reservations', [
            'tableId' => ($table ?? $this->table)->ma_ban,
            'reservationTime' => $time,
            'guestCount' => $guests,
            'note' => $note,
        ]);
    }

    private function seat(array $payload, string $key, string $as = 'staff1'): TestResponse
    {
        return $this->as($as)->withHeader('Idempotency-Key', $key)->postJson('/api/v1/staff/pos/sessions', $payload);
    }

    private function available(string $date, string $time, int $guests = 2): array
    {
        return collect($this->as('kh1')->getJson("/api/v1/customer/tables/available?date={$date}&time={$time}&guestCount={$guests}")->assertOk()->json('data.items'))
            ->pluck('tableId')->all();
    }

    public function test_availability_uses_real_dining_intervals_and_the_cleaning_buffer(): void
    {
        $tomorrow = now()->addDay()->toDateString();
        $this->confirmed(now()->addDay()->setTime(18, 0), 2); // 18:00–19:30, dọn tới 19:45

        $this->assertNotContains($this->table->ma_ban, $this->available($tomorrow, '19:40'));
        $this->assertContains($this->table->ma_ban, $this->available($tomorrow, '19:45'));
        // Nhóm 4 người đặt 16:20 dùng 90 phút → 17:50 + 15 dọn = 18:05 > 18:00.
        $this->assertNotContains($this->table->ma_ban, $this->available($tomorrow, '16:20', 4));
        $this->assertContains($this->table->ma_ban, $this->available($tomorrow, '16:15', 4));
    }

    public function test_a_table_with_guests_seated_now_is_not_offered_until_their_expected_end(): void
    {
        // RSV-17: khách vãng lai 2 người ngồi lúc 12:00 → dự kiến trả bàn 13:30, dọn tới 13:45.
        Reservation::where('ma_tai_khoan', Account::where('ten_dang_nhap', 'kh1')->value('ma_tai_khoan'))->delete();
        $this->seat(['tableIds' => [$this->table->ma_ban], 'guestCount' => 2], 'walk-in-blocks-booking-1')->assertCreated();
        $today = now()->toDateString();

        $this->assertNotContains($this->table->ma_ban, $this->available($today, '12:35'));
        $this->book(now()->setTime(12, 35)->toDateTimeString())->assertStatus(409);
        $this->assertContains($this->table->ma_ban, $this->available($today, '13:45'));
        $this->book(now()->setTime(13, 45)->toDateTimeString())->assertCreated();
    }

    public function test_arrival_must_fall_inside_reservation_hours_while_the_request_can_be_sent_at_night(): void
    {
        $tomorrow = now()->addDay();
        $this->book($tomorrow->copy()->setTime(21, 45)->toDateTimeString())->assertUnprocessable();
        $this->book($tomorrow->copy()->setTime(9, 30)->toDateTimeString())->assertUnprocessable();

        $this->travelTo(now()->setTime(23, 30));
        $this->freshLogin();
        $this->book($tomorrow->copy()->setTime(12, 0)->toDateTimeString())->assertCreated()->assertJsonPath('data.status', 'PENDING');
    }

    public function test_an_account_cannot_hold_more_than_three_reservations_or_overlap_its_own(): void
    {
        Reservation::where('ma_tai_khoan', Account::where('ten_dang_nhap', 'kh1')->value('ma_tai_khoan'))->delete();
        $day = now()->addDays(2);
        $tables = DiningTable::where('suc_chua', '>=', 2)->where('trang_thai', '!=', TableStatus::MAINTENANCE->value)->orderBy('ma_ban')->take(4)->get();

        $this->book($day->copy()->setTime(12, 0)->toDateTimeString(), 2, null, $tables[0])->assertCreated();
        $this->book($day->copy()->setTime(13, 0)->toDateTimeString(), 2, null, $tables[1])
            ->assertStatus(409)->assertJsonPath('message', fn (string $message) => str_contains($message, 'overlaps'));
        $this->book($day->copy()->setTime(15, 0)->toDateTimeString(), 2, null, $tables[1])->assertCreated();
        $this->book($day->copy()->setTime(18, 0)->toDateTimeString(), 2, null, $tables[2])->assertCreated();
        $this->book($day->copy()->setTime(20, 0)->toDateTimeString(), 2, null, $tables[3])
            ->assertStatus(409)->assertJsonPath('message', fn (string $message) => str_contains($message, '3 upcoming'));
    }

    public function test_a_pending_request_expires_at_its_time_even_before_the_sweep_runs(): void
    {
        $id = $this->book(now()->setTime(13, 0)->toDateTimeString())->assertCreated()->json('data.reservationId');

        $this->travelTo(now()->setTime(13, 1));
        $this->freshLogin();
        $this->as('kh1')->getJson('/api/v1/customer/reservations')->assertJsonFragment(['reservationId' => $id, 'status' => 'EXPIRED']);
        $this->as('admin')->patchJson("/api/v1/admin/reservations/{$id}/status", ['targetStatus' => 'CONFIRMED'])->assertStatus(409);
        $this->assertContains($this->table->ma_ban, $this->available(now()->toDateString(), '14:00'));

        $this->artisan('dining:reservations:sweep')->assertSuccessful();
        $this->assertSame(ReservationStatus::EXPIRED, Reservation::find($id)->trang_thai);
    }

    public function test_late_guests_get_a_grace_period_and_staff_can_extend_once_with_a_reason(): void
    {
        $reservation = $this->confirmed(now()->setTime(12, 0));
        $this->confirmed(now()->setTime(14, 30)); // lượt kế tiếp
        $this->travelTo(now()->setTime(12, 18));

        $this->as('staff1')->postJson("/api/v1/staff/pos/reservations/{$reservation->ma_dat_ban}/no-show", ['note' => 'Không thấy khách'])->assertStatus(409);
        $this->as('staff1')->postJson("/api/v1/staff/pos/reservations/{$reservation->ma_dat_ban}/extend", ['minutes' => 15])
            ->assertUnprocessable()->assertJsonValidationErrors(['reason']);
        $this->as('staff1')->postJson("/api/v1/staff/pos/reservations/{$reservation->ma_dat_ban}/extend", ['minutes' => 15, 'reason' => 'Khách gọi báo kẹt xe'])
            ->assertOk()
            ->assertJsonPath('data.hold.holdUntil', now()->setTime(12, 35)->format('Y-m-d\TH:i:s'))
            ->assertJsonPath('data.extension.reason', 'Khách gọi báo kẹt xe');

        // Cộng dồn quá +30 phút sau hạn gốc (12:50) bị từ chối.
        $this->as('staff1')->postJson("/api/v1/staff/pos/reservations/{$reservation->ma_dat_ban}/extend", ['minutes' => 20, 'reason' => 'Khách xin thêm'])->assertStatus(409);

        $this->as('staff1')->getJson('/api/v1/staff/pos/tables')->assertJsonFragment(['reservationId' => $reservation->ma_dat_ban, 'holdExpired' => false, 'minutesLate' => 18]);

        $this->travelTo(now()->setTime(12, 36));
        $this->as('staff1')->postJson("/api/v1/staff/pos/reservations/{$reservation->ma_dat_ban}/no-show", ['note' => 'Gọi không được'])
            ->assertOk()->assertJsonPath('data.status', 'NO_SHOW')->assertJsonPath('data.resolution.reason', 'Gọi không được');
        // Bàn vẫn giữ cho lượt 14:30, nhưng lượt không đến không còn chặn khách vãng lai.
        $this->assertSame(TableStatus::RESERVED, $this->table->fresh()->trang_thai);
        $this->seat(['tableIds' => [$this->table->ma_ban], 'guestCount' => 2], 'walk-in-after-no-show')
            ->assertCreated()->assertJsonPath('data.expectedEndAt', now()->addMinutes(90)->toIso8601String());
    }

    public function test_an_extension_that_would_hit_the_next_booking_is_refused(): void
    {
        $reservation = $this->confirmed(now()->setTime(12, 0));
        $this->confirmed(now()->setTime(14, 0)); // 12:20 + 90 + 15 = 14:05 > 14:00 đã sát
        $this->travelTo(now()->setTime(12, 10));

        $this->as('staff1')->postJson("/api/v1/staff/pos/reservations/{$reservation->ma_dat_ban}/extend", ['minutes' => 10, 'reason' => 'Khách báo trễ'])
            ->assertStatus(409)->assertJsonPath('message', fn (string $message) => str_contains($message, 'overlap'));
    }

    public function test_giving_away_an_expired_hold_and_seating_new_guests_happens_atomically(): void
    {
        $reservation = $this->confirmed(now()->setTime(12, 0));
        $this->travelTo(now()->setTime(12, 10));

        // Hạn giữ chỗ chưa hết: không nhường được, không có gì thay đổi.
        $this->seat(['tableIds' => [$this->table->ma_ban], 'guestCount' => 2, 'releaseReservationId' => $reservation->ma_dat_ban], 'release-too-early-001')->assertStatus(409);
        $this->assertSame(ReservationStatus::CONFIRMED, $reservation->fresh()->trang_thai);
        $this->assertSame(0, ServiceSession::whereHas('tables', fn ($q) => $q->where('BAN_AN.ma_ban', $this->table->ma_ban))->count());

        $this->travelTo(now()->setTime(12, 25));
        $this->seat(['tableIds' => [$this->table->ma_ban], 'guestCount' => 3, 'releaseReservationId' => $reservation->ma_dat_ban], 'release-after-hold-001')
            ->assertCreated()->assertJsonPath('data.status', 'OPEN');
        $this->assertSame(ReservationStatus::NO_SHOW, $reservation->fresh()->trang_thai);
        $this->assertSame(TableStatus::OCCUPIED, $this->table->fresh()->trang_thai);

        // Khách đặt đến muộn không được nhận vào lượt cũ; phải ngồi bàn trống như khách vãng lai.
        $this->seat(['tableIds' => [$this->table->ma_ban], 'guestCount' => 2, 'reservationId' => $reservation->ma_dat_ban], 'late-guest-old-booking')->assertStatus(409);
    }

    public function test_an_expired_hold_stops_blocking_walk_ins_even_if_the_sweep_is_late(): void
    {
        $reservation = $this->confirmed(now()->setTime(10, 0));
        $this->table->update(['trang_thai' => TableStatus::RESERVED]);

        $this->seat(['tableIds' => [$this->table->ma_ban], 'guestCount' => 2], 'walk-in-after-expired-1')->assertCreated();
        $this->as('kh2')->getJson('/api/v1/customer/reservations')->assertJsonFragment(['reservationId' => $reservation->ma_dat_ban, 'status' => 'NO_SHOW']);

        $this->artisan('dining:reservations:sweep')->assertSuccessful();
        $this->assertSame(ReservationStatus::NO_SHOW, $reservation->fresh()->trang_thai);
        $this->assertDatabaseHas('NHAT_KY_THAO_TAC', ['hanh_dong' => 'RESERVATION_NO_SHOW', 'ma_doi_tuong' => $reservation->ma_dat_ban]);
    }

    public function test_seating_outside_the_window_needs_a_manager(): void
    {
        $reservation = $this->confirmed(now()->setTime(13, 0));
        $payload = ['tableIds' => [$this->table->ma_ban], 'guestCount' => 2, 'reservationId' => $reservation->ma_dat_ban];

        $this->seat($payload, 'too-early-staff-0001')->assertForbidden();
        $this->seat($payload, 'too-early-admin-0001', 'admin')->assertUnprocessable();

        $approval = app(ApprovalService::class)->request(ApprovalType::SEAT_OUTSIDE_WINDOW, Account::where('ten_dang_nhap', 'staff1')->firstOrFail(), (int) $reservation->ma_dat_ban, [
            'tableIds' => [$this->table->ma_ban], 'guestCount' => 2,
        ], 'Khách đến sớm 1 tiếng, bàn đang trống');
        app(ApprovalService::class)->approve($approval, Account::where('ten_dang_nhap', 'admin')->firstOrFail(), 'Đồng ý');

        $session = ServiceSession::where('ma_dat_ban', $reservation->ma_dat_ban)->sole();
        $this->assertSame(PosSessionStatus::OPEN, $session->trang_thai);
        $this->assertDatabaseHas('NHAT_KY_THAO_TAC', ['hanh_dong' => 'SESSION_OPENED', 'ma_doi_tuong' => $session->ma_phien, 'ly_do' => 'Khách đến sớm 1 tiếng, bàn đang trống']);
    }

    public function test_actual_guest_count_is_kept_apart_from_the_booking_and_bigger_groups_need_more_tables(): void
    {
        $reservation = $this->confirmed(now()->setTime(12, 10), 2);
        $extra = DiningTable::create(['ten_ban' => 'Bàn Thử Ghép', 'suc_chua' => 4, 'trang_thai' => TableStatus::AVAILABLE, 'vi_tri' => 'Khu thử nghiệm']);

        $this->seat(['tableIds' => [$this->table->ma_ban], 'guestCount' => 6, 'reservationId' => $reservation->ma_dat_ban], 'too-many-guests-0001')->assertUnprocessable();
        $sessionId = $this->seat(['tableIds' => [$this->table->ma_ban, $extra->ma_ban], 'guestCount' => 6, 'reservationId' => $reservation->ma_dat_ban], 'merged-tables-0001')
            ->assertCreated()->assertJsonPath('data.guestCount', 6)->json('data.sessionId');

        $this->assertSame(2, (int) $reservation->fresh()->so_luong_khach);
        $this->as('admin')->getJson("/api/v1/admin/reservations/{$reservation->ma_dat_ban}")
            ->assertJsonPath('data.actualGuestCount', 6)->assertJsonPath('data.isSeated', true);

        // Sau khi đã nhận bàn: khách không sửa/hủy được, nhà hàng không tự đánh dấu hoàn tất.
        $this->as('kh2')->postJson("/api/v1/customer/reservations/{$reservation->ma_dat_ban}/cancel")->assertStatus(409);
        $this->as('admin')->patchJson("/api/v1/admin/reservations/{$reservation->ma_dat_ban}/status", ['targetStatus' => 'COMPLETED'])->assertStatus(409);
        $this->assertNotNull($sessionId);
    }

    public function test_internal_notes_stay_internal_and_restaurant_cancellations_need_a_reason(): void
    {
        $id = $this->book(now()->addDay()->setTime(19, 0)->toDateTimeString(), 2, 'Gần cửa sổ')->assertCreated()->json('data.reservationId');

        $this->as('admin')->patchJson("/api/v1/admin/reservations/{$id}/status", ['targetStatus' => 'CONFIRMED', 'internalNote' => 'Khách VIP, chuẩn bị phòng riêng'])
            ->assertOk()->assertJsonPath('data.internalNote', fn (string $note) => str_contains($note, 'phòng riêng'));
        $this->as('kh1')->getJson('/api/v1/customer/reservations')
            ->assertJsonMissing(['internalNote' => 'Khách VIP, chuẩn bị phòng riêng'])
            ->assertJsonFragment(['reservationId' => $id, 'note' => 'Gần cửa sổ']);
        // Ghi chú nội bộ có "phòng riêng" không làm khách bị áp luật báo trước 2 giờ.
        $this->as('kh1')->putJson("/api/v1/customer/reservations/{$id}", ['note' => 'Gần cửa sổ, ghế trẻ em'])->assertOk();

        $this->as('admin')->patchJson("/api/v1/admin/reservations/{$id}/status", ['targetStatus' => 'CANCELLED'])->assertUnprocessable();
        $this->as('admin')->patchJson("/api/v1/admin/reservations/{$id}/status", ['targetStatus' => 'REJECTED', 'reason' => 'Bàn hỏng đột xuất'])->assertStatus(409);
        $this->as('admin')->patchJson("/api/v1/admin/reservations/{$id}/status", ['targetStatus' => 'CANCELLED', 'reason' => 'Bàn hỏng đột xuất, đã gọi xin lỗi khách'])->assertOk();
        $this->as('kh1')->getJson('/api/v1/customer/reservations')->assertJsonFragment(['reservationId' => $id, 'statusReason' => 'Bàn hỏng đột xuất, đã gọi xin lỗi khách']);
    }

    public function test_the_restaurant_can_move_a_booking_to_another_table_with_conflict_checks(): void
    {
        $other = DiningTable::create(['ten_ban' => 'Bàn Thử Thay', 'suc_chua' => 4, 'trang_thai' => TableStatus::AVAILABLE, 'vi_tri' => 'Khu thử nghiệm']);
        $moving = $this->confirmed(now()->addDay()->setTime(19, 0));
        $this->confirmed(now()->addDay()->setTime(19, 30), 2, $other);

        $this->as('admin')->putJson("/api/v1/admin/reservations/{$moving->ma_dat_ban}", ['tableId' => $other->ma_ban, 'reason' => 'Bàn cũ hỏng'])->assertStatus(409);
        $this->as('admin')->putJson("/api/v1/admin/reservations/{$moving->ma_dat_ban}", ['tableId' => $other->ma_ban, 'reservationTime' => now()->addDay()->setTime(17, 0)->toDateTimeString(), 'reason' => 'Bàn cũ hỏng, đã báo khách'])
            ->assertOk()->assertJsonPath('data.table.tableId', $other->ma_ban);
        $this->as('admin')->getJson("/api/v1/admin/reservations/{$moving->ma_dat_ban}/history")->assertOk()->assertJsonFragment(['action' => 'RESERVATION_EDITED']);
    }

    public function test_the_desk_sweep_marks_no_shows_only_after_the_auto_deadline(): void
    {
        $reservation = $this->confirmed(now()->setTime(11, 0));
        $this->travelTo(now()->setTime(12, 19)); // hạn giữ 11:20 + 60 = 12:20
        $this->assertSame(['expired' => 0, 'noShow' => 0], app(ReservationDeskService::class)->sweep());

        $this->travelTo(now()->setTime(12, 21));
        $this->assertSame(1, app(ReservationDeskService::class)->sweep()['noShow']);
        $this->assertSame(ReservationStatus::NO_SHOW, $reservation->fresh()->trang_thai);
        $this->assertNull($reservation->fresh()->ma_nguoi_xu_ly);
    }
}
