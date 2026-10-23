<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Vòng đời lượt đặt bàn (ADR-026): ghi chú nội bộ tách khỏi ghi chú của khách, gia hạn
 * giữ chỗ khi khách báo trễ, người/thời điểm/lý do xử lý (no-show, hủy, đổi bàn).
 *
 * Backfill: các đoạn "[ADMIN] ..." trước đây nối vào ghi chú khách được chuyển sang
 * ghi_chu_noi_bo để không còn hiển thị cho khách và không ảnh hưởng luật đặt trước 2 giờ.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('DAT_BAN', 'ghi_chu_noi_bo')) {
            return;
        }

        Schema::table('DAT_BAN', function (Blueprint $table) {
            $table->text('ghi_chu_noi_bo')->nullable();
            $table->dateTime('gia_han_den')->nullable();
            $table->string('ly_do_gia_han', 255)->nullable();
            $table->unsignedInteger('ma_nguoi_gia_han')->nullable();
            $table->dateTime('xu_ly_luc')->nullable();
            $table->unsignedInteger('ma_nguoi_xu_ly')->nullable();
            $table->string('ly_do_xu_ly', 500)->nullable();

            $table->foreign('ma_nguoi_gia_han')->references('ma_tai_khoan')->on('TAI_KHOAN')->nullOnDelete();
            $table->foreign('ma_nguoi_xu_ly')->references('ma_tai_khoan')->on('TAI_KHOAN')->nullOnDelete();
            $table->index(['trang_thai', 'thoi_gian']);
        });

        DB::table('DAT_BAN')->where('ghi_chu', 'like', '%[ADMIN]%')->get(['ma_dat_ban', 'ghi_chu'])->each(function ($reservation): void {
            $guestLines = [];
            $internalLines = [];
            foreach (preg_split('/\R/u', (string) $reservation->ghi_chu) as $line) {
                if (str_starts_with(trim($line), '[ADMIN]')) {
                    $internalLines[] = trim(substr(trim($line), strlen('[ADMIN]')));
                } else {
                    $guestLines[] = $line;
                }
            }
            $guestNote = trim(implode("\n", $guestLines));

            DB::table('DAT_BAN')->where('ma_dat_ban', $reservation->ma_dat_ban)->update([
                'ghi_chu' => $guestNote !== '' ? $guestNote : null,
                'ghi_chu_noi_bo' => implode("\n", array_filter($internalLines)),
            ]);
        });
    }

    public function down(): void
    {
        // Giữ lại ghi chú nội bộ theo định dạng cũ để rollback không làm mất dữ liệu.
        DB::table('DAT_BAN')->whereNotNull('ghi_chu_noi_bo')->where('ghi_chu_noi_bo', '!=', '')->get(['ma_dat_ban', 'ghi_chu', 'ghi_chu_noi_bo'])
            ->each(fn ($reservation) => DB::table('DAT_BAN')->where('ma_dat_ban', $reservation->ma_dat_ban)->update([
                'ghi_chu' => trim(trim((string) $reservation->ghi_chu)."\n".collect(preg_split('/\R/u', (string) $reservation->ghi_chu_noi_bo))
                    ->filter()->map(fn ($line) => '[ADMIN] '.$line)->implode("\n")),
            ]));

        Schema::table('DAT_BAN', function (Blueprint $table) {
            $table->dropForeign(['ma_nguoi_gia_han']);
            $table->dropForeign(['ma_nguoi_xu_ly']);
            $table->dropIndex(['trang_thai', 'thoi_gian']);
            $table->dropColumn(['ghi_chu_noi_bo', 'gia_han_den', 'ly_do_gia_han', 'ma_nguoi_gia_han', 'xu_ly_luc', 'ma_nguoi_xu_ly', 'ly_do_xu_ly']);
        });
    }
};
