<?php

namespace Tests\Unit;

use App\DTO\CallbackQueryDTO;
use App\DTO\TelegramUpdateDTO;
use App\Enums\RecurrenceType;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class TelegramUpdateDTOTest extends TestCase
{
    public function test_it_exposes_supported_update_payloads(): void
    {
        $dto = TelegramUpdateDTO::fromArray([
            'update_id' => 42,
            'message' => ['text' => 'hello'],
        ]);

        $this->assertSame(42, $dto->id);
        $this->assertSame(['text' => 'hello'], $dto->message());
        $this->assertNull($dto->callbackQuery());
    }

    public function test_it_rejects_an_update_without_integer_id(): void
    {
        $this->expectException(InvalidArgumentException::class);

        TelegramUpdateDTO::fromArray(['update_id' => '42']);
    }

    public function test_callback_data_is_split_into_action_and_arguments(): void
    {
        $dto = CallbackQueryDTO::fromData('snooze_15_tomorrow');

        $this->assertSame('snooze', $dto->action);
        $this->assertSame(['15', 'tomorrow'], $dto->arguments);
    }

    public function test_recurrence_enum_owns_user_facing_labels(): void
    {
        $this->assertSame('Каждую неделю', RecurrenceType::Weekly->label());
    }
}
