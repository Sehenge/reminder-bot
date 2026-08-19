<?php

namespace App\Jobs;

use App\Events\ReminderNotificationSent;
use App\Models\Reminder;
use App\Services\TelegramService;
use App\Telegram\Presenters\ReminderMessagePresenter;
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
        $presenter = app(ReminderMessagePresenter::class);
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

        $response = $telegram->sendMessage($presenter->notification($reminder, $user));

        if (! (isset($response['ok']) && $response['ok'])) {
            $description = is_string($response['description'] ?? null) ? $response['description'] : 'unknown error';

            Log::error("Failed to send reminder ID: {$reminder->id} to user ID: {$user->telegram_id}. Reason: {$description}");

            // Бросаем исключение, чтобы очередь повторила попытку согласно $tries/backoff().
            // Статус остаётся 'dispatching' до исчерпания попыток (см. failed()).
            throw new RuntimeException("Telegram sendMessage failed for reminder ID {$reminder->id}: {$description}");
        }

        $messageId = $response['result']['message_id'] ?? null;
        $sentAt = Carbon::now();
        ReminderNotificationSent::dispatch($reminder);

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
