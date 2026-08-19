<?php

namespace App\Enums;

enum UserState: string
{
    case WaitingForTimezone = 'wait_timezone';
    case EditingText = 'edit_text';
    case EditingTime = 'edit_time';
    case ClarifyingReminder = 'clarify_reminder';
}
