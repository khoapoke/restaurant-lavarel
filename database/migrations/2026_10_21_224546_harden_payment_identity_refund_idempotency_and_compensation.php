<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Review sổ tiền (ADR-023 bổ sung):
 *  - HOAN_TIEN.loai_hoan: REFUND (hoàn phần nhà hàng đang nợ) / COMPENSATION (giảm trừ cho khách trên
 *    đơn còn hiệu lực); HOAN_TIEN.khoa_yeu_cau: gom các dòng của cùng một yêu cầu hoàn để gửi lại an toàn.
 *  - DON_HANG.so_tien_giam_tru: tổng giảm trừ sau thanh toán; khoản phải thu = tong_tien − giảm trừ.
 *  - GIAO_DICH_TIEN.khoa_duy_nhat của chuyển khoản tính lại theo ngân hàng + tài khoản nhận thật.
 *    Hai dòng cũ trùng định danh mới không bị gộp tiền tự động: dòng sau giữ khóa riêng và được gắn
 *    cờ DUPLICATE/NEEDS_REVIEW để quản lý đối soát.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('HOAN_TIEN', function (Blueprint $table): void {
            if (! Schema::hasColumn('HOAN_TIEN', 'loai_hoan')) {
                $table->string('loai_hoan', 20)->default('REFUND');
            }
            if (! Schema::hasColumn('HOAN_TIEN', 'khoa_yeu_cau')) {
                $table->string('khoa_yeu_cau', 191)->nullable()->index();
            }
        });

        Schema::table('DON_HANG', function (Blueprint $table): void {
            if (! Schema::hasColumn('DON_HANG', 'so_tien_giam_tru')) {
                $table->decimal('so_tien_giam_tru', 15, 2)->default(0);
            }
        });

        // Dòng hoàn cũ: mỗi dòng là một yêu cầu riêng.
        DB::table('HOAN_TIEN')->whereNull('khoa_yeu_cau')->orderBy('ma_hoan_tien')->get(['ma_hoan_tien'])
            ->each(fn ($row) => DB::table('HOAN_TIEN')->where('ma_hoan_tien', $row->ma_hoan_tien)->update(['khoa_yeu_cau' => 'LEGACY:'.$row->ma_hoan_tien]));

        $this->recomputeTransferKeys();
    }

    public function down(): void
    {
        Schema::table('HOAN_TIEN', function (Blueprint $table): void {
            if (Schema::hasColumn('HOAN_TIEN', 'khoa_yeu_cau')) {
                $table->dropIndex(['khoa_yeu_cau']);
                $table->dropColumn('khoa_yeu_cau');
            }
            if (Schema::hasColumn('HOAN_TIEN', 'loai_hoan')) {
                $table->dropColumn('loai_hoan');
            }
        });

        Schema::table('DON_HANG', function (Blueprint $table): void {
            if (Schema::hasColumn('DON_HANG', 'so_tien_giam_tru')) {
                $table->dropColumn('so_tien_giam_tru');
            }
        });
        // Khóa định danh mới vẫn duy nhất và hợp lệ với mã cũ, không cần khôi phục.
    }

    private function recomputeTransferKeys(): void
    {
        $configuredAccount = $this->digits((string) config('services.vietqr.account_no'));
        $configuredBank = $this->bankCode((string) config('services.vietqr.bank_bin')) ?? 'CONFIGURED';
        $seen = [];

        DB::table('GIAO_DICH_TIEN')
            ->whereNotNull('ma_tham_chieu')
            ->where('phuong_thuc', 'Chuyển khoản')
            ->orderBy('ma_giao_dich_tien')
            ->get(['ma_giao_dich_tien', 'nha_cung_cap', 'tai_khoan_nhan', 'ma_tham_chieu', 'khoa_duy_nhat', 'du_lieu_goc'])
            ->each(function ($row) use ($configuredAccount, $configuredBank, &$seen): void {
                $payload = json_decode((string) $row->du_lieu_goc, true) ?: [];
                $gateway = $payload['gateway'] ?? $payload['provider'] ?? $row->nha_cung_cap;
                $account = $this->digits((string) ($payload['accountNumber'] ?? $payload['account'] ?? $row->tai_khoan_nhan));
                $providerBank = $this->bankCode((string) $gateway);
                $isConfigured = ($account !== null && $account === $configuredAccount)
                    || ($account === null && ($providerBank === null || $providerBank === $configuredBank));
                [$bank, $accountKey] = $isConfigured ? [$configuredBank, $configuredAccount] : [$providerBank ?? 'UNKNOWN', $account];
                $key = 'BANK:'.$bank.'|'.($accountKey ?? '').'|'.mb_strtoupper(trim((string) $row->ma_tham_chieu));

                if (isset($seen[$key])) {
                    DB::table('GIAO_DICH_TIEN')->where('ma_giao_dich_tien', $row->ma_giao_dich_tien)->update([
                        'khoa_duy_nhat' => $key.'#DUP'.$row->ma_giao_dich_tien,
                        'canh_bao' => 'DUPLICATE',
                        'trang_thai_doi_soat' => 'NEEDS_REVIEW',
                        'ghi_chu_doi_soat' => 'Trùng định danh chuyển khoản với giao dịch #'.$seen[$key].' sau khi chuẩn hóa ngân hàng/tài khoản nhận — kiểm tra có phải ghi trùng một khoản tiền.',
                    ]);

                    return;
                }

                $seen[$key] = $row->ma_giao_dich_tien;
                DB::table('GIAO_DICH_TIEN')->where('ma_giao_dich_tien', $row->ma_giao_dich_tien)->update(['khoa_duy_nhat' => $key]);
            });
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
