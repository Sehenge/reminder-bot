<?php

namespace App\Services;

use App\Contracts\ReminderParserFallback;
use App\DTO\ParsedReminderDTO;
use Carbon\Carbon;

final class NaturalLanguageParserService
{
    private const WEEKDAYS = [
        'ru' => ['понедельник' => 1, 'понедельникам' => 1, 'вторник' => 2, 'вторникам' => 2, 'среду' => 3, 'средам' => 3, 'четверг' => 4, 'четвергам' => 4, 'пятницу' => 5, 'пятницам' => 5, 'субботу' => 6, 'субботам' => 6, 'воскресенье' => 7, 'воскресеньям' => 7],
        'en' => ['monday' => 1, 'mondays' => 1, 'tuesday' => 2, 'tuesdays' => 2, 'wednesday' => 3, 'wednesdays' => 3, 'thursday' => 4, 'thursdays' => 4, 'friday' => 5, 'fridays' => 5, 'saturday' => 6, 'saturdays' => 6, 'sunday' => 7, 'sundays' => 7],
    ];

    private const MONTHS = [
        'января' => 1, 'февраля' => 2, 'марта' => 3, 'апреля' => 4, 'мая' => 5, 'июня' => 6,
        'июля' => 7, 'августа' => 8, 'сентября' => 9, 'октября' => 10, 'ноября' => 11, 'декабря' => 12,
        'january' => 1, 'february' => 2, 'march' => 3, 'april' => 4, 'may' => 5, 'june' => 6,
        'july' => 7, 'august' => 8, 'september' => 9, 'october' => 10, 'november' => 11, 'december' => 12,
    ];

    public function __construct(private readonly ?ReminderParserFallback $fallback = null) {}

    public function parse(string $input, string $timezone = 'UTC', string $language = 'ru'): ParsedReminderDTO
    {
        // Telegram's profile language describes the client UI, not necessarily the
        // language of a particular message. Prefer the script used in the message so
        // an English-profile user can still create reminders in Russian.
        $locale = preg_match('/[а-яё]/ui', $input) === 1
            ? 'ru'
            : (str_starts_with(mb_strtolower($language), 'en') ? 'en' : 'ru');
        $preferAi = $this->requiresAiInterpretation($input, $locale);
        $text = trim((string) preg_replace('/^(напомни(?:ть)?(?:\s+мне)?|remind\s+me(?:\s+to)?|remind)\s+/ui', '', trim($input)));
        $now = Carbon::now($timezone);
        $target = $now->copy();
        $recurrenceType = 'once';
        $recurrenceValue = null;
        $dateFound = false;
        $timeFound = false;
        $relative = false;

        [$text, $recurrenceType, $recurrenceValue, $recurringDay] = $this->extractRecurrence($text, $locale);
        if ($recurringDay !== null) {
            $target = $this->moveToWeekday($target, $recurringDay, true);
            $dateFound = true;
        }

        if ($recurrenceType === 'monthly' && $recurrenceValue !== null) {
            $day = min((int) $recurrenceValue, $target->daysInMonth);
            $target->day($day);
            if ($target->lessThanOrEqualTo($now)) {
                $target->addMonthNoOverflow()->day(min((int) $recurrenceValue, $target->daysInMonth));
            }
            $dateFound = true;
        }

        [$text, $target, $dateFound, $relative] = $this->extractDate($text, $target, $now, $timezone, $locale, $dateFound);
        [$text, $target, $timeFound] = $this->extractTime($text, $target, $locale, $relative);

        if ($recurrenceType === 'interval' && ! $timeFound && ! $relative) {
            [$unit, $amount] = explode(':', (string) $recurrenceValue, 2);
            $amount = max(1, (int) $amount);
            match ($unit) {
                'minutes' => $target->addMinutes($amount),
                'hours' => $target->addHours($amount),
                'weeks' => $target->addWeeks($amount),
                default => $target->addDays($amount),
            };
            $relative = true;
        }

        $isRecurring = $recurrenceType !== 'once';
        $success = $relative || $dateFound || $timeFound || $isRecurring;

        if ($success && ! $relative && ! $dateFound && $timeFound && ! $isRecurring && $target->lessThanOrEqualTo($now)) {
            $target->addDay();
        }

        if ($success && ! $relative && ! $timeFound) {
            $target->setTime(9, 0);
        }

        $cleaned = $this->cleanTaskText($text, $locale);
        $confidence = $this->confidence($relative, $dateFound, $timeFound, $isRecurring);
        if ($success && (
            $preferAi
            || $this->hasUnparsedTemporalExpression($cleaned, $locale)
            || $this->hasUnparsedTimeQualifier($text, $locale, $dateFound, $timeFound)
        )) {
            $success = false;
            $confidence = 0.0;
        }

        if (! $success && config('services.reminder_parser.ai_fallback', false)) {
            $fallback = $this->fallback?->parse($input, $timezone, $locale);
            if ($fallback !== null) {
                return $fallback;
            }
        }

        return new ParsedReminderDTO(
            text: $cleaned,
            targetAt: $target->copy()->setTimezone('UTC'),
            recurrenceType: $recurrenceType,
            recurrenceValue: $recurrenceValue,
            success: $success,
            needsClarification: ! $success,
            confidence: $confidence,
            failureReason: $success ? null : 'missing_temporal_expression',
            locale: $locale,
        );
    }

