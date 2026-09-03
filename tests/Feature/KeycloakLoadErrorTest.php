<?php

declare(strict_types=1);

namespace Sandstorm\FilamentKeycloakAdmin\Tests\Feature;

use Filament\Facades\Filament;
use Filament\Panel;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Sandstorm\FilamentKeycloakAdmin\Filament\Concerns\HandlesKeycloakLoadErrors;
use Sandstorm\FilamentKeycloakAdmin\Filament\Pages\InspectKeycloakUser;
use Sandstorm\FilamentKeycloakAdmin\Filament\Pages\InspectKeycloakUser\KeycloakUserGroupsTable;
use Sandstorm\FilamentKeycloakAdmin\Filament\Pages\InspectKeycloakUser\KeycloakUserIdentity;
use Sandstorm\FilamentKeycloakAdmin\Filament\Pages\KeycloakUsers;
use Sandstorm\FilamentKeycloakAdmin\FilamentKeycloakAdminPlugin;
use Sandstorm\FilamentKeycloakAdmin\Tests\TestCase;
use Sandstorm\KeycloakAdminApi\Connection\UnexpectedKeycloakResponseException;
use Sandstorm\KeycloakAdminApi\Features\KeycloakGroupsApi;
use Sandstorm\KeycloakAdminApi\Features\KeycloakGroupsApi\Dto\KeycloakGroups;
use Sandstorm\KeycloakAdminApi\Features\KeycloakRealmApi;
use Sandstorm\KeycloakAdminApi\Features\KeycloakRealmApi\Dto\KeycloakUserProfile;
use Sandstorm\KeycloakAdminApi\Features\KeycloakUsersApi;
use Sandstorm\KeycloakAdminApi\Features\KeycloakUsersApi\Dto\CreateKeycloakUserCommand;
use Sandstorm\KeycloakAdminApi\Features\KeycloakUsersApi\Dto\KeycloakUser;
use Sandstorm\KeycloakAdminApi\SharedModel\KeycloakUserId;

/**
 * Exercises the render()-level guard in {@see HandlesKeycloakLoadErrors}
 * against a fake {@see KeycloakUsersApi} instead of a live Keycloak — unlike
 * tests/Integration/KeycloakUsersE2ETest.php (which is skipped without one), this proves the render()
 * override actually catches the failure, and that the *second* call into the loader (Filament re-reading
 * the table/title after render() already returned) short-circuits instead of hitting the fake again.
 */
final class KeycloakLoadErrorTest extends TestCase
{
    #[Test]
    public function the_list_page_shows_a_friendly_notice_instead_of_a_500(): void
    {
        $this->usePanel();
        $this->app->instance(KeycloakUsersApi::class, new class implements KeycloakUsersApi
        {
            public int $calls = 0;

            public function list(?string $search, int $first, int $max, ?bool $enabled): KeycloakUsersApi\Dto\KeycloakUsers
            {
                $this->calls++;

                throw new UnexpectedKeycloakResponseException('boom', 0, null);
            }

            public function count(?string $search, ?bool $enabled): int
            {
                return 0;
            }

            public function getById(KeycloakUserId $id): KeycloakUser
            {
                throw new \RuntimeException('not used by this test');
            }

            public function findByUsername(string $username): ?KeycloakUser
            {
                throw new \RuntimeException('not used by this test');
            }

            public function create(CreateKeycloakUserCommand $command): KeycloakUser
            {
                throw new \RuntimeException('not used by this test');
            }

            public function update(KeycloakUser $user): void
            {
                throw new \RuntimeException('not used by this test');
            }
        });

        Livewire::test(KeycloakUsers::class)
            ->assertOk()
            ->assertSee(__('filament-keycloak-admin::filament-keycloak-admin.load_error.heading'));

        $fake = $this->app->make(KeycloakUsersApi::class);
        $this->assertSame(1, $fake->calls, 'the render()-time load and the later Blade-time table load must not both hit Keycloak');
    }

    /**
     * Unlike the list page above, this drives {@see InspectKeycloakUser::render()} directly instead of
     * through Livewire::test(): the full page also embeds other Livewire components (identity, groups,
     * credentials) that resolve their own real Keycloak*Api implementations and would need their own
     * fakes/config to render — out of scope here, since this is only proving *this* page's own guard
     * (the fake below is never seen by those siblings). render() itself only produces a lazy View, so
     * calling it directly exercises the eager load without triggering Blade/those nested components.
     */
    #[Test]
    public function the_detail_page_resolves_once_and_falls_back_to_the_raw_id(): void
    {
        $this->usePanel();
        $fake = new class implements KeycloakUsersApi
        {
            public int $calls = 0;

            public function list(?string $search, int $first, int $max, ?bool $enabled): KeycloakUsersApi\Dto\KeycloakUsers
            {
                throw new \RuntimeException('not used by this test');
            }

            public function count(?string $search, ?bool $enabled): int
            {
                throw new \RuntimeException('not used by this test');
            }

            public function getById(KeycloakUserId $id): KeycloakUser
            {
                $this->calls++;

                throw new UnexpectedKeycloakResponseException('boom', 0, null);
            }

            public function findByUsername(string $username): ?KeycloakUser
            {
                throw new \RuntimeException('not used by this test');
            }

            public function create(CreateKeycloakUserCommand $command): KeycloakUser
            {
                throw new \RuntimeException('not used by this test');
            }

            public function update(KeycloakUser $user): void
            {
                throw new \RuntimeException('not used by this test');
            }
        };

        $page = new InspectKeycloakUser;
        $page->boot($fake);
        $page->mount('00000000-0000-0000-0000-000000000000');

        $page->render();

        $this->assertSame('00000000-0000-0000-0000-000000000000', $page->getTitle(), 'a failed resolve falls back to the raw id');
        $this->assertNull($page->getSubheading());
        $this->assertSame(1, $fake->calls, 'the render()-time load and the later getTitle()/getSubheading() loads must not both hit Keycloak');
    }

