<?php

namespace App\Events;

use App\Models\Reminder;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class ReminderCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(public Reminder $reminder) {}
}
