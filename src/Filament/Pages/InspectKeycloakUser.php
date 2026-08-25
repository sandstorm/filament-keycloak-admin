<?php

declare(strict_types=1);

namespace Sandstorm\FilamentKeycloakAdmin\Filament\Pages;

use Filament\Pages\Page;
use Filament\Panel;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Schema;
use Sandstorm\FilamentKeycloakAdmin\Filament\Pages\InspectKeycloakUser\KeycloakAdminEventsTable;
use Sandstorm\FilamentKeycloakAdmin\Filament\Pages\InspectKeycloakUser\KeycloakUserCredentialsTable;
use Sandstorm\FilamentKeycloakAdmin\Filament\Pages\InspectKeycloakUser\KeycloakUserEventsTable;
use Sandstorm\FilamentKeycloakAdmin\Filament\Pages\InspectKeycloakUser\KeycloakUserGroupsTable;
use Sandstorm\FilamentKeycloakAdmin\Filament\Pages\InspectKeycloakUser\KeycloakUserIdentity;
use Sandstorm\FilamentKeycloakAdmin\Filament\Pages\InspectKeycloakUser\KeycloakUserSessionsTable;
use Sandstorm\FilamentKeycloakAdmin\FilamentKeycloakAdminPlugin;
use Sandstorm\KeycloakAdminApi\Features\KeycloakUsersApi;
use Sandstorm\KeycloakAdminApi\Features\KeycloakUsersApi\Dto\KeycloakUser;
use Sandstorm\KeycloakAdminApi\SharedModel\KeycloakUserId;

/**
 * Detail page for one Keycloak user, on its own route (`/keycloak-users/{userId}`) so a user is
 * deep-linkable and shareable — not a modal. Named **Inspect** because the embedded sections carry write
 * actions (identity edit + enable toggle, group membership, credential/password operations) alongside
 * the reads.
 *
 * The page fetches almost nothing itself — it is a **tab orchestrator**: {@see self::detailSchema()}
 * builds Filament `Tabs`, each embedding one or more child Livewire components that own their own
 * fetch. Every failure (including 401/403) propagates to the framework error page (plan §8).
 */
final class InspectKeycloakUser extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'keycloak-users/inspect';

    protected string $view = 'filament-keycloak-admin::filament.pages.inspect-keycloak-user';

    public ?string $userId = null;

    protected KeycloakUsersApi $usersApi;

    /**
     * The user, fetched once for the heading. `false` = not yet fetched (distinct from a real `null`
     * field, which no heading needs since the fetch either returns a user or propagates its failure).
     */
    private KeycloakUser | false $resolvedUser = false;

    public function boot(KeycloakUsersApi $usersApi): void
    {
        $this->usersApi = $usersApi;
    }

    /**
     * The same gate as the list page — the detail page has its own route, so it must be authorized in
     * its own right and not merely be unreachable through a hidden menu entry.
     */
    public static function canAccess(): bool
    {
        return FilamentKeycloakAdminPlugin::get()->isAuthorized();
    }

    public function mount(string $userId): void
    {
        $this->userId = $userId;
    }

    public function getTitle(): string
    {
        return $this->resolveUser()->username;
    }

    public function getSubheading(): ?string
    {
        return $this->resolveUser()->email;
    }

    private function resolveUser(): KeycloakUser
    {
        if ($this->resolvedUser !== false) {
            return $this->resolvedUser;
        }

        return $this->resolvedUser = $this->usersApi->getById(new KeycloakUserId((string) $this->userId));
    }

    /**
     * Route with the `{userId}` parameter — the reason this page has its own route:
     * `getUrl(['userId' => ...])` then yields a stable, shareable link to one user.
     */
    public static function getRoutePath(Panel $panel): string
    {
        return '/keycloak-users/{userId}';
    }

    /**
     * One tab per section, each embedding its Livewire child component. The active tab is persisted in
     * the query string (deep-linkable). Tabs after Overview are lazy — fetched on first open.
     */
    public function detailSchema(Schema $schema): Schema
    {
        $userId = ['userId' => $this->userId];

        return $schema->components([
            Tabs::make()
                ->persistTabInQueryString()
                ->tabs([
                    Tabs\Tab::make('Overview')
                        ->schema([
                            Livewire::make(KeycloakUserIdentity::class, $userId),
                            Livewire::make(KeycloakUserGroupsTable::class, $userId),
                            Livewire::make(KeycloakUserCredentialsTable::class, $userId),
                        ]),
                    Tabs\Tab::make('Active sessions')
                        ->schema([Livewire::make(KeycloakUserSessionsTable::class, $userId)->lazy()]),
                    Tabs\Tab::make('User events')
                        ->schema([Livewire::make(KeycloakUserEventsTable::class, $userId)->lazy()]),
                    Tabs\Tab::make('Admin history')
                        ->schema([Livewire::make(KeycloakAdminEventsTable::class, $userId)->lazy()]),
                ]),
        ]);
    }
}
