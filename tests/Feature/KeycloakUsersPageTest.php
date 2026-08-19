<?php

declare(strict_types=1);

namespace Sandstorm\FilamentKeycloakAdmin\Tests\Feature;

use Sandstorm\FilamentKeycloakAdmin\Filament\Pages\InspectKeycloakUser;
use Sandstorm\FilamentKeycloakAdmin\Filament\Pages\KeycloakUsers;
use Sandstorm\FilamentKeycloakAdmin\FilamentKeycloakAdminPlugin;
use Sandstorm\FilamentKeycloakAdmin\Tests\TestCase;
use Filament\Panel;
use PHPUnit\Framework\Attributes\Test;

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
    public function it_exposes_a_navigation_label(): void
    {
        $this->assertSame('Keycloak Users', KeycloakUsers::getNavigationLabel());
    }
}
