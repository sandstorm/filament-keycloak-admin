<?php

declare(strict_types=1);

namespace Broodfonds\KeycloakFilamentAdmin\Filament\Pages;

use Broodfonds\KeycloakFilamentAdmin\Livewire\KeycloakAdminEventsTable;
use Broodfonds\KeycloakFilamentAdmin\Livewire\KeycloakUserCredentialsTable;
use Broodfonds\KeycloakFilamentAdmin\Livewire\KeycloakUserEventsTable;
use Broodfonds\KeycloakFilamentAdmin\Livewire\KeycloakUserGroupsTable;
use Broodfonds\KeycloakFilamentAdmin\Livewire\KeycloakUserIdentity;
use Broodfonds\KeycloakFilamentAdmin\Livewire\KeycloakUserSessionsTable;
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
use Sandstorm\KeycloakAdminApi\Connection\KeycloakAuthenticationException;

use function config;
use function report;

/**
 * Read-only detail page for a single Keycloak user, on its own route
 * (`/keycloak-users/{userId}`) so individual users are deep-linkable and shareable — not a modal.
 *
 * A dedicated Page (not a Filament Resource View page): Keycloak has no local Eloquent model, so the
 * user id is a plain route parameter (see the slice-2 Page-not-Resource decision in the plan).
 *
 * The page fetches nothing — it is a **tab orchestrator**. `detailSchema()` builds Filament's schema
 * `Tabs`, each tab embedding its Livewire component via `Schemas\Components\Livewire::make(...)`. Every
 * tab except Identity is `->lazy()`, so a hidden tab's data is only fetched when the tab is opened
 * (admin history in particular). `->persistTabInQueryString()` makes the active tab deep-linkable. Each
 * component owns its own fetch + three-state auth handling (via the `@keycloakboundary` in its view),
 * so the page needs no adapter dependencies.
 */
final class ViewKeycloakUser extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'keycloak-users/view';

    protected string $view = 'keycloak-filament-admin::filament.pages.view-keycloak-user';

    public ?string $userId = null;

    protected KeycloakCredentialsApi $credentialsApi;

    protected KeycloakUsersApi $usersApi;

    /**
     * Resolved once per request for the page heading (username/email). `false` = not yet fetched;
     * `null` = fetched but unavailable (auth failure) so we fall back to a generic heading.
     */
    private KeycloakUser | null | false $resolvedUser = false;

    public function boot(KeycloakCredentialsApi $credentialsApi, KeycloakUsersApi $usersApi): void
    {
        $this->credentialsApi = $credentialsApi;
        $this->usersApi = $usersApi;
    }

    public function mount(string $userId): void
    {
        $this->userId = $userId;
    }

    /**
     * Page heading = the username (falls back to a generic title if the user can't be resolved).
     */
    public function getTitle(): string
    {
        return $this->resolveUser()?->username ?? 'Keycloak user';
    }

    /**
     * Sub-heading = the email.
     */
    public function getSubheading(): ?string
    {
        return $this->resolveUser()?->email;
    }

    /**
     * True when the user could not be fetched because Keycloak rejected the caller (401/403). The page
     * blade uses this to show a single "unavailable" notice instead of the tabs — the one central gate
     * that lets the view-users-gated tabs (identity/groups/credentials/sessions) drop their own boundary.
     * The events tabs keep theirs: they need `view-events`, a permission this gate does not imply.
     */
    public function keycloakUserUnavailable(): bool
    {
        return $this->resolveUser() === null;
    }

    /**
     * Fetch the user once for the heading. The catchable auth failure (§6.3, invariant #9) degrades to a
     * generic heading rather than crashing the page — the tabs already surface the "unavailable" state.
     * Any other failure (unreachable/5xx) propagates, per the taxonomy.
     */
    private function resolveUser(): ?KeycloakUser
    {
        if ($this->resolvedUser !== false) {
            return $this->resolvedUser;
        }

        try {
            return $this->resolvedUser = $this->usersApi->getById(new KeycloakUserId((string) $this->userId));
        } catch (KeycloakAuthenticationException $exception) {
            report($exception);

            return $this->resolvedUser = null;
        }
    }

    /**
     * Header actions — user-level write operations (as opposed to the per-row/tab reads).
     *
     * First write (slice 6a): trigger a password-reset email. The preferred reset path (§5.4) — Keycloak
     * mails the user a time-limited UPDATE_PASSWORD link; the admin never sees or sets the password.
     */
    protected function getHeaderActions(): array
    {
        // No user resolved (auth failure) → no write actions to offer.
        if ($this->keycloakUserUnavailable()) {
            return [];
        }

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
                try {
                    $this->credentialsApi->executeActionsEmail(
                        new KeycloakUserId((string) $this->userId),
                        ['UPDATE_PASSWORD'],
                        config('keycloak-filament-admin.pw_reset.lifespan'),
                        config('keycloak-filament-admin.pw_reset.client_id'),
                        config('keycloak-filament-admin.pw_reset.redirect_uri'),
                    );
                } catch (KeycloakAuthenticationException $exception) {
                    // The one catchable failure (§6.3): not permitted / not signed in → friendly notice,
                    // still report()ed so Keycloak's upstream 401/403 body lands in the log. Everything
                    // else (SMTP not configured → 5xx, unreachable, malformed) propagates.
                    report($exception);

                    Notification::make()
                        ->title('Not authorized in Keycloak')
                        ->body('You lack permission to send this user a password-reset email. See the application log for the full Keycloak error.')
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Password-reset email sent')
                    ->body('Keycloak has emailed the user a link to set a new password.')
                    ->success()
                    ->send();
            });
    }

    /**
     * Route with the `{userId}` parameter — the reason this page exists as its own route:
     * `getUrl(['userId' => ...])` then yields a stable, shareable link to one user.
     */
    public static function getRoutePath(Panel $panel): string
    {
        return '/keycloak-users/{userId}';
    }

    /**
     * The detail schema: one tab per section, each embedding its Livewire component. The active tab is
     * persisted in the query string (deep-linkable). Tabs after Identity are lazy — fetched on open.
     */
    public function detailSchema(Schema $schema): Schema
    {
        $userId = ['userId' => $this->userId];

        return $schema->components([
            Tabs::make()
                ->persistTabInQueryString()
                ->tabs([
                    Tabs\Tab::make('Identity')
                        ->schema([Livewire::make(KeycloakUserIdentity::class, $userId)]),
                    Tabs\Tab::make('Groups')
                        ->schema([Livewire::make(KeycloakUserGroupsTable::class, $userId)->lazy()]),
                    Tabs\Tab::make('Security / 2FA')
                        ->schema([Livewire::make(KeycloakUserCredentialsTable::class, $userId)->lazy()]),
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
