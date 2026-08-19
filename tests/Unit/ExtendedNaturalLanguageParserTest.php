<?php

namespace Tests\Unit;

use App\Models\Reminder;
use App\Services\NaturalLanguageParserService;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ExtendedNaturalLanguageParserTest extends TestCase
{
    private NaturalLanguageParserService $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = app(NaturalLanguageParserService::class);
        Carbon::setTestNow(Carbon::create(2026, 8, 19, 12, 0, 0, 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[DataProvider('recurrenceProvider')]
    public function test_extended_recurrence_grammar(string $text, string $locale, string $type, ?string $value): void
    {
        $result = $this->parser->parse($text, 'UTC', $locale);

        $this->assertTrue($result->success);
        $this->assertSame($type, $result->recurrenceType);
        $this->assertSame($value, $result->recurrenceValue);
        $this->assertGreaterThanOrEqual(0.82, $result->confidence);
    }

    public static function recurrenceProvider(): array
    {
        return [
            'несколько дней недели' => ['по понедельникам, средам и пятницам в 09:00 тренировка', 'ru', 'custom', '1,3,5'],
            'several weekdays' => ['on mondays, wednesdays and fridays at 9:00 workout', 'en', 'custom', '1,3,5'],
            'русский интервал' => ['каждые 2 дня в 10:00 резервная копия', 'ru', 'interval', 'days:2'],
            'english interval' => ['every 3 hours check the queue', 'en', 'interval', 'hours:3'],
            'monthly day ru' => ['каждого 15 числа в 12:00 оплатить счёт', 'ru', 'monthly', '15'],
            'monthly day en' => ['every month on the 15th at 12:00 pay invoice', 'en', 'monthly', '15'],
        ];
    }

    #[DataProvider('englishProvider')]
    public function test_english_dates_and_time(string $text, string $expectedText, string $expectedUtc): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 19, 10, 0, 0, 'America/New_York'));

        $result = $this->parser->parse($text, 'America/New_York', 'en');

        $this->assertTrue($result->success);
        $this->assertSame('en', $result->locale);
        $this->assertSame($expectedText, $result->text);
        $this->assertSame($expectedUtc, $result->targetAt->format('Y-m-d H:i:s T'));
    }

    public static function englishProvider(): array
    {
        return [
            'tomorrow pm' => ['remind me to call John tomorrow at 3:30 pm', 'call John', '2026-08-20 19:30:00 UTC'],
            'relative' => ['check oven in 45 minutes', 'check oven', '2026-08-19 14:45:00 UTC'],
            'named date' => ['submit report September 5 at 9:00 am', 'submit report', '2026-09-05 13:00:00 UTC'],
        ];
    }

    #[DataProvider('timezoneBoundaryProvider')]
    public function test_timezone_dst_and_year_boundaries(string $now, string $timezone, string $text, string $expected): void
    {
        Carbon::setTestNow(Carbon::parse($now, $timezone));

        $result = $this->parser->parse($text, $timezone, 'en');

        $this->assertSame($expected, $result->targetAt->format('Y-m-d H:i:s T'));
    }

    public static function timezoneBoundaryProvider(): array
    {
        return [
            'spring DST' => ['2026-03-07 12:00:00', 'America/New_York', 'tomorrow at 9:00', '2026-03-08 13:00:00 UTC'],
            'autumn DST' => ['2026-10-31 12:00:00', 'America/New_York', 'tomorrow at 9:00', '2026-11-01 14:00:00 UTC'],
            'new year' => ['2026-12-31 20:00:00', 'Europe/Minsk', 'tomorrow at 9:00', '2027-01-01 06:00:00 UTC'],
            'leap day' => ['2028-02-28 12:00:00', 'Europe/London', 'tomorrow at 9:00', '2028-02-29 09:00:00 UTC'],
        ];
    }

    public function test_failure_explains_why_clarification_is_needed(): void
    {
        $result = $this->parser->parse('buy milk', 'UTC', 'en');

        $this->assertFalse($result->success);
        $this->assertTrue($result->needsClarification);
        $this->assertSame(0.0, $result->confidence);
        $this->assertSame('missing_temporal_expression', $result->failureReason);
    }

    public function test_interval_recurrence_calculates_next_occurrence(): void
    {
        $reminder = new Reminder([
            'target_at' => Carbon::now()->subHour(),
            'recurrence_type' => 'interval',
            'recurrence_value' => 'hours:3',
        ]);

        $this->assertSame('2026-08-19 14:00:00', $reminder->calculateNextOccurrence('UTC')->format('Y-m-d H:i:s'));
    }

    public function test_monthly_recurrence_clamps_day_to_short_month(): void
    {
        Carbon::setTestNow('2027-02-01 00:00:00');
        $reminder = new Reminder([
            'target_at' => Carbon::parse('2027-01-31 09:00:00 UTC'),
            'recurrence_type' => 'monthly',
            'recurrence_value' => '31',
        ]);

        $this->assertSame('2027-02-28 09:00:00', $reminder->calculateNextOccurrence('UTC')->format('Y-m-d H:i:s'));
    }
}
