<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('PHIEN_PHUC_VU', function (Blueprint $table) {
            $table->bigIncrements('ma_phien');
            $table->unsignedInteger('ma_nhan_vien');
            $table->unsignedInteger('so_luong_khach');
            $table->string('trang_thai', 40)->default('OPEN');
            $table->dateTime('mo_luc')->useCurrent();
            $table->dateTime('dong_luc')->nullable();
            $table->unsignedInteger('phien_ban')->default(1);
            $table->string('idempotency_key', 80)->unique();
            $table->string('request_hash', 64);

            $table->foreign('ma_nhan_vien')
                ->references('ma_tai_khoan')
                ->on('TAI_KHOAN')
                ->onDelete('restrict');
            $table->index(['trang_thai', 'mo_luc']);
        });

        Schema::create('PHIEN_BAN', function (Blueprint $table) {
            $table->unsignedBigInteger('ma_phien');
            $table->unsignedInteger('ma_ban');
            $table->dateTime('gan_luc')->useCurrent();

            $table->primary(['ma_phien', 'ma_ban']);
            $table->foreign('ma_phien')->references('ma_phien')->on('PHIEN_PHUC_VU')->onDelete('cascade');
            $table->foreign('ma_ban')->references('ma_ban')->on('BAN_AN')->onDelete('restrict');
            $table->index('ma_ban');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('PHIEN_BAN');
        Schema::dropIfExists('PHIEN_PHUC_VU');
    }
};
