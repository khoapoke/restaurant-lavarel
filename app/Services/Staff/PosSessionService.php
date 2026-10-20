<?php

namespace App\Services\Staff;

use App\Enums\PosSessionStatus;
use App\Enums\TableStatus;
use App\Models\Account;
use App\Models\DiningTable;
use App\Models\ServiceSession;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PosSessionService
{
    public function getFloor(): Collection
    {
        return DiningTable::query()
            ->with(['serviceSessions' => function ($query) {
                $query->whereIn('PHIEN_PHUC_VU.trang_thai', [
                    PosSessionStatus::OPEN->value,
                    PosSessionStatus::AWAITING_PAYMENT->value,
                ])->with('staff.userProfile');
            }])
            ->orderBy('vi_tri')
            ->orderBy('ten_ban')
            ->get();
    }

    public function getSession(int $sessionId): ServiceSession
    {
        return ServiceSession::with([
            'staff.userProfile',
            'tables',
            'orders.items.dish',
            'invoice.payment',
        ])->findOrFail($sessionId);
    }

    public function completeCleaning(DiningTable $table): DiningTable
    {
        return DB::transaction(function () use ($table) {
            $lockedTable = DiningTable::lockForUpdate()->findOrFail($table->ma_ban);

            if ($lockedTable->trang_thai !== TableStatus::CLEANING) {
                throw new HttpException(409, 'Only a table waiting for cleaning can be marked available.');
            }

            $hasActiveSession = ServiceSession::whereHas(
                'tables',
                fn ($query) => $query->where('BAN_AN.ma_ban', $lockedTable->ma_ban)
            )->whereIn('trang_thai', [
                PosSessionStatus::OPEN->value,
                PosSessionStatus::AWAITING_PAYMENT->value,
            ])->exists();

            if ($hasActiveSession) {
                throw new HttpException(409, 'This table still belongs to an active service session.');
            }

            $lockedTable->update(['trang_thai' => TableStatus::AVAILABLE]);

            return $lockedTable->fresh()->load('serviceSessions');
        });
    }

    /**
     * @return array{session: ServiceSession, created: bool}
     */
    public function open(Account $staff, array $data): array
    {
        $tableIds = collect($data['tableIds'])->map(fn ($id) => (int) $id)->sort()->values()->all();
        $requestHash = hash('sha256', json_encode([
            'staffId' => (int) $staff->ma_tai_khoan,
            'tableIds' => $tableIds,
            'guestCount' => (int) $data['guestCount'],
        ], JSON_THROW_ON_ERROR));

        $existing = ServiceSession::where('idempotency_key', $data['idempotencyKey'])->first();
        if ($existing) {
            if (! hash_equals($existing->request_hash, $requestHash)) {
                throw new HttpException(409, 'Idempotency key was already used with a different request.');
            }

            return ['session' => $this->getSession($existing->ma_phien), 'created' => false];
        }

        return DB::transaction(function () use ($staff, $data, $tableIds, $requestHash) {
            $tables = DiningTable::whereIn('ma_ban', $tableIds)
                ->orderBy('ma_ban')
                ->lockForUpdate()
                ->get();

            if ($tables->count() !== count($tableIds)) {
                throw new HttpException(404, 'One or more dining tables were not found.');
            }

            $unavailable = $tables->first(fn ($table) => $table->trang_thai !== TableStatus::AVAILABLE);
            if ($unavailable) {
                throw new HttpException(409, "{$unavailable->ten_ban} is not available for a new service session.");
            }

            $hasOpenSession = ServiceSession::whereHas('tables', fn ($query) => $query->whereIn('BAN_AN.ma_ban', $tableIds))
                ->whereIn('trang_thai', [PosSessionStatus::OPEN->value, PosSessionStatus::AWAITING_PAYMENT->value])
                ->exists();

            if ($hasOpenSession) {
                throw new HttpException(409, 'One or more tables already belong to an open service session.');
            }

            $totalCapacity = $tables->sum('suc_chua');
            if ((int) $data['guestCount'] > $totalCapacity) {
                throw new HttpException(422, "Guest count exceeds the selected tables' capacity of {$totalCapacity}.");
            }

            $session = ServiceSession::create([
                'ma_nhan_vien' => $staff->ma_tai_khoan,
                'so_luong_khach' => $data['guestCount'],
                'trang_thai' => PosSessionStatus::OPEN,
                'mo_luc' => now(),
                'phien_ban' => 1,
                'idempotency_key' => $data['idempotencyKey'],
                'request_hash' => $requestHash,
            ]);

            $session->tables()->attach($tableIds);
            DiningTable::whereIn('ma_ban', $tableIds)->update(['trang_thai' => TableStatus::OCCUPIED->value]);

            return ['session' => $this->getSession($session->ma_phien), 'created' => true];
        });
    }
}
