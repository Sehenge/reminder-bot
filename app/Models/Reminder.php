<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Reminder extends Model
{
    use HasFactory;

    /**
     * Напоминание ожидает наступления срока отправки.
     */
    public const STATUS_PENDING = 'pending';

    /**
     * Напоминание зарезервировано командой диспетчеризации и поставлено в очередь.
     */
    public const STATUS_DISPATCHING = 'dispatching';

    /**
     * Уведомление успешно отправлено.
     */
    public const STATUS_SENT = 'sent';

    /**
     * Отправка окончательно провалилась (исчерпаны все попытки).
     */
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'user_id',
        'category_id',
        'text',
        'target_at',
        'recurrence_type',
        'recurrence_value',
        'is_completed',
        'completed_at',
        'priority',
        'shared_with_chat_id',
        'status',
        'telegram_message_id',
        'sent_at',
    ];

    protected $casts = [
        'target_at' => 'datetime',
        'is_completed' => 'boolean',
        'completed_at' => 'datetime',
        'user_id' => 'integer',
        'category_id' => 'integer',
        'telegram_message_id' => 'integer',
        'sent_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Вычислить следующее время для повторяющегося напоминания.
     *
     * Расчёт ведётся в часовом поясе пользователя, чтобы «каждый день в 09:00»
     * оставалось 09:00 по местному времени и после перехода на летнее/зимнее время
     * (Carbon корректно учитывает DST при добавлении дней/недель/месяцев к дате
     * с именованным часовым поясом). Если было пропущено несколько запусков
     * (например, scheduler не работал несколько дней), метод не «настреливает»
     * промежуточные уведомления, а сразу переходит к ближайшему будущему сроку.
     *
     * @param  string|null  $timezone  Часовой пояс пользователя; если не передан,
     *                                 берётся из связанной модели User (иначе UTC).
     */
    public function calculateNextOccurrence(?string $timezone = null): ?Carbon
    {
        if ($this->recurrence_type === 'once') {
            return null;
        }

        $timezone ??= $this->user?->timezone ?: 'UTC';

        // Переводим в часовой пояс пользователя, чтобы арифметика дат учитывала DST
        // и сохраняла местное время суток (wall-clock time).
        $next = Carbon::parse($this->target_at)->setTimezone($timezone);
        $now = Carbon::now($timezone);

        // Смещаем дату вперёд, пока она не окажется строго в будущем.
        // Цикл может провернуться много раз (догоняя пропущенные запуски),
        // но возвращает только один — ближайший будущий — момент времени.
        while ($next->lessThanOrEqualTo($now)) {
            switch ($this->recurrence_type) {
                case 'daily':
                    $next->addDay();
                    break;
                case 'weekly':
                    $next->addWeek();
                    break;
                case 'monthly':
                    $next->addMonth();
                    break;
                case 'workdays':
                    do {
                        $next->addDay();
                    } while ($next->isWeekend());
                    break;
                case 'custom':
                    // Пример кастомного: recurrence_value может быть списком дней недели "1,3,5" (ПН, СР, ПТ)
                    if ($this->recurrence_value) {
                        $allowedDays = explode(',', $this->recurrence_value);
                        do {
                            $next->addDay();
                        } while (! in_array((string) $next->dayOfWeekIso, $allowedDays, true));
                    } else {
                        $next->addDay();
                    }
                    break;
                default:
                    return null;
            }
        }

        // Храним и сравниваем target_at в UTC.
        return $next->setTimezone('UTC');
    }
}
