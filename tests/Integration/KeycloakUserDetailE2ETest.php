<?php

declare(strict_types=1);

namespace Sandstorm\FilamentKeycloakAdmin\Tests\Integration;

use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Sandstorm\FilamentKeycloakAdmin\Filament\Pages\InspectKeycloakUser;
use Sandstorm\FilamentKeycloakAdmin\Filament\Pages\InspectKeycloakUser\KeycloakAdminEventsTable;
use Sandstorm\FilamentKeycloakAdmin\Filament\Pages\InspectKeycloakUser\KeycloakUserCredentialsTable;
use Sandstorm\FilamentKeycloakAdmin\Filament\Pages\InspectKeycloakUser\KeycloakUserEventsTable;
use Sandstorm\FilamentKeycloakAdmin\Filament\Pages\InspectKeycloakUser\KeycloakUserGroupsTable;
use Sandstorm\FilamentKeycloakAdmin\Filament\Pages\InspectKeycloakUser\KeycloakUserIdentity;
use Sandstorm\FilamentKeycloakAdmin\Filament\Pages\InspectKeycloakUser\KeycloakUserSessionsTable;

#[Group('integration')]
final class KeycloakUserDetailE2ETest extends IntegrationTestCase
{
    #[Test]
    public function identity_shows_the_seeded_user(): void
    {
        $userId = (string) $this->seededUserId()->value;

        Livewire::test(KeycloakUserIdentity::class, ['userId' => $userId])
            ->assertOk()
            ->assertSee('jane')
            ->assertSee('jane@example.test');
    }

    #[Test]
    public function groups_shows_the_seeded_membership(): void
    {
        $userId = (string) $this->seededUserId()->value;

        Livewire::test(KeycloakUserGroupsTable::class, ['userId' => $userId])
            ->assertOk()
            ->assertSee('staff');
    }

    #[Test]
    public function credentials_render(): void
    {
        $userId = (string) $this->seededUserId()->value;

        Livewire::test(KeycloakUserCredentialsTable::class, ['userId' => $userId])
            ->assertOk()
            ->assertSee('password');
    }

    #[Test]
    public function sessions_render_after_a_real_login(): void
    {
        $this->loginAsUser('jane');
        $userId = (string) $this->seededUserId()->value;

        Livewire::test(KeycloakUserSessionsTable::class, ['userId' => $userId])
            ->assertOk();
    }

    #[Test]
    public function user_events_render_after_a_real_login(): void
    {
        $this->loginAsUser('jane');
        $userId = (string) $this->seededUserId()->value;

        Livewire::test(KeycloakUserEventsTable::class, ['userId' => $userId])
            ->assertOk();
    }

    #[Test]
    public function admin_history_renders(): void
    {
        $userId = (string) $this->seededUserId()->value;

        Livewire::test(KeycloakAdminEventsTable::class, ['userId' => $userId])
            ->assertOk();
    }

    /**
     * A placeholder id: the service-account `client_credentials` grant itself is rejected at Keycloak's
     * token endpoint, before any user-scoped request is ever built, so which id is addressed is
     * irrelevant — no real user needs to exist.
     */
    private const DENIED_GRANT_USER_ID = '00000000-0000-0000-0000-000000000000';

    /**
     * No page or tab component catches a denied grant itself any more (plan §8): whichever one hits
     * Keycloak first — here, the identity fetch used for the page title — propagates until {@see
     * \Sandstorm\FilamentKeycloakAdmin\Exceptions\KeycloakLoadErrorRenderer} catches it and renders a
     * friendly notice instead of a 500. That only happens for a real HTTP request through the panel
     * route — `Livewire::test()` calls a component directly and never reaches Laravel's exception
     * handling, where the renderer is registered.
     *
     * The secret must be corrupted *before* any real Keycloak call: `ServiceAccountTokenProvider`
     * caches a valid access token in-memory once obtained (until its ~60s expiry), so a prior real
     * call (e.g. `seededUserId()`) would keep succeeding off the cached token and silently mask the
     * corrupted secret — exactly what made this test pass "for real" even though the fixture was wrong.
     */
    #[Test]
    public function the_detail_page_shows_a_friendly_notice_instead_of_a_500_when_keycloak_denies_the_request(): void
    {
        config()->set('filament-keycloak-admin.connection.client_secret', 'not-the-real-secret');

        $response = $this->get(InspectKeycloakUser::getUrl(['userId' => self::DENIED_GRANT_USER_ID], panel: 'admin'));

        // 200, not 503: Filament only boots Alpine on the client for a 2xx response, and this page's
        // navigation/search need Alpine to work. The real failure is still logged explicitly elsewhere,
        // independently of this HTTP status code.
        $response->assertStatus(200);
        $response->assertSee(__('filament-keycloak-admin::filament-keycloak-admin.load_error.heading'));
    }
}
