<?php

namespace Tests\Unit;

use App\Services\TokenatorReminderParserFallback;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TokenatorReminderParserFallbackTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.reminder_parser', [
            'base_url' => 'https://api.tokenator.test/v1',
            'api_key' => 'test-key',
            'models' => ['doubao-seed-2.0-lite', 'free-gemini-3.7-flash'],
            'timeout' => 2,
            'min_confidence' => 0.75,
        ]);
        Carbon::setTestNow(Carbon::create(2026, 8, 22, 12, 0, 0, 'Europe/Minsk'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_parses_valid_json_wrapped_in_markdown(): void
    {
        Http::fake([
            '*' => Http::response([
                'choices' => [[
                    'message' => ['content' => "```json\n{\"task\":\"купить корм коту\",\"local_datetime\":\"2026-08-24 19:00:00\",\"recurrence_type\":\"once\",\"recurrence_value\":null,\"confidence\":0.95}\n```"],
                ]],
            ]),
        ]);

        $dto = (new TokenatorReminderParserFallback)->parse(
            'напомни после завтра вечерком купить корм коту',
            'Europe/Minsk',
            'ru'
        );

        $this->assertNotNull($dto);
        $this->assertTrue($dto->success);
        $this->assertSame('купить корм коту', $dto->text);
        $this->assertSame('2026-08-24 16:00:00 UTC', $dto->targetAt->format('Y-m-d H:i:s T'));
        $this->assertSame('once', $dto->recurrenceType);
    }

    public function test_tries_backup_model_after_primary_failure(): void
    {
        Http::fakeSequence()
            ->push(['error' => ['message' => 'quota exceeded']], 429)
            ->push([
                'choices' => [[
                    'message' => ['content' => '{"task":"позвонить маме","local_datetime":"2026-08-23 09:00:00","recurrence_type":"once","recurrence_value":null,"confidence":0.9}'],
                ]],
            ]);

        $dto = (new TokenatorReminderParserFallback)->parse('когда-нибудь позвонить маме', 'Europe/Minsk', 'ru');

        $this->assertNotNull($dto);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request['model'] === 'free-gemini-3.7-flash');
    }

    public function test_normalizes_common_one_time_recurrence_alias(): void
    {
        Http::fake([
            '*' => Http::response([
                'choices' => [[
                    'message' => ['content' => '{"task":"купить корм коту","local_datetime":"2026-08-24 19:00:00","recurrence_type":"none","recurrence_value":null,"confidence":1}'],
                ]],
            ]),
        ]);

        $dto = (new TokenatorReminderParserFallback)->parse('через пару дней вечерком купить корм коту', 'Europe/Minsk', 'ru');

        $this->assertNotNull($dto);
        $this->assertSame('once', $dto->recurrenceType);
    }

    public function test_rejects_low_confidence_past_or_malformed_results(): void
    {
        foreach ([
            '{"task":"дело","local_datetime":"2026-08-24 19:00:00","recurrence_type":"once","confidence":0.4}',
            '{"task":"дело","local_datetime":"2026-08-21 19:00:00","recurrence_type":"once","confidence":0.9}',
            'not json',
        ] as $content) {
            Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => $content]]]])]);
            config()->set('services.reminder_parser.models', ['free-gemini-3.7-flash']);

            $this->assertNull((new TokenatorReminderParserFallback)->parse('непонятная фраза', 'Europe/Minsk', 'ru'));
        }
    }

    public function test_does_not_call_api_without_key(): void
    {
        config()->set('services.reminder_parser.api_key', '');
        Http::fake();

        $this->assertNull((new TokenatorReminderParserFallback)->parse('что-то', 'Europe/Minsk', 'ru'));
        Http::assertNothingSent();
    }
}