    /** @return array{string, string, ?string, ?int} */
    private function extractRecurrence(string $text, string $locale): array
    {
        $monthlyDayPattern = $locale === 'en'
            ? '/\b(?:every month on (?:the )?)(\d{1,2})(?:st|nd|rd|th)?\b/ui'
            : '/\b(?:каждого|ежемесячно)\s+(\d{1,2})(?:-го)?\s*(?:числа)?\b/ui';
        if (preg_match($monthlyDayPattern, $text, $match) && (int) $match[1] <= 31) {
            return [(string) preg_replace($monthlyDayPattern, '', $text), 'monthly', (string) (int) $match[1], null];
        }

        $patterns = $locale === 'en'
            ? ['daily' => '/\b(?:every day|daily)\b/ui', 'workdays' => '/\b(?:on weekdays|every weekday)\b/ui', 'monthly' => '/\b(?:every month|monthly)\b/ui']
            : ['daily' => '/(?:каждый день|ежедневно|каждые сутки)/ui', 'workdays' => '/(?:по будням|в будни|каждый будний день)/ui', 'monthly' => '/(?:каждый месяц|ежемесячно)/ui'];

        foreach ($patterns as $type => $pattern) {
            if (preg_match($pattern, $text)) {
                return [(string) preg_replace($pattern, '', $text), $type, null, null];
            }
        }

        $intervalPattern = $locale === 'en'
            ? '/\bevery\s+(\d+)\s+(minutes?|hours?|days?|weeks?)\b/ui'
            : '/каждые?\s+(\d+)\s+(минут[уы]?|час(?:а|ов)?|дн(?:я|ей)?|недел(?:ю|и|ь))/ui';
        if (preg_match($intervalPattern, $text, $match)) {
            $unit = mb_strtolower($match[2]);
            $normalized = str_starts_with($unit, 'min') || str_starts_with($unit, 'мин') ? 'minutes'
                : (str_starts_with($unit, 'hour') || str_starts_with($unit, 'час') ? 'hours'
                    : (str_starts_with($unit, 'week') || str_starts_with($unit, 'нед') ? 'weeks' : 'days'));

            return [(string) preg_replace($intervalPattern, '', $text), 'interval', "{$normalized}:{$match[1]}", null];
        }

        $foundDays = [];
        foreach (self::WEEKDAYS[$locale] as $name => $day) {
            $prefix = $locale === 'en' ? '(?:every|on)' : '(?:кажд(?:ый|ую)|по)';
            if (preg_match("/{$prefix}\\s+{$name}\\b/ui", $text)) {
                $foundDays[] = $day;
                $text = (string) preg_replace("/{$prefix}\\s+{$name}\\b/ui", '', $text);
            } elseif ($foundDays !== [] && preg_match("/(?:,|\\s+(?:and|и))\\s*{$name}\\b/ui", $text)) {
                $foundDays[] = $day;
                $text = (string) preg_replace("/(?:,|\\s+(?:and|и))\\s*{$name}\\b/ui", '', $text);
            }
        }

        $foundDays = array_values(array_unique($foundDays));
        sort($foundDays);
        if ($foundDays !== []) {
            return [$text, count($foundDays) === 1 ? 'weekly' : 'custom', implode(',', $foundDays), $foundDays[0]];
        }

        return [$text, 'once', null, null];
    }

