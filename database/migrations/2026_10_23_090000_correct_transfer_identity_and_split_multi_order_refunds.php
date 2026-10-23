<?php

use App\Models\Order;
use App\Services\Payments\PaymentLedgerService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sửa dữ liệu sau review lần 2 (ADR-028 bổ sung). Migration `2026_10_21_224546` giữ nguyên vì đã chạy.
 *
 *  1. Định danh chuyển khoản: lần chuẩn hóa trước suy ra ngân hàng từ số tài khoản (dãy số trùng tài khoản
 *     cấu hình → ép về ngân hàng cấu hình) nên khoản của ngân hàng khác có thể bị coi là trùng. Tính lại
 *     khóa theo quy tắc mới: ngân hàng được nêu rõ luôn thắng; chỉ nguồn không nêu ngân hàng mới quy về
 *     tài khoản cấu hình. Không gộp, không xóa tiền: dòng va chạm giữ khóa `#DUP{id}` và chờ đối soát;
 *     dòng trước đây bị gắn trùng nhầm hoặc có thông tin ngân hàng mâu thuẫn được chuyển đối soát kèm ghi chú.
 *  2. Hoàn tiền một giao dịch đã áp cho nhiều đơn: dòng HOAN_TIEN cũ gắn toàn bộ số tiền vào một đơn trong
 *     khi các dòng đảo phân bổ rút tiền từ nhiều đơn. Tách thành một dòng cho mỗi đơn (đúng số đã rút),
 *     phần chưa phân bổ ở lại dòng gốc, gắn đơn đích của giao dịch (hoặc null nếu không có đích);
 *     cùng khóa yêu cầu, tổng không đổi. Tính lại bản tổng
 *     hợp THANH_TOAN của các đơn liên quan.
 *
 * down(): không đảo dữ liệu — khóa mới vẫn duy nhất và hợp lệ; gộp lại dòng hoàn sẽ tái tạo lỗi.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->recomputeTransferKeys();
        $this->splitMultiOrderRefunds();
    }

    public function down(): void
    {
        // Xem ghi chú đầu file.
    }

    private function recomputeTransferKeys(): void
    {
        $configuredAccount = $this->digits((string) config('services.vietqr.account_no'));
        $configuredBank = $this->bankCode((string) config('services.vietqr.bank_bin')) ?? 'CONFIGURED';

        $rows = DB::table('GIAO_DICH_TIEN')
            ->whereNotNull('ma_tham_chieu')
            ->where('phuong_thuc', 'Chuyển khoản')
            ->orderBy('ma_giao_dich_tien')
            ->get(['ma_giao_dich_tien', 'nha_cung_cap', 'tai_khoan_nhan', 'ma_tham_chieu', 'khoa_duy_nhat', 'du_lieu_goc', 'ghi_chu_doi_soat']);

        // Hai pha để không va chạm ràng buộc unique khi đổi khóa giữa các dòng.
        foreach ($rows as $row) {
            DB::table('GIAO_DICH_TIEN')->where('ma_giao_dich_tien', $row->ma_giao_dich_tien)->update(['khoa_duy_nhat' => 'REKEY:'.$row->ma_giao_dich_tien]);
        }

        $seen = [];
        foreach ($rows as $row) {
            $payload = json_decode((string) $row->du_lieu_goc, true) ?: [];
            $providerBank = $this->bankCode((string) ($payload['gateway'] ?? $payload['provider'] ?? $row->nha_cung_cap));
            $accountDigits = $this->digits((string) ($payload['accountNumber'] ?? $payload['account'] ?? $row->tai_khoan_nhan));
            $conflict = false;

            if ($providerBank !== null) {
                $bank = $providerBank;
                $account = $accountDigits ?? ($providerBank === $configuredBank ? $configuredAccount : null);
                $conflict = $providerBank !== $configuredBank && $accountDigits !== null && $accountDigits === $configuredAccount;
            } elseif ($accountDigits === null || $accountDigits === $configuredAccount) {
                [$bank, $account] = [$configuredBank, $configuredAccount];
            } else {
                [$bank, $account] = ['UNKNOWN', $accountDigits];
            }

            $key = 'BANK:'.$bank.'|'.($account ?? '').'|'.mb_strtoupper(trim((string) $row->ma_tham_chieu));
            $previous = (string) $row->khoa_duy_nhat;
            $wasFlaggedDuplicate = str_contains($previous, '#DUP');
            $notes = [];
            $update = [];

            if (isset($seen[$key])) {
                $update['khoa_duy_nhat'] = $key.'#DUP'.$row->ma_giao_dich_tien;
                if (! $wasFlaggedDuplicate) {
                    $update['canh_bao'] = 'DUPLICATE';
                    $notes[] = 'Trùng định danh chuyển khoản với giao dịch #'.$seen[$key].' — kiểm tra có phải ghi trùng một khoản tiền.';
                }
            } else {
                $seen[$key] = $row->ma_giao_dich_tien;
                $update['khoa_duy_nhat'] = $key;
                if ($wasFlaggedDuplicate) {
                    $notes[] = 'Lần chuẩn hóa ngày 2026-10-21 coi giao dịch này là trùng; theo định danh mới (ngân hàng nêu rõ) đây là khoản riêng — kiểm tra lại cờ trước khi đối soát.';
                }
            }
            if ($conflict && $previous !== $key) {
                $notes[] = 'Số tài khoản nhận trùng tài khoản đã cấu hình nhưng ngân hàng báo về là '.$providerBank.' — kiểm tra sao kê.';
            }
            if ($notes !== []) {
                $update['trang_thai_doi_soat'] = 'NEEDS_REVIEW';
                $update['ghi_chu_doi_soat'] = mb_substr(trim(implode(' ', array_filter([$row->ghi_chu_doi_soat, ...$notes]))), 0, 500);
            }

            DB::table('GIAO_DICH_TIEN')->where('ma_giao_dich_tien', $row->ma_giao_dich_tien)->update($update);
        }
    }

    private function splitMultiOrderRefunds(): void
    {
        $affectedOrders = [];

        foreach (DB::table('HOAN_TIEN')->orderBy('ma_hoan_tien')->get() as $refund) {
            $byOrder = DB::table('PHAN_BO_THANH_TOAN')
                ->where('ma_hoan_tien', $refund->ma_hoan_tien)
                ->where('loai', 'REFUND_REVERSAL')
                ->get(['ma_phan_bo', 'ma_don_hang', 'so_tien'])
                ->groupBy('ma_don_hang');
            $orders = $byOrder->keys()->map(fn ($id) => (int) $id);
            if ($orders->isEmpty()) {
                continue;
            }

            $fromAllocated = round((float) -$byOrder->flatten(1)->sum('so_tien'), 2);
            $fromUnallocated = round((float) $refund->so_tien - $fromAllocated, 2);
            if ($fromUnallocated <= 0.004 && $orders->every(fn (int $id) => $id === (int) $refund->ma_don_hang)) {
                continue;
            }
            // Dòng gốc giữ phần chưa phân bổ (nếu có), không thì nhận phần của chính đơn nó đang gắn.
            $keepOrder = $fromUnallocated > 0.004 ? null : ($orders->contains((int) $refund->ma_don_hang) ? (int) $refund->ma_don_hang : $orders->first());
            $template = (array) $refund;
            unset($template['ma_hoan_tien']);

            foreach ($byOrder as $orderId => $allocations) {
                $orderId = (int) $orderId;
                $taken = round((float) -$allocations->sum('so_tien'), 2);
                if ($orderId === $keepOrder) {
                    DB::table('HOAN_TIEN')->where('ma_hoan_tien', $refund->ma_hoan_tien)->update([
                        'ma_don_hang' => $orderId, 'so_tien' => $taken, 'so_tien_tu_phan_bo' => $taken,
                    ]);

                    continue;
                }
                $newId = DB::table('HOAN_TIEN')->insertGetId([
                    ...$template, 'ma_don_hang' => $orderId, 'so_tien' => $taken, 'so_tien_tu_phan_bo' => $taken,
                ]);
                DB::table('PHAN_BO_THANH_TOAN')->whereIn('ma_phan_bo', $allocations->pluck('ma_phan_bo'))->update(['ma_hoan_tien' => $newId]);
            }
            if ($keepOrder === null) {
                $targetOrderId = DB::table('GIAO_DICH_TIEN')
                    ->where('ma_giao_dich_tien', $refund->ma_giao_dich_tien)
                    ->value('ma_don_hang_muc_tieu');
                DB::table('HOAN_TIEN')->where('ma_hoan_tien', $refund->ma_hoan_tien)->update([
                    'ma_don_hang' => $targetOrderId, 'loai_hoan' => 'REFUND',
                    'so_tien' => $fromUnallocated, 'so_tien_tu_phan_bo' => 0,
                ]);
                if ($targetOrderId !== null) {
                    $affectedOrders[] = (int) $targetOrderId;
                }
            }

            array_push($affectedOrders, ...$orders->all());
            if ($refund->ma_don_hang !== null) {
                $affectedOrders[] = (int) $refund->ma_don_hang;
            }
        }

        $ledger = app(PaymentLedgerService::class);
        foreach (array_unique($affectedOrders) as $orderId) {
            if ($order = Order::query()->find($orderId)) {
                $ledger->syncOrderPayment($order);
            }
        }
    }

    private function bankCode(?string $label): ?string
    {
        $normalized = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $label));
        if ($normalized === '' || in_array($normalized, config('restaurant.payments.generic_providers', []), true)) {
            return null;
        }
        foreach (config('restaurant.payments.bank_aliases', []) as $code => $aliases) {
            if (in_array($normalized, $aliases, true)) {
                return $code;
            }
        }

        return $normalized;
    }

    private function digits(string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        return $digits === '' ? null : $digits;
    }
};
