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
     * Identity is an infolist, not a table, so it degrades with its own notice entry instead of a
     * table's empty state — proven here against a real denied service-account grant.
     */
    #[Test]
    public function identity_shows_a_friendly_notice_instead_of_a_500_when_keycloak_denies_the_request(): void
    {
        $userId = (string) $this->seededUserId()->value;
        config()->set('filament-keycloak-admin.connection.client_secret', 'not-the-real-secret');

        Livewire::test(KeycloakUserIdentity::class, ['userId' => $userId])
            ->assertOk()
            ->assertSee(__('filament-keycloak-admin::filament-keycloak-admin.load_error.heading'))
            ->assertDontSee('jane');
    }

    /**
     * A table-backed section degrades the same way as the list page (plan §8): a denied grant becomes
     * the table's empty state, not a 500.
     */
    #[Test]
    public function groups_shows_a_friendly_notice_instead_of_a_500_when_keycloak_denies_the_request(): void
    {
        $userId = (string) $this->seededUserId()->value;
        config()->set('filament-keycloak-admin.connection.client_secret', 'not-the-real-secret');

        Livewire::test(KeycloakUserGroupsTable::class, ['userId' => $userId])
            ->assertOk()
            ->assertSee(__('filament-keycloak-admin::filament-keycloak-admin.load_error.heading'))
            ->assertDontSee('staff');
    }

    /**
     * The page orchestrator falls back to the raw id for its title and banners the failure, but still
     * renders its tabs — proven here by the page not throwing even though the identity fetch it uses for
     * the title is denied.
     */
    #[Test]
    public function the_detail_page_shows_a_friendly_notice_instead_of_a_500_when_keycloak_denies_the_request(): void
    {
        $userId = (string) $this->seededUserId()->value;
        config()->set('filament-keycloak-admin.connection.client_secret', 'not-the-real-secret');

        Livewire::test(InspectKeycloakUser::class, ['userId' => $userId])
            ->assertOk()
            ->assertSee(__('filament-keycloak-admin::filament-keycloak-admin.load_error.heading'));
    }
}
