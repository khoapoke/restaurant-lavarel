<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Jobs\GenerateSupportReply;
use App\Models\Account;
use App\Models\CustomerNotice;
use App\Models\Dish;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Services\Shared\GeminiAssistant;
use App\Services\Shared\GuestCommunicationService;
use App\Services\Shared\SupportChatService;
use Database\Seeders\RestaurantSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SupportChatTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $username = 'kh1'): Account
    {
        if (! Account::exists()) {
            config(['communications.enabled' => false]);
            $this->seed(RestaurantSeeder::class);
            config(['communications.enabled' => true, 'communications.ai.key' => 'test-key-not-a-secret']);
        }

        return Account::where('ten_dang_nhap', $username)->firstOrFail();
    }

    private function conversation(): SupportConversation
    {
        return app(SupportChatService::class)->open($this->account());
    }

    private function message(SupportConversation $conversation, string $body = 'Gợi ý cho tôi món ăn nhẹ'): SupportMessage
    {
        return app(SupportChatService::class)->send($conversation, $conversation->account, $body, (string) Str::uuid());
    }

    private function fakeAi(array $reply = []): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://generativelanguage.googleapis.com/v1beta/models/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => json_encode(array_merge(['reply' => 'Mời bạn tham khảo thực đơn Mộc.', 'dishIds' => [], 'handoff' => false], $reply))]]]]],
            'usageMetadata' => ['totalTokenCount' => 25],
        ])]);
    }

    public function test_open_is_idempotent_and_unauthenticated_requests_are_refused(): void
    {
        $this->getJson('/api/v1/customer/support/current')->assertUnauthorized();
        Sanctum::actingAs($this->account());

        $first = $this->postJson('/api/v1/customer/support')->assertCreated()->json('data.conversation.conversationId');
        $this->postJson('/api/v1/customer/support')->assertCreated()->assertJsonPath('data.conversation.conversationId', $first);

        $this->assertDatabaseCount('support_conversations', 1);
    }

    public function test_customer_cannot_read_or_write_another_customers_conversation(): void
    {
        $conversation = $this->conversation();
        Sanctum::actingAs($this->account('kh2'));

        $this->getJson('/api/v1/customer/support/'.$conversation->id)->assertNotFound();
        $this->postJson('/api/v1/customer/support/'.$conversation->id.'/messages', ['body' => 'Nội dung', 'clientKey' => (string) Str::uuid()])->assertNotFound();
        $this->postJson('/api/v1/customer/support/'.$conversation->id.'/actions', ['action' => 'close'])->assertNotFound();

        $this->assertSame('AI', $conversation->fresh()->status);
        $this->assertDatabaseCount('support_messages', 1);
    }

    public function test_sending_same_client_key_twice_creates_one_customer_message(): void
    {
        $conversation = $this->conversation();
        Sanctum::actingAs($this->account());
        Queue::fake([GenerateSupportReply::class]);
        $payload = ['body' => 'Tư vấn món ăn', 'clientKey' => (string) Str::uuid()];

        $this->postJson('/api/v1/customer/support/'.$conversation->id.'/messages', $payload)->assertOk();
        $this->postJson('/api/v1/customer/support/'.$conversation->id.'/messages', $payload)->assertOk();

        $this->assertSame(1, $conversation->messages()->where('sender', 'CUSTOMER')->count());
        Queue::assertPushed(GenerateSupportReply::class);
    }

    public function test_reusing_key_with_different_body_is_a_conflict(): void
    {
        $conversation = $this->conversation();
        $key = (string) Str::uuid();
        app(SupportChatService::class)->send($conversation, $this->account(), 'Nội dung đầu', $key);
        Sanctum::actingAs($this->account());

        $this->postJson('/api/v1/customer/support/'.$conversation->id.'/messages', ['body' => 'Nội dung khác', 'clientKey' => $key])->assertConflict();

        $this->assertSame(1, $conversation->messages()->where('sender', 'CUSTOMER')->count());
    }

    public function test_whitespace_and_oversized_messages_are_rejected(): void
    {
        $conversation = $this->conversation();
        Sanctum::actingAs($this->account());

        $this->postJson('/api/v1/customer/support/'.$conversation->id.'/messages', ['body' => '   ', 'clientKey' => (string) Str::uuid()])->assertUnprocessable();
        $this->postJson('/api/v1/customer/support/'.$conversation->id.'/messages', ['body' => str_repeat('a', 1501), 'clientKey' => (string) Str::uuid()])->assertUnprocessable();

        $this->assertDatabaseCount('support_messages', 1);
    }

    public function test_ai_replies_once_and_filters_dish_ids_against_current_public_menu(): void
    {
        $conversation = $this->conversation();
        $conversation->account->userProfile->update(['di_ung' => []]);
        $dish = Dish::publiclyListed()->where('con_ban', true)->firstOrFail();
        $hidden = Dish::publiclyListed()->where('ma_mon_an', '!=', $dish->getKey())->firstOrFail();
        $hidden->update(['hien_thi' => false]);
        $trigger = $this->message($conversation);
        $this->fakeAi(['dishIds' => [$dish->getKey(), $hidden->getKey(), 999999]]);
        $job = new GenerateSupportReply($trigger->id);

        $job->handle(app(GeminiAssistant::class));
        $job->handle(app(GeminiAssistant::class));

        $reply = $conversation->messages()->where('sender', 'AI')->sole();
        $this->assertSame([$dish->getKey()], $reply->dish_ids);
        $this->assertSame('DONE', $trigger->fresh()->ai_status);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->hasHeader('x-goog-api-key', 'test-key-not-a-secret') && ! str_contains($request->url(), 'key='));
    }

    public function test_provider_failure_falls_back_to_human_without_exposing_provider_details(): void
    {
        $conversation = $this->conversation();
        $trigger = $this->message($conversation);
        Http::preventStrayRequests();
        Http::fake(['https://generativelanguage.googleapis.com/v1beta/models/*' => Http::response(['error' => 'secret-provider-detail'], 503)]);

        (new GenerateSupportReply($trigger->id))->handle(app(GeminiAssistant::class));

        $this->assertSame('WAITING', $conversation->fresh()->status);
        $this->assertStringNotContainsString('secret-provider-detail', $conversation->messages()->where('sender', 'AI')->sole()->body);
        Http::assertSentCount(1);
    }

    public function test_explicit_handoff_prevents_queued_ai_from_answering(): void
    {
        $conversation = $this->conversation();
        $trigger = $this->message($conversation);
        app(SupportChatService::class)->transition($conversation, $this->account(), 'handoff');
        $this->fakeAi();

        (new GenerateSupportReply($trigger->id))->handle(app(GeminiAssistant::class));

        $this->assertSame(0, $conversation->messages()->where('sender', 'AI')->count());
        $this->assertSame('WAITING', $conversation->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_staff_takeover_while_ai_is_waiting_for_provider_discards_late_ai_reply(): void
    {
        $conversation = $this->conversation();
        $trigger = $this->message($conversation);
        $staff = $this->account('staff1');
        Http::preventStrayRequests();
        Http::fake(['https://generativelanguage.googleapis.com/v1beta/models/*' => function () use ($conversation, $staff) {
            app(SupportChatService::class)->transition($conversation, $staff, 'claim');

            return Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode(['reply' => 'LATE_AI_REPLY', 'dishIds' => [], 'handoff' => false])]]]]]]);
        }]);

        (new GenerateSupportReply($trigger->id))->handle(app(GeminiAssistant::class));

        $this->assertSame('HUMAN', $conversation->fresh()->status);
        $this->assertSame(0, $conversation->messages()->where('sender', 'AI')->count());
        Http::assertSentCount(1);
    }

    public function test_only_assigned_staff_can_reply_and_customer_cannot_access_staff_inbox(): void
    {
        $conversation = $this->conversation();
        app(SupportChatService::class)->transition($conversation, $this->account('staff1'), 'claim');
        Sanctum::actingAs($this->account('admin'));

        $this->postJson('/api/v1/staff/support/'.$conversation->id.'/messages', ['body' => 'Trả lời thay', 'clientKey' => (string) Str::uuid()])->assertConflict();
        $this->postJson('/api/v1/staff/support/'.$conversation->id.'/actions', ['action' => 'claim'])->assertConflict();
        $this->assertSame(0, $conversation->messages()->where('sender', 'STAFF')->count());
    }

    public function test_customer_is_forbidden_from_staff_chat_and_delivery_logs(): void
    {
        Sanctum::actingAs($this->account());

        $this->getJson('/api/v1/staff/support')->assertForbidden();
        $this->getJson('/api/v1/admin/communications/deliveries')->assertForbidden();
    }

    public function test_staff_can_read_queue_take_ownership_reply_and_close(): void
    {
        $conversation = $this->conversation();
        app(SupportChatService::class)->transition($conversation, $this->account(), 'handoff');
        Sanctum::actingAs($this->account('staff1'));

        $this->getJson('/api/v1/staff/support')->assertOk()->assertJsonPath('data.waitingCount', 1);
        $this->postJson('/api/v1/staff/support/'.$conversation->id.'/actions', ['action' => 'claim'])->assertOk()->assertJsonPath('data.conversation.isMine', true);
        $this->postJson('/api/v1/staff/support/'.$conversation->id.'/messages', ['body' => 'Mộc chào bạn!', 'clientKey' => (string) Str::uuid()])->assertOk();
        $this->postJson('/api/v1/staff/support/'.$conversation->id.'/actions', ['action' => 'close'])->assertOk()->assertJsonPath('data.conversation.status', 'CLOSED');

        $this->assertSame(1, $conversation->messages()->where('sender', 'STAFF')->count());
        $this->assertNull($conversation->fresh()->active_account_id);
        $this->assertDatabaseHas('customer_notices', ['kind' => 'SUPPORT_REPLY']);
    }

    public function test_closed_conversation_can_be_read_but_not_written_and_new_chat_is_separate(): void
    {
        $conversation = $this->conversation();
        app(SupportChatService::class)->transition($conversation, $this->account(), 'close');
        Sanctum::actingAs($this->account());

        $this->getJson('/api/v1/customer/support/'.$conversation->id)->assertOk();
        $this->postJson('/api/v1/customer/support/'.$conversation->id.'/messages', ['body' => 'Tin mới', 'clientKey' => (string) Str::uuid()])->assertConflict();
        $new = $this->postJson('/api/v1/customer/support')->assertCreated()->json('data.conversation.conversationId');

        $this->assertNotSame($conversation->id, $new);
    }

    public function test_ai_daily_quota_falls_back_to_staff_instead_of_making_extra_paid_calls(): void
    {
        $conversation = $this->conversation();
        config(['communications.ai.daily_account_limit' => 1]);
        $first = $this->message($conversation);
        $this->fakeAi();
        (new GenerateSupportReply($first->id))->handle(app(GeminiAssistant::class));
        $second = $this->message($conversation, 'Tôi muốn thêm gợi ý');

        (new GenerateSupportReply($second->id))->handle(app(GeminiAssistant::class));

        Http::assertSentCount(1);
        $this->assertSame('WAITING', $conversation->fresh()->status);
    }

    public function test_financial_or_cancellation_requests_go_to_staff_without_a_model_call(): void
    {
        $conversation = $this->conversation();
        $message = $this->message($conversation, 'Tôi cần hủy đơn và hoàn tiền');
        $this->fakeAi();

        (new GenerateSupportReply($message->id))->handle(app(GeminiAssistant::class));

        $this->assertSame('WAITING', $conversation->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_private_order_lookup_is_scoped_locally_and_not_sent_to_google(): void
    {
        $conversation = $this->conversation();
        $other = $this->account('kh2')->orders()->firstOrFail();
        $message = $this->message($conversation, 'Kiểm tra trạng thái đơn #'.$other->getKey());
        $this->fakeAi();

        (new GenerateSupportReply($message->id))->handle(app(GeminiAssistant::class));

        $reply = $conversation->messages()->where('sender', 'AI')->sole();
        $this->assertStringContainsString('chưa tìm thấy', $reply->body);
        Http::assertNothingSent();
    }

    public function test_local_private_lookup_is_excluded_from_later_gemini_history(): void
    {
        $conversation = $this->conversation();
        $message = $this->message($conversation, 'Kiểm tra trạng thái đơn #999999');
        $this->fakeAi();
        (new GenerateSupportReply($message->id))->handle(app(GeminiAssistant::class));
        $next = $this->message($conversation, 'Cho mình một món khai vị nhé');

        (new GenerateSupportReply($next->id))->handle(app(GeminiAssistant::class));

        Http::assertSent(fn ($request) => ! str_contains(json_encode($request->data()), '999999'));
        Http::assertSentCount(1);
    }

    public function test_invalid_ai_payload_falls_back_to_staff_without_an_empty_message(): void
    {
        $conversation = $this->conversation();
        $message = $this->message($conversation);
        Http::preventStrayRequests();
        Http::fake(['https://generativelanguage.googleapis.com/v1beta/models/*' => Http::response(['candidates' => []])]);

        (new GenerateSupportReply($message->id))->handle(app(GeminiAssistant::class));

        $this->assertSame('WAITING', $conversation->fresh()->status);
        $this->assertNotEmpty($conversation->messages()->where('sender', 'AI')->sole()->body);
        Http::assertSentCount(1);
    }

    public function test_support_messages_have_bounded_history_pagination(): void
    {
        $conversation = $this->conversation();
        for ($index = 0; $index < 70; $index++) {
            $conversation->messages()->create(['sender' => 'SYSTEM', 'body' => 'Dòng '.$index]);
        }
        Sanctum::actingAs($this->account());

        $latest = $this->getJson('/api/v1/customer/support/'.$conversation->id)->assertOk()->assertJsonCount(60, 'data.conversation.messages')->assertJsonPath('data.conversation.hasOlder', true);
        $firstId = $latest->json('data.conversation.messages.0.messageId');
        $this->getJson('/api/v1/customer/support/'.$conversation->id.'?before='.$firstId)->assertOk()->assertJsonCount(11, 'data.conversation.messages')->assertJsonPath('data.conversation.hasOlder', false);
    }

    public function test_unread_staff_reply_gets_a_delayed_notification_when_customer_closed_chat_recently(): void
    {
        $this->freezeTime();
        $conversation = $this->conversation();
        $conversation->update(['customer_seen_at' => now()->subSecond()]);
        $staff = $this->account('staff1');
        app(SupportChatService::class)->transition($conversation, $staff, 'claim');
        app(SupportChatService::class)->send($conversation, $staff, 'Tin chưa đọc', (string) Str::uuid(), true);
        $this->assertSame(0, CustomerNotice::where('kind', 'SUPPORT_REPLY')->count());
        $this->travel(40)->seconds();
        $service = app(GuestCommunicationService::class);

        $service->unreadSupportReplies();
        $service->unreadSupportReplies();

        $this->assertSame(1, CustomerNotice::where('kind', 'SUPPORT_REPLY')->count());
        $this->travel(2)->hours();
        $service->unreadSupportReplies();
        $this->assertSame(1, CustomerNotice::where('kind', 'SUPPORT_REPLY')->count());
    }

    public function test_locking_customer_before_ai_processing_prevents_provider_call(): void
    {
        $conversation = $this->conversation();
        $message = $this->message($conversation);
        $conversation->account->update(['trang_thai' => AccountStatus::LOCKED]);
        $this->fakeAi();

        (new GenerateSupportReply($message->id))->handle(app(GeminiAssistant::class));

        Http::assertNothingSent();
        $this->assertSame('SKIPPED', $message->fresh()->ai_status);
    }

    public function test_other_staff_reading_does_not_mark_messages_read_for_the_assigned_staff(): void
    {
        $conversation = $this->conversation();
        app(SupportChatService::class)->transition($conversation, $this->account('staff1'), 'claim');
        $this->message($conversation);
        Sanctum::actingAs($this->account('admin'));

        $this->getJson('/api/v1/staff/support/'.$conversation->id)->assertOk();

        $this->assertNull($conversation->fresh()->staff_seen_at);
    }

    public function test_staff_reply_in_same_second_as_customer_read_is_still_unread(): void
    {
        $this->freezeTime();
        $conversation = $this->conversation();
        $chat = app(SupportChatService::class);
        $chat->markSeen($conversation, $this->account(), $chat->snapshot($conversation));
        $staff = $this->account('staff1');
        $chat->transition($conversation, $staff, 'claim');
        $chat->send($conversation, $staff, 'Tin sau khi khách vừa đóng chat', (string) Str::uuid(), true);
        $this->travel(40)->seconds();

        app(GuestCommunicationService::class)->unreadSupportReplies();

        $this->assertDatabaseHas('customer_notices', ['kind' => 'SUPPORT_REPLY']);
    }

    public function test_customer_message_in_same_second_as_staff_read_has_an_unread_badge(): void
    {
        $this->freezeTime();
        $conversation = $this->conversation();
        $chat = app(SupportChatService::class);
        $staff = $this->account('staff1');
        $conversation = $chat->transition($conversation, $staff, 'claim');
        $chat->markSeen($conversation, $staff, $chat->snapshot($conversation), true);
        $this->message($conversation);
        Sanctum::actingAs($staff);

        $this->getJson('/api/v1/staff/support')->assertOk()->assertJsonPath('data.attentionCount', 1)->assertJsonPath('data.items.0.unreadCount', 1);
    }

    private function secondStaff(): Account
    {
        $staff = $this->account('staff1')->replicate();
        $staff->ten_dang_nhap = 'staff2';
        $staff->email = 'staff2@example.invalid';
        $staff->save();

        return $staff;
    }

    private function claimedBy(Account $staff): SupportConversation
    {
        $chat = app(SupportChatService::class);
        $conversation = $this->conversation();
        $chat->transition($conversation, $this->account(), 'handoff');

        return $chat->transition($conversation, $staff, 'claim');
    }

    private function actions(SupportConversation $conversation): string
    {
        return '/api/v1/staff/support/'.$conversation->id.'/actions';
    }

    public function test_assigned_staff_returns_conversation_to_queue_with_a_reason_and_ai_stays_off(): void
    {
        $conversation = $this->claimedBy($this->account('staff1'));
        Sanctum::actingAs($this->account('staff1'));

        $this->postJson($this->actions($conversation), ['action' => 'release'])->assertUnprocessable();
        $this->postJson($this->actions($conversation), ['action' => 'release', 'reason' => 'Hết ca, nhờ đồng nghiệp hỗ trợ'])->assertOk()
            ->assertJsonPath('data.conversation.status', 'WAITING')->assertJsonPath('data.conversation.assignedName', null);

        $this->assertDatabaseHas('NHAT_KY_THAO_TAC', ['hanh_dong' => 'SUPPORT_RELEASED', 'ma_doi_tuong' => $conversation->id, 'ly_do' => 'Hết ca, nhờ đồng nghiệp hỗ trợ']);
        $this->assertStringContainsString('lịch sử trò chuyện vẫn được giữ nguyên', $conversation->messages()->where('sender', 'SYSTEM')->orderByDesc('id')->first()->body);
        $this->assertNull($this->message($conversation->fresh(), 'Còn ai hỗ trợ mình không?')->ai_status);
    }

    public function test_staff_who_is_not_the_owner_cannot_release_reassign_or_take_over(): void
    {
        $conversation = $this->claimedBy($this->account('staff1'));
        Sanctum::actingAs($this->secondStaff());

        $this->postJson($this->actions($conversation), ['action' => 'release', 'reason' => 'Tôi muốn nhận thay'])->assertForbidden();
        $this->postJson($this->actions($conversation), ['action' => 'takeover', 'reason' => 'Tôi muốn nhận thay'])->assertForbidden();
        $this->postJson($this->actions($conversation), ['action' => 'reassign', 'reason' => 'Tôi muốn nhận thay', 'assigneeId' => $this->account('admin')->getKey()])->assertForbidden();
        $this->getJson('/api/v1/admin/support/assignees')->assertForbidden();

        $this->assertSame($this->account('staff1')->getKey(), (int) $conversation->fresh()->assigned_to);
        $this->assertDatabaseMissing('NHAT_KY_THAO_TAC', ['doi_tuong' => 'SupportConversation']);
    }

    public function test_admin_reassigns_to_an_active_staff_member_who_replies_while_the_previous_owner_cannot(): void
    {
        $conversation = $this->claimedBy($this->account('staff1'));
        $target = $this->secondStaff();
        Sanctum::actingAs($this->account('admin'));

        $this->getJson('/api/v1/admin/support/assignees')->assertOk()->assertJsonFragment(['accountId' => $target->getKey()])->assertJsonMissing(['role' => 'CUSTOMER']);
        $this->postJson($this->actions($conversation), ['action' => 'reassign', 'reason' => 'Chuyển cho người trực tối'])->assertUnprocessable();
        $this->postJson($this->actions($conversation), ['action' => 'reassign', 'reason' => 'Chuyển cho người trực tối', 'assigneeId' => $target->getKey()])->assertOk()
            ->assertJsonPath('data.conversation.status', 'HUMAN')->assertJsonPath('data.conversation.assignedTo', $target->getKey());

        $this->assertDatabaseHas('NHAT_KY_THAO_TAC', ['hanh_dong' => 'SUPPORT_REASSIGNED', 'ma_doi_tuong' => $conversation->id]);
        $chat = app(SupportChatService::class);
        $chat->send($conversation, $target, 'Mình là nhân viên tiếp nhận mới', (string) Str::uuid(), true);
        try {
            $chat->send($conversation, $this->account('staff1'), 'Tin của người cũ', (string) Str::uuid(), true);
            $this->fail('Người phụ trách cũ vẫn trả lời được.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertSame(['Mình là nhân viên tiếp nhận mới'], $conversation->messages()->where('sender', 'STAFF')->pluck('body')->all());
    }

    public function test_admin_takes_over_but_cannot_hand_a_conversation_to_locked_or_customer_accounts(): void
    {
        $conversation = $this->claimedBy($this->account('staff1'));
        $locked = $this->secondStaff();
        $locked->update(['trang_thai' => AccountStatus::LOCKED]);
        Sanctum::actingAs($this->account('admin'));

        $this->postJson($this->actions($conversation), ['action' => 'reassign', 'reason' => 'Chuyển người phụ trách', 'assigneeId' => $locked->getKey()])->assertUnprocessable();
        $this->postJson($this->actions($conversation), ['action' => 'reassign', 'reason' => 'Chuyển người phụ trách', 'assigneeId' => $this->account('kh2')->getKey()])->assertUnprocessable();
        $this->postJson($this->actions($conversation), ['action' => 'takeover', 'reason' => 'Khách phản ánh gấp'])->assertOk()
            ->assertJsonPath('data.conversation.isMine', true);

        $this->assertSame($this->account('admin')->getKey(), (int) $conversation->fresh()->assigned_to);
        $this->assertDatabaseHas('NHAT_KY_THAO_TAC', ['hanh_dong' => 'SUPPORT_TAKEN_OVER', 'ma_doi_tuong' => $conversation->id]);
    }

    public function test_locking_the_assigned_staff_returns_their_conversations_to_the_queue_and_tells_the_customer(): void
    {
        $conversation = $this->claimedBy($this->account('staff1'));
        $history = $conversation->messages()->count();
        Sanctum::actingAs($this->account('admin'));

        $this->patchJson('/api/v1/admin/accounts/'.$this->account('staff1')->getKey().'/lock')->assertOk();

        $conversation->refresh();
        $this->assertSame(['WAITING', null], [$conversation->status, $conversation->assigned_to]);
        $this->assertSame($history + 1, $conversation->messages()->count());
        $this->assertStringContainsString('không thể tiếp tục hỗ trợ', $conversation->messages()->orderByDesc('id')->first()->body);
        $this->assertDatabaseHas('NHAT_KY_THAO_TAC', ['hanh_dong' => 'SUPPORT_RELEASED_ON_LOCK', 'ma_doi_tuong' => $conversation->id]);
    }

    public function test_conversation_still_held_by_a_locked_staff_member_can_be_claimed_by_another(): void
    {
        $conversation = $this->claimedBy($this->account('staff1'));
        Account::whereKey($this->account('staff1')->getKey())->update(['trang_thai' => AccountStatus::LOCKED->value]);

        $claimed = app(SupportChatService::class)->transition($conversation, $this->secondStaff(), 'claim');

        $this->assertSame(['HUMAN', $this->account('staff2')->getKey()], [$claimed->status, (int) $claimed->assigned_to]);
    }

    public function test_database_lock_during_claim_is_a_clean_conflict_that_keeps_the_queue_unchanged(): void
    {
        $conversation = $this->conversation();
        app(SupportChatService::class)->transition($conversation, $this->account(), 'handoff');
        DB::connection()->beforeExecuting(function (string $query): void {
            if (str_starts_with($query, 'update '.DB::connection()->getQueryGrammar()->wrapTable('support_conversations'))) {
                $driver = DB::getDriverName();
                $error = $driver === 'mysql' ? 'SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock' : 'SQLSTATE[HY000]: General error: 5 database is locked';
                throw new QueryException($driver, $query, [], new \PDOException($error));
            }
        });
        Sanctum::actingAs($this->account('staff1'));

        $this->postJson($this->actions($conversation), ['action' => 'claim'])->assertConflict();

        $this->assertSame(['WAITING', null], [$conversation->fresh()->status, $conversation->fresh()->assigned_to]);
    }

    public function test_local_lookups_do_not_use_gemini_quota_and_still_answer_after_it_is_exhausted(): void
    {
        $conversation = $this->conversation();
        config(['communications.ai.daily_account_limit' => 1]);
        $this->fakeAi();
        (new GenerateSupportReply($this->message($conversation)->id))->handle(app(GeminiAssistant::class));

        $lookup = $this->message($conversation, 'Kiểm tra trạng thái đơn của tôi');
        (new GenerateSupportReply($lookup->id))->handle(app(GeminiAssistant::class));

        Http::assertSentCount(1);
        $this->assertSame('AI', $conversation->fresh()->status);
        $this->assertSame('DONE_LOCAL', $lookup->fresh()->ai_status);
        $this->assertSame(1, RateLimiter::attempts('ai-day:'.now()->toDateString().':'.$conversation->account_id));
    }

    public function test_disabled_gemini_hands_off_without_reserving_quota_and_logs_a_safe_code(): void
    {
        $conversation = $this->conversation();
        config(['communications.ai.enabled' => false]);
        Http::preventStrayRequests();
        Log::spy();

        (new GenerateSupportReply($this->message($conversation, 'Món nào hợp trời mưa?')->id))->handle(app(GeminiAssistant::class));

        $this->assertSame('WAITING', $conversation->fresh()->status);
        $this->assertSame(0, RateLimiter::attempts('ai-day:'.now()->toDateString().':'.$conversation->account_id));
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => $message === 'communications.ai_fallback' && $context['code'] === 'AI_UNAVAILABLE');
    }

    public function test_truncated_model_json_falls_back_to_staff(): void
    {
        $conversation = $this->conversation();
        Http::preventStrayRequests();
        Http::fake(['https://generativelanguage.googleapis.com/v1beta/models/*' => Http::response([
            'candidates' => [['finishReason' => 'MAX_TOKENS', 'content' => ['parts' => [['text' => '{"reply":"Mộc gợi ý bạn","dishIds":[1']]]]],
        ])]);
        Log::spy();

        (new GenerateSupportReply($this->message($conversation)->id))->handle(app(GeminiAssistant::class));

        $this->assertSame('WAITING', $conversation->fresh()->status);
        $this->assertSame('FALLBACK', $conversation->messages()->where('sender', 'AI')->sole()->ai_status);
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => $context['code'] === 'AI_TRUNCATED');
    }

    public function test_provider_failure_is_logged_with_status_only_never_key_prompt_or_provider_body(): void
    {
        $conversation = $this->conversation();
        Http::preventStrayRequests();
        Http::fake(['https://generativelanguage.googleapis.com/v1beta/models/*' => Http::response(['error' => 'secret-provider-detail'], 429)]);
        Log::spy();

        (new GenerateSupportReply($this->message($conversation, 'Tư vấn giúp mình món chay')->id))->handle(app(GeminiAssistant::class));

        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context): bool {
            $logged = json_encode($context);

            return $context['code'] === 'AI_PROVIDER_QUOTA' && $context['providerStatus'] === 429
                && ! str_contains($logged, 'secret-provider-detail') && ! str_contains($logged, 'test-key-not-a-secret') && ! str_contains($logged, 'món chay');
        });
    }

    public function test_question_left_waiting_while_the_worker_was_down_goes_to_staff_without_calling_gemini(): void
    {
        $conversation = $this->conversation();
        $message = $this->message($conversation);
        $this->travel(11)->minutes();
        $this->fakeAi();

        (new GenerateSupportReply($message->id))->handle(app(GeminiAssistant::class));

        Http::assertNothingSent();
        $this->assertSame('WAITING', $conversation->fresh()->status);
    }

    public function test_ai_jobs_are_not_requeued_every_minute_while_the_worker_is_down(): void
    {
        $this->freezeSecond();
        $conversation = $this->conversation();
        Sanctum::actingAs($this->account());
        $this->postJson('/api/v1/customer/support/'.$conversation->id.'/messages', ['body' => 'Gợi ý món nhẹ', 'clientKey' => (string) Str::uuid()])->assertOk();

        for ($minute = 0; $minute < 30; $minute++) {
            $this->travel(1)->minutes();
            $this->artisan('dining:communications:dispatch')->assertSuccessful();
        }

        $this->assertSame(1, DB::table('jobs')->where('queue', 'communications')->count());
    }

    public function test_http_retries_of_one_message_do_not_add_ai_jobs_during_a_worker_outage(): void
    {
        $this->freezeSecond();
        $conversation = $this->conversation();
        Sanctum::actingAs($this->account());
        $payload = ['body' => 'Gợi ý món nhẹ', 'clientKey' => (string) Str::uuid()];

        for ($retry = 0; $retry < 15; $retry++) {
            $this->postJson('/api/v1/customer/support/'.$conversation->id.'/messages', $payload)->assertOk();
            $this->travel(2)->minutes();
            $this->artisan('dining:communications:dispatch')->assertSuccessful();
        }

        $this->assertSame(1, $conversation->messages()->where('sender', 'CUSTOMER')->count());
        $this->assertSame(1, DB::table('jobs')->where('queue', 'communications')->count());
    }

    public function test_http_retry_recovers_a_lost_ai_job_once_after_its_lease(): void
    {
        $this->freezeSecond();
        $conversation = $this->conversation();
        Sanctum::actingAs($this->account());
        $payload = ['body' => 'Gợi ý món nhẹ', 'clientKey' => (string) Str::uuid()];
        $this->postJson('/api/v1/customer/support/'.$conversation->id.'/messages', $payload)->assertOk();
        DB::table('jobs')->delete();

        $this->travel(2)->minutes();
        $this->postJson('/api/v1/customer/support/'.$conversation->id.'/messages', $payload)->assertOk();
        $this->assertDatabaseCount('jobs', 0);
        $this->travel(9)->minutes();
        $this->postJson('/api/v1/customer/support/'.$conversation->id.'/messages', $payload)->assertOk();
        $this->artisan('dining:communications:dispatch')->assertSuccessful();
        $this->postJson('/api/v1/customer/support/'.$conversation->id.'/messages', $payload)->assertOk();

        $this->assertSame(1, DB::table('jobs')->where('queue', 'communications')->count());
        $this->assertSame(1, $conversation->messages()->where('sender', 'CUSTOMER')->count());
    }

    public function test_scheduler_recovers_a_lost_ai_job_once_after_its_lease(): void
    {
        $this->freezeSecond();
        $conversation = $this->conversation();
        $this->message($conversation);
        $this->artisan('dining:communications:dispatch')->assertSuccessful();
        $this->assertDatabaseCount('jobs', 1);
        DB::table('jobs')->delete();
        $this->travel(11)->minutes();

        $this->artisan('dining:communications:dispatch')->assertSuccessful();
        $this->artisan('dining:communications:dispatch')->assertSuccessful();

        $this->assertSame(1, DB::table('jobs')->where('queue', 'communications')->count());
        $this->assertSame(1, $conversation->messages()->where('sender', 'CUSTOMER')->count());
    }

    public function test_new_http_messages_wait_in_the_outbox_when_the_queue_is_stalled(): void
    {
        $this->freezeSecond();
        $conversation = $this->conversation();
        Sanctum::actingAs($this->account());
        $this->postJson('/api/v1/customer/support/'.$conversation->id.'/messages', ['body' => 'Gợi ý món nhẹ', 'clientKey' => (string) Str::uuid()])->assertOk();
        $this->travel(3)->minutes();

        $this->postJson('/api/v1/customer/support/'.$conversation->id.'/messages', ['body' => 'Gợi ý thêm món chính', 'clientKey' => (string) Str::uuid()])->assertOk();
        $this->artisan('dining:communications:dispatch')->assertSuccessful();

        $this->assertSame(2, $conversation->messages()->where('ai_status', 'PENDING')->count());
        $this->assertSame(1, DB::table('jobs')->where('queue', 'communications')->count());
        $this->assertNull($conversation->messages()->where('sender', 'CUSTOMER')->latest('id')->firstOrFail()->ai_dispatched_at);
    }

    public function test_customer_chat_limit_is_independent_from_the_feedback_bucket(): void
    {
        config(['communications.rate_limits.customer_per_minute' => 2]);
        $notice = app(GuestCommunicationService::class)->record($this->account(), 'feedback-limit', 'FEEDBACK_REQUEST', 'Đánh giá', 'Cảm ơn', '/notifications');
        Sanctum::actingAs($this->account());

        $this->postJson('/api/v1/customer/support')->assertCreated();
        $this->postJson('/api/v1/customer/support')->assertCreated();
        $this->postJson('/api/v1/customer/support')->assertTooManyRequests();

        $this->postJson('/api/v1/customer/notifications/'.$notice->id.'/feedback', ['rating' => 5])->assertOk();
    }

    public function test_staff_get_their_own_higher_limit_separate_from_admin_email_retries(): void
    {
        config(['communications.rate_limits.customer_per_minute' => 2, 'communications.rate_limits.staff_per_minute' => 5, 'communications.rate_limits.email_retry_per_minute' => 1]);
        $conversation = $this->conversation();
        app(SupportChatService::class)->transition($conversation, $this->account(), 'handoff');
        $delivery = app(GuestCommunicationService::class)->record($this->account(), 'retry-limit', 'TEST', 'A', 'B', '/orders')->deliveries()->sole();
        $delivery->update(['status' => 'FAILED']);
        Sanctum::actingAs($this->account('admin'));
        $retry = ['reason' => 'Đã kiểm tra hộp thư khách', 'acknowledgeDuplicateRisk' => true];

        $this->postJson('/api/v1/admin/communications/deliveries/'.$delivery->id.'/retry', $retry)->assertOk();
        $this->postJson('/api/v1/admin/communications/deliveries/'.$delivery->id.'/retry', $retry)->assertTooManyRequests();
        $this->postJson($this->actions($conversation), ['action' => 'claim'])->assertOk();
        for ($index = 0; $index < 3; $index++) {
            $this->postJson('/api/v1/staff/support/'.$conversation->id.'/messages', ['body' => 'Trả lời '.$index, 'clientKey' => (string) Str::uuid()])->assertOk();
        }

        $this->assertSame(3, $conversation->messages()->where('sender', 'STAFF')->count());
    }
}
