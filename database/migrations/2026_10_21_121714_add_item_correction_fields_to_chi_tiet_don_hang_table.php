<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hủy / từ chối / làm lại / tách món (ADR-024).
 *
 * Tiền của một dòng = don_gia × so_luong_tinh_tien. Trạng thái bếp (CANCELLED/REJECTED)
 * không còn tự quyết định tiền: quyết định miễn tiền được lưu riêng (mien_tien, người duyệt).
 * Backfill: dòng đã hủy/từ chối → 0, các dòng khác → so_luong.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('CHI_TIET_DON_HANG', 'so_luong_tinh_tien')) {
            return;
        }

        Schema::table('CHI_TIET_DON_HANG', function (Blueprint $table) {
            $table->unsignedInteger('so_luong_tinh_tien')->nullable();
            $table->string('loai_dong', 20)->default('NORMAL');
            $table->unsignedBigInteger('ma_chi_tiet_goc')->nullable();
            $table->string('loai_ly_do_huy', 40)->nullable();
            $table->string('ly_do_huy', 255)->nullable();
            $table->boolean('mien_tien')->default(false);
            $table->unsignedInteger('ma_nguoi_huy')->nullable();
            $table->unsignedInteger('ma_nguoi_mien_tien')->nullable();
            $table->dateTime('huy_luc')->nullable();
            $table->string('trang_thai_bep_truoc', 30)->nullable();
            $table->unsignedInteger('ma_nguoi_cap_nhat_bep')->nullable();

            $table->foreign('ma_chi_tiet_goc')->references('ma_chi_tiet')->on('CHI_TIET_DON_HANG')->nullOnDelete();
            $table->foreign('ma_nguoi_huy')->references('ma_tai_khoan')->on('TAI_KHOAN')->nullOnDelete();
            $table->foreign('ma_nguoi_mien_tien')->references('ma_tai_khoan')->on('TAI_KHOAN')->nullOnDelete();
            $table->foreign('ma_nguoi_cap_nhat_bep')->references('ma_tai_khoan')->on('TAI_KHOAN')->nullOnDelete();
        });

        DB::table('CHI_TIET_DON_HANG')
            ->whereIn('trang_thai_bep', ['CANCELLED', 'REJECTED'])
            ->update(['so_luong_tinh_tien' => 0]);
        DB::table('CHI_TIET_DON_HANG')
            ->whereNull('so_luong_tinh_tien')
            ->update(['so_luong_tinh_tien' => DB::raw('so_luong')]);
    }

    public function down(): void
    {
        Schema::table('CHI_TIET_DON_HANG', function (Blueprint $table) {
            $table->dropForeign(['ma_chi_tiet_goc']);
            $table->dropForeign(['ma_nguoi_huy']);
            $table->dropForeign(['ma_nguoi_mien_tien']);
            $table->dropForeign(['ma_nguoi_cap_nhat_bep']);
            $table->dropColumn([
                'so_luong_tinh_tien', 'loai_dong', 'ma_chi_tiet_goc', 'loai_ly_do_huy', 'ly_do_huy', 'mien_tien',
                'ma_nguoi_huy', 'ma_nguoi_mien_tien', 'huy_luc', 'trang_thai_bep_truoc', 'ma_nguoi_cap_nhat_bep',
            ]);
        });
    }
};
