<?php

declare(strict_types=1);

namespace Sandstorm\FilamentKeycloakAdmin;

use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Contracts\Foundation\Application;
use Livewire\Livewire;
use RuntimeException;
use Sandstorm\FilamentKeycloakAdmin\Filament\Pages\InspectKeycloakUser\KeycloakAdminEventsTable;
use Sandstorm\FilamentKeycloakAdmin\Filament\Pages\InspectKeycloakUser\KeycloakUserCredentialsTable;
use Sandstorm\FilamentKeycloakAdmin\Filament\Pages\InspectKeycloakUser\KeycloakUserEventsTable;
use Sandstorm\FilamentKeycloakAdmin\Filament\Pages\InspectKeycloakUser\KeycloakUserGroupsTable;
use Sandstorm\FilamentKeycloakAdmin\Filament\Pages\InspectKeycloakUser\KeycloakUserIdentity;
use Sandstorm\FilamentKeycloakAdmin\Filament\Pages\InspectKeycloakUser\KeycloakUserSessionsTable;
use Sandstorm\FilamentKeycloakAdmin\Auth\AdminKeycloakSession;
use Sandstorm\FilamentKeycloakAdmin\Auth\FilamentSsoTokenProvider;
use Sandstorm\FilamentKeycloakAdmin\Auth\HeloufirAdminKeycloakSession;
use Sandstorm\FilamentKeycloakAdmin\Keycloak\ConfigKeycloakSettingsProvider;
use GuzzleHttp\Client;
use Sandstorm\KeycloakAdminApi\Connection\Auth\KeycloakTokenProvider;
use Sandstorm\KeycloakAdminApi\Connection\Auth\ServiceAccountTokenProvider;
use Sandstorm\KeycloakAdminApi\Connection\KeycloakSettingsProvider;
use Sandstorm\KeycloakAdminApi\Connection\KeycloakTransport;
use Sandstorm\KeycloakAdminApi\Features\KeycloakCredentialsApi;
use Sandstorm\KeycloakAdminApi\Features\KeycloakCredentialsApi\KeycloakCredentialsApiImplementation;
use Sandstorm\KeycloakAdminApi\Features\KeycloakEventsApi;
use Sandstorm\KeycloakAdminApi\Features\KeycloakEventsApi\KeycloakEventsApiImplementation;
use Sandstorm\KeycloakAdminApi\Features\KeycloakGroupsApi;
use Sandstorm\KeycloakAdminApi\Features\KeycloakGroupsApi\KeycloakGroupsApiImplementation;
use Sandstorm\KeycloakAdminApi\Features\KeycloakSessionsApi;
use Sandstorm\KeycloakAdminApi\Features\KeycloakSessionsApi\KeycloakSessionsApiImplementation;
use Sandstorm\KeycloakAdminApi\Features\KeycloakUsersApi;
use Sandstorm\KeycloakAdminApi\Features\KeycloakUsersApi\KeycloakUsersApiImplementation;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

/**
 * Wires the client library {@see \Sandstorm\KeycloakAdminApi} into a Laravel/Filament panel: the
 * config-backed settings provider, a non-body-logging Guzzle client, the token provider selected by
 * `auth_mode`, the transport, and the five segregated `Keycloak*Api` implementations as singletons.
 * It also registers the detail page's child Livewire components (a package's components are not
 * auto-discovered) and the plugin's views/translations.
 *
 * The plugin never reads `env()`; it reads resolved `config('filament-keycloak-admin.*')` that the
 * consuming app owns (plan §9). Any config file this package publishes is a structure-only stub.
 */
class FilamentKeycloakAdminServiceProvider extends PackageServiceProvider
{
    public static string $name = 'filament-keycloak-admin';

    public static string $viewNamespace = 'filament-keycloak-admin';

