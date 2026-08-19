<?php

namespace App\Events;

use App\Models\Reminder;
use Carbon\Carbon;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class ReminderSnoozed
{
    use Dispatchable, SerializesModels;

    public function __construct(public Reminder $reminder, public Carbon $until) {}
}
