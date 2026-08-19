<?php

namespace App\Enums;

enum DeliveryStatus: string
{
    case Pending = 'pending';
    case Dispatching = 'dispatching';
    case Sent = 'sent';
    case Failed = 'failed';
}
