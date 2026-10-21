<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Live Dining (ADR-022): a per-session table code. The printed QR only identifies the
 * table; joining a session from a new device requires this code (or staff approval of a
 * check-in), so a previous guest holding the fixed QR link cannot enter the next meal.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('PHIEN_PHUC_VU', 'ma_tham_gia')) {
            return;
        }

        Schema::table('PHIEN_PHUC_VU', function (Blueprint $table) {
            $table->string('ma_tham_gia', 8)->nullable();
            $table->unsignedSmallInteger('so_lan_sai_ma')->default(0);
        });

        DB::table('PHIEN_PHUC_VU')
            ->whereIn('trang_thai', ['OPEN', 'AWAITING_PAYMENT'])
            ->pluck('ma_phien')
            ->each(fn ($sessionId) => DB::table('PHIEN_PHUC_VU')->where('ma_phien', $sessionId)->update([
                'ma_tham_gia' => str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT),
            ]));
    }

    public function down(): void
    {
        if (Schema::hasColumn('PHIEN_PHUC_VU', 'ma_tham_gia')) {
            Schema::table('PHIEN_PHUC_VU', function (Blueprint $table) {
                $table->dropColumn(['ma_tham_gia', 'so_lan_sai_ma']);
            });
        }
    }
};
