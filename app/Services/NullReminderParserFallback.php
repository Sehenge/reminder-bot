<?php

namespace App\Services;

use App\Contracts\ReminderParserFallback;
use App\DTO\ParsedReminderDTO;

final class NullReminderParserFallback implements ReminderParserFallback
{
    public function parse(string $text, string $timezone, string $locale): ?ParsedReminderDTO
    {
        return null;
    }
}
