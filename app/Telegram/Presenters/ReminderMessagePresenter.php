<?php

namespace App\Telegram\Presenters;

use App\Enums\RecurrenceType;
use App\Models\Reminder;
use App\Models\User;

final class ReminderMessagePresenter
{
    public function recurrenceLabel(string $type): string
    {
        return RecurrenceType::tryFrom($type)?->label() ?? 'Повторяющееся';
    }

    /** @return array<string, mixed> */
    public function notification(Reminder $reminder, User $user): array
    {
        $time = $reminder->target_at->copy()->setTimezone($user->timezone)->format('H:i');

        return [
            'chat_id' => $reminder->sharedList?->telegram_chat_id ?: $user->telegram_id,
            'text' => "🔔 <b>НАПОМИНАНИЕ!</b> 🔔\n\n📌 Текст: <b>".e($reminder->text)."</b>\n⏰ Время: <b>{$time}</b>\n\nПожалуйста, выполните или отложите эту задачу.",
            'reply_markup' => json_encode(['inline_keyboard' => [
                [
                    ['text' => '✅ Выполнено', 'callback_data' => "complete_{$reminder->id}"],
                    ['text' => '⏳ 10м', 'callback_data' => "snooze_{$reminder->id}_10"],
                    ['text' => '⏳ 30м', 'callback_data' => "snooze_{$reminder->id}_30"],
                ],
                [
                    ['text' => '⏳ 1ч', 'callback_data' => "snooze_{$reminder->id}_60"],
                    ['text' => '⏳ До завтра', 'callback_data' => "snooze_{$reminder->id}_tomorrow"],
                ],
                [['text' => '❌ Удалить', 'callback_data' => "delete_{$reminder->id}"]],
            ]]),
        ];
    }
}
