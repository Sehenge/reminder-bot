<?php

namespace App\Telegram\Handlers;

use App\DTO\TelegramUpdateDTO;
use App\Models\TelegramUpdate;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

final readonly class TelegramUpdateHandler
{
    public function __construct(
        private MessageUpdateHandler $messages,
        private CallbackQueryHandler $callbacks,
        private PreCheckoutQueryHandler $preCheckout,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        if (! $this->hasValidSecret($request)) {
            Log::warning('Telegram webhook rejected: invalid or missing secret token header.');

            return response()->json(['status' => 'forbidden'], 403);
        }

        try {
            $update = TelegramUpdateDTO::fromArray($request->all());
        } catch (InvalidArgumentException) {
            Log::warning('Telegram webhook received an invalid update payload.');

            return response()->json(['status' => $request->all() === [] ? 'empty update' : 'invalid update'], 400);
        }

        if ($this->reserveUpdate($update->id)) {
            return response()->json(['status' => 'duplicate']);
        }

        try {
            if ($query = $update->preCheckoutQuery()) {
                $this->preCheckout->handle($query);
            } elseif ($message = $update->message()) {
                $this->messages->handle($message);
            } elseif ($callback = $update->callbackQuery()) {
                $this->callbacks->handle($callback);
            } else {
                Log::info("Telegram update {$update->id} has no supported handler.");
            }
        } catch (Throwable $exception) {
            Log::error("Error handling Telegram update {$update->id}: {$exception->getMessage()}", [
                'update_id' => $update->id,
                'exception' => $exception::class,
            ]);
        }

        return response()->json(['status' => 'ok']);
    }

    private function hasValidSecret(Request $request): bool
    {
        $expected = (string) config('services.telegram.webhook_secret', '');

        return $expected === '' || hash_equals($expected, (string) $request->header('X-Telegram-Bot-Api-Secret-Token', ''));
    }

    private function reserveUpdate(int $updateId): bool
    {
        try {
            TelegramUpdate::query()->create(['update_id' => $updateId]);

            return false;
        } catch (QueryException $exception) {
            if ($exception->getCode() === '23000') {
                return true;
            }

            throw $exception;
        }
    }
}
