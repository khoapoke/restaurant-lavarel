<?php

namespace App\Services\Dining;

use App\Enums\PosSessionStatus;
use App\Enums\TableStatus;
use App\Enums\TableVisitStatus;
use App\Models\DiningTable;
use App\Models\ServiceSession;
use App\Models\TableVisit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Resolves the public table token and the guest device token used by Live Dining.
 *
 * The table token (BAN_AN.ma_qr) only identifies a table. Everything a guest can do
 * at that table additionally requires a visit token, which is bound to one table and,
 * once staff accept the check-in (or the guest joins an open session), to one
 * service session. A visit never carries over to the next session on the same table.
 */
class GuestAccessService
{
    /** Upper bound of guest devices per session, to stop token flooding. */
    private const MAX_VISITS_PER_SESSION = 30;

    public function resolveTable(string $tableToken): DiningTable
    {
        $table = DiningTable::query()->where('ma_qr', $tableToken)->first();

        if (! $table) {
            throw new HttpException(404, 'This table QR code is invalid or has been replaced.');
        }

        return $table;
    }

    /**
     * Find the visit behind a device token, scoped to the scanned table.
     */
    public function findVisit(DiningTable $table, ?string $visitToken): ?TableVisit
    {
        if (! is_string($visitToken) || strlen($visitToken) < 32 || strlen($visitToken) > 128) {
            return null;
        }

        $visit = TableVisit::query()
            ->where('token_hash', TableVisit::hashToken($visitToken))
            ->first();

        // Thẻ khách chỉ dùng được ở bàn đã quét, hoặc ở bàn mà phiên của thẻ đã được chuyển/ghép sang.
        if (! $visit || ((int) $visit->ma_ban !== (int) $table->ma_ban && ! $this->sessionCoversTable($visit, $table))) {
            return null;
        }

        if ($visit->trang_thai === TableVisitStatus::PENDING && $this->isExpired($visit)) {
            $visit->update(['trang_thai' => TableVisitStatus::EXPIRED, 'xu_ly_luc' => now()]);
        }

        if (! $visit->truy_cap_luc || $visit->truy_cap_luc->lt(now()->subMinute())) {
            $visit->forceFill(['truy_cap_luc' => now()])->save();
        }

        return $visit;
    }

    /**
     * Phiên của thẻ hiện gồm bàn này (sau khi chuyển/ghép bàn): khi phiên đang mở, hoặc đã đóng trong
     * thời hạn đọc lại hóa đơn (`dining.visit_readable_hours`). Chỉ cấp quyền đọc phiên của chính thẻ —
     * thẻ không bao giờ được gắn vào phiên mới của bàn (xem isBoundToSession).
     */
    public function sessionCoversTable(TableVisit $visit, DiningTable $table): bool
    {
        if (! $visit->ma_phien || $visit->trang_thai !== TableVisitStatus::ACTIVE) {
            return false;
        }

        $session = ServiceSession::query()
            ->whereKey($visit->ma_phien)
            ->whereHas('tables', fn ($query) => $query->where('BAN_AN.ma_ban', $table->ma_ban))
            ->first();
        if (! $session) {
            return false;
        }
        if (in_array($session->trang_thai, [PosSessionStatus::OPEN, PosSessionStatus::AWAITING_PAYMENT], true)) {
            return true;
        }

        return $session->dong_luc !== null
            && $session->dong_luc->copy()->addHours((int) config('dining.visit_readable_hours', 6))->isFuture();
    }

