<?php

namespace App\DTO;

final readonly class CallbackQueryDTO
{
    /** @param array<int, string> $arguments */
    private function __construct(public string $action, public array $arguments) {}

    public static function fromData(string $data): self
    {
        $parts = explode('_', $data);

        return new self(array_shift($parts) ?: '', $parts);
    }
}
