<?php

namespace App\DTO;

use App\Models\PremiumSubscription;

final readonly class PremiumPurchaseResult
{
    public function __construct(
        public PremiumSubscription $subscription,
        public bool $wasCreated,
    ) {}
}
