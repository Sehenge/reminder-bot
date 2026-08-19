<?php

namespace Tests\Feature;

use App\Models\Reminder;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Тесты для оставшихся пунктов раздела "Создание и управление напоминаниями" из task.md:
 * snooze (10/30/60 мин и "до завтра"), редактирование текста/времени/повторения,
 * пагинация списка и семантика "Выполнено" для повторяющихся напоминаний.
 */
class ReminderManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @return array<string, string>
     */
    protected function webhookHeaders(): array
    {
        return [
            'X-Telegram-Bot-Api-Secret-Token' => 'test-secret-token',
        ];
    }

    private function createUser(array $overrides = []): User
    {
        return User::create(array_merge([
            'telegram_id' => random_int(1, 999999999),
            'username' => 'alex',
            'first_name' => 'Alex',
            'timezone' => 'UTC',
        ], $overrides));
    }

    private function createReminder(User $user, array $overrides = []): Reminder
    {
        return Reminder::create(array_merge([
            'user_id' => $user->id,
            'text' => 'Тестовое напоминание',
            'target_at' => Carbon::now('UTC')->subMinute(),
            'recurrence_type' => 'once',
            'is_completed' => false,
            'status' => Reminder::STATUS_PENDING,
        ], $overrides));
    }

    /**
     * @return array<string, mixed>
     */
    private function callbackPayload(int $updateId, User $user, string $data, int $messageId = 1): array
    {
        return [
            'update_id' => $updateId,
            'callback_query' => [
                'id' => 'cbq'.$updateId,
                'from' => [
                    'id' => $user->telegram_id,
                    'is_bot' => false,
                    'first_name' => $user->first_name ?? 'Test',
                    'username' => $user->username ?? 'test',
                ],
                'message' => [
                    'message_id' => $messageId,
                    'chat' => ['id' => $user->telegram_id, 'type' => 'private'],
                ],
                'data' => $data,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function messagePayload(int $updateId, User $user, string $text, int $messageId = 1): array
    {
        return [
            'update_id' => $updateId,
            'message' => [
                'message_id' => $messageId,
                'from' => [
                    'id' => $user->telegram_id,
                    'is_bot' => false,
                    'first_name' => $user->first_name ?? 'Test',
                    'username' => $user->username ?? 'test',
                    'language_code' => 'ru',
                ],
                'chat' => ['id' => $user->telegram_id, 'type' => 'private'],
                'date' => 1716292800,
                'text' => $text,
            ],
        ];
    }

    // -----------------------------------------------------------------
    // 1. Snooze: 10/30/60 минут и "до завтра"
    // -----------------------------------------------------------------

    public function test_snooze_10_minutes_shifts_target_time_and_resets_pending_status(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200)]);
        Carbon::setTestNow(Carbon::create(2026, 6, 1, 10, 0, 0, 'UTC'));

        $user = $this->createUser();
        $reminder = $this->createReminder($user, [
            'status' => Reminder::STATUS_SENT,
            'is_completed' => true,
        ]);

        $this->postJson(route('telegram.webhook'), $this->callbackPayload(1, $user, "snooze_{$reminder->id}_10"), $this->webhookHeaders())
            ->assertStatus(200);

        $reminder->refresh();
        $this->assertSame('2026-06-01 10:10:00', $reminder->target_at->toDateTimeString());
        $this->assertSame(Reminder::STATUS_PENDING, $reminder->status);
        $this->assertFalse($reminder->is_completed);
    }

    public function test_snooze_30_minutes_shifts_target_time(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200)]);
        Carbon::setTestNow(Carbon::create(2026, 6, 1, 10, 0, 0, 'UTC'));

        $user = $this->createUser();
        $reminder = $this->createReminder($user);

        $this->postJson(route('telegram.webhook'), $this->callbackPayload(1, $user, "snooze_{$reminder->id}_30"), $this->webhookHeaders())
            ->assertStatus(200);

        $reminder->refresh();
        $this->assertSame('2026-06-01 10:30:00', $reminder->target_at->toDateTimeString());
        $this->assertSame(Reminder::STATUS_PENDING, $reminder->status);
    }

    public function test_snooze_1_hour_shifts_target_time_and_reactivates_failed_reminder(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200)]);
        Carbon::setTestNow(Carbon::create(2026, 6, 1, 10, 0, 0, 'UTC'));

        $user = $this->createUser();
        // Напоминание уже окончательно провалилось (исчерпаны попытки отправки) — снус
        // должен всё равно перевести его обратно в 'pending', иначе оно никогда не сработает.
        $reminder = $this->createReminder($user, ['status' => Reminder::STATUS_FAILED]);

        $this->postJson(route('telegram.webhook'), $this->callbackPayload(1, $user, "snooze_{$reminder->id}_60"), $this->webhookHeaders())
            ->assertStatus(200);

        $reminder->refresh();
        $this->assertSame('2026-06-01 11:00:00', $reminder->target_at->toDateTimeString());
        $this->assertSame(Reminder::STATUS_PENDING, $reminder->status);
    }

    public function test_snooze_until_tomorrow_preserves_local_wall_clock_time_across_dst_transition(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200)]);

        // 2026-03-29 — переход на летнее время в Европе/Берлине (02:00 CET -> 03:00 CEST).
        // "Сейчас" — 2026-03-28 09:00 по Берлину (CET, UTC+1) = 08:00 UTC.
        Carbon::setTestNow(Carbon::create(2026, 3, 28, 9, 0, 0, 'Europe/Berlin'));

        $user = $this->createUser(['timezone' => 'Europe/Berlin']);
        // Уже было отправлено и полностью завершено ранее — снус "до завтра" должен
        // всё равно вернуть его в работу с новым (будущим) временем.
        $reminder = $this->createReminder($user, [
            'status' => Reminder::STATUS_SENT,
            'is_completed' => true,
        ]);

        $this->postJson(route('telegram.webhook'), $this->callbackPayload(1, $user, "snooze_{$reminder->id}_tomorrow"), $this->webhookHeaders())
            ->assertStatus(200);

        $reminder->refresh();

        // Корректно: 09:00 по Берлину на следующий день — уже CEST (UTC+2) => 07:00 UTC.
        $this->assertSame('2026-03-29 07:00:00', $reminder->target_at->copy()->setTimezone('UTC')->toDateTimeString());
        $this->assertSame('09:00', $reminder->target_at->copy()->setTimezone('Europe/Berlin')->format('H:i'));
        // Наивное прибавление 24 часов в UTC (неверное поведение) дало бы 08:00 UTC.
        $this->assertNotSame('2026-03-29 08:00:00', $reminder->target_at->copy()->setTimezone('UTC')->toDateTimeString());

        $this->assertSame(Reminder::STATUS_PENDING, $reminder->status);
        $this->assertFalse($reminder->is_completed);
    }

    public function test_snooze_ignores_reminder_belonging_to_another_user(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200)]);
        Carbon::setTestNow(Carbon::create(2026, 6, 1, 10, 0, 0, 'UTC'));

        $owner = $this->createUser();
        $attacker = $this->createUser();
        $reminder = $this->createReminder($owner, ['status' => Reminder::STATUS_PENDING]);
        $originalTargetAt = $reminder->target_at->toDateTimeString();

        $this->postJson(route('telegram.webhook'), $this->callbackPayload(1, $attacker, "snooze_{$reminder->id}_10"), $this->webhookHeaders())
            ->assertStatus(200);

        $reminder->refresh();
        $this->assertSame($originalTargetAt, $reminder->target_at->toDateTimeString());
    }

    // -----------------------------------------------------------------
    // 2. Редактирование текста, времени и повторения
    // -----------------------------------------------------------------

    public function test_edit_button_shows_field_selection_menu(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200)]);

        $user = $this->createUser();
        $reminder = $this->createReminder($user);

        $this->postJson(route('telegram.webhook'), $this->callbackPayload(1, $user, "edit_{$reminder->id}"), $this->webhookHeaders())
            ->assertStatus(200);

        Http::assertSent(function ($request) use ($user, $reminder) {
            return str_contains($request->url(), 'editMessageText')
                && $request['chat_id'] === $user->telegram_id
                && str_contains($request['text'], 'Что изменить')
                && str_contains($request['reply_markup'], "editfield_{$reminder->id}_text")
                && str_contains($request['reply_markup'], "editfield_{$reminder->id}_time")
                && str_contains($request['reply_markup'], "editfield_{$reminder->id}_recurrence");
        });
    }

    public function test_editfield_text_sets_fsm_state_and_prompts_for_new_text(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200)]);

        $user = $this->createUser();
        $reminder = $this->createReminder($user);

        $this->postJson(route('telegram.webhook'), $this->callbackPayload(1, $user, "editfield_{$reminder->id}_text"), $this->webhookHeaders())
            ->assertStatus(200);

        $user->refresh();
        $this->assertSame('edit_text', $user->state);
        $this->assertSame($reminder->id, $user->state_data['reminder_id']);
    }

    public function test_sending_new_text_while_editing_updates_reminder_and_clears_state(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200)]);

        $user = $this->createUser();
        $reminder = $this->createReminder($user, ['text' => 'Старый текст']);
        $user->update(['state' => 'edit_text', 'state_data' => ['reminder_id' => $reminder->id]]);

        $this->postJson(route('telegram.webhook'), $this->messagePayload(1, $user, 'Новый текст напоминания'), $this->webhookHeaders())
            ->assertStatus(200);

        $reminder->refresh();
        $user->refresh();
        $this->assertSame('Новый текст напоминания', $reminder->text);
        $this->assertNull($user->state);
        $this->assertNull($user->state_data);
    }

    public function test_editing_text_with_blank_message_asks_again_and_keeps_state(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200)]);

        $user = $this->createUser();
        $reminder = $this->createReminder($user, ['text' => 'Старый текст']);
        $user->update(['state' => 'edit_text', 'state_data' => ['reminder_id' => $reminder->id]]);

        $this->postJson(route('telegram.webhook'), $this->messagePayload(1, $user, '    '), $this->webhookHeaders())
            ->assertStatus(200);

        $reminder->refresh();
        $user->refresh();
        $this->assertSame('Старый текст', $reminder->text);
        // Состояние не сбрасывается — пользователь может повторить попытку.
        $this->assertSame('edit_text', $user->state);
    }

    public function test_editfield_time_sets_fsm_state_and_prompts_for_new_time(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200)]);

        $user = $this->createUser();
        $reminder = $this->createReminder($user);

        $this->postJson(route('telegram.webhook'), $this->callbackPayload(1, $user, "editfield_{$reminder->id}_time"), $this->webhookHeaders())
            ->assertStatus(200);

        $user->refresh();
        $this->assertSame('edit_time', $user->state);
        $this->assertSame($reminder->id, $user->state_data['reminder_id']);
    }

    public function test_sending_new_time_while_editing_updates_target_at_and_keeps_recurrence(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200)]);
        Carbon::setTestNow(Carbon::create(2026, 6, 1, 8, 0, 0, 'UTC'));

        $user = $this->createUser(['timezone' => 'UTC']);
        $reminder = $this->createReminder($user, [
            'recurrence_type' => 'daily',
            'target_at' => Carbon::create(2026, 6, 1, 9, 0, 0, 'UTC'),
        ]);
        $user->update(['state' => 'edit_time', 'state_data' => ['reminder_id' => $reminder->id]]);

        $this->postJson(route('telegram.webhook'), $this->messagePayload(1, $user, 'завтра в 18:00'), $this->webhookHeaders())
            ->assertStatus(200);

        $reminder->refresh();
        $user->refresh();
        $this->assertSame('2026-06-02 18:00:00', $reminder->target_at->toDateTimeString());
        // Тип повторения при редактировании времени намеренно не трогается.
        $this->assertSame('daily', $reminder->recurrence_type);
        $this->assertNull($user->state);
    }

    public function test_sending_unparseable_time_while_editing_asks_again_and_keeps_state(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200)]);

        $user = $this->createUser();
        $originalTargetAt = Carbon::create(2026, 6, 1, 9, 0, 0, 'UTC');
        $reminder = $this->createReminder($user, ['target_at' => $originalTargetAt]);
        $user->update(['state' => 'edit_time', 'state_data' => ['reminder_id' => $reminder->id]]);

        $this->postJson(route('telegram.webhook'), $this->messagePayload(1, $user, 'купить хлеб'), $this->webhookHeaders())
            ->assertStatus(200);

        $reminder->refresh();
        $user->refresh();
        $this->assertSame($originalTargetAt->toDateTimeString(), $reminder->target_at->toDateTimeString());
        $this->assertSame('edit_time', $user->state);

        Http::assertSent(function ($request) use ($user) {
            return str_contains($request->url(), 'sendMessage')
                && $request['chat_id'] === $user->telegram_id
                && str_contains($request['text'], 'Не удалось точно определить');
        });
    }

    public function test_editfield_recurrence_shows_fixed_type_buttons(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200)]);

        $user = $this->createUser();
        $reminder = $this->createReminder($user);

        $this->postJson(route('telegram.webhook'), $this->callbackPayload(1, $user, "editfield_{$reminder->id}_recurrence"), $this->webhookHeaders())
            ->assertStatus(200);

        Http::assertSent(function ($request) use ($reminder) {
            return str_contains($request->url(), 'editMessageText')
                && str_contains($request['reply_markup'], "editrecurrence_{$reminder->id}_once")
                && str_contains($request['reply_markup'], "editrecurrence_{$reminder->id}_daily")
                && str_contains($request['reply_markup'], "editrecurrence_{$reminder->id}_weekly")
                && str_contains($request['reply_markup'], "editrecurrence_{$reminder->id}_monthly")
                && str_contains($request['reply_markup'], "editrecurrence_{$reminder->id}_workdays");
        });
    }

    public function test_editrecurrence_updates_recurrence_type_to_weekly_and_derives_day_of_week(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200)]);

        $user = $this->createUser(['timezone' => 'UTC']);
        // 2026-06-03 — среда (ISO день недели 3).
        $reminder = $this->createReminder($user, [
            'recurrence_type' => 'once',
            'target_at' => Carbon::create(2026, 6, 3, 9, 0, 0, 'UTC'),
        ]);

        $this->postJson(route('telegram.webhook'), $this->callbackPayload(1, $user, "editrecurrence_{$reminder->id}_weekly"), $this->webhookHeaders())
            ->assertStatus(200);

        $reminder->refresh();
        $this->assertSame('weekly', $reminder->recurrence_type);
        $this->assertSame('3', $reminder->recurrence_value);
    }

    public function test_edit_reactivates_sent_reminder_when_new_time_is_in_future(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200)]);
        Carbon::setTestNow(Carbon::create(2026, 6, 1, 8, 0, 0, 'UTC'));

        $user = $this->createUser(['timezone' => 'UTC']);
        // Одноразовое напоминание уже было отправлено и полностью завершено.
        $reminder = $this->createReminder($user, [
            'status' => Reminder::STATUS_SENT,
            'is_completed' => true,
            'target_at' => Carbon::create(2026, 5, 1, 9, 0, 0, 'UTC'),
        ]);
        $user->update(['state' => 'edit_time', 'state_data' => ['reminder_id' => $reminder->id]]);

        $this->postJson(route('telegram.webhook'), $this->messagePayload(1, $user, 'завтра в 10:00'), $this->webhookHeaders())
            ->assertStatus(200);

        $reminder->refresh();
        $this->assertSame(Reminder::STATUS_PENDING, $reminder->status);
        $this->assertFalse($reminder->is_completed);
        $this->assertTrue($reminder->target_at->isFuture());
    }

    // -----------------------------------------------------------------
    // 3. Пагинация списка
    // -----------------------------------------------------------------

    /**
     * Создаёт $count напоминаний с возрастающим target_at, чтобы порядок в списке был предсказуем.
     */
    private function createManyReminders(User $user, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->createReminder($user, [
                'text' => 'Напоминание №'.($i + 1),
                'target_at' => Carbon::create(2026, 6, 1, 9, 0, 0, 'UTC')->addMinutes($i),
            ]);
        }
    }

    public function test_list_command_shows_first_page_with_only_next_button(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200)]);

        $user = $this->createUser();
        $this->createManyReminders($user, 15);

        $this->postJson(route('telegram.webhook'), $this->messagePayload(1, $user, '/list'), $this->webhookHeaders())
            ->assertStatus(200);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'sendMessage')) {
                return false;
            }

            return str_contains($request['text'], 'стр. 1/2')
                && str_contains($request['text'], 'Напоминание №1')
                && ! str_contains($request['text'], 'Напоминание №11')
                && str_contains($request['reply_markup'], 'listpage_2')
                && ! str_contains($request['reply_markup'], 'listpage_0');
        });
    }

    public function test_listpage_2_edits_existing_message_with_remaining_items_and_prev_button_only(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200)]);

        $user = $this->createUser();
        $this->createManyReminders($user, 15);

        $this->postJson(route('telegram.webhook'), $this->callbackPayload(1, $user, 'listpage_2', 42), $this->webhookHeaders())
            ->assertStatus(200);

        Http::assertSent(function ($request) use ($user) {
            if (! str_contains($request->url(), 'editMessageText')) {
                return false;
            }

            return $request['chat_id'] === $user->telegram_id
                && $request['message_id'] === 42
                && str_contains($request['text'], 'стр. 2/2')
                && str_contains($request['text'], 'Напоминание №11')
                // Напоминание №5 относится к первой странице и не должно попасть на вторую.
                && ! str_contains($request['text'], 'Напоминание №5'."\n")
                && str_contains($request['reply_markup'], 'listpage_1')
                && ! str_contains($request['reply_markup'], 'listpage_3');
        });
    }

    public function test_list_with_single_page_shows_no_pagination_buttons(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200)]);

        $user = $this->createUser();
        $this->createManyReminders($user, 3);

        $this->postJson(route('telegram.webhook'), $this->messagePayload(1, $user, '/list'), $this->webhookHeaders())
            ->assertStatus(200);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'sendMessage')
                && str_contains($request['text'], 'стр. 1/1')
                && ! str_contains($request['reply_markup'], 'listpage_');
        });
    }

    // -----------------------------------------------------------------
    // 4. Семантика "Выполнено" для повторяющихся напоминаний
    // -----------------------------------------------------------------

    public function test_complete_once_reminder_permanently_completes_it(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200)]);

        $user = $this->createUser();
        $reminder = $this->createReminder($user, ['recurrence_type' => 'once']);

        $this->postJson(route('telegram.webhook'), $this->callbackPayload(1, $user, "complete_{$reminder->id}"), $this->webhookHeaders())
            ->assertStatus(200);

        $reminder->refresh();
        $this->assertTrue($reminder->is_completed);
        $this->assertNotNull($reminder->completed_at);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'editMessageText')
                && str_contains($request['text'], 'Напоминание выполнено');
        });
    }

    public function test_complete_daily_reminder_advances_to_next_occurrence_and_stays_active(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200)]);
        Carbon::setTestNow(Carbon::create(2026, 6, 1, 9, 0, 1, 'UTC'));

        $user = $this->createUser(['timezone' => 'UTC']);
        $reminder = $this->createReminder($user, [
            'recurrence_type' => 'daily',
            'target_at' => Carbon::create(2026, 6, 1, 9, 0, 0, 'UTC'),
            'status' => Reminder::STATUS_PENDING,
        ]);

        $this->postJson(route('telegram.webhook'), $this->callbackPayload(1, $user, "complete_{$reminder->id}"), $this->webhookHeaders())
            ->assertStatus(200);

        $reminder->refresh();
        $this->assertFalse($reminder->is_completed);
        $this->assertSame(Reminder::STATUS_PENDING, $reminder->status);
        $this->assertSame('2026-06-02 09:00:00', $reminder->target_at->toDateTimeString());

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'editMessageText')
                && str_contains($request['text'], 'Следующее напоминание');
        });
    }

    public function test_complete_recurring_reminder_with_unresolvable_next_occurrence_falls_back_to_permanent_completion(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200)]);

        $user = $this->createUser();
        // Некорректный/непризнанный тип повторения — calculateNextOccurrence() вернёт null
        // (сработает ветка default в switch), что моделирует "исчерпанное или некорректно
        // настроенное" повторение из задания.
        $reminder = $this->createReminder($user, ['recurrence_type' => 'unsupported_type']);

        $this->postJson(route('telegram.webhook'), $this->callbackPayload(1, $user, "complete_{$reminder->id}"), $this->webhookHeaders())
            ->assertStatus(200);

        $reminder->refresh();
        $this->assertTrue($reminder->is_completed);
        $this->assertNotNull($reminder->completed_at);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'editMessageText')
                && str_contains($request['text'], 'Напоминание выполнено');
        });
    }
}
