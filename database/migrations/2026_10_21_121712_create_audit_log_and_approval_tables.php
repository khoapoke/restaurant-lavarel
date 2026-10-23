<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nhật ký thao tác nhạy cảm và yêu cầu duyệt của quản lý (ADR-023).
 *
 * NHAT_KY_THAO_TAC chỉ được ghi thêm (append-only) qua AuditLogger; ứng dụng không có
 * endpoint sửa/xóa. YEU_CAU_DUYET cho phép STAFF đề nghị, ADMIN duyệt trên phiên đăng
 * nhập của chính ADMIN (không nhập mật khẩu quản lý vào form nhân viên).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('NHAT_KY_THAO_TAC')) {
            Schema::create('NHAT_KY_THAO_TAC', function (Blueprint $table) {
                $table->bigIncrements('ma_nhat_ky');
                $table->unsignedInteger('ma_tai_khoan')->nullable();
                $table->string('vai_tro', 20)->nullable();
                $table->string('hanh_dong', 60);
                $table->string('doi_tuong', 40);
                $table->unsignedBigInteger('ma_doi_tuong');
                $table->string('ly_do', 500)->nullable();
                $table->text('truoc')->nullable();
                $table->text('sau')->nullable();
                $table->dateTime('tao_luc');

                $table->foreign('ma_tai_khoan')->references('ma_tai_khoan')->on('TAI_KHOAN')->nullOnDelete();
                $table->index(['doi_tuong', 'ma_doi_tuong']);
                $table->index(['hanh_dong', 'tao_luc']);
            });
        }

        if (! Schema::hasTable('YEU_CAU_DUYET')) {
            Schema::create('YEU_CAU_DUYET', function (Blueprint $table) {
                $table->bigIncrements('ma_yeu_cau_duyet');
                $table->string('loai', 40);
                $table->string('trang_thai', 20);
                $table->string('doi_tuong', 40);
                $table->unsignedBigInteger('ma_doi_tuong');
                $table->unsignedBigInteger('ma_phien')->nullable();
                $table->text('du_lieu');
                $table->string('ly_do', 500);
                $table->unsignedInteger('ma_nguoi_yeu_cau');
                $table->unsignedInteger('ma_nguoi_duyet')->nullable();
                $table->string('ghi_chu_duyet', 500)->nullable();
                $table->dateTime('tao_luc');
                $table->dateTime('xu_ly_luc')->nullable();

                $table->foreign('ma_phien')->references('ma_phien')->on('PHIEN_PHUC_VU')->nullOnDelete();
                $table->foreign('ma_nguoi_yeu_cau')->references('ma_tai_khoan')->on('TAI_KHOAN')->onDelete('restrict');
                $table->foreign('ma_nguoi_duyet')->references('ma_tai_khoan')->on('TAI_KHOAN')->nullOnDelete();
                $table->index(['trang_thai', 'loai']);
                $table->index(['doi_tuong', 'ma_doi_tuong']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('YEU_CAU_DUYET');
        Schema::dropIfExists('NHAT_KY_THAO_TAC');
    }
};
