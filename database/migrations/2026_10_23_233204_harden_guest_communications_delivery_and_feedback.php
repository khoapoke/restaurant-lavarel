<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Review communications (2026-10-23): hạn gửi email, đánh dấu đã đưa vào hàng đợi (claim bền vững),
 * khóa giới hạn lời mời đánh giá theo ngày. Chỉ thêm cột nullable, không đổi dữ liệu cũ.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('notice_deliveries', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
        });
        Schema::table('customer_notices', function (Blueprint $table) {
            $table->string('limit_key', 120)->nullable()->unique();
        });
        Schema::table('support_messages', function (Blueprint $table) {
            $table->timestamp('ai_dispatched_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('support_messages', function (Blueprint $table) {
            $table->dropColumn('ai_dispatched_at');
        });
        Schema::table('customer_notices', function (Blueprint $table) {
            $table->dropUnique(['limit_key']);
            $table->dropColumn('limit_key');
        });
        Schema::table('notice_deliveries', function (Blueprint $table) {
            $table->dropColumn(['expires_at', 'dispatched_at']);
        });
    }
};
