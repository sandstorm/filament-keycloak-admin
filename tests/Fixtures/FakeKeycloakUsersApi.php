<?php

declare(strict_types=1);

namespace Sandstorm\FilamentKeycloakAdmin\Tests\Fixtures;

use LogicException;
use Sandstorm\KeycloakAdminApi\Connection\UnexpectedKeycloakResponseException;
use Sandstorm\KeycloakAdminApi\Features\KeycloakUsersApi;
use Sandstorm\KeycloakAdminApi\Features\KeycloakUsersApi\Dto\CreateKeycloakUserCommand;
use Sandstorm\KeycloakAdminApi\Features\KeycloakUsersApi\Dto\KeycloakUser;
use Sandstorm\KeycloakAdminApi\Features\KeycloakUsersApi\Dto\KeycloakUsers;
use Sandstorm\KeycloakAdminApi\SharedModel\KeycloakUserId;

/**
 * A single-user, in-memory {@see KeycloakUsersApi} for exercising {@see
 * \Sandstorm\FilamentKeycloakAdmin\Filament\Pages\InspectKeycloakUser\KeycloakUserIdentity}'s write
 * actions without a real Keycloak. Only `getById()`/`update()` are implemented — the identity component
 * never calls the others.
 */
final class FakeKeycloakUsersApi implements KeycloakUsersApi
{
    public ?KeycloakUser $updated = null;

    private ?UnexpectedKeycloakResponseException $throwOnUpdate = null;

    public function __construct(private KeycloakUser $user) {}

    public function throwOnUpdate(UnexpectedKeycloakResponseException $exception): void
    {
        $this->throwOnUpdate = $exception;
    }

    public function list(?string $search, int $first, int $max, ?bool $enabled): KeycloakUsers
    {
        throw new LogicException('not used by this fake');
    }

    public function count(?string $search, ?bool $enabled): int
    {
        throw new LogicException('not used by this fake');
    }

    public function getById(KeycloakUserId $id): KeycloakUser
    {
        return $this->user;
    }

    public function findByUsername(string $username): ?KeycloakUser
    {
        throw new LogicException('not used by this fake');
    }

    public function create(CreateKeycloakUserCommand $command): KeycloakUser
    {
        throw new LogicException('not used by this fake');
    }

    public function update(KeycloakUser $user): void
    {
        if ($this->throwOnUpdate !== null) {
            throw $this->throwOnUpdate;
        }

        $this->updated = $user;
        $this->user = $user;
    }
}
