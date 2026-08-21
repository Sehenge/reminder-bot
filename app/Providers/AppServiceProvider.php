<?php

namespace App\Providers;

use App\Contracts\ReminderParserFallback;
use App\Models\Reminder;
use App\Observers\ReminderObserver;
use App\Services\NullReminderParserFallback;
use App\Services\TokenatorReminderParserFallback;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(
            ReminderParserFallback::class,
            config('services.reminder_parser.ai_fallback', false)
                ? TokenatorReminderParserFallback::class
                : NullReminderParserFallback::class,
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Reminder::observe(ReminderObserver::class);
    }
}
