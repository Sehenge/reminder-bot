<?php

namespace App\Enums;

enum RecurrenceType: string
{
    case Once = 'once';
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Workdays = 'workdays';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::Once => 'Одноразово',
            self::Daily => 'Каждый день',
            self::Weekly => 'Каждую неделю',
            self::Monthly => 'Каждый месяц',
            self::Workdays => 'По будням',
            self::Custom => 'Повторяющееся',
        };
    }
}
