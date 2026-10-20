<?php

namespace App\Services\Admin;

use App\Enums\PosSessionStatus;
use App\Enums\ReservationStatus;
use App\Enums\TableStatus;
use App\Models\DiningTable;
use App\Models\ServiceSession;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TableService
{
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

            if (isset($data['status'])) {
                $targetStatus = $this->statusFromName((string) $data['status']);
                if ($targetStatus !== TableStatus::OCCUPIED && $this->hasActiveSession($tableId)) {
                    throw new HttpException(409, 'Cannot change the status of a table in an active POS session');
                }
                $table->trang_thai = $targetStatus;
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

            return $table->fresh()->loadCount('reservations');
        });
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
}
