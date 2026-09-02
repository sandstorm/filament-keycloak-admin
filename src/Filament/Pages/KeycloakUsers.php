<?php

declare(strict_types=1);

namespace Sandstorm\FilamentKeycloakAdmin\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Sandstorm\FilamentKeycloakAdmin\Filament\Helpers\KeycloakRecord;
use Sandstorm\FilamentKeycloakAdmin\FilamentKeycloakAdminPlugin;
use Sandstorm\KeycloakAdminApi\Connection\UnexpectedKeycloakResponseException;
use Sandstorm\KeycloakAdminApi\Features\KeycloakUsersApi;
use Sandstorm\KeycloakAdminApi\Features\KeycloakUsersApi\Dto\KeycloakUser;
use UnitEnum;

use function array_map;
use function assert;

/**
 * The Keycloak user list — a custom Filament Page hosting a table (not a Resource): Keycloak has no
 * local Eloquent table, and Filament's custom-data (`records()`) support is a Tables-only feature with
 * no model-less Resource. The `$view` blade renders `{{ $this->table }}`.
 *
 * Each page maps directly onto a Keycloak Admin API query — server-side search + pagination through
 * {@see KeycloakUsersApi}, no local mirror. A failed Keycloak call ({@see UnexpectedKeycloakResponseException})
 * is caught around the query and surfaced as the table's empty state instead of a 500 page or a plain
 * empty table — see {@see self::loadUsers()}.
 */
final class KeycloakUsers extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament-keycloak-admin::filament.pages.keycloak-users';

    protected KeycloakUsersApi $usersApi;

    private ?UnexpectedKeycloakResponseException $loadError = null;

    public function boot(KeycloakUsersApi $usersApi): void
    {
        $this->usersApi = $usersApi;
    }

    // How this page presents itself in the panel is owned by the plugin instance, so a consuming app
    // configures it once on `->plugin(...)` instead of subclassing the page.

    public static function canAccess(): bool
    {
        return FilamentKeycloakAdminPlugin::get()->isAuthorized();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return FilamentKeycloakAdminPlugin::get()->shouldRegisterNavigation();
    }

    public static function getNavigationLabel(): string
    {
        return FilamentKeycloakAdminPlugin::get()->getNavigationLabel();
    }

    public static function getNavigationGroup(): string | UnitEnum | null
    {
        return FilamentKeycloakAdminPlugin::get()->getNavigationGroup();
    }

    public static function getNavigationParentItem(): ?string
    {
        return FilamentKeycloakAdminPlugin::get()->getNavigationParentItem();
    }

    public static function getNavigationIcon(): string | BackedEnum | null
    {
        return FilamentKeycloakAdminPlugin::get()->getNavigationIcon();
    }

    public static function getNavigationSort(): ?int
    {
        return FilamentKeycloakAdminPlugin::get()->getNavigationSort();
    }

    public function getTitle(): string
    {
        return FilamentKeycloakAdminPlugin::get()->getNavigationLabel();
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (int $page, int $recordsPerPage, ?string $search): LengthAwarePaginator => $this->loadUsers($page, $recordsPerPage, $search))
            ->columns([
                // Keycloak's GET /users has no order param (fixed username order), so only username is
                // marked sortable — faking global sort by ordering one page would mislead (plan §4).
                TextColumn::make('username')->searchable()->state(fn (KeycloakRecord $record): string => self::user($record)->username),
                TextColumn::make('email')->searchable()->state(fn (KeycloakRecord $record): ?string => self::user($record)->email)->placeholder('—'),
                TextColumn::make('name')->state(fn (KeycloakRecord $record): ?string => self::user($record)->fullName())->placeholder('—'),
                IconColumn::make('enabled')->boolean()->state(fn (KeycloakRecord $record): bool => self::user($record)->enabled),
            ])
            // The whole row links to the user's stable, shareable detail address.
            ->recordUrl(fn (KeycloakRecord $record): string => InspectKeycloakUser::getUrl(['userId' => $record->getKey()]))
            // Evaluated after ->records() on the same render, so $this->loadError (set inside loadUsers())
            // is already known by the time these read it.
            ->emptyStateHeading(fn (): string => $this->loadError === null
                ? __('filament-keycloak-admin::filament-keycloak-admin.users.empty.heading')
                : __('filament-keycloak-admin::filament-keycloak-admin.users.load_error.heading'))
            ->emptyStateDescription(fn (): ?string => $this->loadError === null
                ? null
                : self::describeLoadError($this->loadError))
            ->emptyStateIcon(fn (): ?BackedEnum => $this->loadError === null
                ? null
                : Heroicon::OutlinedExclamationTriangle);
    }

    private function loadUsers(int $page, int $recordsPerPage, ?string $search): LengthAwarePaginator
    {
        $this->loadError = null;
        $first = ($page - 1) * $recordsPerPage;

        try {
            $users = $this->usersApi->list($search, $first, $recordsPerPage, null);
            $total = $this->usersApi->count($search, null);
        } catch (UnexpectedKeycloakResponseException $exception) {
            $this->loadError = $exception;

            return new LengthAwarePaginator([], 0, $recordsPerPage, $page);
        }

        $records = array_map(
            static fn (KeycloakUser $user): KeycloakRecord => KeycloakRecord::for($user->id->value, $user),
            $users->all(),
        );

        return new LengthAwarePaginator($records, $total, $recordsPerPage, $page);
    }

    private static function describeLoadError(UnexpectedKeycloakResponseException $exception): string
    {
        return match (true) {
            $exception->statusCode === null => __('filament-keycloak-admin::filament-keycloak-admin.users.load_error.unreachable'),
            in_array($exception->statusCode, [401, 403], strict: true) => __('filament-keycloak-admin::filament-keycloak-admin.users.load_error.forbidden'),
            $exception->statusCode >= 500 => __('filament-keycloak-admin::filament-keycloak-admin.users.load_error.server_error', ['status' => $exception->statusCode]),
            default => __('filament-keycloak-admin::filament-keycloak-admin.users.load_error.unexpected', ['status' => $exception->statusCode]),
        };
    }

    private static function user(KeycloakRecord $record): KeycloakUser
    {
        $user = $record->dto();
        assert($user instanceof KeycloakUser);

        return $user;
    }
}
