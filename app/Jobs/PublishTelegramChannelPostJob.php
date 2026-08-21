<?php

namespace App\Jobs;

use App\Services\TelegramService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

class PublishTelegramChannelPostJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $type,
        public array $payload,
    ) {}

    public function handle(TelegramService $telegram): void
    {
        $response = match ($this->type) {
            'message' => $telegram->sendMessage($this->payload),
            'poll' => $telegram->sendPoll($this->payload),
            default => throw new RuntimeException("Unsupported channel post type: {$this->type}"),
        };

        if (($response['ok'] ?? false) !== true) {
            throw new RuntimeException('Telegram rejected scheduled channel post: '.($response['description'] ?? 'unknown error'));
        }
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60, 300, 900];
    }
}
