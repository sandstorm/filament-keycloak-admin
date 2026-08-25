<?php

declare(strict_types=1);

namespace Sandstorm\FilamentKeycloakAdmin\Tests\Feature;

use Filament\Facades\Filament;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;
use PHPUnit\Framework\Attributes\Test;
use Sandstorm\FilamentKeycloakAdmin\Filament\Pages\InspectKeycloakUser;
use Sandstorm\FilamentKeycloakAdmin\Filament\Pages\KeycloakUsers;
use Sandstorm\FilamentKeycloakAdmin\FilamentKeycloakAdminPlugin;
use Sandstorm\FilamentKeycloakAdmin\Tests\TestCase;

final class KeycloakUsersPageTest extends TestCase
{
    #[Test]
    public function it_registers_the_list_and_detail_pages_on_the_panel(): void
    {
        $panel = Panel::make();

        FilamentKeycloakAdminPlugin::make()->register($panel);

        $pages = $panel->getPages();

        $this->assertContains(KeycloakUsers::class, $pages);
        $this->assertContains(InspectKeycloakUser::class, $pages);
    }

    #[Test]
    public function it_routes_the_detail_page_with_a_user_id_parameter_for_deep_linking(): void
    {
        $this->assertSame('/keycloak-users/{userId}', InspectKeycloakUser::getRoutePath(Panel::make()));
    }

    #[Test]
    public function it_presents_itself_with_defaults_when_the_plugin_is_registered_unconfigured(): void
    {
        $this->usePanelWith(FilamentKeycloakAdminPlugin::make());

        $this->assertSame('Keycloak Users', KeycloakUsers::getNavigationLabel());
        $this->assertSame('Keycloak Users', $this->page()->getTitle());
        $this->assertSame(Heroicon::OutlinedUsers, KeycloakUsers::getNavigationIcon());
        $this->assertNull(KeycloakUsers::getNavigationGroup());
        $this->assertNull(KeycloakUsers::getNavigationParentItem());
        $this->assertNull(KeycloakUsers::getNavigationSort());
        $this->assertTrue(KeycloakUsers::shouldRegisterNavigation());
        $this->assertTrue(KeycloakUsers::canAccess());
        $this->assertTrue(InspectKeycloakUser::canAccess());
    }

    #[Test]
    public function it_takes_its_navigation_presentation_from_the_plugin_instance(): void
    {
        $this->usePanelWith(
            FilamentKeycloakAdminPlugin::make()
                ->navigationLabel('Login accounts')
                ->navigationGroup('User management')
                ->navigationParentItem('People')
                ->navigationIcon(Heroicon::OutlinedKey)
                ->navigationSort(30)
        );

        $this->assertSame('Login accounts', KeycloakUsers::getNavigationLabel());
        $this->assertSame('Login accounts', $this->page()->getTitle());
        $this->assertSame('User management', KeycloakUsers::getNavigationGroup());
        $this->assertSame('People', KeycloakUsers::getNavigationParentItem());
        $this->assertSame(30, KeycloakUsers::getNavigationSort());
    }

    #[Test]
    public function it_drops_its_icon_once_a_navigation_group_is_configured(): void
    {
        // Filament rejects a group that has an icon while its items have icons too, so the group wins.
        $this->usePanelWith(
            FilamentKeycloakAdminPlugin::make()
                ->navigationGroup('User management')
                ->navigationIcon(Heroicon::OutlinedKey)
        );

        $this->assertNull(KeycloakUsers::getNavigationIcon());
    }

    #[Test]
    public function it_keeps_the_default_icon_while_no_navigation_group_is_configured(): void
    {
        $this->usePanelWith(FilamentKeycloakAdminPlugin::make()->navigationGroup(null));

        $this->assertSame(Heroicon::OutlinedUsers, KeycloakUsers::getNavigationIcon());
    }

    #[Test]
    public function it_shows_no_icon_at_all_when_configured_without_one(): void
    {
        $this->usePanelWith(FilamentKeycloakAdminPlugin::make()->navigationIcon(null));

        $this->assertNull(KeycloakUsers::getNavigationIcon());
    }

    #[Test]
    public function it_evaluates_closures_on_every_access_so_presentation_may_depend_on_runtime_state(): void
    {
        $group = 'First';

        $this->usePanelWith(
            FilamentKeycloakAdminPlugin::make()->navigationGroup(function () use (&$group): string {
                return $group;
            })
        );

        $this->assertSame('First', KeycloakUsers::getNavigationGroup());

        $group = 'Second';

        $this->assertSame('Second', KeycloakUsers::getNavigationGroup());
    }

    #[Test]
    public function it_gates_both_pages_when_the_plugin_denies_access(): void
    {
        $this->usePanelWith(FilamentKeycloakAdminPlugin::make()->authorize(false));

        $this->assertFalse(KeycloakUsers::canAccess());
        $this->assertFalse(InspectKeycloakUser::canAccess());
    }

    #[Test]
    public function it_can_be_hidden_from_the_menu_while_staying_reachable_by_url(): void
    {
        $this->usePanelWith(FilamentKeycloakAdminPlugin::make()->registerNavigation(false));

        $this->assertFalse(KeycloakUsers::shouldRegisterNavigation());
        $this->assertTrue(KeycloakUsers::canAccess());
    }

    /**
     * The pages read their presentation from the plugin instance on the *current* panel, so a test that
     * asserts on it needs a panel carrying the configured instance.
     */
    private function usePanelWith(FilamentKeycloakAdminPlugin $plugin): void
    {
        $panel = Panel::make()->id('presentation-test')->path('presentation-test')->plugin($plugin);

        Filament::registerPanel($panel);
        Filament::setCurrentPanel($panel);
    }

    private function page(): KeycloakUsers
    {
        return new KeycloakUsers;
    }
}
