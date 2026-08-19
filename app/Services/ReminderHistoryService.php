<?php

namespace App\Services;

use App\Enums\PremiumFeature;
use App\Models\ReminderHistory;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

final readonly class ReminderHistoryService
{
    public function __construct(private PremiumGate $gate) {}

    /** @return Collection<int, ReminderHistory> */
    public function recent(User $user, int $limit = 10): Collection
    {
        $this->gate->authorize($user, PremiumFeature::History);

        return ReminderHistory::query()
            ->where('owner_id', $user->id)
            ->latest()
            ->limit(max(1, min($limit, 100)))
            ->get();
    }
}
