<?php

namespace App\Modules\Reservation\Services\Admin;

use App\Modules\Core\Enums\ReservationStatus;
use App\Modules\Core\Enums\TableStatus;
use App\Modules\Reservation\Models\DiningTable;
use App\Modules\Reservation\Models\Reservation;
use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TableService
{
    /**
     * Get list of dining tables with optional filters (BKG-05).
     *
     * @param array<string, mixed> $params
     * @return Collection<int, DiningTable>
     */
    public function getTables(array $params = []): Collection
    {
        $query = DiningTable::query();

        // Filter by status if provided
        if (!empty($params['status'])) {
            $statusInput = trim((string) $params['status']);
            $matched = TableStatus::fromName($statusInput);
            if ($matched) {
                $query->where('trang_thai', $matched->value);
            }
        }

        // Filter by location
        if (!empty($params['location'])) {
            $query->where('vi_tri', 'LIKE', '%' . trim((string) $params['location']) . '%');
        }

        // Search by table name or location
        if (!empty($params['search'])) {
            $search = trim((string) $params['search']);
            $query->where(function ($q) use ($search) {
                $q->where('ten_ban', 'LIKE', "%{$search}%")
                  ->orWhere('vi_tri', 'LIKE', "%{$search}%");
            });
        }

        return $query->orderBy('ma_ban')->get();
    }

    /**
     * Get table details by ID.
     *
     * @param int $tableId
     * @return DiningTable
     * @throws HttpException
     */
    public function getTable(int $tableId): DiningTable
    {
        $table = DiningTable::find($tableId);
        if (!$table) {
            throw new HttpException(404, 'Table not found');
        }
        return $table;
    }

    /**
     * Create a new dining table (BKG-06).
     *
     * @param array<string, mixed> $data
     * @return DiningTable
     */
    public function createTable(array $data): DiningTable
    {
        $status = TableStatus::AVAILABLE;
        if (!empty($data['status'])) {
            $status = TableStatus::fromName((string) $data['status']) ?? TableStatus::AVAILABLE;
        }

        return DiningTable::create([
            'ten_ban' => $data['tableName'],
            'suc_chua' => (int) $data['capacity'],
            'vi_tri' => $data['location'] ?? null,
            'trang_thai' => $status,
        ]);
    }

    /**
     * Update an existing dining table (BKG-06).
     *
     * @param int $tableId
     * @param array<string, mixed> $data
     * @return DiningTable
     * @throws HttpException
     */
    public function updateTable(int $tableId, array $data): DiningTable
    {
        $table = $this->getTable($tableId);

        if (isset($data['tableName'])) {
            $table->ten_ban = $data['tableName'];
        }
        if (isset($data['capacity'])) {
            $table->suc_chua = (int) $data['capacity'];
        }
        if (array_key_exists('location', $data)) {
            $table->vi_tri = $data['location'];
        }
        if (!empty($data['status'])) {
            $matched = TableStatus::fromName((string) $data['status']);
            if ($matched) {
                $table->trang_thai = $matched;
            }
        }

        $table->save();
        return $table;
    }

    /**
     * Delete a table if it has no active or upcoming reservations (BKG-06).
     *
     * @param int $tableId
     * @return bool
     * @throws HttpException
     */
    public function deleteTable(int $tableId): bool
    {
        $table = $this->getTable($tableId);

        $hasActiveReservations = Reservation::where('ma_ban', $tableId)
            ->whereIn('trang_thai', [
                ReservationStatus::PENDING->value,
                ReservationStatus::CONFIRMED->value,
            ])
            ->where('thoi_gian', '>=', now()->subMinutes(120))
            ->exists();

        if ($hasActiveReservations) {
            throw new HttpException(400, 'Cannot delete table with active or upcoming reservations');
        }

        return (bool) $table->delete();
    }
}

