<?php

namespace Tests\Feature;

use App\Models\Reminder;
use App\Models\User;
use App\Models\UserActivityEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalyticsDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_is_hidden_when_credentials_are_not_configured(): void
    {
        config()->set('analytics.username', '');
        config()->set('analytics.password', '');

        $this->get(route('analytics.dashboard'))->assertNotFound();
    }

    public function test_dashboard_requires_authentication_and_shows_activity(): void
    {
        config()->set('analytics.username', 'owner');
        config()->set('analytics.password', 'secret');

        $user = User::query()->create([
            'telegram_id' => 123456789,
            'username' => 'dashboard_test',
            'first_name' => 'Test',
            'timezone' => 'Europe/Moscow',
            'acquisition_source' => 'telegram_august',
        ]);
        UserActivityEvent::query()->create([
            'user_id' => $user->id,
            'event_type' => 'command',
            'event_name' => '/start',
            'source' => 'telegram_august',
        ]);
        Reminder::query()->create([
            'user_id' => $user->id,
            'text' => 'Проверить конверсию',
            'target_at' => now()->addDay(),
        ]);

        $this->get(route('analytics.dashboard'))->assertUnauthorized();
        $this->withBasicAuth('owner', 'secret')->get(route('analytics.dashboard'))
            ->assertOk()
            ->assertSee('Рост и активность за 7 дней')
            ->assertSee('Типы активности за 7 дней')
            ->assertSee('Источники пользователей')
            ->assertSee('Воронка использования')
            ->assertSee('Конверсия источников')
            ->assertSee('100%')
            ->assertSee('/start')
            ->assertSee('telegram_august');
    }
}
