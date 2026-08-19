<?php

namespace Tests\Feature;

use App\Models\Reminder;
use App\Models\SharedList;
use App\Models\User;
use App\Telegram\Presenters\ReminderMessagePresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PremiumTelegramCommandsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);
    }

    public function test_free_user_gets_premium_prompt_for_feature_command(): void
    {
        $user = $this->user(false);
        $this->command($user, '/categories', 2001);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'sendMessage')
            && str_contains($request['text'], 'доступна в Premium'));
    }

    public function test_premium_user_can_create_category_and_get_calendar_link(): void
    {
        $user = $this->user();
        $this->command($user, '/category add Работа', 2002);
        $this->command($user, '/calendar', 2003);

        $this->assertDatabaseHas('categories', ['user_id' => $user->id, 'name' => 'Работа']);
        $this->assertNotNull($user->refresh()->calendar_token);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'sendMessage')
            && str_contains($request['text'], '/calendar/'));
    }

    public function test_shared_reminder_presenter_targets_configured_group_chat(): void
    {
        $user = $this->user();
        $list = SharedList::query()->create([
            'owner_id' => $user->id,
            'name' => 'Группа',
            'telegram_chat_id' => '-1001234567890',
        ]);
        $reminder = Reminder::query()->create([
            'user_id' => $user->id,
            'shared_list_id' => $list->id,
            'text' => 'Общее дело',
            'target_at' => now()->addHour(),
            'recurrence_type' => 'once',
        ]);

        $payload = app(ReminderMessagePresenter::class)->notification($reminder, $user);

        $this->assertSame('-1001234567890', $payload['chat_id']);
    }

    public function test_premium_list_can_be_filtered_by_category(): void
    {
        $user = $this->user();
        $category = $user->categories()->create(['name' => 'Работа', 'color' => '#123456']);
        Reminder::query()->create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'text' => 'Рабочая задача',
            'target_at' => now()->addHour(),
            'recurrence_type' => 'once',
        ]);
        Reminder::query()->create([
            'user_id' => $user->id,
            'text' => 'Личная задача',
            'target_at' => now()->addHours(2),
            'recurrence_type' => 'once',
        ]);

        $this->command($user, "/list category:{$category->id}", 2004);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'sendMessage')
            && str_contains($request['text'], 'Рабочая задача')
            && ! str_contains($request['text'], 'Личная задача'));
    }

    private function command(User $user, string $text, int $updateId): void
    {
        $this->postJson(route('telegram.webhook'), [
            'update_id' => $updateId,
            'message' => [
                'message_id' => $updateId,
                'from' => ['id' => $user->telegram_id, 'first_name' => 'Test', 'is_bot' => false],
                'chat' => ['id' => $user->telegram_id, 'type' => 'private'],
                'date' => 1_755_600_000,
                'text' => $text,
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret-token'])->assertOk();
    }

    private function user(bool $premium = true): User
    {
        static $telegramId = 900000;

        return User::query()->create([
            'telegram_id' => ++$telegramId,
            'first_name' => 'Test',
            'timezone' => 'Europe/Moscow',
            'is_premium' => $premium,
            'premium_expires_at' => $premium ? now()->addMonth() : null,
        ]);
    }
}
