<?php

namespace App\Models;

use App\Enums\SharedListRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SharedListMember extends Model
{
    protected $fillable = ['shared_list_id', 'user_id', 'role', 'accepted_at'];

    protected $casts = [
        'role' => SharedListRole::class,
        'accepted_at' => 'datetime',
    ];

    /** @return BelongsTo<SharedList, $this> */
    public function sharedList(): BelongsTo
    {
        return $this->belongsTo(SharedList::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
