<?php

namespace App\Services;

use App\Enums\PremiumFeature;
use App\Enums\SharedListRole;
use App\Models\Reminder;
use App\Models\SharedList;
use App\Models\SharedListMember;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class SharedListService
{
    public function __construct(private PremiumGate $gate) {}

    public function create(User $owner, string $name): SharedList
    {
        $this->gate->authorize($owner, PremiumFeature::SharedLists);
        if (trim($name) === '' || mb_strlen(trim($name)) > 100) {
            throw new InvalidArgumentException('Invalid shared list name.');
        }

        return DB::transaction(function () use ($owner, $name): SharedList {
            $list = SharedList::query()->create(['owner_id' => $owner->id, 'name' => trim($name)]);
            $list->members()->create([
                'user_id' => $owner->id,
                'role' => SharedListRole::Owner,
                'accepted_at' => now(),
            ]);

            return $list;
        });
    }

    public function invite(User $owner, SharedList $list, User $invitee, SharedListRole $role): SharedListMember
    {
        $this->gate->authorize($owner, PremiumFeature::SharedLists);
        $this->gate->authorize($invitee, PremiumFeature::SharedLists);
        $this->assertOwner($owner, $list);
        if ($role === SharedListRole::Owner || $invitee->id === $owner->id) {
            throw new InvalidArgumentException('Invalid invitation role or user.');
        }

        return $list->members()->updateOrCreate(
            ['user_id' => $invitee->id],
            ['role' => $role, 'accepted_at' => null],
        );
    }

    public function accept(User $user, SharedList $list): SharedListMember
    {
        $this->gate->authorize($user, PremiumFeature::SharedLists);
        $member = $list->members()->where('user_id', $user->id)->first();
        if ($member === null) {
            throw new AuthorizationException('No invitation exists for this list.');
        }
        $member->update(['accepted_at' => now()]);

        return $member->refresh();
    }

    /** @return Collection<int, SharedList> */
    public function accessible(User $user): Collection
    {
        $this->gate->authorize($user, PremiumFeature::SharedLists);

        return SharedList::query()
            ->whereHas('members', fn ($query) => $query->where('user_id', $user->id)->whereNotNull('accepted_at'))
            ->orderBy('name')
            ->get();
    }

    public function attachReminder(User $actor, SharedList $list, Reminder $reminder): void
    {
        $this->gate->authorize($actor, PremiumFeature::SharedLists);
        $member = $this->acceptedMember($actor, $list);
        if (! $member->role->canEdit()) {
            throw new AuthorizationException('Viewer cannot edit shared reminders.');
        }
        if ($reminder->user_id !== $actor->id && $reminder->shared_list_id !== $list->id) {
            throw new AuthorizationException('Reminder does not belong to the acting member.');
        }
        $reminder->update(['shared_list_id' => $list->id]);
    }

    public function setTelegramChat(User $owner, SharedList $list, int $chatId): void
    {
        $this->gate->authorize($owner, PremiumFeature::SharedLists);
        $this->assertOwner($owner, $list);
        $list->update(['telegram_chat_id' => (string) $chatId]);
    }

    public function assertCanEdit(User $actor, SharedList $list): void
    {
        $this->gate->authorize($actor, PremiumFeature::SharedLists);
        if (! $this->acceptedMember($actor, $list)->role->canEdit()) {
            throw new AuthorizationException('Viewer cannot edit this list.');
        }
    }

    private function acceptedMember(User $user, SharedList $list): SharedListMember
    {
        $member = $list->members()->where('user_id', $user->id)->whereNotNull('accepted_at')->first();
        if ($member === null) {
            throw new AuthorizationException('User is not an accepted list member.');
        }

        return $member;
    }

    private function assertOwner(User $user, SharedList $list): void
    {
        if ($list->owner_id !== $user->id) {
            throw new AuthorizationException('Only the list owner can invite members.');
        }
    }
}
