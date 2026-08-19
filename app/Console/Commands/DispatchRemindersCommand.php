<?php

namespace App\Console\Commands;

use App\Jobs\SendReminderNotificationJob;
use App\Models\Reminder;
use Carbon\Carbon;
use Illuminate\Console\Command;

class DispatchRemindersCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reminders:dispatch';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Проверка и диспетчеризация напоминаний, у которых наступил срок выполнения';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $now = Carbon::now('UTC');

        // Выбираем невыполненные напоминания в статусе pending, у которых целевое
        // время наступило или уже прошло.
        $dueReminders = Reminder::query()
            ->where('status', Reminder::STATUS_PENDING)
            ->where('is_completed', false)
            ->where('target_at', '<=', $now)
            ->with('user')
            ->get();

        if ($dueReminders->isEmpty()) {
            $this->info('Нет напоминаний для отправки в данный момент.');

            return self::SUCCESS;
        }

        $this->info('Найдено напоминаний для отправки: '.$dueReminders->count());

        $dispatchedCount = 0;

        foreach ($dueReminders as $reminder) {
            // Атомарно резервируем напоминание единственным UPDATE-запросом
            // с условием WHERE status = 'pending'. Если два запуска команды
            // пересекутся (overlapping scheduler run) или выполнятся параллельно,
            // только один из них получит affected rows === 1 и сможет поставить
            // задачу в очередь — второй увидит 0 и пропустит напоминание.
            $reserved = Reminder::query()
                ->where('id', $reminder->id)
                ->where('status', Reminder::STATUS_PENDING)
                ->update(['status' => Reminder::STATUS_DISPATCHING]);

            if ($reserved !== 1) {
                // Уже зарезервировано другим запуском команды — пропускаем.
                continue;
            }

            $reminder->status = Reminder::STATUS_DISPATCHING;

            SendReminderNotificationJob::dispatch($reminder);
            $dispatchedCount++;

            $telegramId = $reminder->user->telegram_id ?? 'unknown';
            $this->line("Диспетчеризовано напоминание ID {$reminder->id} для пользователя {$telegramId}");
        }

        $this->info("Всего диспетчеризовано: {$dispatchedCount}");

        return self::SUCCESS;
    }
}
