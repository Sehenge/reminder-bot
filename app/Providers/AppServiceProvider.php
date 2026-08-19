<?php

namespace App\Providers;

use App\Contracts\ReminderParserFallback;
use App\Services\NullReminderParserFallback;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Безопасный fallback по умолчанию никогда не отправляет пользовательский текст наружу.
        // Реальный AI-адаптер можно подключить явно, после решения по согласию и приватности.
        $this->app->bind(ReminderParserFallback::class, NullReminderParserFallback::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
