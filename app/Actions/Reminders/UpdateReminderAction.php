<?php

namespace App\Actions\Reminders;

use App\Enums\DeliveryStatus;
use App\Events\ReminderUpdated;
use App\Models\Reminder;

final class UpdateReminderAction
{
    /** @param array<string, mixed> $attributes */
    public function execute(Reminder $reminder, array $attributes): Reminder
    {
        $reminder->fill($attributes);

        if (in_array($reminder->status, [DeliveryStatus::Sent->value, DeliveryStatus::Failed->value], true)
            && $reminder->target_at->isFuture()) {
            $reminder->status = DeliveryStatus::Pending->value;
            $reminder->is_completed = false;
        }

        $dirty = array_keys($reminder->getDirty());
        $reminder->save();
        ReminderUpdated::dispatch($reminder, $dirty);

        return $reminder;
    }
}
