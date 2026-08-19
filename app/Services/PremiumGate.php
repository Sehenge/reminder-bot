<?php

namespace App\Services;

use App\Enums\PremiumFeature;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final class PremiumGate
{
    public function allows(User $user, PremiumFeature $feature): bool
    {
        return $user->hasPremium();
    }

    /** @throws AuthorizationException */
    public function authorize(User $user, PremiumFeature $feature): void
    {
        if (! $this->allows($user, $feature)) {
            throw new AuthorizationException("Premium feature '{$feature->value}' is not available.");
        }
    }
}
