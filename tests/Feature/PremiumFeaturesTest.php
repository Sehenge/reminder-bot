<?php

namespace Tests\Feature;

use App\Enums\SharedListRole;
use App\Models\Reminder;
use App\Models\ReminderHistory;
use App\Models\User;
use App\Services\CalendarExportService;
use App\Services\CategoryService;
use App\Services\ReminderHistoryService;
use App\Services\SharedListService;
use App\Services\TagService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class PremiumFeaturesTest extends TestCase
{
    use RefreshDatabase;

    public function test_free_user_cannot_use_premium_feature_services(): void
    {
        $user = $this->user(false);

        $this->expectException(AuthorizationException::class);
        app(CategoryService::class)->create($user, 'Работа');
    }

    public function test_categories_have_crud_and_are_isolated_by_owner(): void
    {
        $owner = $this->user();
        $other = $this->user(telegramId: 800002);
        $service = app(CategoryService::class);
        $category = $service->create($owner, 'Работа', '#ABCDEF');

        $this->assertSame('#abcdef', $category->color);
        $this->assertCount(1, $service->list($owner));
        $this->assertSame('Личное', $service->update($owner, $category, 'Личное', '#123456')->name);

        $this->expectException(AuthorizationException::class);
        $service->delete($other, $category);
    }

    public function test_tags_have_crud_and_only_owned_tags_can_be_attached(): void
    {
        $owner = $this->user();
        $other = $this->user(telegramId: 800003);
        $reminder = $this->reminder($owner);
        $service = app(TagService::class);
        $tag = $service->create($owner, 'Важно');
        $foreign = $service->create($other, 'Чужой');

        $service->syncReminder($owner, $reminder, [$tag->id]);
        $this->assertTrue($reminder->tags()->whereKey($tag->id)->exists());

        $this->expectException(AuthorizationException::class);
        $service->syncReminder($owner, $reminder, [$foreign->id]);
    }

    public function test_reminder_changes_create_immutable_history_and_old_records_are_pruned(): void
    {
        CarbonImmutable::setTestNow('2026-08-19 12:00:00');
        $user = $this->user();
        $reminder = $this->reminder($user);
        $reminder->update(['text' => 'Новый текст']);
        $history = app(ReminderHistoryService::class)->recent($user);

        $this->assertSame(['updated', 'created'], $history->pluck('event_type')->all());
        $this->expectException(LogicException::class);
        $history->first()->delete();
    }

    public function test_history_retention_command_removes_only_records_older_than_six_months(): void
    {
        CarbonImmutable::setTestNow('2026-08-19 12:00:00');
        $user = $this->user();
        $reminder = $this->reminder($user);
        $oldId = ReminderHistory::query()->where('reminder_id', $reminder->id)->value('id');
        DB::table('reminder_history')->where('id', $oldId)->update(['created_at' => now()->subMonthsNoOverflow(6)->subSecond()]);
        $reminder->update(['text' => 'Свежая запись']);

        $this->artisan('reminders:prune-history')->assertSuccessful();

        $this->assertDatabaseMissing('reminder_history', ['id' => $oldId]);
        $this->assertDatabaseCount('reminder_history', 1);
    }

    public function test_shared_list_invitation_roles_and_reminder_access_are_enforced(): void
    {
        $owner = $this->user();
        $editor = $this->user(telegramId: 800004);
        $viewer = $this->user(telegramId: 800005);
        $service = app(SharedListService::class);
        $list = $service->create($owner, 'Семья');
        $service->invite($owner, $list, $editor, SharedListRole::Editor);
        $service->invite($owner, $list, $viewer, SharedListRole::Viewer);
        $service->accept($editor, $list);
        $service->accept($viewer, $list);
        $editorReminder = $this->reminder($editor);

        $service->attachReminder($editor, $list, $editorReminder);
        $this->assertSame($list->id, $editorReminder->refresh()->shared_list_id);
        $this->assertCount(1, $service->accessible($viewer));

        $this->expectException(AuthorizationException::class);
        $service->attachReminder($viewer, $list, $this->reminder($viewer));
    }

    public function test_member_cannot_attach_another_users_private_reminder(): void
    {
        $owner = $this->user();
        $editor = $this->user(telegramId: 800006);
        $service = app(SharedListService::class);
        $list = $service->create($owner, 'Команда');
        $service->invite($owner, $list, $editor, SharedListRole::Editor);
        $service->accept($editor, $list);

        $this->expectException(AuthorizationException::class);
        $service->attachReminder($editor, $list, $this->reminder($owner));
    }

    public function test_shared_list_can_target_a_group_chat(): void
    {
        $owner = $this->user();
        $service = app(SharedListService::class);
        $list = $service->create($owner, 'Группа');
        $service->setTelegramChat($owner, $list, -1001234567890);

        $this->assertSame('-1001234567890', $list->refresh()->telegram_chat_id);
    }

    public function test_calendar_feed_contains_only_active_reminders_and_token_can_be_rotated(): void
    {
        $user = $this->user();
        $active = $this->reminder($user, ['text' => 'Позвонить, маме']);
        $this->reminder($user, ['text' => 'Готово', 'is_completed' => true]);
        $calendar = app(CalendarExportService::class);
        $oldToken = $calendar->token($user);

        $response = $this->get(route('calendar.feed', ['token' => $oldToken]));
        $response->assertOk()->assertHeader('content-type', 'text/calendar; charset=utf-8');
        $response->assertSee('UID:reminder-'.$active->id.'@reminderbot', false);
        $response->assertSee('SUMMARY:Позвонить\, маме', false);
        $response->assertDontSee('Готово', false);

        $newToken = $calendar->token($user, true);
        $this->assertNotSame($oldToken, $newToken);
        $this->get("/calendar/{$oldToken}.ics")->assertNotFound();
    }

    public function test_expired_premium_calendar_token_is_forbidden(): void
    {
        $user = $this->user();
        $token = app(CalendarExportService::class)->token($user);
        $user->update(['premium_expires_at' => now()->subMinute()]);

        $this->get("/calendar/{$token}.ics")->assertForbidden();
    }

    /** @param array<string, mixed> $attributes */
    private function reminder(User $user, array $attributes = []): Reminder
    {
        return Reminder::query()->create(array_merge([
            'user_id' => $user->id,
            'text' => 'Тестовое напоминание',
            'target_at' => now()->addDay(),
            'recurrence_type' => 'once',
            'is_completed' => false,
        ], $attributes));
    }

    private function user(bool $premium = true, int $telegramId = 800001): User
    {
        return User::query()->create([
            'telegram_id' => $telegramId,
            'first_name' => 'Premium Feature Test',
            'timezone' => 'Europe/Moscow',
            'is_premium' => $premium,
            'premium_expires_at' => $premium ? now()->addMonth() : null,
        ]);
    }
}
