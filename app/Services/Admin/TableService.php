<?php

namespace App\Services\Admin;

use App\Enums\PosSessionStatus;
use App\Enums\ReservationStatus;
use App\Enums\TableStatus;
use App\Enums\TableVisitStatus;
use App\Models\DiningTable;
use App\Models\ServiceSession;
use App\Models\TableVisit;
use App\Services\Shared\TableStatusSynchronizer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TableService
{
    public function __construct(private readonly TableStatusSynchronizer $tableStatusSynchronizer) {}

    public function getTables(): Collection
    {
        return DiningTable::query()
            ->withCount(['reservations' => fn ($query) => $query->whereIn('trang_thai', [
                ReservationStatus::PENDING->value,
                ReservationStatus::CONFIRMED->value,
            ])->where('thoi_gian', '>=', now())])
            ->orderBy('vi_tri')
            ->orderBy('ten_ban')
            ->get();
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): DiningTable
    {
        $status = $this->statusFromName((string) ($data['status'] ?? TableStatus::AVAILABLE->name));
        if (! in_array($status, [TableStatus::AVAILABLE, TableStatus::MAINTENANCE], true)) {
            throw new HttpException(422, 'A new table can only start as AVAILABLE or MAINTENANCE');
        }

        return DiningTable::create([
            'ten_ban' => trim($data['tableName']),
            'suc_chua' => (int) $data['capacity'],
            'trang_thai' => $status,
            'vi_tri' => $data['location'] ?? null,
        ]);
    }

    /** @param array<string, mixed> $data */
    public function update(int $tableId, array $data): DiningTable
    {
        return DB::transaction(function () use ($tableId, $data): DiningTable {
            $table = DiningTable::query()->lockForUpdate()->findOrFail($tableId);

            if (isset($data['capacity'])) {
                $hasOversizedReservation = $table->reservations()
                    ->whereIn('trang_thai', [ReservationStatus::PENDING->value, ReservationStatus::CONFIRMED->value])
                    ->where('thoi_gian', '>=', now())
                    ->where('so_luong_khach', '>', (int) $data['capacity'])
                    ->exists();

                if ($hasOversizedReservation) {
                    throw new HttpException(409, 'Capacity cannot be lower than an upcoming reservation guest count');
                }
            }

            $statusChanged = false;
            if (isset($data['status'])) {
                $targetStatus = $this->statusFromName((string) $data['status']);
                if ($targetStatus !== $table->trang_thai) {
                    $this->assertManualStatus($targetStatus);
                    if ($this->hasActiveSession($tableId)) {
                        throw new HttpException(409, 'Cannot change the status of a table in an active POS session');
                    }
                    if ($targetStatus === TableStatus::MAINTENANCE && $this->tableStatusSynchronizer->hasReservationHoldToday($tableId)) {
                        throw new HttpException(409, 'This table has a confirmed reservation today. Cancel or move the reservation before maintenance');
                    }
                    $table->trang_thai = $targetStatus;
                    $statusChanged = true;
                }
            }

            if (isset($data['tableName'])) {
                $table->ten_ban = trim($data['tableName']);
            }
            if (isset($data['capacity'])) {
                $table->suc_chua = (int) $data['capacity'];
            }
            if (array_key_exists('location', $data)) {
                $table->vi_tri = $data['location'];
            }

            $table->save();
            if ($statusChanged && $table->trang_thai === TableStatus::AVAILABLE) {
                $this->tableStatusSynchronizer->refreshReservationHold($table);
            }

            return $table->fresh()->loadCount(['reservations' => fn ($query) => $query->whereIn('trang_thai', [
                ReservationStatus::PENDING->value,
                ReservationStatus::CONFIRMED->value,
            ])->where('thoi_gian', '>=', now())]);
        });
    }

    /**
     * RESERVED and OCCUPIED are driven by reservations and POS sessions, never set by hand.
     */
    private function assertManualStatus(TableStatus $status): void
    {
        if (! in_array($status, [TableStatus::AVAILABLE, TableStatus::CLEANING, TableStatus::MAINTENANCE], true)) {
            throw new HttpException(422, "{$status->name} is managed automatically by reservations and POS sessions");
        }
    }

    private function hasActiveSession(int $tableId): bool
    {
        return ServiceSession::query()
            ->whereHas('tables', fn ($query) => $query->where('BAN_AN.ma_ban', $tableId))
            ->whereIn('trang_thai', [PosSessionStatus::OPEN->value, PosSessionStatus::AWAITING_PAYMENT->value])
            ->exists();
    }

    private function statusFromName(string $input): TableStatus
    {
        foreach (TableStatus::cases() as $status) {
            if ($status->name === $input || $status->value === $input) {
                return $status;
            }
        }

        throw new HttpException(422, 'Unknown table status');
    }

    /**
     * Replace the table's public QR token. Old printed codes stop working and pending
     * check-in requests made through them are expired.
     */
    public function rotateQrToken(int $tableId): DiningTable
    {
        return DB::transaction(function () use ($tableId): DiningTable {
            $table = DiningTable::query()->lockForUpdate()->find($tableId);
            if (! $table) {
                throw new HttpException(404, 'Table not found');
            }

            $table->rotateQrToken();
            TableVisit::query()
                ->where('ma_ban', $table->ma_ban)
                ->where('trang_thai', TableVisitStatus::PENDING->value)
                ->update(['trang_thai' => TableVisitStatus::EXPIRED->value, 'xu_ly_luc' => now()]);

            return $table->loadCount('reservations');
        });
    }
}
