<?php

declare(strict_types=1);

namespace Sandstorm\FilamentKeycloakAdmin\Keycloak;

use RuntimeException;
use Sandstorm\KeycloakAdminApi\Connection\KeycloakSettings;
use Sandstorm\KeycloakAdminApi\Connection\KeycloakSettingsProvider;

use function config;
use function is_string;
use function sprintf;

/**
 * Supplies Keycloak connection settings to the shared adapter from resolved Laravel config
 * (`config('filament-keycloak-admin.connection.*')`, owned by the consuming app — plan §9). This is
 * the plugin-side implementation of the framework-agnostic {@see KeycloakSettingsProvider} seam, so
 * the client library stays app-independent. A missing key fails loudly.
 */
final class ConfigKeycloakSettingsProvider implements KeycloakSettingsProvider
{
    public function get(): KeycloakSettings
    {
        return new KeycloakSettings(
            $this->requireString('connection.base_url'),
            $this->requireString('connection.realm'),
            $this->requireString('connection.client_id'),
            $this->requireString('connection.client_secret'),
        );
    }

    private function requireString(string $key): string
    {
        $value = config('filament-keycloak-admin.' . $key);
        if (! is_string($value) || $value === '') {
            throw new RuntimeException(sprintf('Keycloak plugin config "filament-keycloak-admin.%s" is not set.', $key), 1750000018);
        }

        return $value;
    }
}