    /**
     * Phiên của thẻ đã rời bàn đang quét (đổi bàn): trả về bàn mới để khách chuyển sang.
     *
     * @return array{tableNames: list<string>, tableToken: ?string}|null
     */
    public function movedTo(DiningTable $table, ?TableVisit $visit): ?array
    {
        if (! $visit || $visit->trang_thai !== TableVisitStatus::ACTIVE || ! $visit->ma_phien) {
            return null;
        }

        $session = ServiceSession::query()
            ->with('tables')
            ->whereKey($visit->ma_phien)
            ->whereIn('trang_thai', [PosSessionStatus::OPEN->value, PosSessionStatus::AWAITING_PAYMENT->value])
            ->first();
        if (! $session || $session->tables->contains('ma_ban', $table->ma_ban)) {
            return null;
        }

        return [
            'tableNames' => $session->tables->pluck('ten_ban')->values()->all(),
            'tableToken' => $session->tables->first()?->ma_qr,
        ];
    }

    public function requireVisit(DiningTable $table, ?string $visitToken): TableVisit
    {
        $visit = $this->findVisit($table, $visitToken);

        if (! $visit) {
            throw new HttpException(401, 'Your table pass is missing or no longer valid. Scan the table QR code again.');
        }

        return $visit;
    }

    public function activeSessionFor(DiningTable $table): ?ServiceSession
    {
        return ServiceSession::query()
            ->whereHas('tables', fn ($query) => $query->where('BAN_AN.ma_ban', $table->ma_ban))
            ->whereIn('trang_thai', [PosSessionStatus::OPEN->value, PosSessionStatus::AWAITING_PAYMENT->value])
            ->latest('ma_phien')
            ->first();
    }

    public function pendingCheckInFor(DiningTable $table): ?TableVisit
    {
        return TableVisit::query()
            ->where('ma_ban', $table->ma_ban)
            ->where('trang_thai', TableVisitStatus::PENDING->value)
            ->where('tao_luc', '>=', $this->pendingCutoff())
            ->latest('ma_luot')
            ->first();
    }

    /**
     * Ask the floor team to seat this table.
     *
     * @return array{visit: TableVisit, token: string}
     */
    public function requestCheckIn(DiningTable $table, string $guestName, int $guestCount): array
    {
        return DB::transaction(function () use ($table, $guestName, $guestCount): array {
            $lockedTable = DiningTable::query()->lockForUpdate()->findOrFail($table->ma_ban);

            if ($lockedTable->trang_thai === TableStatus::MAINTENANCE) {
                throw new HttpException(409, 'This table is not in service right now. Please ask a member of staff.');
            }

            if ($this->activeSessionFor($lockedTable)) {
                throw new HttpException(409, 'This table is already being served. Join the current session instead.');
            }

            if ($this->pendingCheckInFor($lockedTable)) {
                throw new HttpException(409, 'Another check-in request for this table is already waiting for staff.');
            }

            if ($guestCount > max(1, (int) $lockedTable->suc_chua) * 2) {
                throw new HttpException(422, 'Guest count is too large for this table. Please ask a member of staff.');
            }

            return $this->createVisit($lockedTable, null, TableVisitStatus::PENDING, $guestName, $guestCount);
        });
    }

