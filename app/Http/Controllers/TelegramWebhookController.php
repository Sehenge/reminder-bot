<?php

namespace App\Http\Controllers;

use App\Models\PremiumSubscription;
use App\Models\Reminder;
use App\Models\TelegramUpdate;
use App\Models\User;
use App\Services\NaturalLanguageParserService;
use App\Services\TelegramService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TelegramWebhookController extends Controller
{
    protected TelegramService $telegram;

    protected NaturalLanguageParserService $parser;

    public function __construct(TelegramService $telegram, NaturalLanguageParserService $parser)
    {
        $this->telegram = $telegram;
        $this->parser = $parser;
    }

    /**
     * Основная точка входа для Webhook Telegram Bot API
     */
    public function handle(Request $request): JsonResponse
    {
        // 0. Проверяем секретный токен вебхука (см. config/services.php -> telegram.webhook_secret
        // и комментарий там же о том, как задать его через Telegram Bot API setWebhook).
        if (! $this->hasValidSecretToken($request)) {
            Log::warning('Telegram webhook rejected: invalid or missing secret token header.');

            return response()->json(['status' => 'forbidden'], 403);
        }

        $update = $request->all();

        if (empty($update)) {
            Log::warning('Telegram webhook received an empty or malformed payload.');

            return response()->json(['status' => 'empty update'], 400);
        }

        // Валидируем структуру update: нам обязательно нужен update_id для идемпотентности.
        // Не логируем содержимое update целиком, чтобы не утечь текст сообщений/имена пользователей.
        $updateId = $update['update_id'] ?? null;

        if (! is_int($updateId)) {
            Log::warning('Telegram webhook received an update without a valid update_id.', [
                'has_message' => isset($update['message']),
                'has_callback_query' => isset($update['callback_query']),
                'has_pre_checkout_query' => isset($update['pre_checkout_query']),
            ]);

            return response()->json(['status' => 'invalid update'], 400);
        }

        // Идемпотентность по update_id: атомарно резервируем update_id перед обработкой.
        // Если такой update_id уже встречался (повторная доставка от Telegram), просто
        // отвечаем 200 без повторной обработки (без повторного создания напоминаний/ответов).
        if ($this->isDuplicateUpdate($updateId)) {
            Log::info("Telegram webhook: duplicate update_id {$updateId} ignored.");

            return response()->json(['status' => 'duplicate']);
        }

        try {
            // 1. Обработка платежей (PreCheckoutQuery)
            if (isset($update['pre_checkout_query']) && is_array($update['pre_checkout_query'])) {
                $this->handlePreCheckoutQuery($update['pre_checkout_query']);

                return response()->json(['status' => 'ok']);
            }

            // 2. Обработка сообщений (Message)
            if (isset($update['message']) && is_array($update['message'])) {
                $this->handleMessage($update['message']);

                return response()->json(['status' => 'ok']);
            }

            // 3. Обработка Callback Query (нажатия на кнопки)
            if (isset($update['callback_query']) && is_array($update['callback_query'])) {
                $this->handleCallbackQuery($update['callback_query']);

                return response()->json(['status' => 'ok']);
            }

            Log::info("Telegram webhook: update {$updateId} has no supported handler (unknown type).");
        } catch (\Exception $e) {
            Log::error("Error handling Telegram update {$updateId}: ".$e->getMessage(), [
                'update_id' => $updateId,
                'exception' => get_class($e),
            ]);
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * Проверка заголовка X-Telegram-Bot-Api-Secret-Token против настроенного секрета.
     * Если секрет не настроен (services.telegram.webhook_secret пуст), проверка пропускается —
     * это осознанное поведение для локальной разработки, но небезопасно для production.
     */
    protected function hasValidSecretToken(Request $request): bool
    {
        $expectedSecret = config('services.telegram.webhook_secret');

        if (empty($expectedSecret)) {
            return true;
        }

        $providedSecret = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');

        return hash_equals((string) $expectedSecret, $providedSecret);
    }

    /**
     * Атомарно резервирует update_id в таблице telegram_updates.
     * Возвращает true, если update_id уже был обработан ранее (дубликат).
     */
    protected function isDuplicateUpdate(int $updateId): bool
    {
        try {
            TelegramUpdate::query()->create(['update_id' => $updateId]);

            return false;
        } catch (QueryException $e) {
            // Код 23000 — нарушение уникального индекса (SQLite/MySQL/PostgreSQL):
            // update_id уже был зарезервирован другим запросом.
            if ($e->getCode() === '23000') {
                return true;
            }

            throw $e;
        }
    }

    /**
     * Обработка обычных текстовых сообщений и команд
     */
    protected function handleMessage(array $message): void
    {
        $chatId = $message['chat']['id'] ?? null;
        $fromId = $message['from']['id'] ?? null;
        $text = $message['text'] ?? '';

        if (! $fromId || ! $chatId || ! is_array($message['from'] ?? null)) {
            Log::warning('Telegram webhook received a message without a valid from/chat id.');

            return;
        }

        // Создаем или получаем пользователя в БД
        $user = User::firstOrCreate(
            ['telegram_id' => $fromId],
            [
                'username' => $message['from']['username'] ?? null,
                'first_name' => $message['from']['first_name'] ?? null,
                'last_name' => $message['from']['last_name'] ?? null,
                'language_code' => $message['from']['language_code'] ?? 'ru',
                'timezone' => 'Europe/Moscow', // По умолчанию ставим часовой пояс МСК
                'state' => null,
            ]
        );

        // Обновляем профиль Telegram при каждом входящем update, а не только при первом создании,
        // чтобы отражать актуальные username/имя/фамилию/язык пользователя.
        $this->syncTelegramProfile($user, $message['from']);

        // Обработка платежа успешной покупки Premium (Successful Payment)
        if (isset($message['successful_payment'])) {
            $this->handleSuccessfulPayment($user, $message['successful_payment']);

            return;
        }

        // Если пользователь находится в каком-то состоянии FSM (например, ждет ввода часового пояса или категории)
        if ($user->state) {
            $this->handleUserState($user, $text, $chatId);

            return;
        }

        // Обработка команд
        if (str_starts_with($text, '/')) {
            // Команды в группах/супергруппах Telegram могут приходить с суффиксом имени бота,
            // например "/start@ReminderBot" — обрабатываем их так же, как "/start".
            $command = explode('@', explode(' ', $text)[0])[0];
            switch ($command) {
                case '/start':
                    $this->sendStartMessage($user, $chatId);
                    break;
                case '/help':
                    $this->sendHelpMessage($user, $chatId);
                    break;
                case '/list':
                    $this->sendListMessage($user, $chatId);
                    break;
                case '/premium':
                    $this->sendPremiumMessage($user, $chatId);
                    break;
                case '/timezone':
                    $this->askTimezone($user, $chatId);
                    break;
                default:
                    $this->telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => '❓ Неизвестная команда. Введите /help для просмотра списка команд.',
                    ]);
            }

            return;
        }

        // Если это обычный текст - парсим как естественный язык для напоминания
        $this->parseAndConfirmReminder($user, $text, $chatId);
    }

    /**
     * Обработка нажатий на инлайн-кнопки
     */
    protected function handleCallbackQuery(array $callbackQuery): void
    {
        $callbackQueryId = $callbackQuery['id'] ?? null;
        $fromId = $callbackQuery['from']['id'] ?? null;
        $data = $callbackQuery['data'] ?? '';
        $message = $callbackQuery['message'] ?? null;
        $chatId = $message['chat']['id'] ?? null;
        $messageId = $message['message_id'] ?? null;

        if (! $callbackQueryId || ! $fromId || ! is_array($callbackQuery['from'] ?? null)) {
            Log::warning('Telegram webhook received a callback_query without a valid id/from.');

            return;
        }

        $user = User::where('telegram_id', $fromId)->first();
        if (! $user) {
            return;
        }

        // Обновляем профиль Telegram при каждом входящем update, а не только при первом создании.
        $this->syncTelegramProfile($user, $callbackQuery['from']);

        // Быстрый ответ чтобы убрать "часики" загрузки на кнопке
        $this->telegram->answerCallbackQuery(['callback_query_id' => $callbackQueryId]);

        if (! $chatId || ! $messageId) {
            Log::warning('Telegram webhook received a callback_query without a valid chat/message id.');

            return;
        }

        // Парсинг переданных параметров из даты кнопки, например: "snooze_123_10"
        $parts = explode('_', $data);
        $action = $parts[0];

        switch ($action) {
            case 'complete': // Отметить выполненным
                $reminderId = $parts[1] ?? null;
                $reminder = Reminder::where('id', $reminderId)->where('user_id', $user->id)->first();
                if ($reminder) {
                    $reminder->update([
                        'is_completed' => true,
                        'completed_at' => Carbon::now(),
                    ]);
                    $this->telegram->editMessageText([
                        'chat_id' => $chatId,
                        'message_id' => $messageId,
                        'text' => '✅ Напоминание выполнено: <s>'.e($reminder->text).'</s>',
                    ]);
                }
                break;

            case 'snooze': // Отложить
                $reminderId = $parts[1] ?? null;
                $minutes = (int) ($parts[2] ?? 10);
                $reminder = Reminder::where('id', $reminderId)->where('user_id', $user->id)->first();
                if ($reminder) {
                    $newTime = Carbon::now()->addMinutes($minutes);
                    $reminder->update([
                        'target_at' => $newTime,
                        'is_completed' => false,
                    ]);

                    $timeFormatted = $newTime->setTimezone($user->timezone)->format('H:i');
                    $this->telegram->editMessageText([
                        'chat_id' => $chatId,
                        'message_id' => $messageId,
                        'text' => '⏳ Напоминание <b>'.e($reminder->text)."</b> отложено на {$minutes} мин (до {$timeFormatted}).",
                    ]);
                }
                break;

            case 'delete': // Удалить напоминание
                $reminderId = $parts[1] ?? null;
                $reminder = Reminder::where('id', $reminderId)->where('user_id', $user->id)->first();
                if ($reminder) {
                    $reminderText = $reminder->text;
                    $reminder->delete();
                    $this->telegram->editMessageText([
                        'chat_id' => $chatId,
                        'message_id' => $messageId,
                        'text' => '❌ Напоминание «'.e($reminderText).'» успешно удалено.',
                    ]);
                }
                break;

            case 'confirm': // Подтверждение создания напоминания из парсера
                $this->saveParsedReminder($user, $chatId, $messageId, $parts);
                break;

            case 'cancel': // Отмена действия
                // При отмене всегда очищаем состояние FSM и его данные, иначе пользователь
                // может застрять (например, в ожидании ввода часового пояса) или следующий
                // текст будет ошибочно интерпретирован как продолжение отменённого сценария.
                $this->clearState($user);
                $this->telegram->editMessageText([
                    'chat_id' => $chatId,
                    'message_id' => $messageId,
                    'text' => '🚫 Действие отменено.',
                ]);
                break;

            case 'buypremium': // Покупка Премиум через Stars
                $this->sendStarsInvoice($user, $chatId);
                break;

            case 'list': // Кнопка "Мои напоминания" (callback_data: list_active)
                if (($parts[1] ?? null) === 'active') {
                    $this->sendListMessage($user, $chatId);
                }
                break;

            case 'timezone': // Кнопка "Изменить часовой пояс" (callback_data: timezone_ask)
                if (($parts[1] ?? null) === 'ask') {
                    $this->askTimezone($user, $chatId);
                }
                break;

            case 'settimezone': // Установка часового пояса по кнопке
                $tz = $parts[1] ?? 'Europe/Moscow';
                // Пользователь сделал выбор — очищаем FSM-состояние (askTimezone выставляет
                // state = wait_timezone), иначе следующее сообщение пользователя будет
                // ошибочно обработано как попытка ввести часовой пояс текстом.
                $user->update(['timezone' => $tz]);
                $this->clearState($user);
                $this->telegram->editMessageText([
                    'chat_id' => $chatId,
                    'message_id' => $messageId,
                    'text' => "✅ Установлен часовой пояс: <b>{$tz}</b>. Теперь все напоминания будут приходить вовремя!",
                ]);
                break;
        }
    }

    /**
     * Очистить состояние FSM (state) и сопутствующие временные данные (state_data) пользователя.
     * Должно вызываться на любом завершении диалога: успешном выборе, отмене или истечении/невалидности.
     */
    protected function clearState(User $user): void
    {
        $user->update([
            'state' => null,
            'state_data' => null,
        ]);
    }

    /**
     * Обновить сохранённые данные профиля Telegram пользователя (username/имя/фамилия/язык),
     * если они изменились с прошлого раза. Вызывается на каждом входящем update, а не
     * только при первом создании пользователя.
     *
     * @param  array<string, mixed>  $from
     */
    protected function syncTelegramProfile(User $user, array $from): void
    {
        $updates = [
            'username' => $from['username'] ?? null,
            'first_name' => $from['first_name'] ?? null,
            'last_name' => $from['last_name'] ?? null,
            'language_code' => $from['language_code'] ?? $user->language_code,
        ];

        $changed = [];
        foreach ($updates as $key => $value) {
            if ($user->{$key} !== $value) {
                $changed[$key] = $value;
            }
        }

        if ($changed !== []) {
            $user->update($changed);
        }
    }

    /**
     * Приветственное сообщение (/start)
     */
    protected function sendStartMessage(User $user, int $chatId): void
    {
        $name = $user->first_name ?: 'Пользователь';
        $text = "👋 Привет, <b>{$name}</b>!\n\n"
              ."Я — <b>ReminderBot</b>, твой умный помощник для напоминаний и задач. 🚀\n\n"
              ."✍️ Просто напиши мне сообщение обычным языком, например:\n"
              ."• <i>«Напомнить купить хлеб через 15 минут»</i>\n"
              ."• <i>«Завтра в 18:00 созвон с мамой»</i>\n"
              ."• <i>«Каждый понедельник в 9:00 планерка»</i>\n\n"
              ."⚙️ Мой часовой пояс по умолчанию: <b>{$user->timezone}</b>.\n"
              ."Если хочешь его изменить, используй команду /timezone.\n\n"
              .'ℹ️ Введи /help для подробной справки по всем функциям.';

        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => $text,
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [
                        ['text' => '📝 Мои напоминания', 'callback_data' => 'list_active'],
                        ['text' => '⭐ Премиум', 'callback_data' => 'buypremium'],
                    ],
                    [
                        ['text' => '⚙️ Изменить часовой пояс', 'callback_data' => 'timezone_ask'],
                    ],
                ],
            ]),
        ]);
    }

    /**
     * Справка (/help)
     */
    protected function sendHelpMessage(User $user, int $chatId): void
    {
        $text = "📚 <b>Справка по командам ReminderBot</b>\n\n"
              ."/start — Запустить бота и получить приветствие\n"
              ."/help — Список всех команд и примеры использования\n"
              ."/list — Показать список активных и выполненных напоминаний\n"
              ."/premium — Подписка Премиум и покупка за Telegram Stars\n"
              ."/timezone — Настройка часового пояса\n\n"
              ."🤖 <b>Примеры фраз для создания напоминания:</b>\n"
              ."• <i>«Напомни выпить витамины в 21:00»</i>\n"
              ."• <i>«Завтра в 14:30 визит к стоматологу»</i>\n"
              ."• <i>«30 мая в 10:00 сдать отчет по проекту»</i>\n"
              ."• <i>«Каждый день в 11:00 разминка»</i> (Повторяющееся)\n"
              ."• <i>«Каждый понедельник в 9:00 созвон»</i> (Повторяющееся)\n"
              ."• <i>«По будням в 8:00 проснуться»</i> (Повторяющееся)\n"
              ."• <i>«Через 30 минут вытащить пирог»</i>\n\n"
              ."👑 <b>Преимущества Премиума:</b>\n"
              ."• Неограниченное количество напоминаний (без Премиума — макс. 30)\n"
              ."• Совместные списки задач и групп\n"
              .'• Приоритетная поддержка и отсутствие рекламы';

        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => $text,
        ]);
    }

    /**
     * Запрос часового пояса (/timezone)
     */
    protected function askTimezone(User $user, int $chatId): void
    {
        $text = "🌍 <b>Настройка часового пояса</b>\n\n"
              ."Текущий часовой пояс: <b>{$user->timezone}</b>\n\n"
              .'Пожалуйста, выберите подходящий часовой пояс из кнопок ниже или отправьте мне текстовым сообщением в формате <code>Region/City</code> (например, <code>Europe/London</code>):';

        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => $text,
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [
                        ['text' => 'MSK (UTC+3)', 'callback_data' => 'settimezone_Europe/Moscow'],
                        ['text' => 'KGD (UTC+2)', 'callback_data' => 'settimezone_Europe/Kaliningrad'],
                    ],
                    [
                        ['text' => 'YEKT (UTC+5)', 'callback_data' => 'settimezone_Asia/Yekaterinburg'],
                        ['text' => 'OMST (UTC+6)', 'callback_data' => 'settimezone_Asia/Omsk'],
                    ],
                    [
                        ['text' => 'KRAT (UTC+7)', 'callback_data' => 'settimezone_Asia/Krasnoyarsk'],
                        ['text' => 'NOVT (UTC+7)', 'callback_data' => 'settimezone_Asia/Novosibirsk'],
                    ],
                    [
                        ['text' => 'IRKT (UTC+8)', 'callback_data' => 'settimezone_Asia/Irkutsk'],
                        ['text' => 'VLAT (UTC+10)', 'callback_data' => 'settimezone_Asia/Vladivostok'],
                    ],
                    [
                        ['text' => 'Cancel', 'callback_data' => 'cancel'],
                    ],
                ],
            ]),
        ]);

        // Начинаем новый шаг FSM — очищаем любые устаревшие state_data от предыдущего
        // незавершённого сценария (например, неподтверждённое напоминание из парсера).
        $user->update([
            'state' => 'wait_timezone',
            'state_data' => null,
        ]);
    }

    /**
     * Обработка текстового сообщения, когда пользователь находится в определенном состоянии (FSM)
     */
    protected function handleUserState(User $user, string $text, int $chatId): void
    {
        if ($user->state === 'wait_timezone') {
            // Пробуем распознать введенный часовой пояс
            try {
                $tz = trim($text);
                // Проверяем валидность часового пояса в PHP
                new \DateTimeZone($tz);

                $user->update(['timezone' => $tz]);
                $this->clearState($user);

                $this->telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => "✅ Часовой пояс успешно изменен на: <b>{$tz}</b>!",
                ]);
            } catch (\Exception $e) {
                $this->telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => '❌ Неверный формат часового пояса. Пожалуйста, отправьте правильный часовой пояс, например: <code>Europe/Moscow</code> или <code>Asia/Baku</code>.',
                ]);
            }

            return;
        }

        // Неизвестное/устаревшее значение state (не должно происходить в норме, но если
        // БД содержит значение, которое мы больше не обрабатываем, — не оставляем
        // пользователя "залипшим" в нём навсегда, а сбрасываем FSM и просим начать заново.
        Log::warning("Telegram webhook: unknown FSM state '{$user->state}' encountered, resetting.");
        $this->clearState($user);
        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => '⚠️ Сессия истекла или устарела. Пожалуйста, повторите запрос.',
        ]);
    }

    /**
     * Парсинг естественного языка и вывод карточки подтверждения
     */
    protected function parseAndConfirmReminder(User $user, string $text, int $chatId): void
    {
        // Проверяем лимиты бесплатных напоминаний
        if (! $user->canCreateReminder()) {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "⚠️ <b>Достигнут лимит напоминаний!</b>\n\nВ бесплатной версии можно создавать не более 30 активных напоминаний.\n\nПриобретите Premium за Telegram Stars, чтобы убрать все ограничения!",
                'reply_markup' => json_encode([
                    'inline_keyboard' => [
                        [['text' => '⭐ Активировать Premium за Stars', 'callback_data' => 'buypremium']],
                    ],
                ]),
            ]);

            return;
        }

        $dto = $this->parser->parse($text, $user->timezone);

        // Если дату/время не удалось уверенно распознать — не подставляем "на глаз" время
        // и не предлагаем сохранить напоминание, а просто просим уточнить формулировку.
        if ($dto->needsClarification) {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "🤔 Не удалось точно определить дату и время напоминания.\n\n"
                         .'Попробуйте переформулировать, например: <b>«завтра в 15:00 позвонить маме»</b> или <b>«через 2 часа проверить почту»</b>.',
            ]);

            return;
        }

        // Переводим время в часовой пояс пользователя для корректного отображения в боте
        $localTargetTime = $dto->targetAt->copy()->setTimezone($user->timezone);

        $recurrenceLabels = [
            'once' => 'Одноразово',
            'daily' => 'Каждый день',
            'weekly' => 'Каждую неделю',
            'monthly' => 'Каждый месяц',
            'workdays' => 'По будням',
        ];
        $recLabel = $recurrenceLabels[$dto->recurrenceType] ?? 'Повторяющееся';

        $confirmText = "📝 <b>Создать новое напоминание?</b>\n\n"
                     .'📋 Текст: <b>'.e($dto->text)."</b>\n"
                     .'📅 Дата/время: <b>'.$localTargetTime->format('d.m.Y H:i')."</b> (часовой пояс: {$user->timezone})\n"
                     ."🔄 Повторение: <b>{$recLabel}</b>\n\n"
                     .'👍 Распознано автоматически.';

        // Кодируем данные для кнопки confirm
        // Чтобы вписаться в лимит 64 байт callback_data, передадим сжатую структуру во временное хранилище state_data пользователя
        $user->update([
            'state_data' => [
                'text' => $dto->text,
                'target_at' => $dto->targetAt->toIso8601String(),
                'recurrence_type' => $dto->recurrenceType,
                'recurrence_value' => $dto->recurrenceValue,
            ],
        ]);

        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => $confirmText,
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [
                        ['text' => '✅ Создать', 'callback_data' => 'confirm_save'],
                        ['text' => '❌ Отмена', 'callback_data' => 'cancel'],
                    ],
                ],
            ]),
        ]);
    }

    /**
     * Сохранение напоминания из подтвержденных данных (кнопка Создать)
     */
    protected function saveParsedReminder(User $user, int $chatId, int $messageId, array $parts): void
    {
        $stateData = $user->state_data;

        if (empty($stateData) || ! isset($stateData['text'])) {
            // Данные подтверждения отсутствуют или устарели (например, повторное нажатие
            // на старую кнопку после того, как сценарий уже завершился/был перезапущен) —
            // на всякий случай сбрасываем состояние, чтобы не оставить пользователя застрявшим.
            $this->clearState($user);
            $this->telegram->editMessageText([
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'text' => '❌ Ошибка сессии. Пожалуйста, напишите запрос заново.',
            ]);

            return;
        }

        // Создаем напоминание
        $reminder = Reminder::create([
            'user_id' => $user->id,
            'text' => $stateData['text'],
            'target_at' => Carbon::parse($stateData['target_at']),
            'recurrence_type' => $stateData['recurrence_type'],
            'recurrence_value' => $stateData['recurrence_value'],
            'is_completed' => false,
        ]);

        // Очищаем временные данные состояния
        $this->clearState($user);

        $localTime = Carbon::parse($reminder->target_at)->setTimezone($user->timezone);
        $recurrenceLabels = [
            'once' => 'Одноразово',
            'daily' => 'Каждый день',
            'weekly' => 'Каждую неделю',
            'monthly' => 'Каждый месяц',
            'workdays' => 'По будням',
        ];
        $recLabel = $recurrenceLabels[$reminder->recurrence_type] ?? 'Одноразово';

        $responseText = "🔔 <b>Напоминание успешно создано!</b>\n\n"
                      .'📌 Текст: <b>'.e($reminder->text)."</b>\n"
                      .'⏰ Время запуска: <b>'.$localTime->format('d.m.Y H:i')."</b>\n"
                      ."🔄 Повторение: <b>{$recLabel}</b>\n\n"
                      .'Я пришлю уведомление точно в указанное время!';

        $this->telegram->editMessageText([
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $responseText,
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [
                        ['text' => '❌ Удалить', 'callback_data' => "delete_{$reminder->id}"],
                    ],
                ],
            ]),
        ]);
    }

    /**
     * Показать список напоминаний (/list)
     */
    protected function sendListMessage(User $user, int $chatId): void
    {
        $reminders = Reminder::where('user_id', $user->id)
            ->where('is_completed', false)
            ->orderBy('target_at', 'asc')
            ->limit(15)
            ->get();

        if ($reminders->isEmpty()) {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => '📭 У вас пока нет активных напоминаний. Просто напишите фразу для создания, например <i>«Купить хлеб в 15:00»</i>.',
            ]);

            return;
        }

        $text = "📋 <b>Ваши активные напоминания (топ 15):</b>\n\n";
        $keyboard = [];

        foreach ($reminders as $index => $reminder) {
            $num = $index + 1;
            $localTime = $reminder->target_at->copy()->setTimezone($user->timezone);
            $formattedTime = $localTime->format('d.m.Y H:i');
            $typeIcon = $reminder->recurrence_type !== 'once' ? '🔄' : '📌';

            $text .= "{$num}. {$typeIcon} <b>".e($reminder->text)."</b> — <i>{$formattedTime}</i>\n";

            // Кнопки удаления для каждого элемента в списке
            $keyboard[] = [
                ['text' => "❌ {$num}", 'callback_data' => "delete_{$reminder->id}"],
                ['text' => "✅ {$num}", 'callback_data' => "complete_{$reminder->id}"],
            ];
        }

        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => $text,
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
        ]);
    }

    /**
     * Окно премиума (/premium)
     */
    protected function sendPremiumMessage(User $user, int $chatId): void
    {
        $isPremium = $user->hasPremium();
        $statusText = $isPremium
            ? '⭐ Ваш премиум статус: <b>Активен</b> (до '.$user->premium_expires_at->setTimezone($user->timezone)->format('d.m.Y H:i').')'
            : '❌ Ваш премиум статус: <b>Не активен</b>';

        $text = "👑 <b>ReminderBot Premium</b>\n\n"
              ."Активируйте Premium за Telegram Stars и откройте все возможности приложения:\n\n"
              ."🚀 <b>Преимущества Premium:</b>\n"
              ."• <b>Безлимитные напоминания</b> (убирает лимит в 30 активных задач)\n"
              ."• <b>Совместные списки</b> и группы для совместного ведения задач\n"
              ."• <b>Категории и теги</b> с красивой цветовой маркировкой задач\n"
              ."• <b>Web-дашборд</b> — удобный интерфейс в браузере\n"
              ."• <b>Экспорт в CSV / iCal</b> — сохранение задач в календарь\n"
              ."• <b>История на 6 месяцев</b>\n\n"
              ."{$statusText}\n\n"
              .'💰 Стоимость подписки: <b>50 ⭐️ (Telegram Stars)</b> в месяц.';

        $buttons = [];
        if (! $isPremium) {
            $buttons[] = [['text' => '💳 Купить Premium за 50 ⭐️', 'callback_data' => 'buypremium']];
        }

        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => $text,
            'reply_markup' => json_encode(['inline_keyboard' => $buttons]),
        ]);
    }

    /**
     * Отправка счета на оплату через Telegram Stars
     */
    protected function sendStarsInvoice(User $user, int $chatId): void
    {
        $this->telegram->sendInvoice([
            'chat_id' => $chatId,
            'title' => 'ReminderBot Premium',
            'description' => 'Активация Premium подписки на 30 дней в боте ReminderBot',
            'payload' => "premium_subscription_{$user->id}",
            'provider_token' => '', // Оставляем пустым для оплаты через Telegram Stars
            'currency' => 'XTR', // Валюта Telegram Stars
            'prices' => json_encode([
                ['label' => 'Premium 30 дней', 'amount' => 50], // Количество звезд
            ]),
        ]);
    }

    /**
     * Обработка шага предварительной проверки платежа (PreCheckoutQuery)
     */
    protected function handlePreCheckoutQuery(array $preCheckoutQuery): void
    {
        $id = $preCheckoutQuery['id'];
        // Подтверждаем, что платеж корректен
        $this->telegram->answerPreCheckoutQuery([
            'pre_checkout_query_id' => $id,
            'ok' => true,
        ]);
    }

    /**
     * Обработка успешного платежа и выдача Premium статуса
     */
    protected function handleSuccessfulPayment(User $user, array $successfulPayment): void
    {
        $chargeId = $successfulPayment['telegram_payment_charge_id'] ?? uniqid('xtr_', true);
        $starsAmount = $successfulPayment['total_amount'] ?? 50;

        // Добавляем запись о подписке
        PremiumSubscription::create([
            'user_id' => $user->id,
            'telegram_payment_charge_id' => $chargeId,
            'stars_amount' => $starsAmount,
            'status' => 'completed',
            'expires_at' => Carbon::now()->addMonth(),
        ]);

        // Обновляем статус пользователя
        $user->update([
            'is_premium' => true,
            'premium_expires_at' => Carbon::now()->addMonth(),
        ]);

        $this->telegram->sendMessage([
            'chat_id' => $user->telegram_id,
            'text' => "🎉 <b>Поздравляем!</b> Вы успешно приобрели подписку <b>ReminderBot Premium</b> за {$starsAmount} ⭐️!\n\nВсе ограничения сняты! Спасибо за поддержку нашего проекта! ❤️",
        ]);
    }
}
