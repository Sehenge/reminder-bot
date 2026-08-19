<?php

namespace App\Models;

use App\Enums\PremiumStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PremiumSubscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'product_id',
        'invoice_payload',
        'telegram_payment_charge_id',
        'stars_amount',
        'currency',
        'status',
        'starts_at',
        'purchased_at',
        'refunded_at',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'starts_at' => 'datetime',
        'purchased_at' => 'datetime',
        'refunded_at' => 'datetime',
        'stars_amount' => 'integer',
        'user_id' => 'integer',
        'status' => PremiumStatus::class,
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
