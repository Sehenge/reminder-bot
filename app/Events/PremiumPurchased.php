<?php

namespace App\Events;

use App\Models\PremiumSubscription;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class PremiumPurchased
{
    use Dispatchable, SerializesModels;

    public function __construct(public PremiumSubscription $subscription) {}
}
