<?php

namespace App\DTO;

use Carbon\Carbon;

class ParsedReminderDTO
{
    /**
     * @param  bool  $success  true, если из текста удалось уверенно определить дату/время
     *                         (явное время, явная/относительная дата или периодичность).
     *                         Когда false — $targetAt не несёт полезной информации (см. ниже),
     *                         и вызывающий код должен переспросить пользователя, а не молча
     *                         создавать напоминание на подставленное "на глаз" время.
     * @param  bool  $needsClarification  Явный флаг-двойник (!$success), введённый для
     *                                    читаемости на стороне вызывающего кода: "нужно
     *                                    уточнение у пользователя, дата/время не распознаны".
     * @param  Carbon  $targetAt  Рассчитанное время напоминания. ВАЖНО: если $success === false,
     *                            это значение — просто текущий момент времени (не подставленное
     *                            наугад "+1 час" или подобное) и НЕ должно использоваться для
     *                            сохранения напоминания без уточнения у пользователя.
     */
    public function __construct(
        public string $text,
        public Carbon $targetAt,
        public string $recurrenceType = 'once',
        public ?string $recurrenceValue = null,
        public bool $success = false,
        public bool $needsClarification = false,
    ) {}
}
