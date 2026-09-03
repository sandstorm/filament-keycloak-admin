<?php

declare(strict_types=1);

namespace Sandstorm\FilamentKeycloakAdmin\Tests\Fixtures;

use Sandstorm\KeycloakAdminApi\Features\KeycloakSessionsApi;
use Sandstorm\KeycloakAdminApi\Features\KeycloakSessionsApi\Dto\KeycloakSessions;
use Sandstorm\KeycloakAdminApi\SharedModel\KeycloakUserId;

/**
 * An in-memory {@see KeycloakSessionsApi} for exercising {@see
 * \Sandstorm\FilamentKeycloakAdmin\Filament\Pages\InspectKeycloakUser\KeycloakUserSessionsTable}'s
 * `logoutAll` action without a real Keycloak.
 */
final class FakeKeycloakSessionsApi implements KeycloakSessionsApi
{
    public bool $loggedOutAll = false;

    public function getSessions(KeycloakUserId $userId): KeycloakSessions
    {
        return KeycloakSessions::fromRawResponse([
            ['id' => 'session-1', 'ipAddress' => '10.0.0.1'],
        ]);
    }

    public function logoutAll(KeycloakUserId $userId): void
    {
        $this->loggedOutAll = true;
    }
}
