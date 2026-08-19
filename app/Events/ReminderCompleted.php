<?php

namespace App\Events;

use App\Models\Reminder;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class ReminderCompleted
{
    use Dispatchable, SerializesModels;

    public function __construct(public Reminder $reminder, public bool $permanently) {}
}
