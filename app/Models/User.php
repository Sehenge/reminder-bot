<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class User extends Model
{
    use HasFactory;

    protected $fillable = [
        'telegram_id',
        'username',
        'first_name',
        'last_name',
        'language_code',
        'timezone',
        'is_premium',
        'premium_expires_at',
        'state',
        'state_data',
    ];

    protected $casts = [
        'telegram_id' => 'integer',
        'is_premium' => 'boolean',
        'premium_expires_at' => 'datetime',
        'state_data' => 'array',
    ];

    /**
     * @return HasMany<Reminder, $this>
     */
    public function reminders(): HasMany
    {
        return $this->hasMany(Reminder::class);
    }

    /**
     * @return HasMany<Category, $this>
     */
    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    /**
     * @return HasMany<PremiumSubscription, $this>
     */
    public function premiumSubscriptions(): HasMany
    {
        return $this->hasMany(PremiumSubscription::class);
    }

    /** @return HasMany<PremiumPaymentEvent, $this> */
    public function premiumPaymentEvents(): HasMany
    {
        return $this->hasMany(PremiumPaymentEvent::class);
    }

    /**
     * Проверка, есть ли у пользователя активный премиум-статус
     */
    public function hasPremium(): bool
    {
        if ($this->is_premium) {
            if ($this->premium_expires_at === null || $this->premium_expires_at->isFuture()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Получить максимальный лимит активных напоминаний для пользователя
     */
    public function getMaxRemindersLimit(): int
    {
        return $this->hasPremium() ? 100000 : 30;
    }

    /**
     * Получить количество активных (невыполненных) напоминаний
     */
    public function getActiveRemindersCount(): int
    {
        return $this->reminders()->where('is_completed', false)->count();
    }

    /**
     * Проверить, может ли пользователь создать новое напоминание
     */
    public function canCreateReminder(): bool
    {
        return $this->getActiveRemindersCount() < $this->getMaxRemindersLimit();
    }
}
