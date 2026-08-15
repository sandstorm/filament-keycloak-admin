<?php

declare(strict_types=1);

namespace Broodfonds\KeycloakFilamentAdmin\Filament\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Sandstorm\KeycloakAdminApi\Features\KeycloakUsersApi;
use Sandstorm\KeycloakAdminApi\Features\KeycloakUsersApi\Dto\KeycloakUser;
use Sandstorm\KeycloakAdminApi\Connection\KeycloakAuthenticationException;

use function array_map;
use function report;

/**
 * The Keycloak user management page.
 *
 * A custom Filament Page (not a Resource): Keycloak has no local Eloquent table, and Filament's
 * custom-data (`records()`) support is a Tables-only feature — there is no model-less Resource. So
 * the table lives on a Page that hosts it (hence the `$view` blade rendering `{{ $this->table }}`).
 *
 * Slice 4: the table is backed by the live Keycloak Admin API through the shared adapter's
 * {@see KeycloakUsersApi}. Every page is a server-side query (search + pagination forwarded to
 * Keycloak); there is no local mirror. Row/detail actions follow in later slices
 * (see docs/2026-08-12-keycloak-filament-extension-initial-plan.md).
 */
final class KeycloakUsers extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedUsers;

    protected string $view = 'keycloak-filament-admin::filament.pages.keycloak-users';

    protected KeycloakUsersApi $usersApi;

    public function boot(KeycloakUsersApi $usersApi): void
    {
        $this->usersApi = $usersApi;
    }

    public static function getNavigationLabel(): string
    {
        return 'Keycloak Users';
    }

    public function getTitle(): string
    {
        return 'Keycloak Users';
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (int $page, int $recordsPerPage, ?string $search): LengthAwarePaginator => $this->loadUsers($page, $recordsPerPage, $search))
            ->columns([
                TextColumn::make('username')->searchable(),
                TextColumn::make('email')->searchable(),
                TextColumn::make('name'),
                IconColumn::make('enabled')->boolean(),
            ])
            ->recordActions([
                $this->viewAction(),
            ]);
    }

    /**
     * Row action linking to the standalone {@see ViewKeycloakUser} detail route.
     *
     * A URL (not a modal) so each user has a stable, shareable, deep-linkable address
     * (`/keycloak-users/{userId}`).
     */
    private function viewAction(): Action
    {
        return Action::make('view')
            ->label('View')
            ->icon(Heroicon::OutlinedEye)
            ->url(fn (array $record): string => ViewKeycloakUser::getUrl(['userId' => $record['__key']]));
    }

    /**
     * Fetch one page of users from Keycloak and shape them into Filament array records (keyed by id).
     *
     * Only {@see KeycloakAuthenticationException} is caught — the expected "not signed in to Keycloak /
     * not permitted" outcome, degraded to a friendly notice + empty page (invariant #9). It is still
     * `report()`ed so the real 401/403 (with Keycloak's upstream error body) lands in the logs for
     * debugging. Every other failure (unreachable/5xx, misconfiguration, malformed response) propagates
     * so the framework logs it and a developer can act.
     */
    private function loadUsers(int $page, int $recordsPerPage, ?string $search): LengthAwarePaginator
    {
        $first = ($page - 1) * $recordsPerPage;

        try {
            $users = $this->usersApi->list($search, $first, $recordsPerPage, null);
            $total = $this->usersApi->count($search, null);
        } catch (KeycloakAuthenticationException $exception) {
            // report() logs the exception — with Keycloak's upstream 401/403 body — so the full detail
            // lives in the application log; the notice just points there.
            report($exception);

            Notification::make()
                ->title('Not authorized in Keycloak')
                ->body('You are not signed in to Keycloak, or lack permission to view users. See the application log for the full Keycloak error.')
                ->danger()
                ->send();

            return new LengthAwarePaginator([], 0, $recordsPerPage, $page);
        }

        $records = array_map(self::toRecord(...), $users->all());

        return new LengthAwarePaginator($records, $total, $recordsPerPage, $page);
    }

    /**
     * Map a domain DTO to the flat array row the table renders (keyed by Keycloak user id).
     *
     * @return array{__key: string, username: string, email: ?string, name: ?string, enabled: bool}
     */
    private static function toRecord(KeycloakUser $user): array
    {
        return [
            '__key' => $user->id->value,
            'username' => $user->username,
            'email' => $user->email,
            'name' => $user->fullName(),
            'enabled' => $user->enabled,
        ];
    }
}
