<?php

declare(strict_types=1);

namespace Sandstorm\FilamentKeycloakAdmin\Tests\Scratch;

use Sandstorm\FilamentKeycloakAdmin\Filament\Pages\InspectKeycloakUser\KeycloakUserIdentity;
use Sandstorm\FilamentKeycloakAdmin\Tests\Fixtures\TestPanelProvider;
use Sandstorm\FilamentKeycloakAdmin\Tests\TestCase;
use Filament\Facades\Filament;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Sandstorm\KeycloakAdminApi\Features\KeycloakUsersApi;
use Sandstorm\KeycloakAdminApi\Features\KeycloakUsersApi\Dto\KeycloakUser;
use Sandstorm\KeycloakAdminApi\Features\KeycloakUsersApi\Dto\KeycloakUsers;
use Sandstorm\KeycloakAdminApi\SharedModel\KeycloakUserId;

final class RenderReproTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), TestPanelProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(KeycloakUsersApi::class, new class implements KeycloakUsersApi {
            public function list(?string $search, int $first, int $max, ?bool $enabled): KeycloakUsers
            {
                return KeycloakUsers::fromRawResponse([]);
            }

            public function count(?string $search, ?bool $enabled): int
            {
                return 0;
            }

            public function getById(KeycloakUserId $id): KeycloakUser
            {
                return KeycloakUser::fromRawResponse([
                    'id' => $id->value,
                    'username' => 'jane',
                    'email' => 'jane@example.test',
                    'firstName' => 'Jane',
                    'lastName' => 'Doe',
                    'enabled' => true,
                    'emailVerified' => true,
                ]);
            }
        });

        Filament::setCurrentPanel('admin');
        Filament::bootCurrentPanel();
    }

    #[Test]
    public function identity_renders(): void
    {
        Livewire::test(KeycloakUserIdentity::class, ['userId' => 'abc'])
            ->assertOk()
            ->assertSee('jane');
    }

    #[Test]
    public function a_plain_livewire_component_renders(): void
    {
        Livewire::test(PlainComponent::class)
            ->assertOk()
            ->assertSee('hello-plain');
    }
}
