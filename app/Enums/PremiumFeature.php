<?php

namespace App\Enums;

enum PremiumFeature: string
{
    case Categories = 'categories';
    case Tags = 'tags';
    case History = 'history';
    case SharedLists = 'shared_lists';
    case CalendarExport = 'calendar_export';
}
