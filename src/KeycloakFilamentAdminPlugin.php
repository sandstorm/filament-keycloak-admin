<?php

namespace Broodfonds\KeycloakFilamentAdmin;

use Broodfonds\KeycloakFilamentAdmin\Filament\Pages\KeycloakUsers;
use Broodfonds\KeycloakFilamentAdmin\Filament\Pages\ViewKeycloakUser;
use Filament\Contracts\Plugin;
use Filament\Panel;

class KeycloakFilamentAdminPlugin implements Plugin
{
    public function getId(): string
    {
        return 'keycloak-filament-admin';
    }

    public function register(Panel $panel): void
    {
        $panel->pages([
            KeycloakUsers::class,
            ViewKeycloakUser::class,
        ]);
    }

    public function boot(Panel $panel): void
    {
        //
    }

    public static function make(): static
    {
        return app(static::class);
    }

    public static function get(): static
    {
        /** @var static $plugin */
        $plugin = filament(app(static::class)->getId());

        return $plugin;
    }
}
