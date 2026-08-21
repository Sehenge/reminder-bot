<?php

namespace Tests\Feature;

use App\Jobs\PublishTelegramChannelPostJob;
use App\Services\TelegramService;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class PublishTelegramChannelPostJobTest extends TestCase
{
    public function test_it_publishes_a_formatted_channel_message(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 11]])]);

        $job = new PublishTelegramChannelPostJob('message', [
            'chat_id' => '@QuietPingNews',
            'text' => '<b>Тестовый пост</b>',
        ]);
        $job->handle(app(TelegramService::class));

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'sendMessage')
            && $request['chat_id'] === '@QuietPingNews'
            && $request['parse_mode'] === 'HTML');
    }

    public function test_it_publishes_a_channel_poll(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 12]])]);

        $job = new PublishTelegramChannelPostJob('poll', [
            'chat_id' => '@QuietPingNews',
            'question' => 'Что добавить следующим?',
            'options' => json_encode(['Категории', 'Календарь']),
        ]);
        $job->handle(app(TelegramService::class));

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'sendPoll')
            && $request['question'] === 'Что добавить следующим?');
    }

    public function test_it_fails_for_a_rejected_post_so_the_queue_can_retry(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Forbidden'], 403)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Forbidden');

        (new PublishTelegramChannelPostJob('message', [
            'chat_id' => '@QuietPingNews',
            'text' => 'Тестовый пост',
        ]))->handle(app(TelegramService::class));
    }
}
