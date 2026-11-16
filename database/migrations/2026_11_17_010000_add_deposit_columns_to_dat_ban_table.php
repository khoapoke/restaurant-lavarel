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
        Schema::table('DAT_BAN', function (Blueprint $table) {
            $table->decimal('tien_coc', 12, 2)->default(0)->after('ghi_chu');
            $table->boolean('da_thanh_toan_coc')->default(false)->after('tien_coc');
            $table->timestamp('han_chot_coc')->nullable()->after('da_thanh_toan_coc');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('DAT_BAN', function (Blueprint $table) {
            $table->dropColumn(['tien_coc', 'da_thanh_toan_coc', 'han_chot_coc']);
        });
    }
};

