<?php

namespace App\Services\Dining;

use App\Enums\PosSessionStatus;
use App\Enums\TableVisitStatus;
use App\Models\Account;
use App\Models\ServiceSession;
use App\Models\TableVisit;
use App\Services\Staff\PosSessionService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Staff side of the QR check-in queue: accept (opens or joins the table session) or
 * reject with a reason. Opening always goes through PosSessionService::open, so
 * reservation holds, capacity and the one-open-session-per-table rule still apply.
 */
class CheckInService
{
    public function __construct(
        private readonly GuestAccessService $guestAccess,
        private readonly PosSessionService $posSessionService,
    ) {}

    public function pending(): Collection
    {
        return TableVisit::query()
            ->with('diningTable')
            ->where('trang_thai', TableVisitStatus::PENDING->value)
            ->where('tao_luc', '>=', $this->guestAccess->pendingCutoff())
            ->orderBy('tao_luc')
            ->get();
    }

    /**
     * @param  array{guestCount?: int|string|null, reservationId?: int|string|null}  $data
     */
    public function accept(TableVisit $visit, Account $staff, array $data): ServiceSession
    {
        return DB::transaction(function () use ($visit, $staff, $data): ServiceSession {
            $locked = $this->lockPending($visit);
            $table = $locked->diningTable;

            $session = $this->guestAccess->activeSessionFor($table);
            if (! $session) {
                $opened = $this->posSessionService->open($staff, [
                    'tableIds' => [(int) $table->ma_ban],
                    'guestCount' => (int) ($data['guestCount'] ?? $locked->so_khach ?? 1),
                    'reservationId' => $data['reservationId'] ?? null,
                    'idempotencyKey' => "dine-check-in-{$locked->ma_luot}",
                ]);
                $session = $opened['session'];
            }

            $locked->update([
                'trang_thai' => TableVisitStatus::ACTIVE,
                'ma_phien' => $session->ma_phien,
                'ma_nhan_vien_xu_ly' => $staff->ma_tai_khoan,
                'xu_ly_luc' => now(),
            ]);

            return $this->posSessionService->getSession((int) $session->ma_phien);
        });
    }

    /** Staff issue a new table code (also lifts a lock after too many wrong codes). */
    public function rotateJoinCode(ServiceSession $session): ServiceSession
    {
        return DB::transaction(function () use ($session): ServiceSession {
            $locked = ServiceSession::query()->lockForUpdate()->findOrFail($session->ma_phien);

            if (! in_array($locked->trang_thai, [PosSessionStatus::OPEN, PosSessionStatus::AWAITING_PAYMENT], true)) {
                throw new HttpException(409, 'This table session has ended.');
            }

            $locked->rotateJoinCode();

            return $this->posSessionService->getSession((int) $locked->ma_phien);
        });
    }

    public function reject(TableVisit $visit, Account $staff, string $reason): TableVisit
    {
        return DB::transaction(function () use ($visit, $staff, $reason): TableVisit {
            $locked = $this->lockPending($visit);

            $locked->update([
                'trang_thai' => TableVisitStatus::REJECTED,
                'ly_do_tu_choi' => trim($reason),
                'ma_nhan_vien_xu_ly' => $staff->ma_tai_khoan,
                'xu_ly_luc' => now(),
            ]);

            return $locked->fresh('diningTable');
        });
    }

    private function lockPending(TableVisit $visit): TableVisit
    {
        $locked = TableVisit::query()->with('diningTable')->lockForUpdate()->findOrFail($visit->ma_luot);

        if ($locked->trang_thai !== TableVisitStatus::PENDING) {
            throw new HttpException(409, 'This check-in request has already been handled.');
        }

        if ($this->guestAccess->isExpired($locked)) {
            throw new HttpException(409, 'This check-in request has expired. Ask the guest to scan the QR code again.');
        }

        return $locked;
    }
}
