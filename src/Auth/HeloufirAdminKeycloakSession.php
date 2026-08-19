<?php

declare(strict_types=1);

namespace Sandstorm\FilamentKeycloakAdmin\Auth;

use Filament\Facades\Filament;
use Heloufir\FilamentKeycloakSso\Helpers\HasKeycloakRoles;
use Heloufir\FilamentKeycloakSso\Models\KeycloakSession;

use function class_exists;
use function is_string;

/**
 * Production {@see AdminKeycloakSession}: reads the logged-in admin's Keycloak tokens from
 * heloufir/filament-keycloak-sso, the panel's OIDC login library. This is the **only** heloufir-coupled
 * class in the package — the seam keeps that coupling out of {@see FilamentSsoTokenProvider} and out of
 * the entire test suite (no test references this class; it is excluded from static analysis because
 * heloufir is an optional, app-provided dependency, not a dependency of this standalone package).
 *
 * Refresh is deliberately **delegated to heloufir** rather than re-implemented: heloufir owns the exact
 * `refresh_token` grant (issuing client, endpoint, context path) in one place, so we reuse it.
 */
final class HeloufirAdminKeycloakSession implements AdminKeycloakSession
{
    use HasKeycloakRoles;

    /**
     * Whether heloufir is actually installed — the ServiceProvider checks this before instantiating, so a
     * deployment that selects `auth_mode=sso` without the login library fails with a clear message
     * instead of a class-not-found fatal.
     */
    public static function isAvailable(): bool
    {
        return class_exists(KeycloakSession::class);
    }

    public function tokens(): ?AdminKeycloakTokens
    {
        $email = self::currentAdminEmail();
        if ($email === null) {
            return null;
        }

        $row = KeycloakSession::query()->where('email', $email)->first();
        if ($row === null) {
            return null;
        }

        return new AdminKeycloakTokens((string) $row->access_token, (string) $row->refresh_token);
    }

    public function refresh(): ?AdminKeycloakTokens
    {
        if (self::currentAdminEmail() === null) {
            return null;
        }

        // heloufir's roles() force-refreshes the stored token as a documented side effect — it redeems
        // the refresh_token against the issuing OIDC client and rewrites the keycloak_sessions row with
        // the rotated pair. We discard the returned roles and re-read the freshly stored tokens.
        $this->keycloakUserRoles();

        return $this->tokens();
    }

    private static function currentAdminEmail(): ?string
    {
        $email = Filament::auth()->user()?->email ?? null; // heloufir keys sessions by the Filament user's email

        return is_string($email) && $email !== '' ? $email : null;
    }
}
