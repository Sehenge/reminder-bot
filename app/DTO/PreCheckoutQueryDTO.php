<?php

namespace App\DTO;

use InvalidArgumentException;

final readonly class PreCheckoutQueryDTO
{
    private function __construct(
        public string $id,
        public int $userTelegramId,
        public int $amount,
        public string $currency,
        public string $payload,
    ) {}

    /** @param array<string, mixed> $query */
    public static function fromArray(array $query): self
    {
        $id = $query['id'] ?? null;
        $fromId = $query['from']['id'] ?? null;
        $amount = $query['total_amount'] ?? null;
        $currency = $query['currency'] ?? null;
        $payload = $query['invoice_payload'] ?? null;

        if (! is_string($id) || $id === '' || ! is_int($fromId) || ! is_int($amount) || $amount <= 0
            || ! is_string($currency) || $currency === '' || ! is_string($payload) || $payload === '') {
            throw new InvalidArgumentException('Malformed Telegram pre_checkout_query payload.');
        }

        return new self($id, $fromId, $amount, $currency, $payload);
    }
}
