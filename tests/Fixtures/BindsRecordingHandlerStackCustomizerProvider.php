<?php

declare(strict_types=1);

namespace Sandstorm\FilamentKeycloakAdmin\Tests\Fixtures;

use Illuminate\Support\ServiceProvider;
use Sandstorm\FilamentKeycloakAdmin\FilamentKeycloakAdminServiceProvider;
use Sandstorm\FilamentKeycloakAdmin\Http\KeycloakAdminHttpHandlerStackCustomizer;

/**
 * Binds a {@see RecordingHandlerStackCustomizer} before {@see FilamentKeycloakAdminServiceProvider}
 * registers — `getEnvironmentSetUp()` runs too late for this, since `buildHttpClient()` (which checks for
 * the binding) executes synchronously during provider registration, before `getEnvironmentSetUp()` fires.
 * List this provider ahead of the package provider in a test's `getPackageProviders()`.
 */
final class BindsRecordingHandlerStackCustomizerProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->instance(KeycloakAdminHttpHandlerStackCustomizer::class, new RecordingHandlerStackCustomizer);
    }
}
