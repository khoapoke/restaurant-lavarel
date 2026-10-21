<?php

namespace App\Services\Dining;

use App\Enums\PaymentMethod;
use App\Enums\PosSessionStatus;
use App\Enums\ServiceRequestStatus;
use App\Enums\ServiceRequestType;
use App\Models\Account;
use App\Models\DiningTable;
use App\Models\ServiceRequest;
use App\Models\ServiceSession;
use App\Models\TableVisit;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Live service requests raised from the table QR page and handled on the POS floor.
 *
 * NEW → ACKNOWLEDGED → COMPLETED; a guest may cancel their own request while it is NEW.
 */
class ServiceRequestService
{
    public function __construct(private readonly GuestAccessService $guestAccess) {}

    /**
     * @param  array{type: string, note?: ?string, preferredMethod?: ?string}  $data
     * @return array{request: ServiceRequest, created: bool}
     */
    public function raise(DiningTable $table, TableVisit $visit, array $data): array
    {
        $session = $this->guestAccess->activeSessionFor($table);
        if (! $session || ! $this->guestAccess->isBoundToSession($visit, $session)) {
            throw new HttpException(409, 'This table session has ended.');
        }

        $type = ServiceRequestType::from($data['type']);
        $note = isset($data['note']) ? trim(strip_tags((string) $data['note'])) : '';
        $note = $note !== '' ? $note : null;

        if ($type === ServiceRequestType::OTHER && $note === null) {
            throw new HttpException(422, 'Please describe what you need.');
        }

        return DB::transaction(function () use ($table, $session, $visit, $type, $note, $data): array {
            $lockedSession = ServiceSession::query()->lockForUpdate()->findOrFail($session->ma_phien);

            if (! in_array($lockedSession->trang_thai, [PosSessionStatus::OPEN, PosSessionStatus::AWAITING_PAYMENT], true)) {
                throw new HttpException(409, 'This table session has ended.');
            }

            $openOfSameType = ServiceRequest::query()
                ->where('ma_phien', $lockedSession->ma_phien)
                ->where('loai', $type->value)
                ->whereIn('trang_thai', ServiceRequestStatus::openValues())
                ->orderBy('ma_yeu_cau')
                ->get();

            if ($type !== ServiceRequestType::OTHER && $openOfSameType->isNotEmpty()) {
                return ['request' => $openOfSameType->first(), 'created' => false];
            }

            if ($type === ServiceRequestType::OTHER) {
                $sameNote = $openOfSameType->first(fn (ServiceRequest $request) => mb_strtolower((string) $request->ghi_chu) === mb_strtolower($note));
                if ($sameNote) {
                    return ['request' => $sameNote, 'created' => false];
                }
                if ($openOfSameType->count() >= (int) config('dining.service_requests.max_open_other', 3)) {
                    throw new HttpException(429, 'You already have several open requests. Staff will be with you shortly.');
                }
            }

            $cooldown = (int) config('dining.service_requests.cooldown_seconds', 30);
            $recentlyClosed = ServiceRequest::query()
                ->where('ma_phien', $lockedSession->ma_phien)
                ->where('loai', $type->value)
                ->where(fn ($query) => $query
                    ->where('hoan_thanh_luc', '>=', now()->subSeconds($cooldown))
                    ->orWhere('huy_luc', '>=', now()->subSeconds($cooldown)))
                ->exists();
            if ($type !== ServiceRequestType::OTHER && $recentlyClosed) {
                throw new HttpException(429, 'Please wait a moment before sending the same request again.');
            }

            $preferredMethod = $type === ServiceRequestType::PAYMENT
                ? match ($data['preferredMethod'] ?? null) {
                    'BANK_TRANSFER_QR' => PaymentMethod::BANK_TRANSFER_QR,
                    'CASH' => PaymentMethod::CASH,
                    default => null,
                }
            : null;

            $request = ServiceRequest::create([
                'ma_phien' => $lockedSession->ma_phien,
                'ma_ban' => $table->ma_ban,
                'ma_luot' => $visit->ma_luot,
                'loai' => $type,
                'ghi_chu' => $note,
                'phuong_thuc_mong_muon' => $preferredMethod,
                'trang_thai' => ServiceRequestStatus::NEW,
                'tao_luc' => now(),
            ]);

            return ['request' => $request, 'created' => true];
        });
    }

