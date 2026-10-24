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
        Schema::create('customer_notices', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('account_id');
            $table->foreign('account_id')->references('ma_tai_khoan')->on('TAI_KHOAN')->cascadeOnDelete();
            $table->string('event_key', 180)->unique();
            $table->string('kind', 60);
            $table->string('title');
            $table->text('body');
            $table->string('path');
            $table->string('entity_type', 25)->nullable();
            $table->unsignedInteger('entity_id')->nullable();
            $table->json('snapshot')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['account_id', 'read_at', 'id']);
        });
        Schema::create('notice_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notice_id')->constrained('customer_notices')->cascadeOnDelete();
            $table->string('channel', 20)->default('email');
            $table->string('recipient')->nullable();
            $table->string('status', 25)->default('PENDING');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('error_code', 50)->nullable();
            $table->string('message_id')->nullable();
            $table->timestamp('available_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
            $table->unique(['notice_id', 'channel']);
            $table->index(['status', 'available_at']);
        });
        Schema::create('meal_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notice_id')->unique()->constrained('customer_notices')->cascadeOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->string('comment', 1000)->nullable();
            $table->timestamps();
        });
        Schema::create('support_conversations', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('account_id');
            $table->foreign('account_id')->references('ma_tai_khoan')->on('TAI_KHOAN')->cascadeOnDelete();
            $table->unsignedInteger('active_account_id')->nullable()->unique();
            $table->unsignedInteger('assigned_to')->nullable();
            $table->foreign('assigned_to')->references('ma_tai_khoan')->on('TAI_KHOAN')->nullOnDelete();
            $table->string('status', 20)->default('AI');
            $table->timestamp('staff_seen_at')->nullable();
            $table->timestamp('customer_seen_at')->nullable();
            $table->timestamp('last_message_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'last_message_at']);
        });
        Schema::create('support_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('support_conversations')->cascadeOnDelete();
            $table->string('sender', 20);
            $table->unsignedInteger('sender_id')->nullable();
            $table->text('body');
            $table->uuid('client_key')->nullable();
            $table->string('request_hash', 64)->nullable();
            $table->unsignedBigInteger('reply_to')->nullable()->unique();
            $table->json('dish_ids')->nullable();
            $table->string('ai_status', 20)->nullable();
            $table->unsignedInteger('tokens_used')->default(0);
            $table->timestamps();
            $table->unique(['conversation_id', 'sender', 'client_key'], 'support_message_idempotency');
            $table->index(['conversation_id', 'id']);
        });
        foreach (['DAT_BAN', 'DON_HANG', 'THANH_TOAN'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->unsignedInteger('communication_revision')->default(0));
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('support_messages');
        Schema::dropIfExists('support_conversations');
        Schema::dropIfExists('notice_deliveries');
        Schema::dropIfExists('meal_feedback');
        Schema::dropIfExists('customer_notices');
        foreach (['DAT_BAN', 'DON_HANG', 'THANH_TOAN'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('communication_revision'));
        }
    }
};