    /**
     * Join the table's open session from a device that is not yet part of it.
     *
     * The printed QR only identifies the table, so a new device (or a device holding a
     * pass from an earlier meal, a pending check-in, or no pass at all) must present the
     * session's table code. A device already bound to this session is returned unchanged.
     * Wrong codes are counted per session; the code locks after `join_code.max_failures`.
     *
     * @return array{visit: TableVisit, token: ?string}
     */
    public function join(DiningTable $table, ?TableVisit $currentVisit, ?string $guestName, ?string $joinCode): array
    {
        $result = DB::transaction(function () use ($table, $currentVisit, $guestName, $joinCode): array {
            $lockedTable = DiningTable::query()->lockForUpdate()->findOrFail($table->ma_ban);
            $activeSession = $this->activeSessionFor($lockedTable);

            if (! $activeSession) {
                throw new HttpException(409, 'This table has not been opened for service yet.');
            }

            $session = ServiceSession::query()->lockForUpdate()->findOrFail($activeSession->ma_phien);
            $visit = $currentVisit ? TableVisit::query()->lockForUpdate()->find($currentVisit->ma_luot) : null;

            if ($visit && $this->isBoundToSession($visit, $session)) {
                return ['visit' => $visit, 'token' => null];
            }

            if ($session->isJoinCodeLocked()) {
                throw new HttpException(423, 'This table code is locked after too many attempts. Ask a member of staff for a new code.');
            }

            if (! is_string($joinCode) || trim($joinCode) === '') {
                throw new HttpException(403, 'Enter the table code shown by staff or by a guest already at this table.');
            }

            if (! $session->ma_tham_gia || ! hash_equals((string) $session->ma_tham_gia, trim($joinCode))) {
                $session->forceFill(['so_lan_sai_ma' => $session->so_lan_sai_ma + 1])->save();

                return ['error' => $session->isJoinCodeLocked()
                    ? new HttpException(423, 'This table code is locked after too many attempts. Ask a member of staff for a new code.')
                    : new HttpException(403, 'Incorrect table code. Ask your table or a member of staff for the current code.')];
            }

            if ($visit && $visit->trang_thai === TableVisitStatus::PENDING) {
                $visit->update([
                    'trang_thai' => TableVisitStatus::ACTIVE,
                    'ma_phien' => $session->ma_phien,
                    'xu_ly_luc' => now(),
                ]);

                return ['visit' => $visit->fresh(), 'token' => null];
            }

            $joinedCount = TableVisit::query()->where('ma_phien', $session->ma_phien)->count();
            if ($joinedCount >= self::MAX_VISITS_PER_SESSION) {
                throw new HttpException(429, 'Too many devices have joined this table. Please ask a member of staff.');
            }

            return $this->createVisit($lockedTable, $session, TableVisitStatus::ACTIVE, $guestName, null);
        });

        if (isset($result['error'])) {
            throw $result['error'];
        }

        return $result;
    }

    public function cancelCheckIn(TableVisit $visit): TableVisit
    {
        return DB::transaction(function () use ($visit): TableVisit {
            $locked = TableVisit::query()->lockForUpdate()->findOrFail($visit->ma_luot);

            if ($locked->trang_thai !== TableVisitStatus::PENDING) {
                throw new HttpException(409, 'Only a check-in request that is still waiting can be cancelled.');
            }

            $locked->update(['trang_thai' => TableVisitStatus::CANCELLED, 'xu_ly_luc' => now()]);

            return $locked;
        });
    }

    /**
     * Can this visit place orders or raise requests for the session right now?
     */
    public function isBoundToSession(TableVisit $visit, ?ServiceSession $session): bool
    {
        return $session
            && $visit->trang_thai === TableVisitStatus::ACTIVE
            && (int) $visit->ma_phien === (int) $session->ma_phien;
    }

    public function isExpired(TableVisit $visit): bool
    {
        return $visit->tao_luc->lt($this->pendingCutoff());
    }

    public function pendingCutoff(): Carbon
    {
        return now()->subMinutes((int) config('dining.check_in_expires_minutes', 15));
    }

    /** @return array{visit: TableVisit, token: string} */
    private function createVisit(DiningTable $table, ?ServiceSession $session, TableVisitStatus $status, ?string $guestName, ?int $guestCount): array
    {
        $plainToken = Str::random(64);
        $name = $guestName !== null ? trim(strip_tags($guestName)) : null;

        $visit = TableVisit::create([
            'ma_ban' => $table->ma_ban,
            'ma_phien' => $session?->ma_phien,
            'token_hash' => TableVisit::hashToken($plainToken),
            'ten_khach' => $name !== '' ? $name : null,
            'so_khach' => $guestCount,
            'trang_thai' => $status,
            'tao_luc' => now(),
            'xu_ly_luc' => $status === TableVisitStatus::ACTIVE ? now() : null,
            'truy_cap_luc' => now(),
        ]);

        return ['visit' => $visit, 'token' => $plainToken];
    }
}
