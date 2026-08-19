<?php

namespace App\Contracts;

use App\DTO\ParsedReminderDTO;

interface ReminderParserFallback
{
    public function parse(string $text, string $timezone, string $locale): ?ParsedReminderDTO;
}
