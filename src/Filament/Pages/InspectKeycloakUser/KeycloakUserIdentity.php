<?php

declare(strict_types=1);

namespace Sandstorm\FilamentKeycloakAdmin\Filament\Pages\InspectKeycloakUser;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;
use Sandstorm\KeycloakAdminApi\Features\KeycloakUsersApi;
use Sandstorm\KeycloakAdminApi\SharedModel\KeycloakUserId;

use function in_array;
use function view;

/**
 * Identity section of the detail page — the key/value fields (username, email, name, enabled,
 * email-verified) plus a pending-TOTP note. Its own Livewire component so the host page fetches
 * nothing. Every failure (including 401/403) propagates (plan §8).
 */
final class KeycloakUserIdentity extends Component implements HasSchemas
{
    use InteractsWithSchemas;

    public string $userId;

    protected KeycloakUsersApi $usersApi;

    public function boot(KeycloakUsersApi $usersApi): void
    {
        $this->usersApi = $usersApi;
    }

    public function mount(string $userId): void
    {
        $this->userId = $userId;
    }

    /**
     * A write in a sibling table (e.g. a credential removal) can change identity state, so re-render on
     * the shared cross-tab signal — the infolist rebuilds from a fresh fetch (plan §7.2).
     */
    #[On('keycloak-user-changed')]
    public function refresh(): void
    {
    }

    public function identityInfolist(Schema $schema): Schema
    {
        $user = $this->usersApi->getById(new KeycloakUserId($this->userId));

        return $schema->components([
            TextEntry::make('username')->state($user->username),
            TextEntry::make('email')->state($user->email)->placeholder('—'),
            TextEntry::make('name')->label('Name')->state($user->fullName())->placeholder('—'),
            IconEntry::make('enabled')->boolean()->state($user->enabled),
            IconEntry::make('emailVerified')->label('Email verified')->boolean()->state($user->emailVerified),
            TextEntry::make('totpPending')
                ->hiddenLabel()
                ->state('TOTP setup pending — the user must configure an authenticator at next login.')
                ->color('warning')
                ->columnSpanFull()
                ->visible(in_array('CONFIGURE_TOTP', $user->requiredActions, true)),
        ])->columns(2);
    }

    public function render(): View
    {
        return view('filament-keycloak-admin::livewire.keycloak-infolist');
    }
}
