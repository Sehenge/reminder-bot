<?php

namespace App\DTO;

use InvalidArgumentException;

final readonly class SuccessfulPaymentDTO
{
    private function __construct(
        public string $chargeId,
        public int $amount,
        public string $currency,
        public string $payload,
    ) {}

    /** @param array<string, mixed> $payment */
    public static function fromArray(array $payment): self
    {
        $chargeId = $payment['telegram_payment_charge_id'] ?? null;
        $amount = $payment['total_amount'] ?? null;
        $currency = $payment['currency'] ?? null;
        $payload = $payment['invoice_payload'] ?? null;

        if (! is_string($chargeId) || $chargeId === '' || ! is_int($amount) || $amount <= 0
            || ! is_string($currency) || ! is_string($payload)) {
            throw new InvalidArgumentException('Malformed Telegram successful_payment payload.');
        }

        return new self($chargeId, $amount, $currency, $payload);
    }
}
