<?php

declare(strict_types=1);

namespace Sandstorm\FilamentKeycloakAdmin\Tests\Fixtures;

use Sandstorm\KeycloakAdminApi\Features\KeycloakGroupsApi;
use Sandstorm\KeycloakAdminApi\Features\KeycloakGroupsApi\Dto\KeycloakGroups;
use Sandstorm\KeycloakAdminApi\SharedModel\KeycloakUserId;

/**
 * An in-memory {@see KeycloakGroupsApi} for exercising {@see
 * \Sandstorm\FilamentKeycloakAdmin\Filament\Pages\InspectKeycloakUser\KeycloakUserGroupsTable}'s write
 * actions without a real Keycloak. Starts the user with one membership so both add and remove have
 * something to act on.
 */
final class FakeKeycloakGroupsApi implements KeycloakGroupsApi
{
    /**
     * @var list<string>
     */
    public array $addedGroupIds = [];

    /**
     * @var list<string>
     */
    public array $removedGroupIds = [];

    public function getUserGroups(KeycloakUserId $userId): KeycloakGroups
    {
        return KeycloakGroups::fromRawResponse([
            ['id' => 'group-staff', 'name' => 'staff', 'path' => '/staff'],
        ]);
    }

    public function listRealmGroups(?string $search = null): KeycloakGroups
    {
        return KeycloakGroups::fromRawResponse([
            ['id' => 'group-admins', 'name' => 'admins', 'path' => '/admins'],
        ]);
    }

    public function addUserToGroup(KeycloakUserId $userId, string $groupId): void
    {
        $this->addedGroupIds[] = $groupId;
    }

    public function removeUserFromGroup(KeycloakUserId $userId, string $groupId): void
    {
        $this->removedGroupIds[] = $groupId;
    }
}
