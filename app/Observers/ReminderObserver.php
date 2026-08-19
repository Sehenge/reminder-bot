<?php

namespace App\Observers;

use App\Models\Reminder;
use App\Models\ReminderHistory;

final class ReminderObserver
{
    public function created(Reminder $reminder): void
    {
        $this->record($reminder, 'created');
    }

    public function updated(Reminder $reminder): void
    {
        $event = $reminder->wasChanged('is_completed') && $reminder->is_completed ? 'completed' : 'updated';
        $this->record($reminder, $event);
    }

    public function deleting(Reminder $reminder): void
    {
        $this->record($reminder, 'deleted');
    }

    private function record(Reminder $reminder, string $event): void
    {
        ReminderHistory::query()->create([
            'reminder_id' => $reminder->id,
            'owner_id' => $reminder->user_id,
            'actor_id' => $reminder->user_id,
            'event_type' => $event,
            'text' => $reminder->text,
            'target_at' => $reminder->target_at,
            'is_completed' => (bool) ($reminder->is_completed ?? false),
            'snapshot' => $reminder->attributesToArray(),
        ]);
    }
}
