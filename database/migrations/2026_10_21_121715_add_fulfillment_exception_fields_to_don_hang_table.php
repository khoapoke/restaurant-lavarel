<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ngoại lệ giao hàng / tự lấy / hủy đơn (ADR-025).
 *
 * - ma_nhan_hang: mã 4 số khách đưa khi đến lấy (hoặc nhận thay sau xác minh).
 * - san_sang_luc: thời điểm bếp xong, để cảnh báo chờ bàn giao quá lâu.
 * - giao thất bại: loại + lý do + số lần giao.
 * - quyet_dinh_tien_huy: REFUND_DUE (phải hoàn) hoặc RETAINED (quản lý quyết định không hoàn).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('DON_HANG', 'ma_nhan_hang')) {
            return;
        }

        Schema::table('DON_HANG', function (Blueprint $table) {
            $table->string('ma_nhan_hang', 8)->nullable();
            $table->dateTime('san_sang_luc')->nullable();
            $table->dateTime('ban_giao_luc')->nullable();
            $table->string('nguoi_nhan_thay', 100)->nullable();
            $table->string('loai_giao_that_bai', 40)->nullable();
            $table->string('ly_do_giao_that_bai', 255)->nullable();
            $table->dateTime('giao_that_bai_luc')->nullable();
            $table->unsignedSmallInteger('so_lan_giao')->default(0);
            $table->string('ly_do_huy', 500)->nullable();
            $table->string('quyet_dinh_tien_huy', 20)->nullable();
            $table->unsignedInteger('ma_nguoi_huy')->nullable();
            $table->dateTime('huy_luc')->nullable();

            $table->foreign('ma_nguoi_huy')->references('ma_tai_khoan')->on('TAI_KHOAN')->nullOnDelete();
        });

        DB::table('DON_HANG')
            ->where('loai_phuc_vu', 'PICKUP')
            ->whereNull('ma_nhan_hang')
            ->pluck('ma_don_hang')
            ->each(fn ($orderId) => DB::table('DON_HANG')->where('ma_don_hang', $orderId)->update([
                'ma_nhan_hang' => str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT),
            ]));
    }

    public function down(): void
    {
        Schema::table('DON_HANG', function (Blueprint $table) {
            $table->dropForeign(['ma_nguoi_huy']);
            $table->dropColumn([
                'ma_nhan_hang', 'san_sang_luc', 'ban_giao_luc', 'nguoi_nhan_thay', 'loai_giao_that_bai', 'ly_do_giao_that_bai',
                'giao_that_bai_luc', 'so_lan_giao', 'ly_do_huy', 'quyet_dinh_tien_huy', 'ma_nguoi_huy', 'huy_luc',
            ]);
        });
    }
};
