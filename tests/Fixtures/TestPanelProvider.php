<?php

declare(strict_types=1);

namespace Sandstorm\FilamentKeycloakAdmin\Tests\Fixtures;

use Filament\Panel;
use Filament\PanelProvider;
use Sandstorm\FilamentKeycloakAdmin\FilamentKeycloakAdminPlugin;

/**
 * A minimal default Filament panel with the plugin registered — the host the E2E suite drives its
 * pages/components through (`Livewire::test(...)` needs a current panel to resolve tables and routes).
 */
final class TestPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->plugin(FilamentKeycloakAdminPlugin::make());
    }
}
