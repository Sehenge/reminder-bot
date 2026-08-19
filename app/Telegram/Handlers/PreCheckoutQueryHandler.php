<?php

namespace App\Telegram\Handlers;

use App\Telegram\TelegramBotWorkflow;

final readonly class PreCheckoutQueryHandler
{
    public function __construct(private TelegramBotWorkflow $workflow) {}

    /** @param array<string, mixed> $query */
    public function handle(array $query): void
    {
        $this->workflow->handlePreCheckoutQuery($query);
    }
}
