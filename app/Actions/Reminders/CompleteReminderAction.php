<?php

namespace App\Actions\Reminders;

use App\Enums\DeliveryStatus;
use App\Enums\RecurrenceType;
use App\Events\ReminderCompleted;
use App\Models\Reminder;
use App\Models\User;
use Carbon\Carbon;

final class CompleteReminderAction
{
    public function __construct(private CalculateNextOccurrenceAction $nextOccurrence) {}

    public function execute(Reminder $reminder, User $user): ?Carbon
    {
        if ($reminder->recurrence_type !== RecurrenceType::Once->value) {
            $next = $this->nextOccurrence->execute($reminder, $user->timezone);

            if ($next) {
                $reminder->update([
                    'target_at' => $next,
                    'is_completed' => false,
                    'status' => DeliveryStatus::Pending->value,
                ]);
                ReminderCompleted::dispatch($reminder, false);

                return $next;
            }
        }

        $reminder->update([
            'is_completed' => true,
            'completed_at' => Carbon::now(),
            'status' => DeliveryStatus::Sent->value,
        ]);
        ReminderCompleted::dispatch($reminder, true);

        return null;
    }
}
