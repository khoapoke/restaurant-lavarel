<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Mộc Live Dining (ADR-020): QR ordering at the table.
 *
 * - BAN_AN.ma_qr: public, rotatable table token printed in the table QR code.
 * - LUOT_KHACH_BAN: one row per guest device (check-in request or joined guest),
 *   identified by a secret token whose SHA-256 hash is stored.
 * - YEU_CAU_PHUC_VU: live service requests (call staff, water, bill, ...).
 * - PHIEU_BEP: a kitchen ticket doubles as an ordering round, so it records its
 *   source (POS / TABLE_QR), the guest visit and an idempotency key.
 * - CHI_TIET_DON_HANG.bep_cap_nhat_luc: last kitchen status change, for live tracking.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('BAN_AN', 'ma_qr')) {
            Schema::table('BAN_AN', function (Blueprint $table) {
                $table->string('ma_qr', 64)->nullable()->unique();
                $table->dateTime('ma_qr_tao_luc')->nullable();
            });

            DB::table('BAN_AN')->whereNull('ma_qr')->orderBy('ma_ban')->pluck('ma_ban')->each(function ($tableId): void {
                DB::table('BAN_AN')->where('ma_ban', $tableId)->update([
                    'ma_qr' => Str::random(40),
                    'ma_qr_tao_luc' => now(),
                ]);
            });
        }

        if (! Schema::hasTable('LUOT_KHACH_BAN')) {
            Schema::create('LUOT_KHACH_BAN', function (Blueprint $table) {
                $table->bigIncrements('ma_luot');
                $table->unsignedInteger('ma_ban');
                $table->unsignedBigInteger('ma_phien')->nullable();
                $table->char('token_hash', 64)->unique();
                $table->string('ten_khach', 60)->nullable();
                $table->unsignedSmallInteger('so_khach')->nullable();
                $table->string('trang_thai', 20);
                $table->string('ly_do_tu_choi', 255)->nullable();
                $table->unsignedInteger('ma_nhan_vien_xu_ly')->nullable();
                $table->dateTime('tao_luc');
                $table->dateTime('xu_ly_luc')->nullable();
                $table->dateTime('truy_cap_luc')->nullable();

                $table->foreign('ma_ban')->references('ma_ban')->on('BAN_AN')->onDelete('restrict');
                $table->foreign('ma_phien')->references('ma_phien')->on('PHIEN_PHUC_VU')->nullOnDelete();
                $table->foreign('ma_nhan_vien_xu_ly')->references('ma_tai_khoan')->on('TAI_KHOAN')->nullOnDelete();
                $table->index(['ma_ban', 'trang_thai']);
                $table->index('ma_phien');
            });
        }

        if (! Schema::hasTable('YEU_CAU_PHUC_VU')) {
            Schema::create('YEU_CAU_PHUC_VU', function (Blueprint $table) {
                $table->bigIncrements('ma_yeu_cau');
                $table->unsignedBigInteger('ma_phien');
                $table->unsignedInteger('ma_ban');
                $table->unsignedBigInteger('ma_luot')->nullable();
                $table->string('loai', 30);
                $table->string('ghi_chu', 200)->nullable();
                $table->string('phuong_thuc_mong_muon', 30)->nullable();
                $table->string('trang_thai', 20);
                $table->unsignedInteger('ma_nhan_vien_xu_ly')->nullable();
                $table->dateTime('tao_luc');
                $table->dateTime('nhan_luc')->nullable();
                $table->dateTime('hoan_thanh_luc')->nullable();
                $table->dateTime('huy_luc')->nullable();

                $table->foreign('ma_phien')->references('ma_phien')->on('PHIEN_PHUC_VU')->onDelete('cascade');
                $table->foreign('ma_ban')->references('ma_ban')->on('BAN_AN')->onDelete('restrict');
                $table->foreign('ma_luot')->references('ma_luot')->on('LUOT_KHACH_BAN')->nullOnDelete();
                $table->foreign('ma_nhan_vien_xu_ly')->references('ma_tai_khoan')->on('TAI_KHOAN')->nullOnDelete();
                $table->index(['trang_thai', 'tao_luc']);
                $table->index(['ma_phien', 'loai', 'trang_thai']);
            });
        }

        if (! Schema::hasColumn('PHIEU_BEP', 'nguon')) {
            Schema::table('PHIEU_BEP', function (Blueprint $table) {
                $table->string('nguon', 20)->default('POS');
                $table->unsignedBigInteger('ma_luot')->nullable();
                $table->string('idempotency_key', 80)->nullable()->unique();
                $table->string('request_hash', 64)->nullable();

                $table->foreign('ma_luot')->references('ma_luot')->on('LUOT_KHACH_BAN')->nullOnDelete();
                $table->index(['ma_don_hang', 'nguon']);
            });
        }

        if (! Schema::hasColumn('CHI_TIET_DON_HANG', 'bep_cap_nhat_luc')) {
            Schema::table('CHI_TIET_DON_HANG', function (Blueprint $table) {
                $table->dateTime('bep_cap_nhat_luc')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('CHI_TIET_DON_HANG', 'bep_cap_nhat_luc')) {
            Schema::table('CHI_TIET_DON_HANG', function (Blueprint $table) {
                $table->dropColumn('bep_cap_nhat_luc');
            });
        }

        if (Schema::hasColumn('PHIEU_BEP', 'nguon')) {
            Schema::table('PHIEU_BEP', function (Blueprint $table) {
                $table->dropForeign(['ma_luot']);
                $table->dropIndex(['ma_don_hang', 'nguon']);
                $table->dropUnique(['idempotency_key']);
                $table->dropColumn(['nguon', 'ma_luot', 'idempotency_key', 'request_hash']);
            });
        }

        Schema::dropIfExists('YEU_CAU_PHUC_VU');
        Schema::dropIfExists('LUOT_KHACH_BAN');

        if (Schema::hasColumn('BAN_AN', 'ma_qr')) {
            Schema::table('BAN_AN', function (Blueprint $table) {
                $table->dropUnique(['ma_qr']);
                $table->dropColumn(['ma_qr', 'ma_qr_tao_luc']);
            });
        }
    }
};
