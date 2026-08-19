<?php

namespace App\Events;

use App\Models\Reminder;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class ReminderUpdated
{
    use Dispatchable, SerializesModels;

    /** @param array<int, string> $fields */
    public function __construct(public Reminder $reminder, public array $fields) {}
}
