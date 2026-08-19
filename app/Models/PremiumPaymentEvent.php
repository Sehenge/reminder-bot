<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class PremiumPaymentEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'event_key',
        'event_type',
        'user_id',
        'telegram_payment_charge_id',
        'product_id',
        'invoice_payload',
        'currency',
        'amount',
        'details',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'amount' => 'integer',
        'details' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Premium payment events are immutable.'));
        static::deleting(fn () => throw new LogicException('Premium payment events are immutable.'));
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
