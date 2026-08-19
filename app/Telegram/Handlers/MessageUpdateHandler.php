<?php

namespace App\Telegram\Handlers;

use App\Telegram\TelegramBotWorkflow;

final readonly class MessageUpdateHandler
{
    public function __construct(private TelegramBotWorkflow $workflow) {}

    /** @param array<string, mixed> $message */
    public function handle(array $message): void
    {
        $this->workflow->handleMessage($message);
    }
}
