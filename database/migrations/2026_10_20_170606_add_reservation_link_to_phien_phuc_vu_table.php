<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Link a dine-in service session to the reservation the guest arrived with.
     */
    public function up(): void
    {
        if (Schema::hasColumn('PHIEN_PHUC_VU', 'ma_dat_ban')) {
            return;
        }

        Schema::table('PHIEN_PHUC_VU', function (Blueprint $table): void {
            $table->unsignedInteger('ma_dat_ban')->nullable()->after('ma_nhan_vien');
            $table->string('ly_do_huy', 255)->nullable()->after('dong_luc');

            $table->foreign('ma_dat_ban')
                ->references('ma_dat_ban')
                ->on('DAT_BAN')
                ->nullOnDelete();
            $table->index('ma_dat_ban');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('PHIEN_PHUC_VU', 'ma_dat_ban')) {
            return;
        }

        Schema::table('PHIEN_PHUC_VU', function (Blueprint $table): void {
            $table->dropForeign(['ma_dat_ban']);
            $table->dropIndex(['ma_dat_ban']);
            $table->dropColumn(['ma_dat_ban', 'ly_do_huy']);
        });
    }
};
