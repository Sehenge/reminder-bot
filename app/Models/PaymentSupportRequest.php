<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentSupportRequest extends Model
{
    protected $fillable = ['user_id', 'message', 'status', 'resolved_at'];

    protected $casts = ['resolved_at' => 'datetime'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
