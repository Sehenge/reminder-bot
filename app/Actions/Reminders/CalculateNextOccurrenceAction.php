<?php

namespace App\Actions\Reminders;

use App\Models\Reminder;
use Carbon\Carbon;

final class CalculateNextOccurrenceAction
{
    public function execute(Reminder $reminder, ?string $timezone = null): ?Carbon
    {
        return $reminder->calculateNextOccurrence($timezone);
    }
}
