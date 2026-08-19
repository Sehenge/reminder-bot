<?php

namespace App\Services;

use App\DTO\ParsedReminderDTO;
use Carbon\Carbon;

class NaturalLanguageParserService
{
    /**
     * Парсинг строки естественного языка на русском языке
     * Примеры:
     * - "Напомни купить молоко завтра в 15:00"
     * - "Каждый понедельник в 9:00 созвон с командой"
     * - "Каждый день в 22:30 выпить витамины"
     * - "Через 15 минут проверить духовку"
     * - "30 мая в 12:00 сдать проект"
     *
     * Контракт результата: если ни дата, ни время, ни периодичность не были распознаны,
     * метод НЕ подставляет случайное время (например, "+1 час"). Вместо этого возвращается
     * ParsedReminderDTO с success=false и needsClarification=true, а поле targetAt содержит
     * лишь текущий момент времени и не должно интерпретироваться как реальный результат
     * разбора — вызывающий код обязан переспросить пользователя.
     */
    public function parse(string $text, string $timezone = 'UTC'): ParsedReminderDTO
    {
        // Очищаем от триггерного слова "Напомни" в начале строки (регистронезависимо)
        $text = preg_replace('/^(напомни|напомнить|remind)\s+/ui', '', trim($text));

        $now = Carbon::now($timezone);
        $targetAt = $now->copy();
        $recurrenceType = 'once';
        $recurrenceValue = null;
        $success = false;
        $needsClarification = false;

        // 1. Определение периодичности (каждый день / каждый понедельник)
        $isRecurring = false;

        // "каждый день" / "ежедневно"
        if (preg_match('/(каждый день|ежедневно|каждые сутки)/ui', $text)) {
            $recurrenceType = 'daily';
            $isRecurring = true;
            $text = preg_replace('/(каждый день|ежедневно|каждые сутки)/ui', '', $text);
        }
        // "по будням" / "каждый будний день"
        elseif (preg_match('/(по будням|в будни|каждый будний день)/ui', $text)) {
            $recurrenceType = 'workdays';
            $isRecurring = true;
            $text = preg_replace('/(по будням|в будни|каждый будний день)/ui', '', $text);
        }
        // "каждый(ую) [день недели]"
        elseif (preg_match('/каждый\s+(понедельник|вторник|среду|четверг|пятницу|субботу|воскресенье)/ui', $text, $matches)) {
            $recurrenceType = 'weekly';
            $isRecurring = true;
            $dayName = mb_strtolower($matches[1]);
            $dayOfWeekMap = [
                'понедельник' => 1,
                'вторник' => 2,
                'среду' => 3,
                'четверг' => 4,
                'пятницу' => 5,
                'субботу' => 6,
                'воскресенье' => 7,
            ];
            $dayIso = $dayOfWeekMap[$dayName] ?? 1;
            $recurrenceValue = (string) $dayIso;

            // Сдвигаем targetAt на ближайший указанный день недели
            if ($targetAt->dayOfWeekIso !== $dayIso) {
                $targetAt->next($dayIso);
            }
            $text = preg_replace('/каждый\s+(понедельник|вторник|среду|четверг|пятницу|субботу|воскресенье)/ui', '', $text);
        }
        // "каждый месяц"
        elseif (preg_match('/(каждый месяц|ежемесячно)/ui', $text)) {
            $recurrenceType = 'monthly';
            $isRecurring = true;
            $text = preg_replace('/(каждый месяц|ежемесячно)/ui', '', $text);
        }

        // 2. Поиск относительных дат ("завтра", "послезавтра", "сегодня", "через X минут/часов/дней")
        // ВАЖНО: этот блок обязан выполняться РАНЬШЕ поиска времени вида "ЧЧ:ММ" (см. ниже).
        // У формата даты "ДД.ММ.ГГГГ" (например, "21.06.2026") первые два числа "21.06"
        // синтаксически неотличимы от времени "21:06" для регулярки времени с разделителями
        // "[:.-]" — если сначала искать время, "21.06" в "21.06.2026 в 10:00 ..." ошибочно
        // распознавался бы как время 21:06, а сама дата терялась. Извлекая дату первой и
        // вырезая её из текста, мы убираем этот конфликт.
        $dateFound = false;
        // "через X ..." задаёт итоговый момент времени явным сложением, а не подстановкой
        // часов/минут через setTime() — эту особенность нужно учитывать ниже при выставлении времени.
        $isRelative = false;

        if (preg_match('/через\s+(\d+)\s*(минут|минуту|минуты|мин|ч|час|часа|часов|дн|дня|дней|день)/ui', $text, $matches)) {
            $value = (int) $matches[1];
            $unit = mb_strtolower($matches[2]);

            if (str_contains($unit, 'мин')) {
                $targetAt->addMinutes($value);
            } elseif (str_contains($unit, 'час') || $unit === 'ч') {
                $targetAt->addHours($value);
            } elseif (str_contains($unit, 'дне') || str_contains($unit, 'дня') || str_contains($unit, 'ден') || $unit === 'дн') {
                $targetAt->addDays($value);
            }
            $dateFound = true;
            $timeFound = true; // При относительном времени типа "через 15 мин" точное время суток уже определено добавлением
            $isRelative = true;
            $text = preg_replace('/через\s+\d+\s*(минут|минуту|минуты|мин|ч|час|часа|часов|дн|дня|дней|день)/ui', '', $text);
        }
        // ВАЖНО: "послезавтра" нужно проверять раньше "завтра", т.к. слово "завтра" является
        // подстрокой слова "послезавтра" — при обратном порядке ветка "завтра" ошибочно
        // срабатывала бы первой на тексте "послезавтра" (регулярка находила подстроку),
        // из-за чего "послезавтра" превращалось в "+1 день" вместо "+2 дня", а в очищенном
        // тексте оставался мусорный обрубок "после".
        elseif (preg_match('/послезавтра/ui', $text)) {
            $targetAt->addDays(2);
            $dateFound = true;
            $text = preg_replace('/послезавтра/ui', '', $text);
        } elseif (preg_match('/завтра/ui', $text)) {
            $targetAt->addDay();
            $dateFound = true;
            $text = preg_replace('/завтра/ui', '', $text);
        } elseif (preg_match('/сегодня/ui', $text)) {
            $dateFound = true;
            $text = preg_replace('/сегодня/ui', '', $text);
        }
        // Конкретная дата: "30 мая", "15 декабря в 12:00", "21.05.2026"
        elseif (preg_match('/(\d{1,2})[\s.](января|февраля|марта|апреля|мая|июня|июля|августа|сентября|октября|ноября|декабря|\d{2})(?:[\s.](\d{4}))?/ui', $text, $matches)) {
            $day = (int) $matches[1];
            $monthStr = mb_strtolower($matches[2]);
            $year = isset($matches[3]) ? (int) $matches[3] : $now->year;

            $monthsMap = [
                'января' => 1, 'январь' => 1, '01' => 1,
                'февраля' => 2, 'февраль' => 2, '02' => 2,
                'марта' => 3, 'март' => 3, '03' => 3,
                'апреля' => 4, 'апрель' => 4, '04' => 4,
                'мая' => 5, 'май' => 5, '05' => 5,
                'июня' => 6, 'июнь' => 6, '06' => 6,
                'июля' => 7, 'июль' => 7, '07' => 7,
                'августа' => 8, 'август' => 8, '08' => 8,
                'сентября' => 9, 'сентябрь' => 9, '09' => 9,
                'октября' => 10, 'октябрь' => 10, '10' => 10,
                'ноября' => 11, 'ноябрь' => 11, '11' => 11,
                'декабря' => 12, 'декабрь' => 12, '12' => 12,
            ];

            $month = $monthsMap[$monthStr] ?? (int) $monthStr;
            if ($month >= 1 && $month <= 12) {
                // Если указанная дата в этом году уже прошла (без года в запросе), ставим на следующий год
                if (! isset($matches[3]) && Carbon::create($year, $month, $day, 23, 59, 59, $timezone)->isBefore($now)) {
                    $year++;
                }
                $targetAt->setDate($year, $month, $day);
                $dateFound = true;
            }
            $text = preg_replace('/(\d{1,2})[\s.](января|февраля|марта|апреля|мая|июня|июля|августа|сентября|октября|ноября|декабря|\d{2})(?:[\s.](\d{4}))?/ui', '', $text);
        }

        // 3. Поиск времени вида "15:30", "9:00", "в 18:45", "в 14" (после того, как дата уже
        // извлечена и вырезана из текста — см. комментарий выше).
        $timeFound = false;
        $hour = 9; // По умолчанию утро
        $minute = 0;

        if (preg_match('/(?:в|в\s+)?(\d{1,2})[:.-](\d{2})/ui', $text, $matches)) {
            $hour = (int) $matches[1];
            $minute = (int) $matches[2];
            $timeFound = true;
            $text = preg_replace('/(?:в|в\s+)?\d{1,2}[:.-]\d{2}/ui', '', $text);
        } elseif (preg_match('/(?:в|в\s+)(\d{1,2})\s*(?:ч|час|часа|часов)?(?!\d)/ui', $text, $matches)) {
            $hour = (int) $matches[1];
            $minute = 0;
            $timeFound = true;
            $text = preg_replace('/(?:в|в\s+)\d{1,2}\s*(?:ч|час|часа|часов)?/ui', '', $text);
        }

        // Есть ли вообще какой-то сигнал о дате/времени: явное время, явная/относительная дата
        // или периодичность (для периодичности без явного времени применяется время по умолчанию).
        $hasTemporalSignal = $timeFound || $dateFound || $isRecurring;

        // Если время найдено явно — выставляем его. Для относительного "через ..." время уже
        // вычислено сложением выше и повторно выставлять его через setTime() не нужно (и нельзя,
        // иначе слетит перенос через полночь/сутки).
        if ($hasTemporalSignal && ! $isRelative) {
            $targetAt->setTime($hour, $minute, 0);
        }

        // Считаем разбор успешным, если удалось определить точное относительное время,
        // конкретную дату/время или периодичность повторения.
        $success = $isRelative || $hasTemporalSignal;

        // Если явной даты не было (только время суток) и напоминание одноразовое, а указанное
        // время на сегодня уже прошло — переносим на завтра.
        if ($success && ! $isRelative && ! $dateFound && $recurrenceType === 'once') {
            if ($targetAt->isBefore($now)) {
                $targetAt->addDay();
            }
        }

        // Если не удалось распознать ни дату, ни время, ни периодичность — НЕ угадываем момент
        // времени (например, старым способом "+1 час"). Помечаем результат как неуспешный и
        // требующий уточнения у пользователя; $targetAt при этом остаётся текущим моментом
        // и не должен использоваться вызывающим кодом для сохранения напоминания.
        if (! $success) {
            $needsClarification = true;
        }

        // Причесываем оставшийся текст (удаляем лишние пробелы, предлоги, союзы в начале)
        $cleanedText = preg_replace('/^\s*(в|во|на|о|об|обо|к|со|с|и|да)\s+/ui', '', trim($text));
        $cleanedText = preg_replace('/\s+/', ' ', $cleanedText);
        $cleanedText = trim($cleanedText);

        if (empty($cleanedText)) {
            $cleanedText = 'Напоминание';
        }

        // Всегда переводим итоговую дату targetAt обратно в UTC перед записью в базу данных
        $targetAtUtc = $targetAt->copy()->setTimezone('UTC');

        return new ParsedReminderDTO(
            text: $cleanedText,
            targetAt: $targetAtUtc,
            recurrenceType: $recurrenceType,
            recurrenceValue: $recurrenceValue,
            success: $success,
            needsClarification: $needsClarification,
        );
    }
}
