<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\CalendarExportService;
use Illuminate\Http\Response;

final class CalendarFeedController extends Controller
{
    public function __invoke(string $token, CalendarExportService $calendar): Response
    {
        $user = User::query()->where('calendar_token', $token)->firstOrFail();
        abort_unless($user->hasPremium(), 403);

        return response($calendar->render($user), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="reminder-bot.ics"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