    /** @return array{string, Carbon, bool, bool} */
    private function extractDate(string $text, Carbon $target, Carbon $now, string $timezone, string $locale, bool $alreadyFound): array
    {
        $relativePattern = $locale === 'en'
            ? '/\bin\s+(?:(\d+|a|an|one|two|three|four|five|six|seven|eight|nine|ten|a couple of)\s+)?(minutes?|hours?|days?|weeks?)\b/ui'
            : '/через\s+(?:(\d+|пару|од(?:ин|на|ну)|дв[ае]|три|четыре|пять|шесть|семь|восемь|девять|десять)\s*)?(минут[уы]?|мин|час(?:а|ов)?|ч|дн(?:я|ей)?|день|сутки|недел(?:ю|и|ь))/ui';
        if (preg_match($relativePattern, $text, $match)) {
            $amount = mb_strtolower((string) ($match[1] ?? ''));
            $wordNumbers = [
                'a' => 1, 'an' => 1, 'one' => 1, 'один' => 1, 'одна' => 1, 'одну' => 1,
                'two' => 2, 'пару' => 2, 'a couple of' => 2, 'два' => 2, 'две' => 2,
                'three' => 3, 'три' => 3, 'four' => 4, 'четыре' => 4, 'five' => 5, 'пять' => 5,
                'six' => 6, 'шесть' => 6, 'seven' => 7, 'семь' => 7, 'eight' => 8, 'восемь' => 8,
                'nine' => 9, 'девять' => 9, 'ten' => 10, 'десять' => 10,
            ];
            $value = ctype_digit($amount) ? (int) $amount : ($wordNumbers[$amount] ?? 1);
            $unit = mb_strtolower($match[2]);
            match (true) {
                str_starts_with($unit, 'min'), str_starts_with($unit, 'мин') => $target->addMinutes($value),
                str_starts_with($unit, 'hour'), str_starts_with($unit, 'час'), $unit === 'ч' => $target->addHours($value),
                str_starts_with($unit, 'week'), str_starts_with($unit, 'нед') => $target->addWeeks($value),
                default => $target->addDays($value),
            };

            return [(string) preg_replace($relativePattern, '', $text), $target, true, true];
        }

        // Keep typo tolerance restricted to temporal vocabulary: correcting the whole
        // task text could silently change names, titles or the reminder itself.
        $relativeDates = $locale === 'en'
            ? ['/\bday\s+after\s+tomorrow\b/ui' => 2, '/\btomorrow\b/ui' => 1, '/\btoday\b/ui' => 0]
            : [
                '/\bпосле[\s-]*завтр[ао]\b/ui' => 2,
                '/\bзавтр[ао]\b/ui' => 1,
                '/\b(?:сегодня|севодня|сигодня|сегодна)\b/ui' => 0,
            ];
        foreach ($relativeDates as $pattern => $days) {
            if (preg_match($pattern, $text)) {
                $target->addDays($days);

                return [(string) preg_replace($pattern, '', $text), $target, true, false];
            }
        }

        $monthNames = implode('|', array_keys(self::MONTHS));
        $namedDate = $locale === 'en'
            ? "/\\b({$monthNames})\\s+(\\d{1,2})(?:,?\\s+(\\d{4}))?\\b/ui"
            : "/\\b(\\d{1,2})\\s+({$monthNames})(?:\\s+(\\d{4})(?:\\s+г(?:од(?:а)?)?\\.? )?)?\\b/uix";
        if (preg_match($namedDate, $text, $match, PREG_UNMATCHED_AS_NULL)) {
            $day = (int) ($locale === 'en' ? $match[2] : $match[1]);
            $month = self::MONTHS[mb_strtolower($locale === 'en' ? $match[1] : $match[2])];
            $year = $match[3] !== null ? (int) $match[3] : $now->year;
            if (checkdate($month, $day, $year)) {
                $candidate = Carbon::create($year, $month, $day, 0, 0, 0, $timezone);
                if ($match[3] === null && $candidate->endOfDay()->isPast()) {
                    $year++;
                }
                $target->setDate($year, $month, $day);

                return [(string) preg_replace($namedDate, '', $text), $target, true, false];
            }
        }

        if (preg_match('/\b(\d{1,2})[.\/-](\d{1,2})(?:[.\/-](\d{4}))?\b/u', $text, $match)) {
            $day = (int) $match[1];
            $month = (int) $match[2];
            $year = isset($match[3]) ? (int) $match[3] : $now->year;
            if (checkdate($month, $day, $year)) {
                if (! isset($match[3]) && Carbon::create($year, $month, $day, 23, 59, 59, $timezone)->isPast()) {
                    $year++;
                }
                $target->setDate($year, $month, $day);

                return [(string) preg_replace('/\b\d{1,2}[.\/-]\d{1,2}(?:[.\/-]\d{4})?\b/u', '', $text), $target, true, false];
            }
        }

        foreach (self::WEEKDAYS[$locale] as $name => $day) {
            $pattern = $locale === 'en' ? "/\\b(?:on\\s+)?{$name}\\b/ui" : "/\\b(?:в(?:о)?\\s+)?{$name}\\b/ui";
            if (preg_match($pattern, $text)) {
                return [(string) preg_replace($pattern, '', $text), $this->moveToWeekday($target, $day), true, false];
            }
        }

        return [$text, $target, $alreadyFound, false];
    }

