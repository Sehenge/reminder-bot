<?php

namespace Tests\Unit;

use App\Services\NaturalLanguageParserService;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NaturalLanguageParserTest extends TestCase
{
    protected NaturalLanguageParserService $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new NaturalLanguageParserService;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Тест относительного парсинга "через X минут"
     */
    public function test_parses_relative_minutes(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 5, 21, 12, 0, 0, 'Europe/Moscow'));

        $dto = $this->parser->parse('Напомни купить молоко через 15 минут', 'Europe/Moscow');

        $this->assertTrue($dto->success);
        $this->assertFalse($dto->needsClarification);
        $this->assertEquals('купить молоко', $dto->text);
        // Проверяем, что в UTC вернется правильное время (12:15 MSK = 09:15 UTC)
        $this->assertEquals(
            Carbon::create(2026, 5, 21, 9, 15, 0, 'UTC')->toIso8601String(),
            $dto->targetAt->toIso8601String()
        );
        $this->assertEquals('once', $dto->recurrenceType);
    }

    #[DataProvider('relativeWithoutNumericAmountProvider')]
    public function test_parses_relative_period_without_numeric_amount(
        string $input,
        string $expectedLocalDateTime,
        string $expectedText
    ): void {
        Carbon::setTestNow(Carbon::create(2026, 8, 22, 0, 19, 0, 'Europe/Moscow'));

        $dto = $this->parser->parse($input, 'Europe/Moscow', 'ru');

        $this->assertTrue($dto->success);
        $this->assertSame($expectedText, $dto->text);
        $this->assertSame(
            $expectedLocalDateTime,
            $dto->targetAt->setTimezone('Europe/Moscow')->format('Y-m-d H:i')
        );
    }

    public static function relativeWithoutNumericAmountProvider(): array
    {
        return [
            'через неделю вечером' => [
                'Напомни через неделю вечером полить цветы пожалуйста',
                '2026-08-29 19:00',
                'полить цветы пожалуйста',
            ],
            'через пару дней' => [
                'Напомни через пару дней купить корм коту',
                '2026-08-24 00:19',
                'купить корм коту',
            ],
            'через две недели вечером' => [
                'Напомни через две недели вечером полить цветы пожалуйста',
                '2026-09-05 19:00',
                'полить цветы пожалуйста',
            ],
            'через час' => [
                'Напомни через час проверить духовку',
                '2026-08-22 01:19',
                'проверить духовку',
            ],
        ];
    }

    public function test_does_not_accept_partial_parse_with_unconsumed_temporal_expression(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 22, 0, 22, 0, 'Europe/Moscow'));

        $dto = $this->parser->parse(
            'Напомни через несколько недель вечером проверить календарь',
            'Europe/Moscow',
            'ru'
        );

        $this->assertFalse($dto->success);
        $this->assertTrue($dto->needsClarification);
        $this->assertSame('missing_temporal_expression', $dto->failureReason);
    }

    public function test_russian_message_overrides_english_telegram_profile_language(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 21, 23, 31, 0, 'Europe/Moscow'));

        $dto = $this->parser->parse(
            'Напомни мне 21 ноября 2026 года проверить дату выхода Solo Leveling: Beyond the System и новости о третьем сезоне.',
            'Europe/Moscow',
            'en'
        );

        $this->assertTrue($dto->success);
        $this->assertFalse($dto->needsClarification);
        $this->assertSame('ru', $dto->locale);
        $this->assertSame(
            'проверить дату выхода Solo Leveling: Beyond the System и новости о третьем сезоне.',
            $dto->text
        );
        $this->assertSame('2026-11-21 06:00:00 UTC', $dto->targetAt->format('Y-m-d H:i:s T'));
    }

    #[DataProvider('russianDayPartProvider')]
    public function test_parses_russian_day_parts(string $input, string $expectedLocalTime): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 21, 12, 0, 0, 'Europe/Moscow'));

        $dto = $this->parser->parse($input, 'Europe/Moscow', 'ru');

        $this->assertTrue($dto->success);
        $this->assertFalse($dto->needsClarification);
        $this->assertSame('проверить новости', $dto->text);
        $this->assertSame($expectedLocalTime, $dto->targetAt->setTimezone('Europe/Moscow')->format('Y-m-d H:i'));
    }

    public static function russianDayPartProvider(): array
    {
        return [
            'завтра утром' => ['Напомни мне завтра утром проверить новости', '2026-08-22 09:00'],
            'послезавтра днём' => ['Напомни мне послезавтра днём проверить новости', '2026-08-23 13:00'],
            'послезавтра днем' => ['Напомни мне послезавтра днем проверить новости', '2026-08-23 13:00'],
            'завтра вечером' => ['Напомни мне завтра вечером проверить новости', '2026-08-22 19:00'],
            'завтра ночью' => ['Напомни мне завтра ночью проверить новости', '2026-08-22 22:00'],
        ];
    }

    /**
     * Тест парсинга точного времени "завтра в 15:30"
     */
    public function test_parses_tomorrow_with_exact_time(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 5, 21, 12, 0, 0, 'Europe/Moscow'));

        $dto = $this->parser->parse('завтра в 15:30 созвон по работе', 'Europe/Moscow');

        $this->assertTrue($dto->success);
        $this->assertEquals('созвон по работе', $dto->text);
        // Завтра (22 мая) в 15:30 MSK = 12:30 UTC
        $this->assertEquals(
            Carbon::create(2026, 5, 22, 12, 30, 0, 'UTC')->toIso8601String(),
            $dto->targetAt->toIso8601String()
        );
    }

    /**
     * Тест повторяющегося напоминания "каждый день в 11:00"
     */
    public function test_parses_recurring_daily(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 5, 21, 12, 0, 0, 'Europe/Moscow'));

        $dto = $this->parser->parse('каждый день в 11:00 зарядка', 'Europe/Moscow');

        $this->assertTrue($dto->success);
        $this->assertEquals('зарядка', $dto->text);
        $this->assertEquals('daily', $dto->recurrenceType);
        // 11:00 MSK = 08:00 UTC
        $this->assertEquals(
            Carbon::create(2026, 5, 21, 8, 0, 0, 'UTC')->toIso8601String(),
            $dto->targetAt->toIso8601String()
        );
    }

    /**
     * Регрессионный тест на баг "завтра"/"послезавтра": слово "завтра" является подстрокой
     * слова "послезавтра", из-за чего наивное сопоставление регуляркой путало эти два случая.
     * Проверяем оба слова по отдельности и с одинаковым текстовым окружением.
     */
    #[DataProvider('tomorrowVsDayAfterTomorrowProvider')]
    public function test_distinguishes_tomorrow_from_day_after_tomorrow(
        string $inputText,
        int $expectedDay,
        string $expectedCleanedText
    ): void {
        Carbon::setTestNow(Carbon::create(2026, 5, 21, 12, 0, 0, 'Europe/Moscow'));

        $dto = $this->parser->parse($inputText, 'Europe/Moscow');

        $this->assertTrue($dto->success);
        $this->assertEquals($expectedCleanedText, $dto->text);
        $this->assertEquals(
            Carbon::create(2026, 5, $expectedDay, 9, 0, 0, 'Europe/Moscow')->setTimezone('UTC')->toIso8601String(),
            $dto->targetAt->toIso8601String()
        );
    }

    public static function tomorrowVsDayAfterTomorrowProvider(): array
    {
        return [
            'завтра одно' => ['завтра сходить в спортзал', 22, 'сходить в спортзал'],
            'опечатка завтро' => ['завтро сходить в спортзал', 22, 'сходить в спортзал'],
            'послезавтра одно' => ['послезавтра сходить в спортзал', 23, 'сходить в спортзал'],
            'после завтра раздельно' => ['после завтра сходить в спортзал', 23, 'сходить в спортзал'],
            'после-завтра через дефис' => ['после-завтра сходить в спортзал', 23, 'сходить в спортзал'],
            'опечатка послезавтро' => ['послезавтро сходить в спортзал', 23, 'сходить в спортзал'],
            // "послезавтра" содержит "завтра" как подстроку — раньше это приводило к тому,
            // что распознавалось "+1 день" вместо "+2 дня" и в тексте оставался мусор "после".
            'послезавтра с похожим контекстом' => ['послезавтра купить билеты', 23, 'купить билеты'],
            'завтра с похожим контекстом' => ['завтра купить билеты', 22, 'купить билеты'],
        ];
    }

    #[DataProvider('todayTypoProvider')]
    public function test_parses_common_today_typos(string $input): void
    {
        Carbon::setTestNow(Carbon::create(2026, 5, 21, 8, 0, 0, 'Europe/Moscow'));

        $dto = $this->parser->parse($input, 'Europe/Moscow');

        $this->assertTrue($dto->success);
        $this->assertSame('проверить новости', $dto->text);
        $this->assertSame('2026-05-21 09:00', $dto->targetAt->setTimezone('Europe/Moscow')->format('Y-m-d H:i'));
    }

    public static function todayTypoProvider(): array
    {
        return [
            'сегодня' => ['сегодня проверить новости'],
            'севодня' => ['севодня проверить новости'],
            'сигодня' => ['сигодня проверить новости'],
        ];
    }

    /**
     * Если в тексте нет ни явной/относительной даты, ни времени, ни периодичности,
     * парсер не должен "угадывать" момент времени (например, старым способом "+1 час").
     * Вместо этого должен возвращаться success=false и needsClarification=true, а targetAt —
     * оставаться текущим моментом (а не выдуманным будущим временем), чтобы вызывающий код
     * мог однозначно понять, что нужно переспросить пользователя.
     */
    #[DataProvider('unparseableInputProvider')]
    public function test_does_not_guess_time_when_nothing_could_be_parsed(string $inputText): void
    {
        $frozenNow = Carbon::create(2026, 5, 21, 12, 0, 0, 'Europe/Moscow');
        Carbon::setTestNow($frozenNow);

        $dto = $this->parser->parse($inputText, 'Europe/Moscow');

        $this->assertFalse($dto->success);
        $this->assertTrue($dto->needsClarification);
        // targetAt должен остаться текущим моментом, а не подставленным "+1 час" или любым
        // другим угаданным будущим временем.
        $this->assertEquals(
            $frozenNow->copy()->setTimezone('UTC')->toIso8601String(),
            $dto->targetAt->toIso8601String()
        );
    }

    public static function unparseableInputProvider(): array
    {
        return [
            'просто текст без даты и времени' => ['купить молоко'],
            'только триггерное слово' => ['напомни'],
            'пустая строка' => [''],
            'бытовая фраза без временных маркеров' => ['не забыть про важную встречу'],
            // Единица измерения "секунд" не поддерживается парсером - не должно приниматься
            // как валидное относительное время.
            'через с неподдерживаемой единицей измерения' => ['через 5 секунд проверить кофе'],
        ];
    }

    /**
     * Если распознана только дата (без явного времени), парсер должен успешно завершиться,
     * применив время по умолчанию (09:00), а не откатываться в состояние "не распознано".
     */
    public function test_specific_date_without_time_defaults_to_nine_am(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 5, 21, 12, 0, 0, 'Europe/Moscow'));

        $dto = $this->parser->parse('30 мая сдать проект', 'Europe/Moscow');

        $this->assertTrue($dto->success);
        $this->assertFalse($dto->needsClarification);
        $this->assertEquals('сдать проект', $dto->text);
        $this->assertEquals(
            Carbon::create(2026, 5, 30, 9, 0, 0, 'Europe/Moscow')->setTimezone('UTC')->toIso8601String(),
            $dto->targetAt->toIso8601String()
        );
    }

    /**
     * Конкретная дата в формате "ДД.ММ.ГГГГ" вместе с временем.
     */
    public function test_specific_date_with_dot_format_and_year(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 5, 21, 12, 0, 0, 'Europe/Moscow'));

        $dto = $this->parser->parse('21.06.2026 в 10:00 сдать отчет', 'Europe/Moscow');

        $this->assertTrue($dto->success);
        $this->assertEquals('сдать отчет', $dto->text);
        $this->assertEquals(
            Carbon::create(2026, 6, 21, 10, 0, 0, 'Europe/Moscow')->setTimezone('UTC')->toIso8601String(),
            $dto->targetAt->toIso8601String()
        );
    }

    /**
     * Если указанная дата (без года) в этом году уже прошла, она должна автоматически
     * переноситься на следующий год.
     */
    public function test_specific_date_already_passed_this_year_rolls_to_next_year(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 5, 21, 12, 0, 0, 'Europe/Moscow'));

        $dto = $this->parser->parse('10 января в 9:00 сделать бэкап', 'Europe/Moscow');

        $this->assertTrue($dto->success);
        $this->assertEquals(
            Carbon::create(2027, 1, 10, 9, 0, 0, 'Europe/Moscow')->setTimezone('UTC')->toIso8601String(),
            $dto->targetAt->toIso8601String()
        );
    }

    /**
     * Периодичности, которые заявлены как поддерживаемые парсером: по будням, еженедельно
     * (конкретный день недели) и ежемесячно.
     */
    #[DataProvider('recurrenceProvider')]
    public function test_parses_recurrence_variants(
        string $inputText,
        string $expectedType,
        ?string $expectedValue,
        string $expectedText
    ): void {
        Carbon::setTestNow(Carbon::create(2026, 5, 21, 12, 0, 0, 'Europe/Moscow')); // Четверг

        $dto = $this->parser->parse($inputText, 'Europe/Moscow');

        $this->assertTrue($dto->success);
        $this->assertFalse($dto->needsClarification);
        $this->assertEquals($expectedType, $dto->recurrenceType);
        $this->assertEquals($expectedValue, $dto->recurrenceValue);
        $this->assertEquals($expectedText, $dto->text);
    }

    public static function recurrenceProvider(): array
    {
        return [
            'по будням' => ['по будням в 8:00 проснуться', 'workdays', null, 'проснуться'],
            'каждый месяц' => ['каждый месяц в 10:00 оплатить аренду', 'monthly', null, 'оплатить аренду'],
            'каждый понедельник (сдвиг на ближайший)' => [
                'каждый понедельник в 9:00 созвон с командой',
                'weekly',
                '1',
                'созвон с командой',
            ],
        ];
    }

    /**
     * "Каждый понедельник" должен сдвигать дату на ближайший будущий понедельник.
     * 21.05.2026 — четверг, ближайший понедельник — 25.05.2026.
     */
    public function test_weekly_recurrence_shifts_to_nearest_matching_weekday(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 5, 21, 12, 0, 0, 'Europe/Moscow'));

        $dto = $this->parser->parse('каждый понедельник в 9:00 созвон с командой', 'Europe/Moscow');

        $this->assertEquals(
            Carbon::create(2026, 5, 25, 9, 0, 0, 'Europe/Moscow')->setTimezone('UTC')->toIso8601String(),
            $dto->targetAt->toIso8601String()
        );
    }

    /**
     * Относительное время: часы и дни (в дополнение к уже покрытым минутам).
     */
    #[DataProvider('relativeTimeProvider')]
    public function test_parses_relative_hours_and_days(string $inputText, \Closure $expectedTargetAt): void
    {
        $frozenNow = Carbon::create(2026, 5, 21, 12, 0, 0, 'Europe/Moscow');
        Carbon::setTestNow($frozenNow);

        $dto = $this->parser->parse($inputText, 'Europe/Moscow');

        $this->assertTrue($dto->success);
        $this->assertEquals(
            $expectedTargetAt($frozenNow->copy())->setTimezone('UTC')->toIso8601String(),
            $dto->targetAt->toIso8601String()
        );
    }

    public static function relativeTimeProvider(): array
    {
        return [
            'через 2 часа' => [
                'через 2 часа проверить духовку',
                fn (Carbon $now) => $now->addHours(2),
            ],
            'через 3 дня' => [
                'через 3 дня сдать отчет',
                fn (Carbon $now) => $now->addDays(3),
            ],
        ];
    }

    /**
     * Время без даты: если время суток ещё не наступило сегодня, напоминание остаётся
     * на сегодня; если уже прошло — переносится на завтра (для одноразовых напоминаний).
     */
    public function test_time_only_stays_today_when_not_yet_passed(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 5, 21, 8, 0, 0, 'Europe/Moscow'));

        $dto = $this->parser->parse('в 23:00 полить цветы', 'Europe/Moscow');

        $this->assertTrue($dto->success);
        $this->assertEquals(
            Carbon::create(2026, 5, 21, 23, 0, 0, 'Europe/Moscow')->setTimezone('UTC')->toIso8601String(),
            $dto->targetAt->toIso8601String()
        );
    }

    public function test_time_only_rolls_over_to_next_day_when_already_passed_today(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 5, 21, 20, 0, 0, 'Europe/Moscow'));

        // Формат времени без минут: "в 9"
        $dto = $this->parser->parse('убрать посуду в 9', 'Europe/Moscow');

        $this->assertTrue($dto->success);
        $this->assertEquals(
            Carbon::create(2026, 5, 22, 9, 0, 0, 'Europe/Moscow')->setTimezone('UTC')->toIso8601String(),
            $dto->targetAt->toIso8601String()
        );
    }
}
