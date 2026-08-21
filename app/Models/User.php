<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class User extends Model
{
    use HasFactory;

    protected $hidden = ['calendar_token'];

    protected $fillable = [
        'telegram_id',
        'username',
        'first_name',
        'last_name',
        'language_code',
        'acquisition_source',
        'timezone',
        'is_premium',
        'premium_expires_at',
        'calendar_token',
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

    /** @return HasMany<UserActivityEvent, $this> */
    public function activityEvents(): HasMany
    {
        return $this->hasMany(UserActivityEvent::class);
    }

    /**
     * @return HasMany<Category, $this>
     */
    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    /** @return HasMany<Tag, $this> */
    public function tags(): HasMany
    {
        return $this->hasMany(Tag::class);
    }

    /** @return HasMany<SharedList, $this> */
    public function ownedSharedLists(): HasMany
    {
        return $this->hasMany(SharedList::class, 'owner_id');
    }

    /** @return HasMany<SharedListMember, $this> */
    public function sharedListMemberships(): HasMany
    {
        return $this->hasMany(SharedListMember::class);
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

    /** @return HasMany<PaymentSupportRequest, $this> */
    public function paymentSupportRequests(): HasMany
    {
        return $this->hasMany(PaymentSupportRequest::class);
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
