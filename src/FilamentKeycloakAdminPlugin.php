<?php

declare(strict_types=1);

namespace Sandstorm\FilamentKeycloakAdmin;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Sandstorm\FilamentKeycloakAdmin\Filament\Pages\InspectKeycloakUser;
use Sandstorm\FilamentKeycloakAdmin\Filament\Pages\KeycloakUsers;

/**
 * Registers the Keycloak user-management pages on a Filament panel: the {@see KeycloakUsers} list and
 * the {@see InspectKeycloakUser} detail page. Register it on a panel with `->plugin(...)`.
 */
final class FilamentKeycloakAdminPlugin implements Plugin
{
    public function getId(): string
    {
        return 'filament-keycloak-admin';
    }

    public function register(Panel $panel): void
    {
        $panel->pages([
            KeycloakUsers::class,
            InspectKeycloakUser::class,
        ]);
    }

    public function boot(Panel $panel): void {}

    public static function make(): static
    {
        return app(self::class);
    }

    public static function get(): static
    {
        /** @var static $plugin */
        $plugin = filament(app(static::class)->getId());

        return $plugin;
    }
}
