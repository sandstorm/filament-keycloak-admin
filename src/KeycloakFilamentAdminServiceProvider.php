<?php

namespace Broodfonds\KeycloakFilamentAdmin;

use Broodfonds\KeycloakFilamentAdmin\Commands\KeycloakFilamentAdminCommand;
use Broodfonds\KeycloakFilamentAdmin\Keycloak\ConfigKeycloakSettingsProvider;
use Broodfonds\KeycloakFilamentAdmin\Livewire\KeycloakAdminEventsTable;
use Broodfonds\KeycloakFilamentAdmin\Livewire\KeycloakUserCredentialsTable;
use Broodfonds\KeycloakFilamentAdmin\Livewire\KeycloakUserEventsTable;
use Broodfonds\KeycloakFilamentAdmin\Livewire\KeycloakUserGroupsTable;
use Broodfonds\KeycloakFilamentAdmin\Livewire\KeycloakUserIdentity;
use Broodfonds\KeycloakFilamentAdmin\Livewire\KeycloakUserSessionsTable;
use Broodfonds\KeycloakFilamentAdmin\Testing\TestsKeycloakFilamentAdmin;
use Filament\Support\Assets\AlpineComponent;
use Filament\Support\Assets\Asset;
use Filament\Support\Assets\Css;
use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;
use Filament\Support\Facades\FilamentIcon;
use GuzzleHttp\Client;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Blade;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use RuntimeException;
use Sandstorm\KeycloakAdminApi\Connection\Auth\KeycloakTokenProvider;
use Sandstorm\KeycloakAdminApi\Connection\Auth\ServiceAccountTokenProvider;
use Sandstorm\KeycloakAdminApi\Features\KeycloakCredentialsApi;
use Sandstorm\KeycloakAdminApi\Features\KeycloakEventsApi;
use Sandstorm\KeycloakAdminApi\Features\KeycloakGroupsApi;
use Sandstorm\KeycloakAdminApi\Features\KeycloakSessionsApi;
use Sandstorm\KeycloakAdminApi\Features\KeycloakUsersApi;
use Sandstorm\KeycloakAdminApi\Features\KeycloakCredentialsApi\KeycloakCredentialsApiImplementation;
use Sandstorm\KeycloakAdminApi\Features\KeycloakEventsApi\KeycloakEventsApiImplementation;
use Sandstorm\KeycloakAdminApi\Features\KeycloakGroupsApi\KeycloakGroupsApiImplementation;
use Sandstorm\KeycloakAdminApi\Features\KeycloakSessionsApi\KeycloakSessionsApiImplementation;
use Sandstorm\KeycloakAdminApi\Features\KeycloakUsersApi\KeycloakUsersApiImplementation;
use Sandstorm\KeycloakAdminApi\Connection\KeycloakSettingsProvider;
use Sandstorm\KeycloakAdminApi\Connection\KeycloakTransport;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class KeycloakFilamentAdminServiceProvider extends PackageServiceProvider
{
    public static string $name = 'keycloak-filament-admin';

    public static string $viewNamespace = 'keycloak-filament-admin';

    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package->name(static::$name)
            ->hasCommands($this->getCommands())
            ->hasInstallCommand(function (InstallCommand $command) {
                $command
                    ->publishConfigFile()
                    ->publishMigrations()
                    ->askToRunMigrations()
                    ->askToStarRepoOnGitHub('broodfonds/keycloak-filament-admin');
            });

        $configFileName = $package->shortName();

        if (file_exists($package->basePath("/../config/{$configFileName}.php"))) {
            $package->hasConfigFile();
        }

        if (file_exists($package->basePath('/../database/migrations'))) {
            $package->hasMigrations($this->getMigrations());
        }

        if (file_exists($package->basePath('/../resources/lang'))) {
            $package->hasTranslations();
        }

        if (file_exists($package->basePath('/../resources/views'))) {
            $package->hasViews(static::$viewNamespace);
        }
    }

    public function packageRegistered(): void
    {
        $this->registerKeycloakAdapter();
    }

    /**
     * Wire the shared Keycloak adapter for the plugin: config-backed settings, a non-body-logging
     * Guzzle client (admin responses carry user PII), the token provider selected by `auth_mode`
     * (no fallback — a wrong/underprivileged mode fails loudly), the transport and the users API.
     */
    private function registerKeycloakAdapter(): void
    {
        $this->app->singleton(KeycloakSettingsProvider::class, ConfigKeycloakSettingsProvider::class);

        $this->app->singleton(KeycloakTokenProvider::class, function (Application $app): KeycloakTokenProvider {
            $authMode = config('keycloak-filament-admin.auth_mode');

            // Explicit mode, no auto-fallback (invariant #1/#2). SSO (act-as-user) lands in a later slice.
            return match ($authMode) {
                'service_account' => new ServiceAccountTokenProvider(
                    $app->make(KeycloakSettingsProvider::class),
                    self::buildHttpClient(),
                ),
                'sso' => throw new RuntimeException('Keycloak auth_mode "sso" is not implemented yet; use "service_account".', 1750000019),
                default => throw new RuntimeException(sprintf('Unknown Keycloak auth_mode "%s"; expected "service_account" or "sso".', (string) $authMode), 1750000020),
            };
        });

        $this->app->singleton(KeycloakTransport::class, fn (Application $app): KeycloakTransport => new KeycloakTransport(
            $app->make(KeycloakSettingsProvider::class),
            self::buildHttpClient(),
            $app->make(KeycloakTokenProvider::class),
        ));

        $this->app->singleton(KeycloakUsersApi::class, fn (Application $app): KeycloakUsersApi => new KeycloakUsersApiImplementation(
            $app->make(KeycloakTransport::class),
        ));

        // Detail-view read slices — each a focused collaborator over the same transport (§3.1).
        $this->app->singleton(KeycloakGroupsApi::class, fn (Application $app): KeycloakGroupsApi => new KeycloakGroupsApiImplementation(
            $app->make(KeycloakTransport::class),
        ));

        $this->app->singleton(KeycloakCredentialsApi::class, fn (Application $app): KeycloakCredentialsApi => new KeycloakCredentialsApiImplementation(
            $app->make(KeycloakTransport::class),
        ));

        $this->app->singleton(KeycloakSessionsApi::class, fn (Application $app): KeycloakSessionsApi => new KeycloakSessionsApiImplementation(
            $app->make(KeycloakTransport::class),
        ));

        $this->app->singleton(KeycloakEventsApi::class, fn (Application $app): KeycloakEventsApi => new KeycloakEventsApiImplementation(
            $app->make(KeycloakTransport::class),
        ));
    }

    /**
     * Register the `@keycloakboundary` / `@endkeycloakboundary` Blade directives — a rendering boundary
     * that turns the one *catchable* Keycloak failure into a friendly fallback. It buffers the enclosed
     * output and, on a {@see KeycloakAuthenticationException} (401/403 — §6.3), discards the partial
     * output, reports it, and renders the fallback partial with the given message. Because it lives
     * inside each Livewire component's own view, it wraps every render (initial + Livewire updates).
     * Only that exception is caught; everything else propagates (5xx/malformed → framework error).
     */
    private function registerKeycloakBoundaryDirective(): void
    {
        Blade::directive('keycloakboundary', static function (string $expression): string {
            $message = $expression === '' ? "''" : $expression;

            return "<?php try { ob_start(); \$__keycloakBoundaryMessage = {$message}; ?>";
        });

        Blade::directive('endkeycloakboundary', static fn (): string => <<<'PHP'
            <?php
            echo ob_get_clean();
            } catch (\Sandstorm\KeycloakAdminApi\Connection\KeycloakAuthenticationException $__keycloakBoundaryException) {
                ob_end_clean();
                report($__keycloakBoundaryException);
                echo view('keycloak-filament-admin::partials.keycloak-unavailable', ['message' => $__keycloakBoundaryMessage])->render();
            }
            ?>
            PHP);
    }

    /**
     * A plain Guzzle client with connect/read timeouts and NO logging middleware — the adapter
     * requires a client that never body-logs, since token requests carry the client secret and
     * admin responses carry user PII.
     */
    private static function buildHttpClient(): Client
    {
        return new Client([
            'connect_timeout' => (float) config('keycloak-filament-admin.http.connect_timeout', 5),
            'timeout' => (float) config('keycloak-filament-admin.http.timeout', 15),
        ]);
    }

    public function packageBooted(): void
    {
        // Package Livewire components are not auto-discovered (Livewire only scans the app's own
        // app/Livewire). Register the user-events table explicitly so `@livewire()` can resolve it.
        Livewire::component('keycloak-user-identity', KeycloakUserIdentity::class);
        Livewire::component('keycloak-user-groups-table', KeycloakUserGroupsTable::class);
        Livewire::component('keycloak-user-credentials-table', KeycloakUserCredentialsTable::class);
        Livewire::component('keycloak-user-sessions-table', KeycloakUserSessionsTable::class);
        Livewire::component('keycloak-user-events-table', KeycloakUserEventsTable::class);
        Livewire::component('keycloak-admin-events-table', KeycloakAdminEventsTable::class);

        $this->registerKeycloakBoundaryDirective();

        // Asset Registration
        FilamentAsset::register(
            $this->getAssets(),
            $this->getAssetPackageName()
        );

        FilamentAsset::registerScriptData(
            $this->getScriptData(),
            $this->getAssetPackageName()
        );

        // Icon Registration
        FilamentIcon::register($this->getIcons());

        // Handle Stubs
        if (app()->runningInConsole()) {
            foreach (app(Filesystem::class)->files(__DIR__ . '/../stubs/') as $file) {
                $this->publishes([
                    $file->getRealPath() => base_path("stubs/keycloak-filament-admin/{$file->getFilename()}"),
                ], 'keycloak-filament-admin-stubs');
            }
        }

        // Testing
        Testable::mixin(new TestsKeycloakFilamentAdmin);
    }

    protected function getAssetPackageName(): ?string
    {
        return 'broodfonds/keycloak-filament-admin';
    }

    /**
     * @return array<Asset>
     */
    protected function getAssets(): array
    {
        return [
            // AlpineComponent::make('keycloak-filament-admin', __DIR__ . '/../resources/dist/components/keycloak-filament-admin.js'),
            // Css::make('keycloak-filament-admin-styles', __DIR__ . '/../resources/dist/keycloak-filament-admin.css'),
            // Js::make('keycloak-filament-admin-scripts', __DIR__ . '/../resources/dist/keycloak-filament-admin.js'),
        ];
    }

    /**
     * @return array<class-string>
     */
    protected function getCommands(): array
    {
        return [
            KeycloakFilamentAdminCommand::class,
        ];
    }

    /**
     * @return array<string>
     */
    protected function getIcons(): array
    {
        return [];
    }

    /**
     * @return array<string>
     */
    protected function getRoutes(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getScriptData(): array
    {
        return [];
    }

    /**
     * @return array<string>
     */
    protected function getMigrations(): array
    {
        return [
            'create_keycloak-filament-admin_table',
        ];
    }
}
