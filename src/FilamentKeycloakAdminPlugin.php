<?php

declare(strict_types=1);

namespace Sandstorm\FilamentKeycloakAdmin;

use BackedEnum;
use Closure;
use Filament\Contracts\Plugin;
use Filament\Panel;
use Filament\Support\Concerns\EvaluatesClosures;
use Filament\Support\Icons\Heroicon;
use Sandstorm\FilamentKeycloakAdmin\Filament\Pages\InspectKeycloakUser;
use Sandstorm\FilamentKeycloakAdmin\Filament\Pages\KeycloakUsers;
use UnitEnum;

/**
 * Registers the Keycloak user-management pages on a Filament panel: the {@see KeycloakUsers} list and
 * the {@see InspectKeycloakUser} detail page. Register it on a panel with `->plugin(...)`.
 *
 * How the pages appear — who may see them, their menu label, their menu group and their icon — is
 * configured on the plugin instance rather than by subclassing the pages:
 *
 * ```php
 * $panel->plugin(
 *     FilamentKeycloakAdminPlugin::make()
 *         ->authorize(fn (): bool => auth()->user()?->isAdministrator() ?? false)
 *         ->navigationLabel('Login accounts')
 *         ->navigationGroup('User management')
 *         ->navigationIcon(Heroicon::OutlinedKey)
 *         ->navigationSort(30),
 * );
 * ```
 *
 * Every setter also takes a closure, evaluated on each access, so the value may depend on the
 * currently authenticated admin or on runtime configuration.
 *
 * The pages read their configuration back through {@see self::get()}, which resolves the instance
 * registered on the *current* panel — so two panels can show the same pages differently.
 */
final class FilamentKeycloakAdminPlugin implements Plugin
{
    use EvaluatesClosures;

    private Closure | bool $isAuthorized = true;

    private Closure | bool $shouldRegisterNavigation = true;

    private Closure | string | null $navigationLabel = null;

    private Closure | string | UnitEnum | null $navigationGroup = null;

    private Closure | string | null $navigationParentItem = null;

    private Closure | string | BackedEnum | null $navigationIcon = Heroicon::OutlinedUsers;

    private Closure | int | null $navigationSort = null;

    public function getId(): string
    {
        return 'filament-keycloak-admin';
    }

    public function register(Panel $panel): void
    {
        $panel->pages([
            KeycloakUsers::class,
            InspectKeycloakUser::class,
        ]);
    }

    public function boot(Panel $panel): void {}

    public static function make(): static
    {
        return app(self::class);
    }

    public static function get(): static
    {
        /** @var static $plugin */
        $plugin = filament(app(static::class)->getId());

        return $plugin;
    }

    /**
     * Who may use the module: gates both pages (`canAccess()`), which also hides them from the
     * navigation. Access is open by default — a panel that exposes Keycloak user administration to a
     * subset of its admins must set this.
     */
    public function authorize(Closure | bool $condition = true): static
    {
        $this->isAuthorized = $condition;

        return $this;
    }

    public function isAuthorized(): bool
    {
        return (bool) $this->evaluate($this->isAuthorized);
    }

    /**
     * Whether the list page shows up in the main menu at all. An unregistered page stays reachable by
     * URL (subject to {@see self::authorize()}) — use it when the panel links to the module itself.
     */
    public function registerNavigation(Closure | bool $condition = true): static
    {
        $this->shouldRegisterNavigation = $condition;

        return $this;
    }

    public function shouldRegisterNavigation(): bool
    {
        return (bool) $this->evaluate($this->shouldRegisterNavigation);
    }

    /**
     * The name of the module in the main menu; also the list page's own title.
     */
    public function navigationLabel(Closure | string | null $label): static
    {
        $this->navigationLabel = $label;

        return $this;
    }

    public function getNavigationLabel(): string
    {
        return $this->evaluate($this->navigationLabel) ?? 'Keycloak Users';
    }

    /**
     * The parent menu category the module is listed under; `null` (the default) puts it top-level.
     */
    public function navigationGroup(Closure | string | UnitEnum | null $group): static
    {
        $this->navigationGroup = $group;

        return $this;
    }

    public function getNavigationGroup(): string | UnitEnum | null
    {
        return $this->evaluate($this->navigationGroup);
    }

    /**
     * Nests the module underneath another navigation *item* (by its label), rather than under a group.
     */
    public function navigationParentItem(Closure | string | null $item): static
    {
        $this->navigationParentItem = $item;

        return $this;
    }

    public function getNavigationParentItem(): ?string
    {
        return $this->evaluate($this->navigationParentItem);
    }

    /**
     * The menu icon — pass `null` for no icon at all.
     *
     * Ignored while a navigation group is configured, see {@see self::getNavigationIcon()}.
     */
    public function navigationIcon(Closure | string | BackedEnum | null $icon): static
    {
        $this->navigationIcon = $icon;

        return $this;
    }

    /**
     * Filament refuses to render a navigation group that has an icon while its items have icons too
     * ("Either the group or its items can have icons, but not both"), and the group's icon is the one
     * the panel owns. So a configured group always wins: the module drops its icon rather than letting
     * the panel blow up on a combination the plugin cannot see from here.
     */
    public function getNavigationIcon(): string | BackedEnum | null
    {
        if ($this->getNavigationGroup() !== null) {
            return null;
        }

        return $this->evaluate($this->navigationIcon);
    }

    /**
     * Position within its menu group; `null` leaves the ordering to Filament.
     */
    public function navigationSort(Closure | int | null $sort): static
    {
        $this->navigationSort = $sort;

        return $this;
    }

    public function getNavigationSort(): ?int
    {
        return $this->evaluate($this->navigationSort);
    }
}
