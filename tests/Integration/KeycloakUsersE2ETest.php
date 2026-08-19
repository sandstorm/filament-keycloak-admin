<?php

declare(strict_types=1);

namespace Sandstorm\FilamentKeycloakAdmin\Tests\Integration;

use Sandstorm\FilamentKeycloakAdmin\Filament\Pages\KeycloakUsers;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Sandstorm\KeycloakAdminApi\Connection\UnexpectedKeycloakResponseException;
use Sandstorm\KeycloakAdminApi\Features\KeycloakUsersApi;
use Sandstorm\KeycloakAdminApi\SharedModel\KeycloakUserId;

#[Group('integration')]
final class KeycloakUsersE2ETest extends IntegrationTestCase
{
    #[Test]
    public function the_list_renders_the_seeded_user(): void
    {
        Livewire::test(KeycloakUsers::class)
            ->assertOk()
            ->assertSee('jane');
    }

    #[Test]
    public function the_list_searches_by_username(): void
    {
        Livewire::test(KeycloakUsers::class)
            ->set('tableSearch', 'jane')
            ->assertSee('jane')
            ->assertDontSee('login-user');
    }

    /**
     * A denied/absent call surfaces the client library's single failure type rather than being
     * swallowed (plan §8) — proven here against a bogus user id.
     */
    #[Test]
    public function an_absent_user_propagates_the_keycloak_exception(): void
    {
        $this->expectException(UnexpectedKeycloakResponseException::class);

        app(KeycloakUsersApi::class)->getById(new KeycloakUserId('00000000-0000-0000-0000-000000000000'));
    }
}
