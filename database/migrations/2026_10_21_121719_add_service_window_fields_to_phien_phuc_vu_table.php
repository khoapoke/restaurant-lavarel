<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phiên phục vụ (ADR-026/027): thời điểm dự kiến trả bàn (để tính bàn trống cho lượt đặt
 * và cảnh báo ngồi quá giờ), cách kết thúc phiên (thường / thất thu do khách bỏ đi).
 *
 * Backfill: phiên đang mở nhận du_kien_ket_thuc_luc = mo_luc + 90/120 phút theo số khách.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('PHIEN_PHUC_VU', 'du_kien_ket_thuc_luc')) {
            return;
        }

        Schema::table('PHIEN_PHUC_VU', function (Blueprint $table) {
            $table->dateTime('du_kien_ket_thuc_luc')->nullable();
            $table->string('kieu_ket_thuc', 20)->nullable();
            $table->decimal('so_tien_that_thu', 18, 2)->default(0);
            $table->string('ly_do_that_thu', 500)->nullable();
            $table->unsignedInteger('ma_nguoi_duyet_that_thu')->nullable();

            $table->foreign('ma_nguoi_duyet_that_thu')->references('ma_tai_khoan')->on('TAI_KHOAN')->nullOnDelete();
        });

        DB::table('PHIEN_PHUC_VU')->whereIn('trang_thai', ['OPEN', 'AWAITING_PAYMENT'])->get(['ma_phien', 'mo_luc', 'so_luong_khach'])
            ->each(fn ($session) => DB::table('PHIEN_PHUC_VU')->where('ma_phien', $session->ma_phien)->update([
                'du_kien_ket_thuc_luc' => Carbon::parse($session->mo_luc)->addMinutes((int) $session->so_luong_khach > 4 ? 120 : 90),
            ]));
    }

    public function down(): void
    {
        Schema::table('PHIEN_PHUC_VU', function (Blueprint $table) {
            $table->dropForeign(['ma_nguoi_duyet_that_thu']);
            $table->dropColumn(['du_kien_ket_thuc_luc', 'kieu_ket_thuc', 'so_tien_that_thu', 'ly_do_that_thu', 'ma_nguoi_duyet_that_thu']);
        });
    }
};
