<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramBotWorkflowTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Заголовки с корректным секретом вебхука, ожидаемым в тестовом окружении
     * (см. TELEGRAM_WEBHOOK_SECRET в phpunit.xml).
     *
     * @return array<string, string>
     */
    protected function webhookHeaders(): array
    {
        return [
            'X-Telegram-Bot-Api-Secret-Token' => 'test-secret-token',
        ];
    }

    /**
     * Тест отправки команды /start через Webhook
     */
    public function test_webhook_handles_start_command(): void
    {
        // Мокаем отправку HTTP-запросов к API Telegram
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200),
        ]);

        $payload = [
            'update_id' => 123456,
            'message' => [
                'message_id' => 1,
                'from' => [
                    'id' => 99999,
                    'is_bot' => false,
                    'first_name' => 'John',
                    'username' => 'john_doe',
                    'language_code' => 'ru',
                ],
                'chat' => [
                    'id' => 99999,
                    'first_name' => 'John',
                    'type' => 'private',
                ],
                'date' => 1716292800,
                'text' => '/start',
            ],
        ];

        $response = $this->postJson(route('telegram.webhook'), $payload, $this->webhookHeaders());

        $response->assertStatus(200);
        $response->assertJson(['status' => 'ok']);

        // Проверяем, что пользователь создался в базе данных
        $this->assertDatabaseHas('users', [
            'telegram_id' => 99999,
            'username' => 'john_doe',
            'first_name' => 'John',
        ]);

        // Проверяем, что к Telegram API был сделан исходящий запрос sendMessage
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'sendMessage') &&
                   $request['chat_id'] === 99999 &&
                   str_contains($request['text'], 'Привет');
        });
    }

    /**
     * Тест парсинга и подтверждения через вебхук
     */
    public function test_webhook_parses_natural_language_reminder(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200),
        ]);

        // Создаем пользователя заранее
        $user = User::create([
            'telegram_id' => 88888,
            'username' => 'alex',
            'first_name' => 'Alex',
            'timezone' => 'Europe/Moscow',
        ]);

        $payload = [
            'update_id' => 123457,
            'message' => [
                'message_id' => 2,
                'from' => [
                    'id' => 88888,
                    'is_bot' => false,
                    'first_name' => 'Alex',
                    'username' => 'alex',
                    'language_code' => 'ru',
                ],
                'chat' => [
                    'id' => 88888,
                    'first_name' => 'Alex',
                    'type' => 'private',
                ],
                'date' => 1716292800,
                'text' => 'Напомни сходить в спортзал завтра в 14:00',
            ],
        ];

        $response = $this->postJson(route('telegram.webhook'), $payload, $this->webhookHeaders());

        $response->assertStatus(200);

        // Проверяем, что состояние сохранилось в state_data пользователя
        $user->refresh();
        $this->assertNotNull($user->state_data);
        $this->assertEquals('сходить в спортзал', $user->state_data['text']);
        $this->assertEquals('once', $user->state_data['recurrence_type']);

        // Проверяем отправку сообщения подтверждения
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'sendMessage') &&
                   $request['chat_id'] === 88888 &&
                   str_contains($request['text'], 'Создать новое напоминание?');
        });
    }

    /**
     * Если парсер не смог уверенно определить дату/время, бот не должен молча
     * подставлять "+1 час" и предлагать сохранить напоминание — он должен
     * попросить пользователя уточнить дату и сохранить исходный текст для продолжения диалога.
     */
    public function test_webhook_asks_for_clarification_when_datetime_cannot_be_parsed(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200),
        ]);

        $user = User::create([
            'telegram_id' => 88889,
            'username' => 'alex',
            'first_name' => 'Alex',
            'timezone' => 'Europe/Moscow',
        ]);

        $payload = [
            'update_id' => 123458,
            'message' => [
                'message_id' => 3,
                'from' => [
                    'id' => 88889,
                    'is_bot' => false,
                    'first_name' => 'Alex',
                    'username' => 'alex',
                    'language_code' => 'ru',
                ],
                'chat' => [
                    'id' => 88889,
                    'first_name' => 'Alex',
                    'type' => 'private',
                ],
                'date' => 1716292800,
                'text' => 'Напомни купить хлеб',
            ],
        ];

        $response = $this->postJson(route('telegram.webhook'), $payload, $this->webhookHeaders());

        $response->assertStatus(200);

        $user->refresh();
        $this->assertSame('clarify_reminder', $user->state);
        $this->assertSame('купить хлеб', $user->state_data['original_text']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'sendMessage') &&
                   $request['chat_id'] === 88889 &&
                   str_contains($request['text'], 'Когда напомнить') &&
                   ! str_contains($request['text'], 'Создать новое напоминание?');
        });
    }

    public function test_clarification_reply_completes_original_reminder_request(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        Carbon::setTestNow(Carbon::create(2026, 8, 19, 12, 0, 0, 'Europe/Moscow'));
        $user = User::create([
            'telegram_id' => 88890,
            'first_name' => 'Alex',
            'language_code' => 'ru',
            'timezone' => 'Europe/Moscow',
            'state' => 'clarify_reminder',
            'state_data' => ['original_text' => 'купить хлеб'],
        ]);

        $payload = [
            'update_id' => 123459,
            'message' => [
                'message_id' => 4,
                'from' => ['id' => 88890, 'is_bot' => false, 'first_name' => 'Alex', 'language_code' => 'ru'],
                'chat' => ['id' => 88890, 'type' => 'private'],
                'date' => 1716292800,
                'text' => 'завтра в 15:00',
            ],
        ];

        $this->postJson(route('telegram.webhook'), $payload, $this->webhookHeaders())->assertOk();

        $user->refresh();
        $this->assertNull($user->state);
        $this->assertSame('купить хлеб', $user->state_data['text']);
        Http::assertSent(fn ($request) => str_contains($request['text'] ?? '', 'Создать новое напоминание?'));
    }

    /**
     * Запрос без секретного токена (или с неверным) должен быть отклонён с 403
     * и не должен создавать пользователя/отправлять сообщения.
     */
    public function test_webhook_rejects_request_with_missing_or_invalid_secret_token(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200),
        ]);

        $payload = [
            'update_id' => 555001,
            'message' => [
                'message_id' => 1,
                'from' => [
                    'id' => 77777,
                    'is_bot' => false,
                    'first_name' => 'NoSecret',
                ],
                'chat' => [
                    'id' => 77777,
                    'type' => 'private',
                ],
                'date' => 1716292800,
                'text' => '/start',
            ],
        ];

        // Без заголовка
        $response = $this->postJson(route('telegram.webhook'), $payload);
        $response->assertStatus(403);

        // С неверным значением заголовка
        $response = $this->postJson(route('telegram.webhook'), $payload, [
            'X-Telegram-Bot-Api-Secret-Token' => 'wrong-secret',
        ]);
        $response->assertStatus(403);

        $this->assertDatabaseMissing('users', ['telegram_id' => 77777]);
        Http::assertNothingSent();
    }

    /**
     * Повторная доставка того же update_id не должна повторно обрабатываться:
     * не должно быть создано второе напоминание и отправлен второй ответ.
     */
    public function test_duplicate_update_id_is_processed_only_once(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200),
        ]);

        $payload = [
            'update_id' => 999001,
            'message' => [
                'message_id' => 10,
                'from' => [
                    'id' => 55555,
                    'is_bot' => false,
                    'first_name' => 'Dup',
                    'username' => 'dup_user',
                    'language_code' => 'ru',
                ],
                'chat' => [
                    'id' => 55555,
                    'type' => 'private',
                ],
                'date' => 1716292800,
                'text' => '/start',
            ],
        ];

        $first = $this->postJson(route('telegram.webhook'), $payload, $this->webhookHeaders());
        $first->assertStatus(200);
        $first->assertJson(['status' => 'ok']);

        $second = $this->postJson(route('telegram.webhook'), $payload, $this->webhookHeaders());
        $second->assertStatus(200);
        $second->assertJson(['status' => 'duplicate']);

        // Ровно один вызов sendMessage к Telegram API, несмотря на два одинаковых update.
        Http::assertSentCount(1);
        $this->assertDatabaseCount('telegram_updates', 1);
    }

    /**
     * Кнопка "Мои напоминания" (callback_data: list_active) должна присылать список напоминаний.
     */
    public function test_list_active_callback_button_sends_reminders_list(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200),
        ]);

        $user = User::create([
            'telegram_id' => 11111,
            'username' => 'listuser',
            'first_name' => 'List',
            'timezone' => 'Europe/Moscow',
        ]);

        $payload = [
            'update_id' => 700001,
            'callback_query' => [
                'id' => 'cbq1',
                'from' => [
                    'id' => 11111,
                    'is_bot' => false,
                    'first_name' => 'List',
                    'username' => 'listuser',
                ],
                'message' => [
                    'message_id' => 20,
                    'chat' => ['id' => 11111, 'type' => 'private'],
                ],
                'data' => 'list_active',
            ],
        ];

        $response = $this->postJson(route('telegram.webhook'), $payload, $this->webhookHeaders());
        $response->assertStatus(200);

        Http::assertSent(function ($request) use ($user) {
            return str_contains($request->url(), 'sendMessage') &&
                   $request['chat_id'] === $user->telegram_id &&
                   str_contains($request['text'], 'нет активных напоминаний');
        });
    }

    /**
     * Кнопка "Изменить часовой пояс" (callback_data: timezone_ask) должна запускать диалог
     * выбора часового пояса и переводить пользователя в состояние wait_timezone.
     */
    public function test_timezone_ask_callback_button_starts_timezone_dialog(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200),
        ]);

        $user = User::create([
            'telegram_id' => 22222,
            'username' => 'tzuser',
            'first_name' => 'Tz',
            'timezone' => 'Europe/Moscow',
        ]);

        $payload = [
            'update_id' => 700002,
            'callback_query' => [
                'id' => 'cbq2',
                'from' => [
                    'id' => 22222,
                    'is_bot' => false,
                    'first_name' => 'Tz',
                    'username' => 'tzuser',
                ],
                'message' => [
                    'message_id' => 21,
                    'chat' => ['id' => 22222, 'type' => 'private'],
                ],
                'data' => 'timezone_ask',
            ],
        ];

        $response = $this->postJson(route('telegram.webhook'), $payload, $this->webhookHeaders());
        $response->assertStatus(200);

        $user->refresh();
        $this->assertEquals('wait_timezone', $user->state);

        Http::assertSent(function ($request) use ($user) {
            return str_contains($request->url(), 'sendMessage') &&
                   $request['chat_id'] === $user->telegram_id &&
                   str_contains($request['text'], 'Настройка часового пояса');
        });
    }

    /**
     * После выбора часового пояса кнопкой (settimezone_*) FSM-состояние должно быть очищено,
     * иначе следующее обычное сообщение пользователя будет ошибочно принято за ввод часового пояса.
     */
    public function test_settimezone_callback_clears_fsm_state(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200),
        ]);

        $user = User::create([
            'telegram_id' => 33333,
            'username' => 'settz',
            'first_name' => 'SetTz',
            'timezone' => 'Europe/Moscow',
            'state' => 'wait_timezone',
        ]);

        $payload = [
            'update_id' => 700003,
            'callback_query' => [
                'id' => 'cbq3',
                'from' => [
                    'id' => 33333,
                    'is_bot' => false,
                    'first_name' => 'SetTz',
                    'username' => 'settz',
                ],
                'message' => [
                    'message_id' => 22,
                    'chat' => ['id' => 33333, 'type' => 'private'],
                ],
                'data' => 'settimezone_Asia/Yekaterinburg',
            ],
        ];

        $response = $this->postJson(route('telegram.webhook'), $payload, $this->webhookHeaders());
        $response->assertStatus(200);

        $user->refresh();
        $this->assertEquals('Asia/Yekaterinburg', $user->timezone);
        $this->assertNull($user->state);
        $this->assertNull($user->state_data);
    }

    /**
     * Нажатие "Отмена" должно очищать и state, и state_data.
     */
    public function test_cancel_callback_clears_state_and_state_data(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200),
        ]);

        $user = User::create([
            'telegram_id' => 44444,
            'username' => 'canceluser',
            'first_name' => 'Cancel',
            'timezone' => 'Europe/Moscow',
            'state' => 'wait_timezone',
            'state_data' => ['text' => 'что-то незавершённое'],
        ]);

        $payload = [
            'update_id' => 700004,
            'callback_query' => [
                'id' => 'cbq4',
                'from' => [
                    'id' => 44444,
                    'is_bot' => false,
                    'first_name' => 'Cancel',
                    'username' => 'canceluser',
                ],
                'message' => [
                    'message_id' => 23,
                    'chat' => ['id' => 44444, 'type' => 'private'],
                ],
                'data' => 'cancel',
            ],
        ];

        $response = $this->postJson(route('telegram.webhook'), $payload, $this->webhookHeaders());
        $response->assertStatus(200);

        $user->refresh();
        $this->assertNull($user->state);
        $this->assertNull($user->state_data);
    }

    /**
     * Успешное подтверждение создания напоминания (confirm_save) тоже должно очищать
     * state_data после сохранения (основной happy path FSM).
     */
    public function test_confirm_save_callback_clears_state_data_after_creating_reminder(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200),
        ]);

        $user = User::create([
            'telegram_id' => 66666,
            'username' => 'confirmuser',
            'first_name' => 'Confirm',
            'timezone' => 'Europe/Moscow',
            'state_data' => [
                'text' => 'сходить в спортзал',
                'target_at' => now('UTC')->addDay()->toIso8601String(),
                'recurrence_type' => 'once',
                'recurrence_value' => null,
            ],
        ]);

        $payload = [
            'update_id' => 700005,
            'callback_query' => [
                'id' => 'cbq5',
                'from' => [
                    'id' => 66666,
                    'is_bot' => false,
                    'first_name' => 'Confirm',
                    'username' => 'confirmuser',
                ],
                'message' => [
                    'message_id' => 24,
                    'chat' => ['id' => 66666, 'type' => 'private'],
                ],
                'data' => 'confirm_save',
            ],
        ];

        $response = $this->postJson(route('telegram.webhook'), $payload, $this->webhookHeaders());
        $response->assertStatus(200);

        $user->refresh();
        $this->assertNull($user->state);
        $this->assertNull($user->state_data);
        $this->assertDatabaseHas('reminders', [
            'user_id' => $user->id,
            'text' => 'сходить в спортзал',
        ]);
    }

    /**
     * Команда с суффиксом имени бота "/start@ReminderBot" должна обрабатываться так же, как "/start".
     */
    public function test_command_with_bot_username_suffix_is_handled(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200),
        ]);

        $payload = [
            'update_id' => 800001,
            'message' => [
                'message_id' => 30,
                'from' => [
                    'id' => 88881,
                    'is_bot' => false,
                    'first_name' => 'Suffix',
                    'username' => 'suffix_user',
                    'language_code' => 'ru',
                ],
                'chat' => [
                    'id' => 88881,
                    'type' => 'private',
                ],
                'date' => 1716292800,
                'text' => '/start@ReminderBot',
            ],
        ];

        $response = $this->postJson(route('telegram.webhook'), $payload, $this->webhookHeaders());
        $response->assertStatus(200);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'sendMessage') &&
                   $request['chat_id'] === 88881 &&
                   str_contains($request['text'], 'Привет');
        });
    }

    /**
     * Профиль пользователя (username/имя/фамилия) должен обновляться на повторных визитах,
     * а не оставаться зафиксированным на значениях из первого /start.
     */
    public function test_user_profile_is_updated_on_repeat_visit_with_changed_name(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200),
        ]);

        $user = User::create([
            'telegram_id' => 90001,
            'username' => 'old_username',
            'first_name' => 'OldFirst',
            'last_name' => 'OldLast',
            'language_code' => 'ru',
            'timezone' => 'Europe/Moscow',
        ]);

        $payload = [
            'update_id' => 800002,
            'message' => [
                'message_id' => 31,
                'from' => [
                    'id' => 90001,
                    'is_bot' => false,
                    'first_name' => 'NewFirst',
                    'last_name' => 'NewLast',
                    'username' => 'new_username',
                    'language_code' => 'en',
                ],
                'chat' => [
                    'id' => 90001,
                    'type' => 'private',
                ],
                'date' => 1716292800,
                'text' => '/help',
            ],
        ];

        $response = $this->postJson(route('telegram.webhook'), $payload, $this->webhookHeaders());
        $response->assertStatus(200);

        $user->refresh();
        $this->assertEquals('new_username', $user->username);
        $this->assertEquals('NewFirst', $user->first_name);
        $this->assertEquals('NewLast', $user->last_name);
        $this->assertEquals('en', $user->language_code);

        // По-прежнему только один пользователь в базе — профиль обновлён, а не задублирован.
        $this->assertDatabaseCount('users', 1);
    }
}
