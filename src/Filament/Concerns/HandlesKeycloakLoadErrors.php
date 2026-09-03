<?php

declare(strict_types=1);

namespace Sandstorm\FilamentKeycloakAdmin\Filament\Concerns;

use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Sandstorm\KeycloakAdminApi\Connection\UnexpectedKeycloakResponseException;

use function in_array;

/**
 * Shared render-time guard for every Keycloak page/component that fetches on its own initial load (the
 * list page, the detail page, and each of the detail page's tab components). Livewire's own `exception()`
 * lifecycle hook cannot help here: it only wraps `render()` itself and explicit action calls, never the
 * Blade evaluation `render()` triggers — and that's exactly where Filament invokes a table's `records()`
 * closure, an infolist/schema builder, or a form. A Keycloak failure thrown from there never reaches it.
 *
 * So each component overrides `render()` to run its own Keycloak read(s) eagerly, right here, via
 * {@see self::catchKeycloakLoadError()} — before Filament's lazy table/schema machinery gets a chance to
 * invoke the same read again mid-Blade-compile. That later, cached re-invocation must short-circuit on
 * its own (checking {@see self::$keycloakLoadError}) instead of calling Keycloak a second time. This is
 * the one guarded call site per component; any *other* Keycloak call an action triggers later (fetching
 * options for a picker, a write) still propagates or is guarded on its own terms
 * (see {@see InteractsWithKeycloakWrites}).
 */
trait HandlesKeycloakLoadErrors
{
    private ?UnexpectedKeycloakResponseException $keycloakLoadError = null;

    /**
     * Run the page's one Keycloak read for this request, stashing any failure on
     * {@see self::$keycloakLoadError} instead of letting it propagate into Blade evaluation.
     */
    protected function catchKeycloakLoadError(callable $load): void
    {
        try {
            $load();
        } catch (UnexpectedKeycloakResponseException $exception) {
            $this->keycloakLoadError = $exception;
        }
    }

    /**
     * Apply the failure-aware empty state to a table backed by {@see self::catchKeycloakLoadError()}:
     * $heading while reads succeed (or simply come back empty), a status-bucketed notice once one has
     * failed.
     */
    protected function keycloakLoadErrorEmptyState(Table $table, string $heading): Table
    {
        return $table
            ->emptyStateHeading(fn (): string => $this->keycloakLoadError === null
                ? $heading
                : __('filament-keycloak-admin::filament-keycloak-admin.load_error.heading'))
            ->emptyStateDescription(fn (): ?string => $this->keycloakLoadError === null
                ? null
                : self::describeKeycloakLoadError($this->keycloakLoadError))
            ->emptyStateIcon(fn (): ?BackedEnum => $this->keycloakLoadError === null
                ? null
                : Heroicon::OutlinedExclamationTriangle);
    }

    protected static function describeKeycloakLoadError(UnexpectedKeycloakResponseException $exception): string
    {
        return match (true) {
            $exception->statusCode === null => __('filament-keycloak-admin::filament-keycloak-admin.load_error.unreachable'),
            in_array($exception->statusCode, [401, 403], strict: true) => __('filament-keycloak-admin::filament-keycloak-admin.load_error.forbidden'),
            $exception->statusCode >= 500 => __('filament-keycloak-admin::filament-keycloak-admin.load_error.server_error', ['status' => $exception->statusCode]),
            default => __('filament-keycloak-admin::filament-keycloak-admin.load_error.unexpected', ['status' => $exception->statusCode]),
        };
    }
}