    /** @return array{string, Carbon, bool} */
    private function extractTime(string $text, Carbon $target, string $locale, bool $relative): array
    {
        $dayParts = $locale === 'en'
            ? ['morning' => 9, 'afternoon' => 13, 'evening' => 19, 'night' => 22]
            : ['утром' => 9, 'днём' => 13, 'днем' => 13, 'вечером' => 19, 'ночью' => 22];
        foreach ($dayParts as $phrase => $hour) {
            $pattern = '/\\b'.preg_quote($phrase, '/').'\\b/ui';
            if (preg_match($pattern, $text)) {
                $target->setTime($hour, 0);

                return [(string) preg_replace($pattern, '', $text), $target, true];
            }
        }

        if ($locale === 'en' && preg_match('/\b(?:at\s+)?(\d{1,2})(?::(\d{2}))?\s*(am|pm)\b/ui', $text, $match, PREG_UNMATCHED_AS_NULL)) {
            $hour = (int) $match[1] % 12 + (mb_strtolower($match[3]) === 'pm' ? 12 : 0);
            $target->setTime($hour, (int) ($match[2] ?? 0));

            return [(string) preg_replace('/\b(?:at\s+)?\d{1,2}(?::\d{2})?\s*(?:am|pm)\b/ui', '', $text), $target, true];
        }

        if (preg_match('/(?:\b(?:в|at)\s+)?\b([01]?\d|2[0-3])[:.]([0-5]\d)\b/ui', $text, $match)) {
            $target->setTime((int) $match[1], (int) $match[2]);

            return [(string) preg_replace('/(?:\b(?:в|at)\s+)?\b(?:[01]?\d|2[0-3])[:.][0-5]\d\b/ui', '', $text), $target, true];
        }

        if (preg_match('/\b(?:в|at)\s+([01]?\d|2[0-3])(?:\s*(?:ч|час(?:а|ов)?|o’clock))?\b/ui', $text, $match)) {
            $target->setTime((int) $match[1], 0);

            return [(string) preg_replace('/\b(?:в|at)\s+(?:[01]?\d|2[0-3])(?:\s*(?:ч|час(?:а|ов)?|o’clock))?\b/ui', '', $text), $target, true];
        }

        // A relative duration already carries an exact target. Preserve it when no
        // explicit clock time/day part follows, but allow "через неделю вечером" to
        // override the inherited current time with the requested evening default.
        return [$text, $target, $relative];
    }

    private function moveToWeekday(Carbon $target, int $day, bool $allowToday = false): Carbon
    {
        if ($target->dayOfWeekIso === $day && $allowToday) {
            return $target;
        }

        do {
            $target->addDay();
        } while ($target->dayOfWeekIso !== $day);

        return $target;
    }

    private function cleanTaskText(string $text, string $locale): string
    {
        $prefix = $locale === 'en' ? '/^\s*(?:to|on|at|and)\s+/ui' : '/^\s*(?:в|во|на|о|об|обо|к|со|с|и|да)\s+/ui';
        $cleaned = trim((string) preg_replace('/\s+/', ' ', (string) preg_replace($prefix, '', trim($text))));

        return $cleaned !== '' ? $cleaned : ($locale === 'en' ? 'Reminder' : 'Напоминание');
    }

    private function confidence(bool $relative, bool $date, bool $time, bool $recurring): float
    {
        return match (true) {
            $relative => 1.0,
            $date && $time => 0.98,
            $recurring && $time => 0.95,
            $date || $time || $recurring => 0.82,
            default => 0.0,
        };
    }

    private function hasUnparsedTemporalExpression(string $text, string $locale): bool
    {
        $pattern = $locale === 'en'
            ? '/\bin\s+(?:\d+|[a-z-]+(?:\s+[a-z-]+)?)\s+(?:minutes?|hours?|days?|weeks?|months?|years?)\b/ui'
            : '/\bчерез\s+(?:\d+|[а-яё-]+(?:\s+[а-яё-]+)?)\s*(?:минут\w*|час\w*|д(?:ень|ня|ней)|сут\w*|недел\w*|месяц\w*|год\w*)\b/ui';

        return preg_match($pattern, $text) === 1;
    }

    private function hasUnparsedTimeQualifier(
        string $text,
        string $locale,
        bool $dateFound,
        bool $timeFound
    ): bool {
        if (! $dateFound || $timeFound) {
            return false;
        }

        // A date followed by an unresolved connector usually contains a
        // conversational time qualifier ("tomorrow at lunch", "завтра в обед").
        // Let the AI interpret the whole phrase instead of growing a language-
        // specific dictionary and silently defaulting to 09:00.
        $pattern = $locale === 'en'
            ? '/^\s*(?:at|around|by|near)\b/ui'
            : '/^\s*(?:в|во|к|около|примерно)\b/ui';

        return preg_match($pattern, $text) === 1;
    }

    private function requiresAiInterpretation(string $text, string $locale): bool
    {
        if ($locale !== 'ru') {
            return false;
        }

        return preg_match('/\bпосле\s+после[\s-]*завтр[ао]\b/ui', $text) === 1;
    }
}
