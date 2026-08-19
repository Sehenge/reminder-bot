<?php

namespace App\Actions\Reminders;

use App\Enums\DeliveryStatus;
use App\Events\ReminderSnoozed;
use App\Models\Reminder;
use App\Models\User;
use Carbon\Carbon;

final class SnoozeReminderAction
{
    /** @return array{time: Carbon, label: string} */
    public function execute(Reminder $reminder, User $user, string $unit): array
    {
        if ($unit === 'tomorrow') {
            $time = Carbon::now($user->timezone)->addDay()->setTimezone('UTC');
            $label = 'до завтра';
        } else {
            $minutes = max(1, (int) $unit);
            $time = Carbon::now()->addMinutes($minutes);
            $label = "на {$minutes} мин";
        }

        $reminder->update([
            'target_at' => $time,
            'is_completed' => false,
            'completed_at' => null,
            'status' => DeliveryStatus::Pending->value,
        ]);
        ReminderSnoozed::dispatch($reminder, $time);

        return ['time' => $time, 'label' => $label];
    }
}