    public function cancelByGuest(TableVisit $visit, int $requestId): ServiceRequest
    {
        return DB::transaction(function () use ($visit, $requestId): ServiceRequest {
            $request = ServiceRequest::query()
                ->where('ma_yeu_cau', $requestId)
                ->where('ma_luot', $visit->ma_luot)
                ->lockForUpdate()
                ->first();

            if (! $request) {
                throw new HttpException(404, 'Service request not found');
            }

            if ($request->trang_thai !== ServiceRequestStatus::NEW) {
                throw new HttpException(409, 'Staff are already handling this request.');
            }

            $request->update(['trang_thai' => ServiceRequestStatus::CANCELLED, 'huy_luc' => now()]);

            return $request;
        });
    }

    /** Open requests for the POS queue, payment requests first, oldest first. */
    public function openQueue(): Collection
    {
        return ServiceRequest::query()
            ->with(['diningTable', 'handledBy.userProfile'])
            ->whereIn('trang_thai', ServiceRequestStatus::openValues())
            ->whereHas('serviceSession', fn ($query) => $query->whereIn('trang_thai', [
                PosSessionStatus::OPEN->value,
                PosSessionStatus::AWAITING_PAYMENT->value,
            ]))
            ->orderBy('tao_luc')
            ->get()
            ->sortBy(fn (ServiceRequest $request) => [$request->loai->isPriority() ? 0 : 1, $request->tao_luc->getTimestamp()])
            ->values();
    }

    public function acknowledge(ServiceRequest $request, Account $staff): ServiceRequest
    {
        return DB::transaction(function () use ($request, $staff): ServiceRequest {
            $locked = ServiceRequest::query()->lockForUpdate()->findOrFail($request->ma_yeu_cau);

            if ($locked->trang_thai !== ServiceRequestStatus::NEW) {
                throw new HttpException(409, 'This request has already been picked up or closed.');
            }

            $locked->update([
                'trang_thai' => ServiceRequestStatus::ACKNOWLEDGED,
                'ma_nhan_vien_xu_ly' => $staff->ma_tai_khoan,
                'nhan_luc' => now(),
            ]);

            return $locked->fresh(['diningTable', 'handledBy.userProfile']);
        });
    }

    public function complete(ServiceRequest $request, Account $staff): ServiceRequest
    {
        return DB::transaction(function () use ($request, $staff): ServiceRequest {
            $locked = ServiceRequest::query()->lockForUpdate()->findOrFail($request->ma_yeu_cau);

            if (! $locked->trang_thai->isOpen()) {
                throw new HttpException(409, 'This request has already been closed.');
            }

            $locked->update([
                'trang_thai' => ServiceRequestStatus::COMPLETED,
                'ma_nhan_vien_xu_ly' => $locked->ma_nhan_vien_xu_ly ?? $staff->ma_tai_khoan,
                'nhan_luc' => $locked->nhan_luc ?? now(),
                'hoan_thanh_luc' => now(),
            ]);

            return $locked->fresh(['diningTable', 'handledBy.userProfile']);
        });
    }

    /** Creating the bill answers a pending "request the bill". */
    public function acknowledgePaymentRequests(int $sessionId, ?int $staffId): void
    {
        ServiceRequest::query()
            ->where('ma_phien', $sessionId)
            ->where('loai', ServiceRequestType::PAYMENT->value)
            ->where('trang_thai', ServiceRequestStatus::NEW->value)
            ->update([
                'trang_thai' => ServiceRequestStatus::ACKNOWLEDGED->value,
                'ma_nhan_vien_xu_ly' => $staffId,
                'nhan_luc' => now(),
            ]);
    }

    /** Settling the bill completes every open payment request. */
    public function completePaymentRequests(int $sessionId, ?int $staffId): void
    {
        ServiceRequest::query()
            ->where('ma_phien', $sessionId)
            ->where('loai', ServiceRequestType::PAYMENT->value)
            ->whereIn('trang_thai', ServiceRequestStatus::openValues())
            ->get()
            ->each(fn (ServiceRequest $request) => $request->update([
                'trang_thai' => ServiceRequestStatus::COMPLETED,
                'ma_nhan_vien_xu_ly' => $request->ma_nhan_vien_xu_ly ?? $staffId,
                'nhan_luc' => $request->nhan_luc ?? now(),
                'hoan_thanh_luc' => now(),
            ]));
    }

    /** When a session ends, nothing it asked for stays open on the floor. */
    public function closeForSession(int $sessionId): void
    {
        ServiceRequest::query()
            ->where('ma_phien', $sessionId)
            ->whereIn('trang_thai', ServiceRequestStatus::openValues())
            ->update([
                'trang_thai' => ServiceRequestStatus::CANCELLED->value,
                'huy_luc' => now(),
            ]);
    }
}
