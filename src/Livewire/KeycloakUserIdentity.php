<?php

declare(strict_types=1);

namespace Broodfonds\KeycloakFilamentAdmin\Livewire;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Sandstorm\KeycloakAdminApi\Features\KeycloakUsersApi;
use Sandstorm\KeycloakAdminApi\SharedModel\KeycloakUserId;

use function in_array;
use function view;

/**
 * Identity tab of the user detail page — the key/value fields (username, email, name, enabled,
 * email-verified) plus a pending-TOTP note. Its own Livewire component so the host page fetches nothing.
 * The catchable auth failure (§6.3, invariant #9) is handled by the `@keycloakboundary` wrapping the
 * infolist in this component's view — so this simply fetches and builds, letting it propagate.
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
        return view('keycloak-filament-admin::livewire.keycloak-user-identity');
    }
}
