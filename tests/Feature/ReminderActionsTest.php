<?php

namespace Tests\Feature;

use App\Actions\Reminders\CompleteReminderAction;
use App\Actions\Reminders\CreateReminderAction;
use App\Actions\Reminders\SnoozeReminderAction;
use App\Events\ReminderCompleted;
use App\Events\ReminderCreated;
use App\Events\ReminderSnoozed;
use App\Models\Reminder;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ReminderActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_action_persists_and_dispatches_domain_event(): void
    {
        Event::fake([ReminderCreated::class]);
        $user = $this->user(101);

        $reminder = app(CreateReminderAction::class)->execute($user, [
            'text' => 'Позвонить маме',
            'target_at' => now()->addHour()->toIso8601String(),
            'recurrence_type' => 'once',
            'recurrence_value' => null,
        ]);

        $this->assertDatabaseHas('reminders', ['id' => $reminder->id, 'text' => 'Позвонить маме']);
        Event::assertDispatched(ReminderCreated::class, fn ($event) => $event->reminder->is($reminder));
    }

    public function test_snooze_action_reactivates_and_dispatches_domain_event(): void
    {
        Event::fake([ReminderSnoozed::class]);
        Carbon::setTestNow('2026-08-19 12:00:00');
        $user = $this->user(102, 'UTC');
        $reminder = Reminder::query()->create([
            'user_id' => $user->id,
            'text' => 'Проверить почту',
            'target_at' => now()->subMinute(),
            'recurrence_type' => 'once',
            'status' => 'failed',
            'is_completed' => true,
        ]);

        app(SnoozeReminderAction::class)->execute($reminder, $user, '30');

        $this->assertSame('pending', $reminder->fresh()->status);
        $this->assertFalse($reminder->fresh()->is_completed);
        Event::assertDispatched(ReminderSnoozed::class);
        Carbon::setTestNow();
    }

    public function test_complete_action_dispatches_domain_event(): void
    {
        Event::fake([ReminderCompleted::class]);
        $user = $this->user(103);
        $reminder = Reminder::query()->create([
            'user_id' => $user->id,
            'text' => 'Позвонить',
            'target_at' => now()->addHour(),
            'recurrence_type' => 'once',
        ]);

        $next = app(CompleteReminderAction::class)->execute($reminder, $user);

        $this->assertNull($next);
        $this->assertTrue($reminder->fresh()->is_completed);
        Event::assertDispatched(ReminderCompleted::class, fn ($event) => $event->permanently);
    }

    private function user(int $telegramId, string $timezone = 'Europe/Moscow'): User
    {
        return User::query()->create([
            'telegram_id' => $telegramId,
            'first_name' => 'Test',
            'timezone' => $timezone,
        ]);
    }
}
