<?php

declare(strict_types=1);

namespace Sandstorm\FilamentKeycloakAdmin\Tests\Integration;

use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Sandstorm\FilamentKeycloakAdmin\Filament\Pages\KeycloakUsers;
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
     * swallowed — proven here against a bogus user id.
     */
    #[Test]
    public function an_absent_user_propagates_the_keycloak_exception(): void
    {
        $this->expectException(UnexpectedKeycloakResponseException::class);

        app(KeycloakUsersApi::class)->getById(new KeycloakUserId('00000000-0000-0000-0000-000000000000'));
    }

    /**
     * A rejected service-account grant (a real non-2xx from Keycloak's token endpoint, forced here with a
     * corrupted secret) does not propagate to a 500 page: {@see
     * \Sandstorm\FilamentKeycloakAdmin\Exceptions\KeycloakLoadErrorRenderer} catches it and renders a
     * friendly notice instead. Nothing in the page itself catches this any more (see §8 of the plan doc),
     * so this has to be a real HTTP request through the panel route — `Livewire::test()` calls the
     * component directly and never reaches Laravel's exception handling, where the renderer is
     * registered.
     */
    #[Test]
    public function the_list_shows_a_friendly_notice_instead_of_a_500_when_keycloak_denies_the_request(): void
    {
        config()->set('filament-keycloak-admin.connection.client_secret', 'not-the-real-secret');

        $response = $this->get(KeycloakUsers::getUrl(panel: 'admin'));

        // 200, not 503: Filament only boots Alpine on the client for a 2xx response, and this page's
        // navigation/search need Alpine to work. The real failure is still logged explicitly elsewhere,
        // independently of this HTTP status code.
        $response->assertStatus(200);
        $response->assertSee(__('filament-keycloak-admin::filament-keycloak-admin.load_error.heading'));
        $response->assertDontSee('jane');
    }
}
