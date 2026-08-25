<?php

declare(strict_types=1);

namespace Sandstorm\FilamentKeycloakAdmin\Tests;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Illuminate\Config\Repository;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase as Orchestra;
use RyanChandler\BladeCaptureDirective\BladeCaptureDirectiveServiceProvider;
use Sandstorm\FilamentKeycloakAdmin\FilamentKeycloakAdminServiceProvider;

/**
 * Boots Laravel via Testbench with the Filament stack and this plugin's provider registered — a
 * Filament plugin needs a booted app to register pages and resolve the shared Keycloak adapter. There
 * is no database: Keycloak is the source of truth, so no migrations or factories are involved.
 */
abstract class TestCase extends Orchestra
{
    use WithWorkbench;

    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            BladeCaptureDirectiveServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            BladeIconsServiceProvider::class,

            FilamentServiceProvider::class,
            ActionsServiceProvider::class,
            TablesServiceProvider::class,
            SchemasServiceProvider::class,
            SupportServiceProvider::class,
            FormsServiceProvider::class,
            InfolistsServiceProvider::class,

            // CRUCIAL THAT THIS COMES LAST, just before this package's provider - otherwise
            // tests fail with REALLY hard to debug Illuminate\Support\ViewErrorBag::put(): Argument #2 ($bag) must be of type Illuminate\Contracts\Support\MessageBag, null given, called in ...livewire/livewire/src/Features/SupportValidation/SupportValidation.php on line 22
            LivewireServiceProvider::class,
            FilamentKeycloakAdminServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app)
    {
        // Setup default database to use sqlite :memory:
        tap($app['config'], function (Repository $config) {
            $config->set('app.key', 'base64:hnuu2C3jj0wBW3IYrtNrRjXpK+qE5PnQa65nvcHeq90=');
        });
    }
}
