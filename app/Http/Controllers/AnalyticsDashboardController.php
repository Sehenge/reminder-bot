<?php

namespace App\Http\Controllers;

use App\Models\Reminder;
use App\Models\User;
use App\Models\UserActivityEvent;
use Illuminate\Http\Request;
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
            ];
        });

        return response()->view('analytics.dashboard', [
            'generatedAt' => $now,
            'totals' => [
                'users' => User::query()->count(),
                'newToday' => User::query()->where('created_at', '>=', $today)->count(),
                'activeToday' => UserActivityEvent::query()->where('created_at', '>=', $today)->distinct()->count('user_id'),
                'reminders' => Reminder::query()->count(),
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
            'sources' => User::query()->selectRaw("coalesce(acquisition_source, 'без метки') as source, count(*) as users")
                ->groupBy('acquisition_source')->orderByDesc('users')->get(),
            'recent' => UserActivityEvent::query()->with('user:id,telegram_id,username,first_name,last_name')
                ->latest()->limit(100)->get(),
        ])->header('Cache-Control', 'no-store, private');
    }
}
