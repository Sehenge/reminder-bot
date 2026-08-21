<?php

namespace App\Http\Controllers;

use App\Models\Reminder;
use App\Models\User;
use App\Models\UserActivityEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class AnalyticsDashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $username = (string) config('analytics.username');
        $password = (string) config('analytics.password');

        if ($username === '' || $password === '') {
            abort(404);
        }

        if (! hash_equals($username, (string) $request->getUser())
            || ! hash_equals($password, (string) $request->getPassword())) {
            return response('Authentication required.', 401, [
                'WWW-Authenticate' => 'Basic realm="ReminderBot analytics", charset="UTF-8"',
            ]);
        }

        $now = now();
        $today = $now->copy()->startOfDay();
        $week = $now->copy()->subDays(6)->startOfDay();

        $daily = collect(range(0, 6))->map(function (int $offset) use ($week) {
            $date = $week->copy()->addDays($offset);

            return [
                'label' => $date->format('d.m'),
                'users' => User::query()->whereDate('created_at', $date)->count(),
                'active' => UserActivityEvent::query()->whereDate('created_at', $date)->distinct()->count('user_id'),
                'events' => UserActivityEvent::query()->whereDate('created_at', $date)->count(),
                'reminders' => Reminder::query()->whereDate('created_at', $date)->count(),
            ];
        });

        $users = User::query()->count();
        $engaged = User::query()->whereHas('activityEvents', fn ($query) => $query
            ->where(fn ($events) => $events->where('event_type', '!=', 'command')->orWhere('event_name', '!=', '/start')))->count();
        $activated = User::query()->has('reminders')->count();
        $repeatUsers = User::query()->has('reminders', '>=', 2)->count();
        $returned = User::query()->whereHas('activityEvents', function ($query): void {
            $createdAfterDay = DB::connection()->getDriverName() === 'sqlite'
                ? "datetime(users.created_at, '+1 day')"
                : 'date_add(users.created_at, interval 1 day)';

            $query->whereRaw("user_activity_events.created_at >= {$createdAfterDay}");
        })->count();

        $sources = User::query()
            ->leftJoin('reminders', 'reminders.user_id', '=', 'users.id')
            ->selectRaw("coalesce(users.acquisition_source, 'без метки') as source")
            ->selectRaw('count(distinct users.id) as users')
            ->selectRaw('count(distinct case when reminders.id is not null then users.id end) as activated')
            ->groupBy('users.acquisition_source')
            ->orderByDesc('users')
            ->get();

        return response()->view('analytics.dashboard', [
            'generatedAt' => $now,
            'totals' => [
                'users' => $users,
                'newToday' => User::query()->where('created_at', '>=', $today)->count(),
                'activeToday' => UserActivityEvent::query()->where('created_at', '>=', $today)->distinct()->count('user_id'),
                'reminders' => Reminder::query()->count(),
                'activationRate' => $users > 0 ? round($activated / $users * 100, 1) : 0,
                'returnRate' => $users > 0 ? round($returned / $users * 100, 1) : 0,
            ],
            'funnel' => [
                ['label' => 'Запустили бота', 'value' => $users],
                ['label' => 'Сделали действие', 'value' => $engaged],
                ['label' => 'Создали напоминание', 'value' => $activated],
                ['label' => 'Создали 2+ напоминания', 'value' => $repeatUsers],
                ['label' => 'Вернулись спустя сутки', 'value' => $returned],
            ],
            'daily' => $daily,
            'commands' => UserActivityEvent::query()->where('event_type', 'command')->where('created_at', '>=', $week)
                ->selectRaw('event_name, count(*) as total, count(distinct user_id) as users')
                ->groupBy('event_name')->orderByDesc('total')->get(),
            'callbacks' => UserActivityEvent::query()->where('event_type', 'callback')->where('created_at', '>=', $week)
                ->selectRaw('event_name, count(*) as total, count(distinct user_id) as users')
                ->groupBy('event_name')->orderByDesc('total')->limit(30)->get(),
            'activityTypes' => UserActivityEvent::query()->where('created_at', '>=', $week)
                ->selectRaw('event_type, count(*) as total')
                ->groupBy('event_type')->orderByDesc('total')->get(),
            'sources' => $sources,
            'recent' => UserActivityEvent::query()->with('user:id,telegram_id,username,first_name,last_name')
                ->latest()->limit(100)->get(),
        ])->header('Cache-Control', 'no-store, private');
    }
}
