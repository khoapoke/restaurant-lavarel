<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. VAI_TRO
        Schema::create('VAI_TRO', function (Blueprint $table) {
            $table->increments('ma_vai_tro');
            $table->string('ten_vai_tro', 50);
        });

        // 2. NGUOI_DUNG
        Schema::create('NGUOI_DUNG', function (Blueprint $table) {
            $table->increments('ma_nguoi_dung');
            $table->string('ho_ten', 100);
            $table->date('ngay_sinh')->nullable();
            $table->string('dia_chi', 255)->nullable();
            $table->string('so_dien_thoai', 20)->nullable();
            $table->json('di_ung')->nullable();
            $table->json('che_do_an')->nullable();
            $table->json('so_dia_chi')->nullable();
        });

        // 3. TAI_KHOAN
        Schema::create('TAI_KHOAN', function (Blueprint $table) {
            $table->increments('ma_tai_khoan');
            $table->string('ten_dang_nhap', 50)->unique();
            $table->string('mat_khau', 255);
            $table->string('email', 100)->nullable()->unique();
            $table->string('trang_thai', 100)->default('Hoạt động');
            $table->unsignedInteger('ma_nguoi_dung')->nullable();
            $table->unsignedInteger('ma_vai_tro')->nullable();

            $table->foreign('ma_nguoi_dung')->references('ma_nguoi_dung')->on('NGUOI_DUNG')->onDelete('set null');
            $table->foreign('ma_vai_tro')->references('ma_vai_tro')->on('VAI_TRO')->onDelete('restrict');
        });

        // 4. BAN_AN
        Schema::create('BAN_AN', function (Blueprint $table) {
            $table->increments('ma_ban');
            $table->string('ten_ban', 50);
            $table->integer('suc_chua')->default(4);
            $table->string('trang_thai', 50)->default('Trống');
            $table->string('vi_tri', 100)->nullable();
        });

        // 5. DANH_MUC
        Schema::create('DANH_MUC', function (Blueprint $table) {
            $table->increments('ma_danh_muc');
            $table->string('ten_danh_muc', 100);
            $table->text('mo_ta')->nullable();
            $table->integer('thu_tu_hien_thi')->default(0);
            $table->boolean('hien_thi')->default(true);
            $table->boolean('da_xoa')->default(false);
        });

        // 6. MON_AN
        Schema::create('MON_AN', function (Blueprint $table) {
            $table->increments('ma_mon_an');
            $table->string('ten_mon_an', 150);
            $table->decimal('gia_tien', 18, 2);
            $table->text('mo_ta')->nullable();
            $table->string('hinh_anh', 255)->nullable();
            $table->unsignedInteger('ma_danh_muc')->nullable();
            $table->json('nguyen_lieu')->nullable();
            $table->json('chat_di_ung')->nullable();
            $table->json('the_thoi_tiet')->nullable();
            $table->boolean('con_ban')->default(true);
            $table->boolean('hien_thi')->default(true);
            $table->boolean('da_xoa')->default(false);

            $table->foreign('ma_danh_muc')->references('ma_danh_muc')->on('DANH_MUC')->onDelete('set null');
        });

        // 7. GIO_HANG
        Schema::create('GIO_HANG', function (Blueprint $table) {
            $table->increments('ma_gio_hang');
            $table->dateTime('ngay_tao')->useCurrent();
            $table->unsignedInteger('ma_tai_khoan')->unique();

            $table->foreign('ma_tai_khoan')->references('ma_tai_khoan')->on('TAI_KHOAN')->onDelete('cascade');
        });

        // 8. MON_AN_GIO_HANG (Pivot)
        Schema::create('MON_AN_GIO_HANG', function (Blueprint $table) {
            $table->unsignedInteger('ma_gio_hang');
            $table->unsignedInteger('ma_mon_an');
            $table->integer('so_luong')->default(1);

            $table->primary(['ma_gio_hang', 'ma_mon_an']);
            $table->foreign('ma_gio_hang')->references('ma_gio_hang')->on('GIO_HANG')->onDelete('cascade');
            $table->foreign('ma_mon_an')->references('ma_mon_an')->on('MON_AN')->onDelete('cascade');
        });

        // 9. DON_HANG
        Schema::create('DON_HANG', function (Blueprint $table) {
            $table->increments('ma_don_hang');
            $table->dateTime('ngay_dat_hang')->useCurrent();
            $table->decimal('tong_tien', 18, 2)->default(0);
            $table->string('trang_thai', 50)->default('Chờ xác nhận');
            $table->string('dia_chi_giao_hang', 255)->nullable();
            $table->unsignedInteger('ma_tai_khoan');

            $table->foreign('ma_tai_khoan')->references('ma_tai_khoan')->on('TAI_KHOAN')->onDelete('cascade');
        });

        // 10. CHI_TIET_DON_HANG (Pivot)
        Schema::create('CHI_TIET_DON_HANG', function (Blueprint $table) {
            $table->unsignedInteger('ma_don_hang');
            $table->unsignedInteger('ma_mon_an');
            $table->integer('so_luong')->default(1);
            $table->decimal('don_gia', 18, 2);

            $table->primary(['ma_don_hang', 'ma_mon_an']);
            $table->foreign('ma_don_hang')->references('ma_don_hang')->on('DON_HANG')->onDelete('cascade');
            $table->foreign('ma_mon_an')->references('ma_mon_an')->on('MON_AN')->onDelete('cascade');
        });

        // 11. THANH_TOAN
        Schema::create('THANH_TOAN', function (Blueprint $table) {
            $table->increments('ma_thanh_toan');
            $table->decimal('so_tien', 18, 2);
            $table->string('phuong_thuc', 50)->default('Tiền mặt');
            $table->string('trang_thai', 50)->default('Chưa thanh toán');
            $table->unsignedInteger('ma_don_hang')->unique();
            $table->string('ma_giao_dich', 100)->nullable();
            $table->text('log_callback')->nullable();
            $table->string('ly_do_hoan_tien', 500)->nullable();

            $table->foreign('ma_don_hang')->references('ma_don_hang')->on('DON_HANG')->onDelete('cascade');
        });

        // 12. DAT_BAN
        Schema::create('DAT_BAN', function (Blueprint $table) {
            $table->increments('ma_dat_ban');
            $table->dateTime('thoi_gian');
            $table->integer('so_luong_khach');
            $table->string('trang_thai', 50)->default('Chờ xử lý');
            $table->text('ghi_chu')->nullable();
            $table->unsignedInteger('ma_tai_khoan');
            $table->unsignedInteger('ma_ban');

            $table->foreign('ma_tai_khoan')->references('ma_tai_khoan')->on('TAI_KHOAN')->onDelete('cascade');
            $table->foreign('ma_ban')->references('ma_ban')->on('BAN_AN')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('DAT_BAN');
        Schema::dropIfExists('THANH_TOAN');
        Schema::dropIfExists('CHI_TIET_DON_HANG');
        Schema::dropIfExists('DON_HANG');
        Schema::dropIfExists('MON_AN_GIO_HANG');
        Schema::dropIfExists('GIO_HANG');
        Schema::dropIfExists('MON_AN');
        Schema::dropIfExists('DANH_MUC');
        Schema::dropIfExists('BAN_AN');
        Schema::dropIfExists('TAI_KHOAN');
        Schema::dropIfExists('NGUOI_DUNG');
        Schema::dropIfExists('VAI_TRO');
    }
};
