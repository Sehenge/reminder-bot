<?php

namespace App\DTO;

use InvalidArgumentException;

final readonly class TelegramUpdateDTO
{
    /** @param array<string, mixed> $payload */
    private function __construct(public int $id, public array $payload) {}

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        if (! is_int($payload['update_id'] ?? null)) {
            throw new InvalidArgumentException('Telegram update_id must be an integer.');
        }

        return new self($payload['update_id'], $payload);
    }

    /** @return array<string, mixed>|null */
    public function message(): ?array
    {
        return is_array($this->payload['message'] ?? null) ? $this->payload['message'] : null;
    }

    /** @return array<string, mixed>|null */
    public function callbackQuery(): ?array
    {
        return is_array($this->payload['callback_query'] ?? null) ? $this->payload['callback_query'] : null;
    }

    /** @return array<string, mixed>|null */
    public function preCheckoutQuery(): ?array
    {
        return is_array($this->payload['pre_checkout_query'] ?? null) ? $this->payload['pre_checkout_query'] : null;
    }
}
