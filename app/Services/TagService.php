<?php

namespace App\Services;

use App\Enums\PremiumFeature;
use App\Models\Reminder;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

final readonly class TagService
{
    public function __construct(private PremiumGate $gate) {}

    /** @return Collection<int, Tag> */
    public function list(User $user): Collection
    {
        $this->gate->authorize($user, PremiumFeature::Tags);

        return $user->tags()->orderBy('name')->get();
    }

    public function create(User $user, string $name, string $color = '#64748b'): Tag
    {
        $this->gate->authorize($user, PremiumFeature::Tags);
        $this->validate($name, $color);

        return $user->tags()->create(['name' => trim($name), 'color' => strtolower($color)]);
    }

    public function update(User $user, Tag $tag, string $name, string $color): Tag
    {
        $this->gate->authorize($user, PremiumFeature::Tags);
        $this->assertOwner($user, $tag);
        $this->validate($name, $color);
        $tag->update(['name' => trim($name), 'color' => strtolower($color)]);

        return $tag->refresh();
    }

    public function delete(User $user, Tag $tag): void
    {
        $this->gate->authorize($user, PremiumFeature::Tags);
        $this->assertOwner($user, $tag);
        $tag->delete();
    }

    /** @param array<int, int> $tagIds */
    public function syncReminder(User $user, Reminder $reminder, array $tagIds): void
    {
        $this->gate->authorize($user, PremiumFeature::Tags);

        if ($reminder->user_id !== $user->id) {
            throw new AuthorizationException('Reminder belongs to another user.');
        }

        $ownedIds = $user->tags()->whereKey($tagIds)->pluck('id')->all();
        if (count($ownedIds) !== count(array_unique($tagIds))) {
            throw new AuthorizationException('One or more tags belong to another user.');
        }

        $reminder->tags()->sync($ownedIds);
    }

    private function validate(string $name, string $color): void
    {
        if (trim($name) === '' || mb_strlen(trim($name)) > 50 || preg_match('/^#[0-9a-fA-F]{6}$/', $color) !== 1) {
            throw new InvalidArgumentException('Invalid tag name or color.');
        }
    }

    private function assertOwner(User $user, Tag $tag): void
    {
        if ($tag->user_id !== $user->id) {
            throw new AuthorizationException('Tag belongs to another user.');
        }
    }
}
