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
        // 1. Bảng PHIEN_CHAT (Phiên hội thoại hỗ trợ khách hàng)
        Schema::create('PHIEN_CHAT', function (Blueprint $table) {
            $table->increments('ma_phien_chat');
            $table->unsignedInteger('ma_tai_khoan');
            $table->unsignedInteger('ma_nhan_vien')->nullable();
            $table->string('trang_thai', 50)->default('BOT'); // BOT, WAITING_STAFF, STAFF_CONNECTED, CLOSED
            $table->string('tieu_de', 255)->default('Hỗ trợ khách hàng');
            $table->timestamp('thoi_gian_tao')->useCurrent();
            $table->timestamp('thoi_gian_dong')->nullable();

            $table->foreign('ma_tai_khoan')
                ->references('ma_tai_khoan')
                ->on('TAI_KHOAN')
                ->onDelete('cascade');

            $table->foreign('ma_nhan_vien')
                ->references('ma_tai_khoan')
                ->on('TAI_KHOAN')
                ->onDelete('set null');
        });

        // 2. Bảng TIN_NHAN_CHAT (Chi tiết từng tin nhắn trong phiên)
        Schema::create('TIN_NHAN_CHAT', function (Blueprint $table) {
            $table->increments('ma_tin_nhan');
            $table->unsignedInteger('ma_phien_chat');
            $table->string('loai_nguoi_gui', 20); // CUSTOMER, BOT, STAFF
            $table->unsignedInteger('ma_tai_khoan')->nullable(); // Null nếu là BOT
            $table->text('noi_dung');
            $table->json('du_lieu_dinh_kem')->nullable(); // Dữ liệu thẻ món ăn gợi ý, link đơn hàng...
            $table->timestamp('thoi_gian_gui')->useCurrent();

            $table->foreign('ma_phien_chat')
                ->references('ma_phien_chat')
                ->on('PHIEN_CHAT')
                ->onDelete('cascade');

            $table->foreign('ma_tai_khoan')
                ->references('ma_tai_khoan')
                ->on('TAI_KHOAN')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('TIN_NHAN_CHAT');
        Schema::dropIfExists('PHIEN_CHAT');
    }
};
