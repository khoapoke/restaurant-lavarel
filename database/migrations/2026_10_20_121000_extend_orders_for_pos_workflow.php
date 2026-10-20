<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('DON_HANG', function (Blueprint $table) {
            $table->unsignedBigInteger('ma_phien')->nullable()->after('ma_tai_khoan');
            $table->string('kenh', 30)->default('ONLINE')->after('ma_phien');
            $table->text('ghi_chu')->nullable()->after('dia_chi_giao_hang');
            $table->unsignedInteger('phien_ban')->default(1)->after('trang_thai');
            $table->foreign('ma_phien')->references('ma_phien')->on('PHIEN_PHUC_VU')->onDelete('set null');
            $table->index(['kenh', 'trang_thai']);
        });

        Schema::disableForeignKeyConstraints();
        Schema::rename('CHI_TIET_DON_HANG', 'CHI_TIET_DON_HANG_CU');

        Schema::create('CHI_TIET_DON_HANG', function (Blueprint $table) {
            $table->bigIncrements('ma_chi_tiet');
            $table->unsignedInteger('ma_don_hang');
            $table->unsignedInteger('ma_mon_an');
            $table->string('ten_mon_snapshot', 150);
            $table->integer('so_luong')->default(1);
            $table->decimal('don_gia', 18, 2);
            $table->text('ghi_chu')->nullable();
            $table->string('trang_thai_bep', 30)->default('DRAFT');
            $table->string('client_request_id', 80)->nullable()->unique();

            $table->foreign('ma_don_hang')->references('ma_don_hang')->on('DON_HANG')->onDelete('cascade');
            $table->foreign('ma_mon_an')->references('ma_mon_an')->on('MON_AN')->onDelete('restrict');
            $table->index(['ma_don_hang', 'trang_thai_bep']);
        });

        $legacyItems = DB::table('CHI_TIET_DON_HANG_CU')
            ->join('MON_AN', 'MON_AN.ma_mon_an', '=', 'CHI_TIET_DON_HANG_CU.ma_mon_an')
            ->select([
                'CHI_TIET_DON_HANG_CU.ma_don_hang',
                'CHI_TIET_DON_HANG_CU.ma_mon_an',
                'CHI_TIET_DON_HANG_CU.so_luong',
                'CHI_TIET_DON_HANG_CU.don_gia',
                'MON_AN.ten_mon_an',
            ])->get();

        foreach ($legacyItems as $item) {
            DB::table('CHI_TIET_DON_HANG')->insert([
                'ma_don_hang' => $item->ma_don_hang,
                'ma_mon_an' => $item->ma_mon_an,
                'ten_mon_snapshot' => $item->ten_mon_an,
                'so_luong' => $item->so_luong,
                'don_gia' => $item->don_gia,
                'trang_thai_bep' => 'SERVED',
            ]);
        }

        Schema::drop('CHI_TIET_DON_HANG_CU');
        Schema::enableForeignKeyConstraints();

        Schema::create('PHIEU_BEP', function (Blueprint $table) {
            $table->bigIncrements('ma_phieu_bep');
            $table->unsignedInteger('ma_don_hang');
            $table->unsignedInteger('ma_nhan_vien');
            $table->string('trang_thai', 30)->default('OPEN');
            $table->dateTime('gui_luc')->useCurrent();

            $table->foreign('ma_don_hang')->references('ma_don_hang')->on('DON_HANG')->onDelete('cascade');
            $table->foreign('ma_nhan_vien')->references('ma_tai_khoan')->on('TAI_KHOAN')->onDelete('restrict');
        });

        Schema::create('CHI_TIET_PHIEU_BEP', function (Blueprint $table) {
            $table->unsignedBigInteger('ma_phieu_bep');
            $table->unsignedBigInteger('ma_chi_tiet');
            $table->primary(['ma_phieu_bep', 'ma_chi_tiet']);
            $table->foreign('ma_phieu_bep')->references('ma_phieu_bep')->on('PHIEU_BEP')->onDelete('cascade');
            $table->foreign('ma_chi_tiet')->references('ma_chi_tiet')->on('CHI_TIET_DON_HANG')->onDelete('cascade');
        });

        Schema::create('HOA_DON', function (Blueprint $table) {
            $table->bigIncrements('ma_hoa_don');
            $table->unsignedBigInteger('ma_phien')->unique();
            $table->unsignedInteger('ma_don_hang')->unique();
            $table->decimal('tam_tinh', 18, 2);
            $table->decimal('giam_gia', 18, 2)->default(0);
            $table->decimal('tong_thanh_toan', 18, 2);
            $table->string('trang_thai', 30)->default('OPEN');
            $table->dateTime('tao_luc')->useCurrent();
            $table->dateTime('thanh_toan_luc')->nullable();

            $table->foreign('ma_phien')->references('ma_phien')->on('PHIEN_PHUC_VU')->onDelete('cascade');
            $table->foreign('ma_don_hang')->references('ma_don_hang')->on('DON_HANG')->onDelete('cascade');
        });

        Schema::table('THANH_TOAN', function (Blueprint $table) {
            $table->unsignedBigInteger('ma_hoa_don')->nullable()->after('ma_don_hang');
            $table->string('idempotency_key', 80)->nullable()->unique()->after('ma_giao_dich');
            $table->foreign('ma_hoa_don')->references('ma_hoa_don')->on('HOA_DON')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('THANH_TOAN', function (Blueprint $table) {
            $table->dropForeign(['ma_hoa_don']);
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn(['ma_hoa_don', 'idempotency_key']);
        });
        Schema::dropIfExists('HOA_DON');
        Schema::dropIfExists('CHI_TIET_PHIEU_BEP');
        Schema::dropIfExists('PHIEU_BEP');

        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('CHI_TIET_DON_HANG');
        Schema::create('CHI_TIET_DON_HANG', function (Blueprint $table) {
            $table->unsignedInteger('ma_don_hang');
            $table->unsignedInteger('ma_mon_an');
            $table->integer('so_luong')->default(1);
            $table->decimal('don_gia', 18, 2);
            $table->primary(['ma_don_hang', 'ma_mon_an']);
        });
        Schema::enableForeignKeyConstraints();

        Schema::table('DON_HANG', function (Blueprint $table) {
            $table->dropForeign(['ma_phien']);
            $table->dropIndex(['kenh', 'trang_thai']);
            $table->dropColumn(['ma_phien', 'kenh', 'ghi_chu', 'phien_ban']);
        });
    }
};
