<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ReminderHistory extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'reminder_history';

    protected $fillable = [
        'reminder_id', 'owner_id', 'actor_id', 'event_type', 'text',
        'target_at', 'is_completed', 'snapshot',
    ];

    protected $casts = [
        'target_at' => 'datetime',
        'is_completed' => 'boolean',
        'snapshot' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Reminder history is immutable.'));
        static::deleting(fn () => throw new LogicException('Reminder history is immutable.'));
    }

    /** @return BelongsTo<Reminder, $this> */
    public function reminder(): BelongsTo
    {
        return $this->belongsTo(Reminder::class);
    }
}
