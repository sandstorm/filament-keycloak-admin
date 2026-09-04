<?php

declare(strict_types=1);

namespace Sandstorm\FilamentKeycloakAdmin\Tests\Support;

use Filament\FilamentManager;
use Filament\Models\Contracts\HasAvatar;
use Filament\Models\Contracts\HasName;
use Illuminate\Auth\GenericUser;

/**
 * A logged-in admin for tests that render a real Filament panel layout (topbar user menu, notifications
 * component included) — plain {@see GenericUser} is not enough there: {@see
 * \Filament\FilamentManager::getUserName()} and {@see FilamentManager::getUserAvatarUrl()} both
 * fall back to `Model::getAttributeValue(...)` for anything that is not a {@see HasName}/{@see HasAvatar},
 * and Filament's notifications Livewire component calls `getKey()` (an Eloquent method) to build its
 * broadcast channel name — none of which a non-Eloquent user has. Tests that only exercise a Livewire
 * component in isolation (never rendering the topbar) can keep using bare `GenericUser`.
 */
final class FakeAdminUser extends GenericUser implements HasAvatar, HasName
{
    public function getFilamentName(): string
    {
        return (string) ($this->attributes['name'] ?? 'Test Admin');
    }

    public function getFilamentAvatarUrl(): ?string
    {
        return null;
    }

    public function getKey(): mixed
    {
        return $this->attributes['id'] ?? null;
    }
}
