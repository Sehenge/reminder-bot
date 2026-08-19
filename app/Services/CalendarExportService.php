<?php

namespace App\Services;

use App\Enums\PremiumFeature;
use App\Models\User;
use Illuminate\Support\Str;

final readonly class CalendarExportService
{
    public function __construct(private PremiumGate $gate) {}

    public function token(User $user, bool $rotate = false): string
    {
        $this->gate->authorize($user, PremiumFeature::CalendarExport);
        if ($rotate || $user->calendar_token === null) {
            $user->update(['calendar_token' => Str::random(48)]);
        }

        return (string) $user->refresh()->calendar_token;
    }

    public function render(User $user): string
    {
        $this->gate->authorize($user, PremiumFeature::CalendarExport);
        $reminders = $user->reminders()->where('is_completed', false)->orderBy('target_at')->get();
        $lines = [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//ReminderBot//Calendar//RU',
            'CALSCALE:GREGORIAN', 'METHOD:PUBLISH', 'X-WR-CALNAME:ReminderBot',
        ];

        foreach ($reminders as $reminder) {
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:reminder-'.$reminder->id.'@reminderbot';
            $lines[] = 'DTSTAMP:'.$reminder->updated_at->utc()->format('Ymd\THis\Z');
            $lines[] = 'DTSTART:'.$reminder->target_at->utc()->format('Ymd\THis\Z');
            $lines[] = 'SUMMARY:'.$this->escape($reminder->text);
            $lines[] = 'END:VEVENT';
        }
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", $lines)."\r\n";
    }

    private function escape(string $value): string
    {
        return str_replace(['\\', ';', ',', "\r\n", "\n", "\r"], ['\\\\', '\\;', '\\,', '\\n', '\\n', '\\n'], $value);
    }
}
