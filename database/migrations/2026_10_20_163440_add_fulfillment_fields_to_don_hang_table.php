<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('DON_HANG', function (Blueprint $table): void {
            $table->string('loai_phuc_vu', 20)->default('DELIVERY')->after('kenh');
            $table->string('trang_thai_giao_nhan', 30)->default('PENDING')->after('loai_phuc_vu');
            $table->string('ten_nguoi_nhan', 100)->nullable()->after('dia_chi_giao_hang');
            $table->string('so_dien_thoai_nhan', 20)->nullable()->after('ten_nguoi_nhan');
            $table->dateTime('thoi_gian_nhan_du_kien')->nullable()->after('so_dien_thoai_nhan');
            $table->decimal('phi_giao_hang', 18, 2)->default(0)->after('tong_tien');

            $table->index(['loai_phuc_vu', 'trang_thai_giao_nhan'], 'don_hang_fulfillment_index');
        });

        DB::table('DON_HANG')
            ->where('kenh', 'POS')
            ->update([
                'loai_phuc_vu' => 'DINE_IN',
                'trang_thai_giao_nhan' => 'SERVING',
            ]);

        DB::table('DON_HANG')
            ->where('dia_chi_giao_hang', 'like', '[PICKUP]%')
            ->update(['loai_phuc_vu' => 'PICKUP']);

        foreach ([
            'DELIVERY' => 'DELIVERED',
            'PICKUP' => 'COLLECTED',
            'DINE_IN' => 'SERVED',
        ] as $type => $status) {
            DB::table('DON_HANG')
                ->where('trang_thai', 'Đã giao')
                ->where('loai_phuc_vu', $type)
                ->update(['trang_thai_giao_nhan' => $status]);
        }
    }

    public function down(): void
    {
        Schema::table('DON_HANG', function (Blueprint $table): void {
            $table->dropIndex('don_hang_fulfillment_index');
            $table->dropColumn([
                'loai_phuc_vu',
                'trang_thai_giao_nhan',
                'ten_nguoi_nhan',
                'so_dien_thoai_nhan',
                'thoi_gian_nhan_du_kien',
                'phi_giao_hang',
            ]);
        });
    }
};
