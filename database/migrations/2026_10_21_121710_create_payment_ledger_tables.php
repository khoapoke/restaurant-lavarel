<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sổ giao dịch tiền (ADR-023).
 *
 * - GIAO_DICH_TIEN: mỗi khoản tiền thực nhận một dòng (webhook, xác nhận thủ công, POS,
 *   thu COD, dữ liệu lịch sử). Mã giao dịch duy nhất theo nhà cung cấp + tài khoản nhận.
 * - PHAN_BO_THANH_TOAN: phần tiền của một giao dịch được áp vào đơn/hóa đơn (dòng âm khi hoàn).
 * - HOAN_TIEN: ghi nhận đã hoàn (toàn phần hoặc một phần) có chứng từ.
 * - HOA_DON_PHIEN_BAN: lịch sử phiên bản hóa đơn; phiên bản VOID không được dùng lại.
 * - THANH_TOAN trở thành bản tổng hợp của đơn (đã thu, đã hoàn).
 *
 * Backfill: giao dịch PAID/REFUNDED cũ được chuyển thành dòng LEGACY, không bịa thời điểm
 * nhận tiền (chỉ dùng HOA_DON.thanh_toan_luc khi có), không đặt khóa duy nhất cho mã cũ.
 */
return new class extends Migration
{
    private const PAID = 'Đã thanh toán';

    private const REFUNDED = 'Đã hoàn tiền';

    public function up(): void
    {
        if (Schema::hasTable('GIAO_DICH_TIEN')) {
            return;
        }

        Schema::create('GIAO_DICH_TIEN', function (Blueprint $table) {
            $table->bigIncrements('ma_giao_dich_tien');
            $table->string('nguon', 20);
            $table->string('nha_cung_cap', 40);
            $table->string('tai_khoan_nhan', 40)->nullable();
            $table->string('ma_tham_chieu', 100)->nullable();
            $table->string('khoa_duy_nhat', 200)->nullable()->unique();
            $table->string('idempotency_key', 80)->nullable()->unique();
            $table->string('phuong_thuc', 50);
            $table->decimal('so_tien', 18, 2);
            $table->decimal('so_tien_da_phan_bo', 18, 2)->default(0);
            $table->decimal('so_tien_da_hoan', 18, 2)->default(0);
            $table->string('noi_dung', 255)->nullable();
            $table->unsignedInteger('ma_don_hang_muc_tieu')->nullable();
            $table->unsignedBigInteger('ma_hoa_don_muc_tieu')->nullable();
            $table->unsignedInteger('phien_ban_hoa_don')->nullable();
            $table->string('trang_thai_doi_soat', 20);
            $table->string('canh_bao', 40)->nullable();
            $table->string('ghi_chu_doi_soat', 500)->nullable();
            $table->unsignedInteger('ma_nguoi_doi_soat')->nullable();
            $table->dateTime('doi_soat_luc')->nullable();
            $table->dateTime('nhan_luc')->nullable();
            $table->dateTime('ghi_nhan_luc');
            $table->unsignedInteger('ma_nguoi_ghi_nhan')->nullable();
            $table->text('du_lieu_goc')->nullable();
            $table->boolean('la_du_lieu_lich_su')->default(false);

            $table->foreign('ma_don_hang_muc_tieu')->references('ma_don_hang')->on('DON_HANG')->nullOnDelete();
            $table->foreign('ma_hoa_don_muc_tieu')->references('ma_hoa_don')->on('HOA_DON')->nullOnDelete();
            $table->foreign('ma_nguoi_ghi_nhan')->references('ma_tai_khoan')->on('TAI_KHOAN')->nullOnDelete();
            $table->foreign('ma_nguoi_doi_soat')->references('ma_tai_khoan')->on('TAI_KHOAN')->nullOnDelete();
            $table->index(['trang_thai_doi_soat', 'ghi_nhan_luc']);
            $table->index('nhan_luc');
        });

        Schema::create('HOAN_TIEN', function (Blueprint $table) {
            $table->bigIncrements('ma_hoan_tien');
            $table->unsignedBigInteger('ma_giao_dich_tien');
            $table->unsignedInteger('ma_don_hang')->nullable();
            $table->decimal('so_tien', 18, 2);
            $table->decimal('so_tien_tu_phan_bo', 18, 2)->default(0);
            $table->string('phuong_thuc', 40);
            $table->string('ma_chung_tu', 100)->nullable();
            $table->string('ly_do', 500);
            $table->unsignedInteger('ma_nguoi_thuc_hien')->nullable();
            $table->dateTime('hoan_luc')->nullable();
            $table->dateTime('ghi_nhan_luc');
            $table->boolean('la_du_lieu_lich_su')->default(false);

            $table->foreign('ma_giao_dich_tien')->references('ma_giao_dich_tien')->on('GIAO_DICH_TIEN')->onDelete('restrict');
            $table->foreign('ma_don_hang')->references('ma_don_hang')->on('DON_HANG')->nullOnDelete();
            $table->foreign('ma_nguoi_thuc_hien')->references('ma_tai_khoan')->on('TAI_KHOAN')->nullOnDelete();
            $table->index('hoan_luc');
        });

        Schema::create('PHAN_BO_THANH_TOAN', function (Blueprint $table) {
            $table->bigIncrements('ma_phan_bo');
            $table->unsignedBigInteger('ma_giao_dich_tien');
            $table->unsignedInteger('ma_don_hang');
            $table->unsignedBigInteger('ma_hoa_don')->nullable();
            $table->unsignedInteger('phien_ban_hoa_don')->nullable();
            $table->decimal('so_tien', 18, 2);
            $table->string('loai', 20);
            $table->unsignedBigInteger('ma_hoan_tien')->nullable();
            $table->unsignedInteger('ma_nguoi_thuc_hien')->nullable();
            $table->dateTime('tao_luc')->nullable();

            $table->foreign('ma_giao_dich_tien')->references('ma_giao_dich_tien')->on('GIAO_DICH_TIEN')->onDelete('restrict');
            $table->foreign('ma_don_hang')->references('ma_don_hang')->on('DON_HANG')->onDelete('restrict');
            $table->foreign('ma_hoa_don')->references('ma_hoa_don')->on('HOA_DON')->nullOnDelete();
            $table->foreign('ma_hoan_tien')->references('ma_hoan_tien')->on('HOAN_TIEN')->nullOnDelete();
            $table->foreign('ma_nguoi_thuc_hien')->references('ma_tai_khoan')->on('TAI_KHOAN')->nullOnDelete();
            $table->index('ma_don_hang');
            $table->index('ma_giao_dich_tien');
        });

        Schema::create('HOA_DON_PHIEN_BAN', function (Blueprint $table) {
            $table->bigIncrements('ma_phien_ban_hoa_don');
            $table->unsignedBigInteger('ma_hoa_don');
            $table->unsignedInteger('phien_ban');
            $table->decimal('tong_thanh_toan', 18, 2);
            $table->string('trang_thai', 30);
            $table->dateTime('tao_luc')->nullable();
            $table->dateTime('huy_luc')->nullable();
            $table->string('ly_do_huy', 255)->nullable();
            $table->unsignedInteger('ma_nguoi_huy')->nullable();

            $table->foreign('ma_hoa_don')->references('ma_hoa_don')->on('HOA_DON')->onDelete('cascade');
            $table->foreign('ma_nguoi_huy')->references('ma_tai_khoan')->on('TAI_KHOAN')->nullOnDelete();
            $table->unique(['ma_hoa_don', 'phien_ban']);
        });

        Schema::table('HOA_DON', function (Blueprint $table) {
            $table->unsignedInteger('phien_ban')->default(1);
            $table->decimal('so_tien_that_thu', 18, 2)->default(0);
        });

        Schema::table('THANH_TOAN', function (Blueprint $table) {
            $table->decimal('so_tien_da_thu', 18, 2)->default(0);
            $table->decimal('so_tien_da_hoan', 18, 2)->default(0);
            $table->dateTime('thanh_toan_luc')->nullable();
            $table->dateTime('cap_nhat_luc')->nullable();
        });

        $this->backfill();
    }

    private function backfill(): void
    {
        $now = now();

        DB::table('HOA_DON')->orderBy('ma_hoa_don')->get()->each(function ($invoice): void {
            DB::table('HOA_DON_PHIEN_BAN')->insert([
                'ma_hoa_don' => $invoice->ma_hoa_don,
                'phien_ban' => 1,
                'tong_thanh_toan' => $invoice->tong_thanh_toan,
                'trang_thai' => $invoice->trang_thai,
                'tao_luc' => $invoice->tao_luc,
            ]);
        });

        $payments = DB::table('THANH_TOAN')
            ->whereIn('trang_thai', [self::PAID, self::REFUNDED])
            ->orderBy('ma_thanh_toan')
            ->get();

        foreach ($payments as $payment) {
            $paidAt = $payment->ma_hoa_don
                ? DB::table('HOA_DON')->where('ma_hoa_don', $payment->ma_hoa_don)->value('thanh_toan_luc')
                : null;
            $refunded = $payment->trang_thai === self::REFUNDED;

            $transactionId = DB::table('GIAO_DICH_TIEN')->insertGetId([
                'nguon' => 'LEGACY',
                'nha_cung_cap' => 'LEGACY',
                'ma_tham_chieu' => $payment->ma_giao_dich,
                'phuong_thuc' => $payment->phuong_thuc,
                'so_tien' => $payment->so_tien,
                'so_tien_da_phan_bo' => $refunded ? 0 : $payment->so_tien,
                'so_tien_da_hoan' => $refunded ? $payment->so_tien : 0,
                'ma_don_hang_muc_tieu' => $payment->ma_don_hang,
                'ma_hoa_don_muc_tieu' => $payment->ma_hoa_don,
                'trang_thai_doi_soat' => 'MATCHED',
                'nhan_luc' => $paidAt,
                'ghi_nhan_luc' => $now,
                'du_lieu_goc' => $payment->log_callback,
                'la_du_lieu_lich_su' => true,
            ], 'ma_giao_dich_tien');

            DB::table('PHAN_BO_THANH_TOAN')->insert([
                'ma_giao_dich_tien' => $transactionId,
                'ma_don_hang' => $payment->ma_don_hang,
                'ma_hoa_don' => $payment->ma_hoa_don,
                'phien_ban_hoa_don' => $payment->ma_hoa_don ? 1 : null,
                'so_tien' => $payment->so_tien,
                'loai' => 'APPLY',
                'tao_luc' => $paidAt,
            ]);

            if ($refunded) {
                $refundId = DB::table('HOAN_TIEN')->insertGetId([
                    'ma_giao_dich_tien' => $transactionId,
                    'ma_don_hang' => $payment->ma_don_hang,
                    'so_tien' => $payment->so_tien,
                    'so_tien_tu_phan_bo' => $payment->so_tien,
                    'phuong_thuc' => 'LEGACY',
                    'ly_do' => $payment->ly_do_hoan_tien ?: 'Dữ liệu cũ trước sổ giao dịch',
                    'ghi_nhan_luc' => $now,
                    'la_du_lieu_lich_su' => true,
                ], 'ma_hoan_tien');

                DB::table('PHAN_BO_THANH_TOAN')->insert([
                    'ma_giao_dich_tien' => $transactionId,
                    'ma_don_hang' => $payment->ma_don_hang,
                    'so_tien' => -1 * (float) $payment->so_tien,
                    'loai' => 'REFUND_REVERSAL',
                    'ma_hoan_tien' => $refundId,
                ]);
            }

            DB::table('THANH_TOAN')->where('ma_thanh_toan', $payment->ma_thanh_toan)->update([
                'so_tien_da_thu' => $refunded ? 0 : $payment->so_tien,
                'so_tien_da_hoan' => $refunded ? $payment->so_tien : 0,
                'thanh_toan_luc' => $paidAt,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('THANH_TOAN', function (Blueprint $table) {
            $table->dropColumn(['so_tien_da_thu', 'so_tien_da_hoan', 'thanh_toan_luc', 'cap_nhat_luc']);
        });
        Schema::table('HOA_DON', function (Blueprint $table) {
            $table->dropColumn(['phien_ban', 'so_tien_that_thu']);
        });
        Schema::dropIfExists('HOA_DON_PHIEN_BAN');
        Schema::dropIfExists('PHAN_BO_THANH_TOAN');
        Schema::dropIfExists('HOAN_TIEN');
        Schema::dropIfExists('GIAO_DICH_TIEN');
    }
};
