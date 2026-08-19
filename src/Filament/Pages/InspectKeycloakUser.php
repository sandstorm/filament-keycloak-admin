<?php

declare(strict_types=1);

namespace Sandstorm\FilamentKeycloakAdmin\Filament\Pages;

use Sandstorm\FilamentKeycloakAdmin\Filament\Pages\InspectKeycloakUser\KeycloakAdminEventsTable;
use Sandstorm\FilamentKeycloakAdmin\Filament\Pages\InspectKeycloakUser\KeycloakUserCredentialsTable;
use Sandstorm\FilamentKeycloakAdmin\Filament\Pages\InspectKeycloakUser\KeycloakUserEventsTable;
use Sandstorm\FilamentKeycloakAdmin\Filament\Pages\InspectKeycloakUser\KeycloakUserGroupsTable;
use Sandstorm\FilamentKeycloakAdmin\Filament\Pages\InspectKeycloakUser\KeycloakUserIdentity;
use Sandstorm\FilamentKeycloakAdmin\Filament\Pages\InspectKeycloakUser\KeycloakUserSessionsTable;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Sandstorm\KeycloakAdminApi\Features\KeycloakCredentialsApi;
use Sandstorm\KeycloakAdminApi\Features\KeycloakUsersApi;
use Sandstorm\KeycloakAdminApi\Features\KeycloakUsersApi\Dto\KeycloakUser;
use Sandstorm\KeycloakAdminApi\SharedModel\KeycloakUserId;

use function config;

/**
 * Detail page for one Keycloak user, on its own route (`/keycloak-users/{userId}`) so a user is
 * deep-linkable and shareable — not a modal. Named **Inspect** because it carries write actions
 * (password-reset email, and the per-tab mutations) alongside the reads.
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

    protected KeycloakCredentialsApi $credentialsApi;

    protected KeycloakUsersApi $usersApi;

    /**
     * The user, fetched once for the heading. `false` = not yet fetched (distinct from a real `null`
     * field, which no heading needs since the fetch either returns a user or propagates its failure).
     */
    private KeycloakUser | false $resolvedUser = false;

    public function boot(KeycloakCredentialsApi $credentialsApi, KeycloakUsersApi $usersApi): void
    {
        $this->credentialsApi = $credentialsApi;
        $this->usersApi = $usersApi;
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
     * Header actions — user-level writes (as opposed to the per-tab reads/mutations).
     *
     * Send a password-reset email: Keycloak mails the user a time-limited UPDATE_PASSWORD link — the
     * preferred reset path, the admin never sees or sets the password (plan §7.2). Requires realm SMTP.
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->triggerPasswordResetAction(),
        ];
    }

    private function triggerPasswordResetAction(): Action
    {
        return Action::make('triggerPasswordReset')
            ->label('Send password-reset email')
            ->icon(Heroicon::OutlinedEnvelope)
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Send a password-reset email?')
            ->modalDescription('Keycloak will email this user a time-limited link to set a new password. You will not see or set the password yourself. Requires realm SMTP to be configured.')
            ->modalSubmitActionLabel('Send email')
            ->action(function (): void {
                $this->credentialsApi->executeActionsEmail(
                    new KeycloakUserId((string) $this->userId),
                    ['UPDATE_PASSWORD'],
                    config('filament-keycloak-admin.pw_reset.lifespan'),
                    config('filament-keycloak-admin.pw_reset.client_id'),
                    config('filament-keycloak-admin.pw_reset.redirect_uri'),
                );

                Notification::make()
                    ->title('Password-reset email sent')
                    ->body('Keycloak has emailed the user a link to set a new password.')
                    ->success()
                    ->send();
            });
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
