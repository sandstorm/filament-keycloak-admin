<?php

declare(strict_types=1);

namespace Sandstorm\FilamentKeycloakAdmin\Exceptions;

use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\ViewException;
use Sandstorm\FilamentKeycloakAdmin\Filament\Concerns\InteractsWithKeycloakWrites;
use Sandstorm\FilamentKeycloakAdmin\FilamentKeycloakAdminServiceProvider;
use Sandstorm\KeycloakAdminApi\Connection\UnexpectedKeycloakResponseException;
use Throwable;

use function in_array;
use function response;

/**
 * The one place a Keycloak *read* failure becomes a response — registered once, as a Laravel exception
 * renderable, in {@see FilamentKeycloakAdminServiceProvider::packageBooted()}.
 * Every page and detail-tab component makes its Keycloak reads with no error handling of its own; if one
 * fails — on the initial page load, a lazy tab's first open, a pagination click, wherever — the exception
 * simply propagates until it reaches here. (Keycloak *write* denials are handled separately, at the
 * point of the write, by {@see InteractsWithKeycloakWrites}
 * — those need a specific per-action notice, not a page-replacing one.)
 *
 * A read failure almost always happens from inside Blade evaluation (a table's `records()` closure, an
 * infolist builder, a nested tab component) — Laravel's compiler engine wraps *any* exception thrown
 * there in a {@see ViewException}, possibly nested more than once across component
 * boundaries, so {@see self::unwrap()} walks `getPrevious()` to find the real cause before deciding
 * whether this is ours to handle.
 *
 * The response is built from the panel's own layout view directly, not `<x-filament-panels::page>` (which
 * needs a live Filament page component bound as `$this`) — so the topbar/sidebar render exactly as they
 * normally would, while the content area becomes one generic notice. The view forces `.fi-main-ctn`
 * visible via a CSS override, since Filament otherwise only reveals it once Alpine runs a JS-driven
 * opacity toggle — which never happens here, this response being rendered from inside Laravel's
 * exception handler rather than a normal page request/response cycle. Nothing here tries to still show
 * whatever table or infolist was mid-render when the failure happened.
 */
final class KeycloakLoadErrorRenderer
{
    public function __invoke(Throwable $exception, Request $request): ?Response
    {
        $cause = self::unwrap($exception);

        if ($cause === null) {
            return null;
        }

        if (Filament::getCurrentPanel() === null) {
            // Not inside a Filament panel request — e.g. a console command using the API client
            // directly. Nothing to render here; fall back to Laravel's normal exception handling.
            return null;
        }

        return response()->view('filament-keycloak-admin::filament.pages.keycloak-load-error', [
            'heading' => __('filament-keycloak-admin::filament-keycloak-admin.load_error.heading'),
            'message' => self::describe($cause),
        ], 503);
    }

    private static function unwrap(Throwable $exception): ?UnexpectedKeycloakResponseException
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof UnexpectedKeycloakResponseException) {
                return $current;
            }
        }

        return null;
    }

    private static function describe(UnexpectedKeycloakResponseException $exception): string
    {
        return match (true) {
            $exception->statusCode === null => __('filament-keycloak-admin::filament-keycloak-admin.load_error.unreachable'),
            in_array($exception->statusCode, [401, 403], strict: true) => __('filament-keycloak-admin::filament-keycloak-admin.load_error.forbidden'),
            $exception->statusCode >= 500 => __('filament-keycloak-admin::filament-keycloak-admin.load_error.server_error', ['status' => $exception->statusCode]),
            default => __('filament-keycloak-admin::filament-keycloak-admin.load_error.unexpected', ['status' => $exception->statusCode]),
        };
    }
}
