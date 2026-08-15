<?php

use Broodfonds\KeycloakFilamentAdmin\Filament\Pages\KeycloakUsers;
use Broodfonds\KeycloakFilamentAdmin\Filament\Pages\ViewKeycloakUser;
use Broodfonds\KeycloakFilamentAdmin\KeycloakFilamentAdminPlugin;
use Filament\Panel;

it('registers the Keycloak Users list and detail pages on the panel', function () {
    $panel = Panel::make();

    KeycloakFilamentAdminPlugin::make()->register($panel);

    expect($panel->getPages())
        ->toContain(KeycloakUsers::class)
        ->toContain(ViewKeycloakUser::class);
});

it('routes the detail page with a userId parameter for deep-linking', function () {
    expect(ViewKeycloakUser::getRoutePath(Panel::make()))->toBe('/keycloak-users/{userId}');
});

it('exposes a navigation label', function () {
    expect(KeycloakUsers::getNavigationLabel())->toBe('Keycloak Users');
});
