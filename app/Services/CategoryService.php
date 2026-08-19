<?php

namespace App\Services;

use App\Enums\PremiumFeature;
use App\Models\Category;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

final readonly class CategoryService
{
    public function __construct(private PremiumGate $gate) {}

    /** @return Collection<int, Category> */
    public function list(User $user): Collection
    {
        $this->gate->authorize($user, PremiumFeature::Categories);

        return $user->categories()->orderBy('name')->get();
    }

    public function create(User $user, string $name, string $color = '#3b82f6'): Category
    {
        $this->gate->authorize($user, PremiumFeature::Categories);
        $this->validate($name, $color);

        return $user->categories()->create(['name' => trim($name), 'color' => strtolower($color)]);
    }

    public function update(User $user, Category $category, string $name, string $color): Category
    {
        $this->gate->authorize($user, PremiumFeature::Categories);
        $this->assertOwner($user, $category);
        $this->validate($name, $color);
        $category->update(['name' => trim($name), 'color' => strtolower($color)]);

        return $category->refresh();
    }

    public function delete(User $user, Category $category): void
    {
        $this->gate->authorize($user, PremiumFeature::Categories);
        $this->assertOwner($user, $category);
        $category->delete();
    }

    private function validate(string $name, string $color): void
    {
        if (trim($name) === '' || mb_strlen(trim($name)) > 50 || preg_match('/^#[0-9a-fA-F]{6}$/', $color) !== 1) {
            throw new InvalidArgumentException('Invalid category name or color.');
        }
    }

    private function assertOwner(User $user, Category $category): void
    {
        if ($category->user_id !== $user->id) {
            throw new AuthorizationException('Category belongs to another user.');
        }
    }
}
