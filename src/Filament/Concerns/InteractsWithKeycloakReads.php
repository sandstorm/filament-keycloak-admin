<?php

declare(strict_types=1);

namespace Sandstorm\FilamentKeycloakAdmin\Filament\Concerns;

use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Sandstorm\KeycloakAdminApi\Connection\UnexpectedKeycloakResponseException;

use function in_array;
use function value;

/**
 * Shared read-guard for every component that fetches from Keycloak to render its *own* initial view (a
 * list/detail page, or one of the detail page's tab components). A Keycloak outage or a denied read is
 * the expected, recoverable steady state for a page/section an admin can land on directly — this is the
 * scoped exception to "every failure propagates" on the read side, mirroring
 * {@see InteractsWithKeycloakWrites} on the write side. Any *other* Keycloak call an action triggers
 * later (fetching options for a picker, a write) still propagates or is guarded on its own terms.
 */
trait InteractsWithKeycloakReads
{
    private ?UnexpectedKeycloakResponseException $keycloakLoadError = null;

    /**
     * Run a Keycloak read. Returns $load()'s result, or $fallback (resolved via `value()`, so a Closure
     * is called lazily — useful when building the fallback needs the failure to already be stashed) once
     * {@see UnexpectedKeycloakResponseException} is caught and stashed on {@see self::$keycloakLoadError}.
     *
     * @template T
     *
     * @param  callable(): T  $load
     * @param  T | callable(): T  $fallback
     * @return T
     */
    protected function loadFromKeycloak(callable $load, mixed $fallback): mixed
    {
        try {
            return $load();
        } catch (UnexpectedKeycloakResponseException $exception) {
            $this->keycloakLoadError = $exception;

            return value($fallback);
        }
    }

    /**
     * Apply the failure-aware empty state to a table backed by {@see self::loadFromKeycloak()}: $heading
     * while reads succeed (or simply come back empty), a status-bucketed notice once one has failed.
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
