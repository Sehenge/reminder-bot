<?php

namespace App\Jobs;

use App\Models\Reminder;
use App\Services\TelegramService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class SendReminderNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Количество попыток отправки прежде, чем задача считается окончательно провалившейся.
     */
    public int $tries = 5;

    /**
     * Максимальное время выполнения одной попытки (в секундах).
     */
    public int $timeout = 30;

    protected Reminder $reminder;

    /**
     * Create a new job instance.
     */
    public function __construct(Reminder $reminder)
    {
        $this->reminder = $reminder;
    }

    /**
     * Задержки перед повторными попытками (в секундах).
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60, 300, 900];
    }

    /**
     * Execute the job.
     */
    public function handle(TelegramService $telegram): void
    {
        // Всегда перечитываем актуальное состояние из БД: модель могла быть
        // сериализована в очередь ранее, а её статус — измениться с тех пор.
        $reminder = $this->reminder->fresh();

        if (! $reminder) {
            // Напоминание было удалено до отправки — резервирование более не актуально.
            return;
        }

        if ($reminder->status !== Reminder::STATUS_DISPATCHING) {
            // Уже обработано (отправлено/провалено) другой попыткой или запуском —
            // не отправляем уведомление повторно.
            return;
        }

        $user = $reminder->user;
        if (! $user) {
            $reminder->update(['status' => Reminder::STATUS_FAILED]);
            Log::error("Reminder ID {$reminder->id} has no associated user; marking as failed.");

            return;
        }

        $localTime = Carbon::parse($reminder->target_at)->setTimezone($user->timezone);
        $formattedTime = $localTime->format('H:i');

        // Создаем сообщение уведомления
        $text = "🔔 <b>НАПОМИНАНИЕ!</b> 🔔\n\n"
              .'📌 Текст: <b>'.e($reminder->text)."</b>\n"
              ."⏰ Время: <b>{$formattedTime}</b>\n\n"
              .'Пожалуйста, выполните или отложите эту задачу.';

        // Готовим inline-кнопки (Выполнено, Отложить на 10м/30м/1ч/до завтра, Удалить).
        // Суффикс "tomorrow" у snooze специально нечисловой (в отличие от 10/30/60) —
        // обработчик в контроллере распознаёт его до приведения к (int).
        $buttons = [
            [
                ['text' => '✅ Выполнено', 'callback_data' => "complete_{$reminder->id}"],
                ['text' => '⏳ 10м', 'callback_data' => "snooze_{$reminder->id}_10"],
                ['text' => '⏳ 30м', 'callback_data' => "snooze_{$reminder->id}_30"],
            ],
            [
                ['text' => '⏳ 1ч', 'callback_data' => "snooze_{$reminder->id}_60"],
                ['text' => '⏳ До завтра', 'callback_data' => "snooze_{$reminder->id}_tomorrow"],
            ],
            [
                ['text' => '❌ Удалить', 'callback_data' => "delete_{$reminder->id}"],
            ],
        ];

        // Отправляем через Telegram Service
        $response = $telegram->sendMessage([
            'chat_id' => $user->telegram_id,
            'text' => $text,
            'reply_markup' => json_encode(['inline_keyboard' => $buttons]),
        ]);

        if (! (isset($response['ok']) && $response['ok'])) {
            $description = is_string($response['description'] ?? null) ? $response['description'] : 'unknown error';

            Log::error("Failed to send reminder ID: {$reminder->id} to user ID: {$user->telegram_id}. Reason: {$description}");

            // Бросаем исключение, чтобы очередь повторила попытку согласно $tries/backoff().
            // Статус остаётся 'dispatching' до исчерпания попыток (см. failed()).
            throw new RuntimeException("Telegram sendMessage failed for reminder ID {$reminder->id}: {$description}");
        }

        $messageId = $response['result']['message_id'] ?? null;
        $sentAt = Carbon::now();

        // Если это повторяющееся напоминание — рассчитываем следующее время
        if ($reminder->recurrence_type !== 'once') {
            $nextTime = $reminder->calculateNextOccurrence($user->timezone);

            if ($nextTime) {
                $reminder->update([
                    'target_at' => $nextTime,
                    'is_completed' => false,
                    'status' => Reminder::STATUS_PENDING,
                    'telegram_message_id' => $messageId,
                    'sent_at' => $sentAt,
                ]);

                return;
            }

            $reminder->update([
                'is_completed' => true,
                'completed_at' => $sentAt,
                'status' => Reminder::STATUS_SENT,
                'telegram_message_id' => $messageId,
                'sent_at' => $sentAt,
            ]);

            return;
        }

        // Если одноразовое — помечаем как выполненное и отправленное
        $reminder->update([
            'is_completed' => true,
            'completed_at' => $sentAt,
            'status' => Reminder::STATUS_SENT,
            'telegram_message_id' => $messageId,
            'sent_at' => $sentAt,
        ]);
    }

    /**
     * Обработка окончательно провалившейся задачи (исчерпаны все попытки).
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('SendReminderNotificationJob permanently failed for reminder ID '.$this->reminder->id.': '.($exception?->getMessage() ?? 'unknown error'));

        // Не оставляем напоминание навечно застрявшим в статусе 'dispatching'.
        Reminder::whereKey($this->reminder->id)->update(['status' => Reminder::STATUS_FAILED]);
    }
}
