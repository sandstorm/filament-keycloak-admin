<?php

declare(strict_types=1);

namespace Sandstorm\FilamentKeycloakAdmin\Exceptions;

use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Sandstorm\FilamentKeycloakAdmin\Auth\FilamentSsoTokenProvider;
use Sandstorm\FilamentKeycloakAdmin\FilamentKeycloakAdminServiceProvider;
use Sandstorm\FilamentKeycloakAdmin\Logging\KeycloakAdminLoggerFactory;
use Throwable;

use function response;

/**
 * The one place an `sso`-mode auth failure — {@see SsoAuthException}, thrown by
 * {@see FilamentSsoTokenProvider} whenever the current admin has no
 * usable Keycloak session to act as — becomes a response. Registered once, as a Laravel exception
 * renderable, in {@see FilamentKeycloakAdminServiceProvider::packageBooted()}, the same way as
 * {@see KeycloakLoadErrorRenderer}.
 *
 * The token provider is reached from the same call sites as a Keycloak read (a table's `records()`
 * closure, an infolist builder, a nested tab component), so this failure surfaces from inside Blade
 * evaluation just like a read failure does, and needs the same unwrap-through-ViewException handling —
 * see {@see self::unwrap()}.
 */
final class SsoAuthErrorRenderer
{
    public function __invoke(Throwable $exception, Request $request): ?Response
    {
        $cause = self::unwrap($exception);

        if ($cause === null) {
            return null;
        }

        if (Filament::getCurrentPanel() === null) {
            // Not inside a Filament panel request — nothing to render here; fall back to Laravel's
            // normal exception handling.
            return null;
        }

        // Log warning level, as this is most likely
        // NOT a programming / configuration problem.
        KeycloakAdminLoggerFactory::resolve(app())?->warning(
            'Keycloak admin SSO auth error',
            [
                'admin_id' => Filament::auth()->id(),
                'exception' => $exception,
            ]
        );

        // IMPORTANT:
        // Respond 200 status code here, otherwise filament will not initialize
        // alpine.js and the page JS will break.
        return response()->view('filament-keycloak-admin::filament.pages.keycloak-load-error', [
            'heading' => __('filament-keycloak-admin::filament-keycloak-admin.sso_auth_error.heading'),
            'message' => __('filament-keycloak-admin::filament-keycloak-admin.sso_auth_error.message'),
        ], 200);
    }

    private static function unwrap(Throwable $exception): ?SsoAuthException
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof SsoAuthException) {
                return $current;
            }
        }

        return null;
    }
}
