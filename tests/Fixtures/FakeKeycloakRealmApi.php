<?php

declare(strict_types=1);

namespace Sandstorm\FilamentKeycloakAdmin\Tests\Fixtures;

use Sandstorm\KeycloakAdminApi\Features\KeycloakRealmApi;
use Sandstorm\KeycloakAdminApi\Features\KeycloakRealmApi\Dto\KeycloakUserProfile;

/**
 * A {@see KeycloakRealmApi} with no custom User-Profile attributes — {@see
 * \Sandstorm\FilamentKeycloakAdmin\Filament\Pages\InspectKeycloakUser\KeycloakUserIdentity} reads this to
 * build its identity form; an empty schema keeps the edit action to just the built-in fields.
 */
final class FakeKeycloakRealmApi implements KeycloakRealmApi
{
    public function getUserProfile(): KeycloakUserProfile
    {
        return KeycloakUserProfile::fromRawResponse(['attributes' => []]);
    }
}
