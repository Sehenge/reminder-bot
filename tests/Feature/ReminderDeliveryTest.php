<?php

namespace Tests\Feature;

use App\Jobs\SendReminderNotificationJob;
use App\Models\Reminder;
use App\Models\User;
use App\Services\TelegramService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class ReminderDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
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

    // -----------------------------------------------------------------
    // Атомарное резервирование / отсутствие дублей
    // -----------------------------------------------------------------

    public function test_concurrent_reservation_of_the_same_reminder_succeeds_only_once(): void
    {
        $user = $this->createUser();
        $reminder = $this->createReminder($user);

        // Симулируем два пересекающихся запуска команды, пытающихся
        // одновременно зарезервировать одно и то же напоминание.
        $firstRun = Reminder::query()
            ->where('id', $reminder->id)
            ->where('status', Reminder::STATUS_PENDING)
            ->update(['status' => Reminder::STATUS_DISPATCHING]);

        $secondRun = Reminder::query()
            ->where('id', $reminder->id)
            ->where('status', Reminder::STATUS_PENDING)
            ->update(['status' => Reminder::STATUS_DISPATCHING]);

        $this->assertSame(1, $firstRun, 'Первый запуск должен успешно зарезервировать напоминание.');
        $this->assertSame(0, $secondRun, 'Второй (пересекающийся) запуск не должен ничего зарезервировать.');
    }

    public function test_dispatch_command_does_not_queue_job_for_already_dispatching_reminder(): void
    {
        Queue::fake();

        $user = $this->createUser();
        // Напоминание уже зарезервировано предыдущим (ещё не завершённым) запуском.
        $this->createReminder($user, ['status' => Reminder::STATUS_DISPATCHING]);

        Artisan::call('reminders:dispatch');

        Queue::assertNotPushed(SendReminderNotificationJob::class);
    }

    public function test_dispatch_command_reserves_each_due_reminder_exactly_once_across_overlapping_runs(): void
    {
        Queue::fake();

        $user = $this->createUser();
        $this->createReminder($user);

        // Первый запуск планировщика: резервирует и ставит задачу в очередь.
        Artisan::call('reminders:dispatch');
        Queue::assertPushed(SendReminderNotificationJob::class, 1);

        // Второй, «пересекающийся» запуск до того, как задача была обработана:
        // статус уже 'dispatching', поэтому повторной постановки в очередь быть не должно.
        Artisan::call('reminders:dispatch');
        Queue::assertPushed(SendReminderNotificationJob::class, 1);
    }

    // -----------------------------------------------------------------
    // Job: retry / backoff / timeout / failed()
    // -----------------------------------------------------------------

    public function test_job_declares_retry_backoff_and_timeout(): void
    {
        $user = $this->createUser();
        $reminder = $this->createReminder($user, ['status' => Reminder::STATUS_DISPATCHING]);

        $job = new SendReminderNotificationJob($reminder);

        $this->assertSame(5, $job->tries);
        $this->assertSame(30, $job->timeout);
        $this->assertSame([10, 30, 60, 300, 900], $job->backoff());
    }

    public function test_job_throws_and_keeps_reminder_dispatching_when_telegram_send_fails(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Bad Request'], 400),
        ]);

        $user = $this->createUser();
        $reminder = $this->createReminder($user, ['status' => Reminder::STATUS_DISPATCHING]);

        $job = new SendReminderNotificationJob($reminder);

        $this->expectException(RuntimeException::class);

        try {
            $job->handle(app(TelegramService::class));
        } finally {
            $reminder->refresh();
            // Статус остаётся 'dispatching' — задача ещё будет повторена согласно $tries/backoff().
            $this->assertSame(Reminder::STATUS_DISPATCHING, $reminder->status);
            $this->assertNull($reminder->telegram_message_id);
            $this->assertNull($reminder->sent_at);
        }
    }

    public function test_job_failed_hook_marks_reminder_as_failed_and_logs(): void
    {
        Log::spy();

        $user = $this->createUser();
        $reminder = $this->createReminder($user, ['status' => Reminder::STATUS_DISPATCHING]);

        $job = new SendReminderNotificationJob($reminder);
        $job->failed(new RuntimeException('Telegram unreachable'));

        $reminder->refresh();
        $this->assertSame(Reminder::STATUS_FAILED, $reminder->status);

        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message) => str_contains($message, 'permanently failed') && str_contains($message, (string) $reminder->id))
            ->once();
    }

    public function test_job_does_not_resend_reminder_that_is_no_longer_dispatching(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 555]], 200),
        ]);

        $user = $this->createUser();
        // Уже было отправлено другой попыткой/потоком.
        $reminder = $this->createReminder($user, ['status' => Reminder::STATUS_SENT]);

        $job = new SendReminderNotificationJob($reminder);
        $job->handle(app(TelegramService::class));

        Http::assertNothingSent();
    }

    // -----------------------------------------------------------------
    // Сохранение telegram_message_id и sent_at при успешной отправке
    // -----------------------------------------------------------------

    public function test_successful_send_persists_message_id_and_sent_at_for_one_off_reminder(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 4242]], 200),
        ]);

        Carbon::setTestNow(Carbon::create(2026, 6, 1, 10, 0, 0, 'UTC'));

        $user = $this->createUser();
        $reminder = $this->createReminder($user, [
            'status' => Reminder::STATUS_DISPATCHING,
            'recurrence_type' => 'once',
        ]);

        $job = new SendReminderNotificationJob($reminder);
        $job->handle(app(TelegramService::class));

        $reminder->refresh();

        $this->assertSame(4242, $reminder->telegram_message_id);
        $this->assertNotNull($reminder->sent_at);
        $this->assertTrue($reminder->sent_at->equalTo(Carbon::now()));
        $this->assertSame(Reminder::STATUS_SENT, $reminder->status);
        $this->assertTrue($reminder->is_completed);
    }

    public function test_successful_send_resets_recurring_reminder_to_pending_with_next_occurrence(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 777]], 200),
        ]);

        Carbon::setTestNow(Carbon::create(2026, 6, 1, 9, 0, 1, 'UTC'));

        $user = $this->createUser(['timezone' => 'UTC']);
        $reminder = $this->createReminder($user, [
            'status' => Reminder::STATUS_DISPATCHING,
            'recurrence_type' => 'daily',
            'target_at' => Carbon::create(2026, 6, 1, 9, 0, 0, 'UTC'),
        ]);

        $job = new SendReminderNotificationJob($reminder);
        $job->handle(app(TelegramService::class));

        $reminder->refresh();

        $this->assertSame(777, $reminder->telegram_message_id);
        $this->assertNotNull($reminder->sent_at);
        $this->assertSame(Reminder::STATUS_PENDING, $reminder->status);
        $this->assertFalse($reminder->is_completed);
        $this->assertSame('2026-06-02 09:00:00', $reminder->target_at->toDateTimeString());
    }

    // -----------------------------------------------------------------
    // Расчёт повторений: DST и "догоняющие" пропущенные запуски
    // -----------------------------------------------------------------

    public function test_recurrence_preserves_local_wall_clock_time_across_dst_transition(): void
    {
        // 2026-03-29 — переход на летнее время в Европе/Берлине (02:00 CET -> 03:00 CEST).
        // Исходное напоминание: 2026-03-28 09:00 по Берлину (CET, UTC+1) = 08:00 UTC.
        $reminder = new Reminder([
            'recurrence_type' => 'daily',
            'target_at' => Carbon::create(2026, 3, 28, 8, 0, 0, 'UTC'),
        ]);

        Carbon::setTestNow(Carbon::create(2026, 3, 28, 8, 0, 1, 'UTC'));

        $next = $reminder->calculateNextOccurrence('Europe/Berlin');

        $this->assertNotNull($next);
        // Корректно: 09:00 по Берлину на следующий день — уже CEST (UTC+2) => 07:00 UTC.
        $this->assertSame('2026-03-29 07:00:00', $next->toDateTimeString());
        // Подтверждаем, что местное время суток не сдвинулось из-за DST.
        $this->assertSame('09:00', $next->clone()->setTimezone('Europe/Berlin')->format('H:i'));

        // Наивное прибавление суток в UTC (старое поведение) дало бы 08:00 UTC,
        // что соответствовало бы уже 10:00 по местному времени — на час позже нужного.
        $this->assertNotSame('2026-03-29 08:00:00', $next->toDateTimeString());
    }

    public function test_recurrence_catches_up_missed_daily_occurrences_by_jumping_to_next_future_slot(): void
    {
        // Напоминание должно было срабатывать ежедневно в 09:00 UTC, но scheduler
        // не запускался несколько дней подряд.
        $reminder = new Reminder([
            'recurrence_type' => 'daily',
            'target_at' => Carbon::create(2026, 3, 1, 9, 0, 0, 'UTC'),
        ]);

        // "Сейчас" — на 5 дней позже исходного срока и уже после 09:00 в этот день.
        Carbon::setTestNow(Carbon::create(2026, 3, 6, 15, 0, 0, 'UTC'));

        $next = $reminder->calculateNextOccurrence('UTC');

        $this->assertNotNull($next);
        // Не "накопленные" 5 отдельных occurrences, а один прыжок к ближайшему будущему сроку.
        $this->assertSame('2026-03-07 09:00:00', $next->toDateTimeString());
        $this->assertTrue($next->isFuture());
    }

    public function test_recurrence_returns_null_for_once_reminders(): void
    {
        $reminder = new Reminder([
            'recurrence_type' => 'once',
            'target_at' => Carbon::now('UTC')->subDay(),
        ]);

        $this->assertNull($reminder->calculateNextOccurrence('UTC'));
    }
}
