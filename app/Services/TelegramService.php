<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramService
{
    protected string $token;

    protected string $apiUrl;

    public function __construct()
    {
        $this->token = config('services.telegram.bot_token');
        $this->apiUrl = "https://api.telegram.org/bot{$this->token}";
    }

    /**
     * Отправка текстового сообщения
     */
    public function sendMessage(array $params): array
    {
        // По умолчанию включаем HTML форматирование, если не переопределено
        if (! isset($params['parse_mode'])) {
            $params['parse_mode'] = 'HTML';
        }

        return $this->call('sendMessage', $params);
    }

    /**
     * Редактирование текста сообщения
     */
    public function editMessageText(array $params): array
    {
        if (! isset($params['parse_mode'])) {
            $params['parse_mode'] = 'HTML';
        }

        return $this->call('editMessageText', $params);
    }

    /**
     * Ответ на Callback Query
     */
    public function answerCallbackQuery(array $params): array
    {
        return $this->call('answerCallbackQuery', $params);
    }

    /**
     * Отправка счета для оплаты (Telegram Stars)
     */
    public function sendInvoice(array $params): array
    {
        return $this->call('sendInvoice', $params);
    }

    /**
     * Подтверждение предварительной проверки платежа (PreCheckoutQuery)
     */
    public function answerPreCheckoutQuery(array $params): array
    {
        return $this->call('answerPreCheckoutQuery', $params);
    }

    public function refundStarPayment(int $userId, string $chargeId): array
    {
        return $this->call('refundStarPayment', [
            'user_id' => $userId,
            'telegram_payment_charge_id' => $chargeId,
        ]);
    }

    /**
     * Установка вебхука бота
     */
    public function setWebhook(string $url): array
    {
        return $this->call('setWebhook', ['url' => $url]);
    }

    /**
     * Базовый метод отправки HTTP-запроса к Telegram Bot API
     */
    protected function call(string $method, array $params = []): array
    {
        try {
            $response = Http::timeout(10)
                ->post("{$this->apiUrl}/{$method}", $params);

            if ($response->failed()) {
                Log::error("Telegram API Error [{$method}]: ".$response->body(), [
                    'params' => $params,
                ]);

                return [
                    'ok' => false,
                    'description' => $response->json('description', 'Unknown error'),
                ];
            }

            return $response->json();
        } catch (\Exception $e) {
            Log::error("Telegram Bot API Exception [{$method}]: ".$e->getMessage(), [
                'params' => $params,
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'ok' => false,
                'description' => $e->getMessage(),
            ];
        }
    }
}
