<?php

namespace App\Services;

use App\Contracts\ReminderParserFallback;
use App\DTO\ParsedReminderDTO;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

final class TokenatorReminderParserFallback implements ReminderParserFallback
{
    public function parse(string $text, string $timezone, string $locale): ?ParsedReminderDTO
    {
        $apiKey = (string) config('services.reminder_parser.api_key');
        if ($apiKey === '') {
            return null;
        }

        foreach ((array) config('services.reminder_parser.models', []) as $model) {
            $result = $this->request((string) $model, $text, $timezone, $locale);
            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    private function request(string $model, string $text, string $timezone, string $locale): ?ParsedReminderDTO
    {
        if ($model === '') {
            return null;
        }

        $now = Carbon::now($timezone);

        try {
            $response = Http::baseUrl(rtrim((string) config('services.reminder_parser.base_url'), '/'))
                ->withToken((string) config('services.reminder_parser.api_key'))
                ->acceptJson()
                ->timeout((int) config('services.reminder_parser.timeout', 10))
                ->post('/chat/completions', [
                    'model' => $model,
                    'temperature' => 0,
                    'max_tokens' => 300,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        ['role' => 'system', 'content' => $this->systemPrompt($now, $timezone, $locale)],
                        ['role' => 'user', 'content' => $text],
                    ],
                ]);

            if (! $response->successful()) {
                Log::warning('Reminder AI fallback request failed.', [
                    'model' => $model,
                    'status' => $response->status(),
                ]);

                return null;
            }

            $content = $response->json('choices.0.message.content');
            if (! is_string($content)) {
                return null;
            }

            return $this->toDto($content, $timezone, $locale, $now);
        } catch (Throwable $exception) {
            Log::warning('Reminder AI fallback unavailable.', [
                'model' => $model,
                'exception' => $exception::class,
            ]);

            return null;
        }
    }

    private function systemPrompt(Carbon $now, string $timezone, string $locale): string
    {
        return <<<PROMPT
Extract one reminder from the user's message. The message language is {$locale}.
Current local datetime: {$now->format('Y-m-d H:i:s')}. Timezone: {$timezone}.
Interpret typos and conversational date expressions. Never invent a date when none is implied.
Russian relative dates: "послезавтра" means today + 2 calendar days; "после послезавтра" means today + 3 calendar days.
Day-part defaults: morning/утро 09:00, afternoon/день 13:00, evening/вечер 19:00, night/ночь 22:00. A date without a time means 09:00.
Return JSON only: {"task":"...","local_datetime":"YYYY-MM-DD HH:MM:SS","recurrence_type":"once|daily|workdays|weekly|monthly|interval|custom","recurrence_value":null,"confidence":0.0}.
For weekly/custom recurrence_value is comma-separated ISO weekdays 1..7; monthly is day 1..31; interval is minutes|hours|days|weeks followed by a colon and positive integer.
If no date, time, relative expression or recurrence is present, return {"error":"missing_temporal_expression"}.
PROMPT;
    }

    private function toDto(string $content, string $timezone, string $locale, Carbon $now): ?ParsedReminderDTO
    {
        $start = strpos($content, '{');
        $end = strrpos($content, '}');
        if ($start === false || $end === false || $end < $start) {
            return null;
        }

        $data = json_decode(substr($content, $start, $end - $start + 1), true);
        if (! is_array($data) || isset($data['error'])) {
            return null;
        }

        $task = trim((string) ($data['task'] ?? ''));
        $confidence = filter_var($data['confidence'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($task === '' || mb_strlen($task) > 500 || $confidence === false
            || $confidence < (float) config('services.reminder_parser.min_confidence', 0.75)
            || $confidence > 1) {
            return null;
        }

        try {
            $target = Carbon::createFromFormat('!Y-m-d H:i:s', (string) ($data['local_datetime'] ?? ''), $timezone);
        } catch (Throwable) {
            return null;
        }

        if ($target === false || $target->lessThanOrEqualTo($now)) {
            return null;
        }

        $recurrenceType = mb_strtolower(trim((string) ($data['recurrence_type'] ?? 'once')));
        if (in_array($recurrenceType, ['none', 'null', 'single', 'one_time'], true)) {
            $recurrenceType = 'once';
        }
        $recurrenceValue = isset($data['recurrence_value']) ? (string) $data['recurrence_value'] : null;
        if (! $this->validRecurrence($recurrenceType, $recurrenceValue)) {
            return null;
        }

        return new ParsedReminderDTO(
            text: $task,
            targetAt: $target->setTimezone('UTC'),
            recurrenceType: $recurrenceType,
            recurrenceValue: $recurrenceValue,
            success: true,
            needsClarification: false,
            confidence: (float) $confidence,
            locale: $locale,
        );
    }

    private function validRecurrence(string $type, ?string $value): bool
    {
        return match ($type) {
            'once', 'daily', 'workdays' => $value === null || $value === '',
            'weekly', 'custom' => $value !== null
                && preg_match('/^[1-7](?:,[1-7])*$/', $value) === 1,
            'monthly' => $value !== null && ctype_digit($value) && (int) $value >= 1 && (int) $value <= 31,
            'interval' => $value !== null
                && preg_match('/^(?:minutes|hours|days|weeks):[1-9]\d*$/', $value) === 1,
            default => false,
        };
    }
}
