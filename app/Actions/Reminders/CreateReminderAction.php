<?php

namespace App\Actions\Reminders;

use App\Events\ReminderCreated;
use App\Models\Reminder;
use App\Models\User;
use Carbon\Carbon;

final class CreateReminderAction
{
    /** @param array<string, mixed> $data */
    public function execute(User $user, array $data): Reminder
    {
        $reminder = Reminder::query()->create([
            'user_id' => $user->id,
            'text' => (string) $data['text'],
            'target_at' => Carbon::parse((string) $data['target_at']),
            'recurrence_type' => (string) $data['recurrence_type'],
            'recurrence_value' => $data['recurrence_value'] ?? null,
            'is_completed' => false,
        ]);

        ReminderCreated::dispatch($reminder);

        return $reminder;
    }
}
