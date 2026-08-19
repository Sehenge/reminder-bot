<?php

namespace App\Http\Controllers;

use App\Telegram\Handlers\TelegramUpdateHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request, TelegramUpdateHandler $handler): JsonResponse
    {
        return $handler->handle($request);
    }
}
