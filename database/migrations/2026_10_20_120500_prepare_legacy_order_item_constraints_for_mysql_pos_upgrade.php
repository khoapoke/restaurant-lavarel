<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql' || ! Schema::hasTable('CHI_TIET_DON_HANG') || Schema::hasColumn('CHI_TIET_DON_HANG', 'ma_chi_tiet')) {
            return;
        }

        // Must precede the immutable 121000 rebuild: MySQL FK names are schema-wide and survive RENAME.
        foreach (Schema::getForeignKeys('CHI_TIET_DON_HANG') as $foreignKey) {
            if (! in_array($foreignKey['name'], ['chi_tiet_don_hang_ma_don_hang_foreign', 'chi_tiet_don_hang_ma_mon_an_foreign'], true)) {
                continue;
            }
            Schema::table('CHI_TIET_DON_HANG', function (Blueprint $table) use ($foreignKey): void {
                $table->dropForeign($foreignKey['name']);
                $table->foreign($foreignKey['columns'], 'legacy_pos_'.$foreignKey['columns'][0])
                    ->references($foreignKey['foreign_columns'])->on($foreignKey['foreign_table'])
                    ->onUpdate($foreignKey['on_update'])->onDelete($foreignKey['on_delete']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // The POS rebuild drops the temporary legacy table; there is no data transformation to reverse.
    }
};
