<?php

use App\Http\Controllers\TelegramWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'name' => 'ReminderBot API',
        'version' => '1.0.0',
        'status' => 'active',
        'timestamp' => now()->toIso8601String(),
    ]);
});

// Наш эндпоинт для вебхука Telegram
Route::post('/webhook/telegram', TelegramWebhookController::class)->name('telegram.webhook');
