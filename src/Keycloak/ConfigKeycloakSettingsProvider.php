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
 * the client library stays app-independent. A missing required key fails loudly.
 *
 * `frontend_url` / `administration_url` stay optional: {@see KeycloakSettings} falls back to the
 * backchannel URL, which is correct for the single-hostname deployment most installations run.
 */
final class ConfigKeycloakSettingsProvider implements KeycloakSettingsProvider
{
    public function get(): KeycloakSettings
    {
        return new KeycloakSettings(
            $this->requireString('connection.backchannel_url'),
            $this->requireString('connection.realm'),
            $this->requireString('connection.client_id'),
            $this->requireString('connection.client_secret'),
            $this->optionalString('connection.frontend_url'),
            $this->optionalString('connection.administration_url'),
        );
    }

    private function requireString(string $key): string
    {
        $value = $this->optionalString($key);
        if ($value === null) {
            throw new RuntimeException(sprintf('Keycloak plugin config "filament-keycloak-admin.%s" is not set.', $key), 1750000018);
        }

        return $value;
    }

    private function optionalString(string $key): ?string
    {
        $value = config('filament-keycloak-admin.' . $key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
