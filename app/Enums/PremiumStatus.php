<?php

namespace App\Enums;

enum PremiumStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Refunded = 'refunded';
}
