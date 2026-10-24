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
        Schema::table('support_conversations', function (Blueprint $table) {
            $table->unsignedBigInteger('customer_seen_message_id')->default(0);
            $table->unsignedBigInteger('staff_seen_message_id')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('support_conversations', function (Blueprint $table) {
            $table->dropColumn(['customer_seen_message_id', 'staff_seen_message_id']);
        });
    }
};