    /**
     * The same render()-level guard, now on a plain Livewire\Component (not a Filament Page like the
     * list page above) — confirms getTable()->getRecords() caching in {@see HandlesKeycloakLoadErrors}
     * works identically regardless of which base class hosts the trait.
     */
    #[Test]
    public function a_detail_tab_component_shows_a_friendly_notice_instead_of_a_500(): void
    {
        $this->usePanel();
        $this->app->instance(KeycloakGroupsApi::class, new class implements KeycloakGroupsApi
        {
            public int $calls = 0;

            public function getUserGroups(KeycloakUserId $userId): KeycloakGroups
            {
                $this->calls++;

                throw new UnexpectedKeycloakResponseException('boom', 0, null);
            }

            public function listRealmGroups(?string $search = null): KeycloakGroups
            {
                throw new \RuntimeException('not used by this test');
            }

            public function addUserToGroup(KeycloakUserId $userId, string $groupId): void
            {
                throw new \RuntimeException('not used by this test');
            }

            public function removeUserFromGroup(KeycloakUserId $userId, string $groupId): void
            {
                throw new \RuntimeException('not used by this test');
            }
        });

        Livewire::test(KeycloakUserGroupsTable::class, ['userId' => '00000000-0000-0000-0000-000000000000'])
            ->assertOk()
            ->assertSee(__('filament-keycloak-admin::filament-keycloak-admin.load_error.heading'));

        $fake = $this->app->make(KeycloakGroupsApi::class);
        $this->assertSame(1, $fake->calls, 'the render()-time load and the later Blade-time table load must not both hit Keycloak');
    }

    /**
     * Regression test: {@see KeycloakUserIdentity::mount()} used to call syncEnabledToggle() (and thus
     * loadUser()) directly, unguarded — mount() runs before render(), so that call sat entirely outside
     * {@see HandlesKeycloakLoadErrors::catchKeycloakLoadError()} and a denied/failed read there surfaced
     * as an uncaught exception instead of the friendly notice. This drives the real Livewire::test() flow
     * (mount() included, not just render()) so it actually exercises that path.
     */
    #[Test]
    public function the_identity_component_shows_a_friendly_notice_instead_of_a_500_from_mount(): void
    {
        $this->usePanel();
        $this->app->instance(KeycloakUsersApi::class, new class implements KeycloakUsersApi
        {
            public int $calls = 0;

            public function list(?string $search, int $first, int $max, ?bool $enabled): KeycloakUsersApi\Dto\KeycloakUsers
            {
                throw new \RuntimeException('not used by this test');
            }

            public function count(?string $search, ?bool $enabled): int
            {
                throw new \RuntimeException('not used by this test');
            }

            public function getById(KeycloakUserId $id): KeycloakUser
            {
                $this->calls++;

                throw new UnexpectedKeycloakResponseException('boom', 0, null);
            }

            public function findByUsername(string $username): ?KeycloakUser
            {
                throw new \RuntimeException('not used by this test');
            }

            public function create(CreateKeycloakUserCommand $command): KeycloakUser
            {
                throw new \RuntimeException('not used by this test');
            }

            public function update(KeycloakUser $user): void
            {
                throw new \RuntimeException('not used by this test');
            }
        });
        $this->app->instance(KeycloakRealmApi::class, new class implements KeycloakRealmApi
        {
            public function getUserProfile(): KeycloakUserProfile
            {
                throw new \RuntimeException('not used by this test — the user fetch fails first');
            }
        });

        Livewire::test(KeycloakUserIdentity::class, ['userId' => '00000000-0000-0000-0000-000000000000'])
            ->assertOk()
            ->assertSee(__('filament-keycloak-admin::filament-keycloak-admin.load_error.heading'));

        $fake = $this->app->make(KeycloakUsersApi::class);
        $this->assertSame(1, $fake->calls, 'mount()\'s toggle sync and render()\'s eager load must not both hit Keycloak');
    }

    private function usePanel(): void
    {
        $panel = Panel::make()->id('load-error-test')->path('load-error-test')->plugin(FilamentKeycloakAdminPlugin::make());

        Filament::registerPanel($panel);
        Filament::setCurrentPanel($panel);
    }
}
