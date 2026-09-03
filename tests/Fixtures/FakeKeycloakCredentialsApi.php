<?php

declare(strict_types=1);

namespace Sandstorm\FilamentKeycloakAdmin\Tests\Fixtures;

use Sandstorm\KeycloakAdminApi\Features\KeycloakCredentialsApi;
use Sandstorm\KeycloakAdminApi\Features\KeycloakCredentialsApi\Dto\KeycloakCredentials;
use Sandstorm\KeycloakAdminApi\SharedModel\KeycloakUserId;

/**
 * An in-memory {@see KeycloakCredentialsApi} for exercising {@see
 * \Sandstorm\FilamentKeycloakAdmin\Filament\Pages\InspectKeycloakUser\KeycloakUserCredentialsTable}'s
 * write actions without a real Keycloak. Starts the user with one removable OTP credential.
 */
final class FakeKeycloakCredentialsApi implements KeycloakCredentialsApi
{
    public bool $passwordResetEmailSent = false;

    public ?string $deletedCredentialId = null;

    public function get(KeycloakUserId $userId): KeycloakCredentials
    {
        return KeycloakCredentials::fromRawResponse([
            ['id' => 'credential-otp', 'type' => 'otp', 'userLabel' => 'Authenticator'],
        ]);
    }

    public function executeActionsEmail(
        KeycloakUserId $userId,
        array $actions,
        ?int $lifespan = null,
        ?string $clientId = null,
        ?string $redirectUri = null,
    ): void {
        $this->passwordResetEmailSent = true;
    }

    public function delete(KeycloakUserId $userId, string $credentialId): void
    {
        $this->deletedCredentialId = $credentialId;
    }
}
