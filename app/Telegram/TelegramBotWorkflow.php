<?php

namespace App\Telegram;

use App\Actions\Reminders\CompleteReminderAction;
use App\Actions\Reminders\CreateReminderAction;
use App\Actions\Reminders\SnoozeReminderAction;
use App\Actions\Reminders\UpdateReminderAction;
use App\DTO\CallbackQueryDTO;
use App\DTO\PreCheckoutQueryDTO;
use App\DTO\RefundedPaymentDTO;
use App\DTO\SuccessfulPaymentDTO;
use App\Enums\SharedListRole;
use App\Enums\UserState;
use App\Models\Category;
use App\Models\Reminder;
use App\Models\ReminderHistory;
use App\Models\SharedList;
use App\Models\Tag;
use App\Models\User;
use App\Services\CalendarExportService;
use App\Services\CategoryService;
use App\Services\NaturalLanguageParserService;
use App\Services\PremiumPaymentService;
use App\Services\ReminderHistoryService;
use App\Services\SharedListService;
use App\Services\TagService;
use App\Services\TelegramService;
use App\Telegram\Presenters\ReminderMessagePresenter;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class TelegramBotWorkflow
{
    /**
     * Максимальная длина текста напоминания при редактировании (в символах).
     */
    protected const MAX_REMINDER_TEXT_LENGTH = 500;

    /**
     * Количество напоминаний на одной странице списка /list.
     */
    protected const REMINDERS_PER_PAGE = 10;

    protected TelegramService $telegram;

    protected NaturalLanguageParserService $parser;

    public function __construct(
        TelegramService $telegram,
        NaturalLanguageParserService $parser,
        protected CompleteReminderAction $completeReminderAction,
        protected CreateReminderAction $createReminderAction,
        protected SnoozeReminderAction $snoozeReminderAction,
        protected UpdateReminderAction $updateReminderAction,
        protected ReminderMessagePresenter $presenter,
        protected PremiumPaymentService $premiumPayments,
        protected CategoryService $categories,
        protected TagService $tags,
        protected SharedListService $sharedLists,
        protected CalendarExportService $calendar,
        protected ReminderHistoryService $history,
    ) {
        $this->telegram = $telegram;
        $this->parser = $parser;
    }

    /**
     * Обработка обычных текстовых сообщений и команд
     */
    public function handleMessage(array $message): void
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
            $this->handleSuccessfulPayment($user, SuccessfulPaymentDTO::fromArray($message['successful_payment']));

            return;
        }

        if (isset($message['refunded_payment'])) {
            $this->handleRefundedPayment($user, RefundedPaymentDTO::fromArray($message['refunded_payment']));

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
            $commandToken = explode(' ', $text, 2)[0];
            $command = explode('@', $commandToken)[0];
            $arguments = trim(substr($text, strlen($commandToken)));
            switch ($command) {
                case '/start':
                    $this->sendStartMessage($user, $chatId);
                    break;
                case '/help':
                    $this->sendHelpMessage($user, $chatId);
                    break;
                case '/list':
                    $this->sendListMessage($user, $chatId, filter: $arguments !== '' ? $arguments : null);
                    break;
                case '/premium':
                    $this->sendPremiumMessage($user, $chatId);
                    break;
                case '/timezone':
                    $this->askTimezone($user, $chatId);
                    break;
                case '/categories':
                case '/category':
                    $this->handleCategoryCommand($user, $chatId, $arguments);
                    break;
                case '/tags':
                case '/tag':
                    $this->handleTagCommand($user, $chatId, $arguments);
                    break;
                case '/history':
                    $this->sendHistory($user, $chatId);
                    break;
                case '/shared':
                    $this->handleSharedCommand($user, $chatId, $arguments);
                    break;
                case '/calendar':
                    $this->sendCalendarLink($user, $chatId, $arguments === 'rotate');
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
    public function handleCallbackQuery(array $callbackQuery): void
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
        $callback = CallbackQueryDTO::fromData((string) $data);
        $action = $callback->action;
        $parts = array_merge([$action], $callback->arguments);

        switch ($action) {
            case 'complete': // Отметить выполненным / подтвердить текущее срабатывание повторяющегося
                $reminderId = $parts[1] ?? null;
                $reminder = Reminder::where('id', $reminderId)->where('user_id', $user->id)->first();
                if ($reminder) {
                    $this->completeReminder($reminder, $user, $chatId, $messageId);
                }
                break;

            case 'snooze': // Отложить на 10/30/60 минут или "до завтра"
                $reminderId = $parts[1] ?? null;
                $unit = $parts[2] ?? '10';
                $reminder = Reminder::where('id', $reminderId)->where('user_id', $user->id)->first();
                if ($reminder) {
                    $this->snoozeReminder($reminder, $user, $chatId, $messageId, $unit);
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

            case 'listpage': // Переключение страницы списка (callback_data: listpage_{page})
                $page = (int) ($parts[1] ?? 1);
                $filter = isset($parts[2], $parts[3]) ? $parts[2].':'.$parts[3] : null;
                $this->sendListMessage($user, $chatId, max(1, $page), $messageId, $filter);
                break;

            case 'edit': // Кнопка "✏️ Изменить" на элементе списка (callback_data: edit_{id})
                $reminderId = $parts[1] ?? null;
                $reminder = Reminder::where('id', $reminderId)->where('user_id', $user->id)->first();
                if ($reminder) {
                    $this->showEditMenu($reminder, $chatId, $messageId);
                }
                break;

            case 'editfield': // Выбор редактируемого поля (callback_data: editfield_{id}_text|time|recurrence)
                $reminderId = $parts[1] ?? null;
                $field = $parts[2] ?? null;
                $reminder = Reminder::where('id', $reminderId)->where('user_id', $user->id)->first();
                if ($reminder && in_array($field, ['text', 'time', 'recurrence'], true)) {
                    $this->startEditField($user, $reminder, $chatId, $messageId, $field);
                }
                break;

            case 'editrecurrence': // Выбор нового типа повторения (callback_data: editrecurrence_{id}_{type})
                $reminderId = $parts[1] ?? null;
                $type = $parts[2] ?? null;
                $reminder = Reminder::where('id', $reminderId)->where('user_id', $user->id)->first();
                if ($reminder && in_array($type, ['once', 'daily', 'weekly', 'monthly', 'workdays'], true)) {
                    $this->applyRecurrenceEdit($reminder, $user, $chatId, $messageId, $type);
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
     * Отметить напоминание "Выполненным" по кнопке.
     *
     * Для одноразового напоминания это окончательное завершение (как и раньше).
     * Для повторяющегося — ручное нажатие "Выполнено" ДО автоматической отправки должно
     * вести себя так же, как штатная отправка в SendReminderNotificationJob: подтверждать
     * только текущее срабатывание и переводить напоминание на следующий срок, а не тихо
     * убивать все будущие повторения. Если calculateNextOccurrence() не смог посчитать
     * следующий срок (повторение исчерпано или некорректно настроено), откатываемся
     * к окончательному завершению — как для once.
     */
    protected function completeReminder(Reminder $reminder, User $user, int $chatId, int $messageId): void
    {
        $nextOccurrence = $this->completeReminderAction->execute($reminder, $user);

        if ($nextOccurrence) {
            $localTime = $nextOccurrence->copy()->setTimezone($user->timezone);
            $this->telegram->editMessageText([
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'text' => '✅ Текущее срабатывание подтверждено: <b>'.e($reminder->text).'</b>.'
                         .' Следующее напоминание: <b>'.$localTime->format('d.m.Y H:i').'</b>.',
            ]);

            return;
        }

        $this->telegram->editMessageText([
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => '✅ Напоминание выполнено: <s>'.e($reminder->text).'</s>',
        ]);
    }

    /**
     * Отложить напоминание на 10/30/60 минут или "до завтра" по кнопке.
     *
     * "До завтра" считается в часовом поясе пользователя (то же местное время суток,
     * на календарный день позже) той же техникой, что и Reminder::calculateNextOccurrence():
     * арифметика ведётся в именованном часовом поясе, чтобы Carbon корректно учёл
     * переход на летнее/зимнее время, а не наивно прибавлял 24 часа в UTC.
     *
     * Статус всегда возвращается в 'pending' независимо от того, каким он был
     * (pending/dispatching/sent/failed): пользователь явно попросил напомнить снова
     * в новое — заведомо будущее — время, а DispatchRemindersCommand выбирает на
     * отправку только status='pending', так что без этого сброса отложенное
     * напоминание, уже прошедшее через пайплайн отправки один раз, никогда
     * больше не сработает.
     */
    protected function snoozeReminder(Reminder $reminder, User $user, int $chatId, int $messageId, string $unit): void
    {
        ['time' => $newTime, 'label' => $label] = $this->snoozeReminderAction->execute($reminder, $user, $unit);

        $timeFormatted = $newTime->copy()->setTimezone($user->timezone)->format('d.m.Y H:i');
        $this->telegram->editMessageText([
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => '⏳ Напоминание <b>'.e($reminder->text)."</b> отложено {$label} (до {$timeFormatted}).",
        ]);
    }

    /**
     * Показать меню выбора редактируемого поля напоминания (текст/время/повторение).
     */
    protected function showEditMenu(Reminder $reminder, int $chatId, int $messageId): void
    {
        $this->telegram->editMessageText([
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => '✏️ Что изменить в напоминании «'.e($reminder->text).'»?',
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [
                        ['text' => '📝 Текст', 'callback_data' => "editfield_{$reminder->id}_text"],
                        ['text' => '⏰ Время', 'callback_data' => "editfield_{$reminder->id}_time"],
                    ],
                    [
                        ['text' => '🔄 Повторение', 'callback_data' => "editfield_{$reminder->id}_recurrence"],
                    ],
                    [
                        ['text' => '🚫 Отмена', 'callback_data' => 'cancel'],
                    ],
                ],
            ]),
        ]);
    }

    /**
     * Начать редактирование конкретного поля: для текста/времени переводит пользователя
     * в соответствующее FSM-состояние (следующее текстовое сообщение будет интерпретировано
     * как новое значение), для повторения — сразу показывает кнопки с фиксированными типами.
     */
    protected function startEditField(User $user, Reminder $reminder, int $chatId, int $messageId, string $field): void
    {
        if ($field === 'recurrence') {
            $this->telegram->editMessageText([
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'text' => '🔄 Выберите новую периодичность повторения:',
                'reply_markup' => json_encode([
                    'inline_keyboard' => [
                        [
                            ['text' => 'Одноразово', 'callback_data' => "editrecurrence_{$reminder->id}_once"],
                            ['text' => 'Каждый день', 'callback_data' => "editrecurrence_{$reminder->id}_daily"],
                        ],
                        [
                            ['text' => 'Каждую неделю', 'callback_data' => "editrecurrence_{$reminder->id}_weekly"],
                            ['text' => 'Каждый месяц', 'callback_data' => "editrecurrence_{$reminder->id}_monthly"],
                        ],
                        [
                            ['text' => 'По будням', 'callback_data' => "editrecurrence_{$reminder->id}_workdays"],
                        ],
                        [
                            ['text' => '🚫 Отмена', 'callback_data' => 'cancel'],
                        ],
                    ],
                ]),
            ]);

            return;
        }

        $prompts = [
            'text' => '📝 Отправьте новый текст напоминания одним сообщением.',
            'time' => '⏰ Отправьте новое время/дату напоминания текстом, например: «завтра в 18:00» или «через 2 часа».',
        ];

        // Запоминаем, какое напоминание и какое поле редактируем — следующее обычное
        // текстовое сообщение пользователя обработает handleUserState() (state = edit_text/edit_time).
        $user->update([
            'state' => "edit_{$field}",
            'state_data' => ['reminder_id' => $reminder->id],
        ]);

        $this->telegram->editMessageText([
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $prompts[$field],
        ]);
    }

    /**
     * Применить новый текст напоминания (FSM state = edit_text).
     */
    protected function handleEditTextState(User $user, string $text, int $chatId): void
    {
        $reminder = $this->findReminderBeingEdited($user);

        if (! $reminder) {
            $this->clearState($user);
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => '❌ Напоминание не найдено (возможно, уже удалено). Редактирование отменено.',
            ]);

            return;
        }

        $newText = trim($text);

        if ($newText === '' || mb_strlen($newText) > self::MAX_REMINDER_TEXT_LENGTH) {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => '❌ Текст напоминания должен быть от 1 до '.self::MAX_REMINDER_TEXT_LENGTH.' символов. Отправьте текст ещё раз.',
            ]);

            // Остаёмся в состоянии edit_text, чтобы пользователь мог повторить попытку.
            return;
        }

        $this->updateReminderAction->execute($reminder, ['text' => $newText]);
        $this->clearState($user);

        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => '✅ Текст напоминания обновлён: <b>'.e($reminder->text).'</b>.',
        ]);
    }

    /**
     * Применить новое время напоминания (FSM state = edit_time).
     *
     * Реиспользует NaturalLanguageParserService и уважает контракт needsClarification —
     * так же, как parseAndConfirmReminder(): если время не удалось уверенно распознать,
     * НЕ сохраняем бессмысленное значение, а просим пользователя переформулировать
     * и остаёмся в том же состоянии.
     *
     * Тип и значение повторения (recurrence_type/recurrence_value) намеренно не трогаем —
     * при редактировании времени повторяющегося напоминания смещается только точка
     * отсчёта (target_at), а периодичность остаётся прежней; для смены периодичности
     * есть отдельное редактирование "Повторение".
     */
    protected function handleEditTimeState(User $user, string $text, int $chatId): void
    {
        $reminder = $this->findReminderBeingEdited($user);

        if (! $reminder) {
            $this->clearState($user);
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => '❌ Напоминание не найдено (возможно, уже удалено). Редактирование отменено.',
            ]);

            return;
        }

        $dto = $this->parser->parse($text, $user->timezone, $user->language_code);

        if ($dto->needsClarification) {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "🤔 Не удалось точно определить дату и время.\n\n"
                         .'Попробуйте переформулировать, например: <b>«завтра в 15:00»</b> или <b>«через 2 часа»</b>.',
            ]);

            // Остаёмся в состоянии edit_time, чтобы пользователь мог повторить попытку.
            return;
        }

        $this->updateReminderAction->execute($reminder, ['target_at' => $dto->targetAt]);
        $this->clearState($user);

        $localTime = $dto->targetAt->copy()->setTimezone($user->timezone);
        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => '✅ Время напоминания обновлено: <b>'.$localTime->format('d.m.Y H:i')."</b> (часовой пояс: {$user->timezone}).",
        ]);
    }

    /**
     * Применить новый тип повторения (callback editrecurrence_{id}_{type}).
     *
     * При переключении на "еженедельно" сохраняем день недели из текущего target_at
     * (в часовом поясе пользователя), чтобы recurrence_value был согласован с форматом,
     * который использует NaturalLanguageParserService/Reminder::calculateNextOccurrence()
     * (ISO-номер дня недели). Для остальных типов recurrence_value не используется.
     */
    protected function applyRecurrenceEdit(Reminder $reminder, User $user, int $chatId, int $messageId, string $type): void
    {
        $recurrenceValue = null;
        if ($type === 'weekly') {
            $localTarget = $reminder->target_at->copy()->setTimezone($user->timezone);
            $recurrenceValue = (string) $localTarget->dayOfWeekIso;
        }

        $this->updateReminderAction->execute($reminder, [
            'recurrence_type' => $type,
            'recurrence_value' => $recurrenceValue,
        ]);

        $this->clearState($user);

        $this->telegram->editMessageText([
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => '✅ Повторение обновлено: <b>'.$this->recurrenceLabel($type).'</b>.',
        ]);
    }

    /**
     * Найти напоминание, которое сейчас редактируется (по reminder_id в state_data),
     * ограниченное текущим пользователем — чтобы нельзя было отредактировать чужое
     * напоминание, подделав/переиспользовав state_data.
     */
    protected function findReminderBeingEdited(User $user): ?Reminder
    {
        $reminderId = $user->state_data['reminder_id'] ?? null;

        if (! $reminderId) {
            return null;
        }

        return Reminder::where('id', $reminderId)->where('user_id', $user->id)->first();
    }

    /**
     * Человекочитаемая метка типа повторения для сообщений пользователю.
     */
    protected function recurrenceLabel(string $type): string
    {
        return $this->presenter->recurrenceLabel($type);
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
              ."/categories — Категории Premium\n"
              ."/tags — Теги Premium\n"
              ."/history — История изменений за 6 месяцев\n"
              ."/shared — Совместные списки\n"
              ."/calendar — Персональная ссылка iCal\n\n"
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
        if ($user->state === UserState::WaitingForTimezone->value) {
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

        if ($user->state === UserState::EditingText->value) {
            $this->handleEditTextState($user, $text, $chatId);

            return;
        }

        if ($user->state === UserState::EditingTime->value) {
            $this->handleEditTimeState($user, $text, $chatId);

            return;
        }

        if ($user->state === UserState::ClarifyingReminder->value) {
            $original = (string) ($user->state_data['original_text'] ?? '');
            $this->clearState($user);
            $this->parseAndConfirmReminder($user, trim($text.' '.$original), $chatId);

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

        $dto = $this->parser->parse($text, $user->timezone, $user->language_code);

        // Если дату/время не удалось уверенно распознать — не подставляем "на глаз" время
        // и не предлагаем сохранить напоминание, а просто просим уточнить формулировку.
        if ($dto->needsClarification) {
            $user->update([
                'state' => UserState::ClarifyingReminder->value,
                'state_data' => ['original_text' => $dto->text],
            ]);
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => $dto->locale === 'en'
                    ? '🤔 When should I remind you? Reply with a date or time, for example: <b>tomorrow at 3:00 pm</b> or <b>in 2 hours</b>.'
                    : '🤔 Когда напомнить? Ответьте датой или временем, например: <b>«завтра в 15:00»</b> или <b>«через 2 часа»</b>.',
            ]);

            return;
        }

        // Переводим время в часовой пояс пользователя для корректного отображения в боте
        $localTargetTime = $dto->targetAt->copy()->setTimezone($user->timezone);

        $recLabel = $this->recurrenceLabel($dto->recurrenceType);

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
        $reminder = $this->createReminderAction->execute($user, $stateData);

        // Очищаем временные данные состояния
        $this->clearState($user);

        $localTime = Carbon::parse($reminder->target_at)->setTimezone($user->timezone);
        $recLabel = $this->recurrenceLabel($reminder->recurrence_type);

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
     * Показать список активных напоминаний (/list, кнопка "Мои напоминания" или переключение
     * страницы кнопками "◀️ Пред."/"▶️ След.").
     *
     * @param  int  $page  Номер страницы, отсчёт с 1 (значения вне диапазона зажимаются).
     * @param  int|null  $editMessageId  Если передан — редактируем существующее сообщение
     *                                   вместо отправки нового. Используется при пагинации
     *                                   по кнопкам, чтобы чат не засорялся дублирующимися
     *                                   списками. Для исходного вызова из /list или
     *                                   list_active передавать не нужно — там уместно
     *                                   отправить новое сообщение.
     */
    protected function sendListMessage(User $user, int $chatId, int $page = 1, ?int $editMessageId = null, ?string $filter = null): void
    {
        $page = max(1, $page);
        $perPage = self::REMINDERS_PER_PAGE;
        $query = Reminder::query()->where('user_id', $user->id)->where('is_completed', false);
        $filterCallbackSuffix = '';

        if ($filter !== null) {
            if (! $user->hasPremium() || preg_match('/^(category|tag):(\d+)$/', $filter, $matches) !== 1) {
                $this->sendText($chatId, 'Фильтры category:ID и tag:ID доступны только в Premium.');

                return;
            }

            $filterId = (int) $matches[2];
            if ($matches[1] === 'category') {
                $query->where('category_id', $filterId)->whereHas('category', fn ($category) => $category->where('user_id', $user->id));
            } else {
                $query->whereHas('tags', fn ($tag) => $tag->where('tags.id', $filterId)->where('tags.user_id', $user->id));
            }
            $filterCallbackSuffix = '_'.$matches[1].'_'.$filterId;
        }

        $total = (clone $query)->count();

        if ($total === 0) {
            $emptyText = '📭 У вас пока нет активных напоминаний. Просто напишите фразу для создания, например <i>«Купить хлеб в 15:00»</i>.';

            if ($editMessageId) {
                $this->telegram->editMessageText([
                    'chat_id' => $chatId,
                    'message_id' => $editMessageId,
                    'text' => $emptyText,
                ]);
            } else {
                $this->telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => $emptyText,
                ]);
            }

            return;
        }

        $totalPages = (int) max(1, ceil($total / $perPage));
        $page = min($page, $totalPages);

        $reminders = $query
            ->orderBy('target_at', 'asc')
            ->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get();

        $text = "📋 <b>Ваши активные напоминания (стр. {$page}/{$totalPages}):</b>\n\n";
        $keyboard = [];

        foreach ($reminders as $index => $reminder) {
            $num = ($page - 1) * $perPage + $index + 1;
            $localTime = $reminder->target_at->copy()->setTimezone($user->timezone);
            $formattedTime = $localTime->format('d.m.Y H:i');
            $typeIcon = $reminder->recurrence_type !== 'once' ? '🔄' : '📌';

            $text .= "{$num}. {$typeIcon} <b>".e($reminder->text)."</b> — <i>{$formattedTime}</i>\n";

            // Кнопки действий для каждого элемента в списке
            $keyboard[] = [
                ['text' => "❌ {$num}", 'callback_data' => "delete_{$reminder->id}"],
                ['text' => "✅ {$num}", 'callback_data' => "complete_{$reminder->id}"],
                ['text' => "✏️ {$num}", 'callback_data' => "edit_{$reminder->id}"],
            ];
        }

        // Кнопки пагинации показываем только когда соответствующее направление доступно.
        $pagerRow = [];
        if ($page > 1) {
            $pagerRow[] = ['text' => '◀️ Пред.', 'callback_data' => 'listpage_'.($page - 1).$filterCallbackSuffix];
        }
        if ($page < $totalPages) {
            $pagerRow[] = ['text' => '▶️ След.', 'callback_data' => 'listpage_'.($page + 1).$filterCallbackSuffix];
        }
        if ($pagerRow !== []) {
            $keyboard[] = $pagerRow;
        }

        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
        ];

        if ($editMessageId) {
            $this->telegram->editMessageText(array_merge($payload, ['message_id' => $editMessageId]));
        } else {
            $this->telegram->sendMessage($payload);
        }
    }

    /**
     * Окно премиума (/premium)
     */
    protected function sendPremiumMessage(User $user, int $chatId): void
    {
        $isPremium = $user->hasPremium();
        $statusText = match (true) {
            ! $isPremium => '❌ Ваш премиум статус: <b>Не активен</b>',
            $user->premium_expires_at === null => '⭐ Ваш премиум статус: <b>Активен бессрочно</b>',
            default => '⭐ Ваш премиум статус: <b>Активен</b> (до '.$user->premium_expires_at->setTimezone($user->timezone)->format('d.m.Y H:i').')',
        };

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

        $buttons = [[[
            'text' => $isPremium ? '💳 Продлить Premium за 50 ⭐️' : '💳 Купить Premium за 50 ⭐️',
            'callback_data' => 'buypremium',
        ]]];

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
        $order = $this->premiumPayments->createPendingOrder($user);

        $this->telegram->sendInvoice([
            'chat_id' => $chatId,
            'title' => 'ReminderBot Premium',
            'description' => 'Активация Premium подписки на 30 дней в боте ReminderBot',
            'payload' => $order->invoice_payload,
            'currency' => $this->premiumPayments->currency(),
            'prices' => json_encode([
                ['label' => 'Premium 30 дней', 'amount' => $this->premiumPayments->amount()],
            ]),
        ]);
    }

    /**
     * Обработка шага предварительной проверки платежа (PreCheckoutQuery)
     */
    public function handlePreCheckoutQuery(array $preCheckoutQuery): void
    {
        try {
            $query = PreCheckoutQueryDTO::fromArray($preCheckoutQuery);
        } catch (InvalidArgumentException) {
            if (is_string($preCheckoutQuery['id'] ?? null) && $preCheckoutQuery['id'] !== '') {
                $this->telegram->answerPreCheckoutQuery([
                    'pre_checkout_query_id' => $preCheckoutQuery['id'],
                    'ok' => false,
                    'error_message' => 'Не удалось проверить параметры платежа. Создайте новый счёт.',
                ]);
            }

            return;
        }

        $user = $this->premiumPayments->userForCheckout($query);
        $accepted = $user !== null;
        $this->premiumPayments->recordCheckout($query, $user, $accepted, $accepted ? null : 'order_mismatch');

        $this->telegram->answerPreCheckoutQuery([
            'pre_checkout_query_id' => $query->id,
            'ok' => $accepted,
            ...($accepted ? [] : ['error_message' => 'Параметры счёта не совпадают. Создайте новый счёт.']),
        ]);
    }

    /**
     * Обработка успешного платежа и выдача Premium статуса
     */
    protected function handleSuccessfulPayment(User $user, SuccessfulPaymentDTO $payment): void
    {
        if (! $this->premiumPayments->isValidSuccessfulPayment($user, $payment)) {
            $this->premiumPayments->recordRejectedPayment($user, $payment);
            Log::warning('Rejected Telegram successful payment with mismatched order data.', [
                'user_id' => $user->id,
                'telegram_payment_charge_id' => $payment->chargeId,
            ]);

            return;
        }

        $result = $this->premiumPayments->purchase($user, $payment);

        if (! $result->wasCreated) {
            return;
        }

        $this->telegram->sendMessage([
            'chat_id' => $user->telegram_id,
            'text' => "🎉 <b>Поздравляем!</b> Вы успешно приобрели подписку <b>ReminderBot Premium</b> за {$payment->amount} ⭐️!\n\nВсе ограничения сняты! Спасибо за поддержку нашего проекта! ❤️",
        ]);
    }

    protected function handleRefundedPayment(User $user, RefundedPaymentDTO $payment): void
    {
        if (! $this->premiumPayments->refund($user, $payment)) {
            Log::warning('Rejected Telegram refunded payment with unknown or mismatched charge.', [
                'user_id' => $user->id,
                'telegram_payment_charge_id' => $payment->chargeId,
            ]);

            return;
        }

        $this->telegram->sendMessage([
            'chat_id' => $user->telegram_id,
            'text' => 'Возврат Premium обработан. Срок доступа был пересчитан.',
        ]);
    }

    private function handleCategoryCommand(User $user, int $chatId, string $arguments): void
    {
        $this->runPremiumCommand($user, $chatId, function () use ($user, $chatId, $arguments): void {
            [$action, $rest] = array_pad(explode(' ', $arguments, 2), 2, '');
            if ($action === 'add') {
                $category = $this->categories->create($user, $rest);
                $this->sendText($chatId, 'Категория создана: <b>'.e($category->name)."</b> (#{$category->id}).");

                return;
            }
            if ($action === 'delete' && ctype_digit($rest)) {
                $this->categories->delete($user, Category::query()->findOrFail((int) $rest));
                $this->sendText($chatId, 'Категория удалена.');

                return;
            }
            if ($action === 'set') {
                [$reminderId, $categoryId] = array_pad(explode(' ', $rest, 2), 2, '');
                $reminder = $user->reminders()->findOrFail((int) $reminderId);
                $category = $user->categories()->findOrFail((int) $categoryId);
                $reminder->update(['category_id' => $category->id]);
                $this->sendText($chatId, 'Категория назначена напоминанию.');

                return;
            }

            $items = $this->categories->list($user);
            $lines = $items->map(fn (Category $category): string => "#{$category->id} — ".e($category->name))->all();
            $this->sendText($chatId, $lines === []
                ? 'Категорий пока нет. Создание: <code>/category add Название</code>'
                : "<b>Категории</b>\n".implode("\n", $lines)."\n\nНазначить: <code>/category set ID_напоминания ID_категории</code>");
        });
    }

    private function handleTagCommand(User $user, int $chatId, string $arguments): void
    {
        $this->runPremiumCommand($user, $chatId, function () use ($user, $chatId, $arguments): void {
            [$action, $rest] = array_pad(explode(' ', $arguments, 2), 2, '');
            if ($action === 'add') {
                $tag = $this->tags->create($user, $rest);
                $this->sendText($chatId, 'Тег создан: <b>'.e($tag->name)."</b> (#{$tag->id}).");

                return;
            }
            if ($action === 'delete' && ctype_digit($rest)) {
                $this->tags->delete($user, Tag::query()->findOrFail((int) $rest));
                $this->sendText($chatId, 'Тег удалён.');

                return;
            }
            if ($action === 'set') {
                [$reminderId, $tagList] = array_pad(explode(' ', $rest, 2), 2, '');
                $ids = array_values(array_filter(array_map('intval', explode(',', $tagList))));
                $this->tags->syncReminder($user, $user->reminders()->findOrFail((int) $reminderId), $ids);
                $this->sendText($chatId, 'Теги назначены напоминанию.');

                return;
            }

            $items = $this->tags->list($user);
            $lines = $items->map(fn (Tag $tag): string => "#{$tag->id} — ".e($tag->name))->all();
            $this->sendText($chatId, $lines === []
                ? 'Тегов пока нет. Создание: <code>/tag add Название</code>'
                : "<b>Теги</b>\n".implode("\n", $lines)."\n\nНазначить: <code>/tag set ID_напоминания ID,ID</code>");
        });
    }

    private function sendHistory(User $user, int $chatId): void
    {
        $this->runPremiumCommand($user, $chatId, function () use ($user, $chatId): void {
            $history = $this->history->recent($user);
            $lines = $history->map(fn (ReminderHistory $item): string => sprintf(
                '%s — %s: %s',
                $item->created_at->setTimezone($user->timezone)->format('d.m H:i'),
                e($item->event_type),
                e($item->text),
            ))->all();
            $this->sendText($chatId, $lines === [] ? 'История пока пуста.' : "<b>Последние изменения</b>\n".implode("\n", $lines));
        });
    }

    private function handleSharedCommand(User $user, int $chatId, string $arguments): void
    {
        $this->runPremiumCommand($user, $chatId, function () use ($user, $chatId, $arguments): void {
            $parts = preg_split('/\s+/', trim($arguments), 4) ?: [];
            $action = $parts[0] ?? '';
            if ($action === 'create' && isset($parts[1])) {
                $name = trim(substr($arguments, strlen('create')));
                $list = $this->sharedLists->create($user, $name);
                $this->sendText($chatId, 'Совместный список создан: <b>'.e($list->name)."</b> (#{$list->id}).");

                return;
            }
            if ($action === 'invite' && isset($parts[1], $parts[2], $parts[3])) {
                $list = SharedList::query()->findOrFail((int) $parts[1]);
                $invitee = User::query()->where('telegram_id', (int) $parts[2])->firstOrFail();
                $role = SharedListRole::from($parts[3]);
                $this->sharedLists->invite($user, $list, $invitee, $role);
                $this->sendText($chatId, 'Приглашение создано. Пользователь должен принять его командой /shared accept.');

                return;
            }
            if ($action === 'accept' && isset($parts[1])) {
                $this->sharedLists->accept($user, SharedList::query()->findOrFail((int) $parts[1]));
                $this->sendText($chatId, 'Приглашение принято.');

                return;
            }
            if ($action === 'add' && isset($parts[1], $parts[2])) {
                $this->sharedLists->attachReminder(
                    $user,
                    SharedList::query()->findOrFail((int) $parts[1]),
                    Reminder::query()->findOrFail((int) $parts[2]),
                );
                $this->sendText($chatId, 'Напоминание добавлено в совместный список.');

                return;
            }
            if ($action === 'chat' && isset($parts[1], $parts[2])) {
                $this->sharedLists->setTelegramChat(
                    $user,
                    SharedList::query()->findOrFail((int) $parts[1]),
                    (int) $parts[2],
                );
                $this->sendText($chatId, 'Групповой чат назначен совместному списку.');

                return;
            }

            $lists = $this->sharedLists->accessible($user);
            $lines = $lists->map(fn (SharedList $list): string => "#{$list->id} — ".e($list->name))->all();
            $this->sendText($chatId, $lines === []
                ? 'Совместных списков пока нет. Создание: <code>/shared create Название</code>'
                : "<b>Совместные списки</b>\n".implode("\n", $lines));
        });
    }

    private function sendCalendarLink(User $user, int $chatId, bool $rotate): void
    {
        $this->runPremiumCommand($user, $chatId, function () use ($user, $chatId, $rotate): void {
            $token = $this->calendar->token($user, $rotate);
            $url = route('calendar.feed', ['token' => $token]);
            $this->sendText($chatId, "Персональная ссылка календаря:\n<code>".e($url)."</code>\n\nДля отзыва старой ссылки: /calendar rotate");
        });
    }

    private function runPremiumCommand(User $user, int $chatId, callable $command): void
    {
        if (! $user->hasPremium()) {
            $this->sendText($chatId, 'Эта функция доступна в Premium. Используйте /premium для подключения.');

            return;
        }

        try {
            $command();
        } catch (Throwable) {
            $this->sendText($chatId, 'Не удалось выполнить команду. Проверьте параметры и права доступа.');
        }
    }

    private function sendText(int $chatId, string $text): void
    {
        $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => $text]);
    }
}
