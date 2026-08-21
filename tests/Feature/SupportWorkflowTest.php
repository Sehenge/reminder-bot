<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SupportWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.telegram.new_user_notification_chat_id' => '208791603']);
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200),
        ]);
    }

    public function test_user_can_contact_support_without_seeing_admin_identity(): void
    {
        $user = User::create([
            'telegram_id' => 70001,
            'first_name' => 'Котик',
            'timezone' => 'Europe/Moscow',
        ]);

        $this->send(70001, '/support', 810001);
        $user->refresh();
        $this->assertSame('wait_support', $user->state);

        $this->send(70001, '<b>Помогите</b>', 810002);
        $user->refresh();
        $this->assertNull($user->state);

        Http::assertSent(fn ($request) => (string) $request['chat_id'] === '208791603'
            && str_contains($request['text'], 'Новое обращение')
            && str_contains($request['text'], '&lt;b&gt;Помогите&lt;/b&gt;')
            && str_contains($request['text'], '/reply 70001'));

        Http::assertSent(fn ($request) => (int) $request['chat_id'] === 70001
            && str_contains($request['text'], 'от имени бота')
            && ! str_contains($request['text'], '208791603'));
    }

    public function test_only_admin_can_reply_through_bot(): void
    {
        User::create(['telegram_id' => 70002, 'timezone' => 'Europe/Moscow']);
        User::create(['telegram_id' => 208791603, 'timezone' => 'Europe/Moscow']);

        $this->send(70003, '/reply 70002 Поддельный ответ', 820001);
        $this->send(208791603, '/reply 70002 Всё исправили', 820002);

        Http::assertNotSent(fn ($request) => (int) $request['chat_id'] === 70002
            && str_contains($request['text'], 'Поддельный'));
        Http::assertSent(fn ($request) => (int) $request['chat_id'] === 70002
            && str_contains($request['text'], 'Ответ поддержки')
            && str_contains($request['text'], 'Всё исправили'));
    }

    private function send(int $fromId, string $text, int $updateId): void
    {
        $this->postJson(route('telegram.webhook'), [
            'update_id' => $updateId,
            'message' => [
                'message_id' => $updateId,
                'from' => ['id' => $fromId, 'is_bot' => false, 'first_name' => 'Test'],
                'chat' => ['id' => $fromId, 'type' => 'private'],
                'date' => 1716292800,
                'text' => $text,
            ],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => 'test-secret-token',
        ])->assertOk();
    }
}
