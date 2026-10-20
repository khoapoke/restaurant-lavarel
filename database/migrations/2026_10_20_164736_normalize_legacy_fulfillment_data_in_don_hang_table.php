<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumns('DON_HANG', [
            'loai_phuc_vu',
            'ten_nguoi_nhan',
            'so_dien_thoai_nhan',
            'dia_chi_giao_hang',
        ])) {
            return;
        }

        DB::table('DON_HANG')
            ->where(function ($query): void {
                $query->where('dia_chi_giao_hang', 'like', '[DELIVERY]%')
                    ->orWhere('dia_chi_giao_hang', 'like', '[PICKUP]%');
            })
            ->orderBy('ma_don_hang')
            ->chunkById(100, function ($orders): void {
                foreach ($orders as $order) {
                    $this->normalizeLegacyOrder($order);
                }
            }, 'ma_don_hang');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // The original combined address cannot be reconstructed without losing newer edits.
    }

    private function normalizeLegacyOrder(object $order): void
    {
        $legacyAddress = trim((string) $order->dia_chi_giao_hang);

        if (preg_match('/^\[DELIVERY\]\s*(.*?)\s+-\s+((?:0|\+84)\d{9,10})\s*\|\s*(.*?)(?:\s*\|\s*Note:\s*(.*))?$/u', $legacyAddress, $matches) === 1) {
            $this->updateOrder($order, 'DELIVERY', $matches[1], $matches[2], $matches[3], $matches[4] ?? null);

            return;
        }

        if (preg_match('/^\[PICKUP\]\s*Nhận tại nhà hàng\s*-\s*KH:\s*(.*?)\s*-\s*SĐT:\s*((?:0|\+84)\d{9,10})(?:\s*\|\s*Note:\s*(.*))?$/u', $legacyAddress, $matches) === 1) {
            $this->updateOrder($order, 'PICKUP', $matches[1], $matches[2], null, $matches[3] ?? null);
        }
    }

    private function updateOrder(
        object $order,
        string $type,
        string $recipientName,
        string $recipientPhone,
        ?string $shippingAddress,
        ?string $legacyNote,
    ): void {
        $updates = [
            'loai_phuc_vu' => $type,
            'ten_nguoi_nhan' => trim($recipientName),
            'so_dien_thoai_nhan' => trim($recipientPhone),
            'dia_chi_giao_hang' => $shippingAddress === null ? null : trim($shippingAddress),
        ];

        if (blank($order->ghi_chu) && filled($legacyNote)) {
            $updates['ghi_chu'] = trim($legacyNote);
        }

        DB::table('DON_HANG')
            ->where('ma_don_hang', $order->ma_don_hang)
            ->update($updates);
    }
};