    public function configurePackage(Package $package): void
    {
        $package->name(static::$name)
            ->hasConfigFile()
            ->hasTranslations()
            ->hasViews(static::$viewNamespace);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(KeycloakSettingsProvider::class, ConfigKeycloakSettingsProvider::class);

        $httpFactory = new HttpFactory(); // PSR-17 request + stream factory
        $client = self::buildHttpClient();

        // Explicit mode, no auto-fallback: a wrong/underprivileged mode fails loudly rather than
        // silently switching identity (plan §5). SSO (act-as-user) lands in a later slice.
        $this->app->singleton(KeycloakTokenProvider::class, function (Application $app) use ($httpFactory, $client): KeycloakTokenProvider {
            $authMode = config('filament-keycloak-admin.auth_mode');



            return match ($authMode) {
                'service_account' => new ServiceAccountTokenProvider(
                    $app->make(KeycloakSettingsProvider::class),
                    $client,
                    $httpFactory,
                    $httpFactory
                ),
                'sso' => new FilamentSsoTokenProvider(self::resolveAdminKeycloakSession($app)),
                default => throw new RuntimeException(sprintf('Unknown Keycloak auth_mode "%s"; expected "service_account" or "sso".', (string) $authMode), 1750000020),
            };
        });

        $this->app->singleton(KeycloakTransport::class, fn (Application $app): KeycloakTransport => new KeycloakTransport(
            $app->make(KeycloakSettingsProvider::class),
            $client,
            $httpFactory,
            $httpFactory,
            $app->make(KeycloakTokenProvider::class),
        ));

        $this->app->singleton(KeycloakUsersApi::class, fn (Application $app): KeycloakUsersApi => new KeycloakUsersApiImplementation($app->make(KeycloakTransport::class)));
        $this->app->singleton(KeycloakGroupsApi::class, fn (Application $app): KeycloakGroupsApi => new KeycloakGroupsApiImplementation($app->make(KeycloakTransport::class)));
        $this->app->singleton(KeycloakCredentialsApi::class, fn (Application $app): KeycloakCredentialsApi => new KeycloakCredentialsApiImplementation($app->make(KeycloakTransport::class)));
        $this->app->singleton(KeycloakSessionsApi::class, fn (Application $app): KeycloakSessionsApi => new KeycloakSessionsApiImplementation($app->make(KeycloakTransport::class)));
        $this->app->singleton(KeycloakEventsApi::class, fn (Application $app): KeycloakEventsApi => new KeycloakEventsApiImplementation($app->make(KeycloakTransport::class)));
    }

    public function packageBooted(): void
    {
        // A package's Livewire components are not auto-discovered (Livewire only scans the app's own
        // app/Livewire), so register each detail-tab component for `Livewire::make(...)` to resolve.
        Livewire::component('keycloak-user-identity', KeycloakUserIdentity::class);
        Livewire::component('keycloak-user-groups-table', KeycloakUserGroupsTable::class);
        Livewire::component('keycloak-user-credentials-table', KeycloakUserCredentialsTable::class);
        Livewire::component('keycloak-user-sessions-table', KeycloakUserSessionsTable::class);
        Livewire::component('keycloak-user-events-table', KeycloakUserEventsTable::class);
        Livewire::component('keycloak-admin-events-table', KeycloakAdminEventsTable::class);
    }

    /**
     * The token source for `sso` (act-as-user) mode. The app may bind its own {@see AdminKeycloakSession}
     * (any OIDC login library); otherwise this package falls back to the heloufir-backed adapter when
     * heloufir is installed. Selecting `sso` without either is a loud configuration error — never a
     * silent switch to the shared service account.
     */
    private static function resolveAdminKeycloakSession(Application $app): AdminKeycloakSession
    {
        if ($app->bound(AdminKeycloakSession::class)) {
            return $app->make(AdminKeycloakSession::class);
        }

        if (HeloufirAdminKeycloakSession::isAvailable()) {
            return new HeloufirAdminKeycloakSession();
        }

        throw new RuntimeException('Keycloak auth_mode "sso" needs an AdminKeycloakSession: bind one in the app, or install heloufir/filament-keycloak-sso for the bundled adapter.', 1755600010);
    }

    /**
     * A plain Guzzle client with connect/read timeouts and NO logging middleware — token requests
     * carry the client secret and admin responses carry user PII, so the client must never body-log.
     */
    private static function buildHttpClient(): Client
    {
        return new Client([
            'connect_timeout' => (float) config('filament-keycloak-admin.http.connect_timeout', 5),
            'timeout' => (float) config('filament-keycloak-admin.http.timeout', 15),
        ]);
    }
}
