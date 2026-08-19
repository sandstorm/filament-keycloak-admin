<?php

declare(strict_types=1);

namespace Sandstorm\FilamentKeycloakAdmin\Auth;

/**
 * The seam between {@see FilamentSsoTokenProvider} and wherever the panel's OIDC login stashes the
 * logged-in admin's Keycloak tokens. Production binds a heloufir-backed implementation that reads the
 * `keycloak_sessions` row for the current Filament user; the test suite binds an in-memory fake and so
 * never depends on heloufir.
 *
 * Deliberately tiny: read the current admin's tokens, and persist a refreshed pair. All token *logic*
 * (validity, refresh, fail-loud) lives in the provider, not here, so it is testable without a session.
 */
interface AdminKeycloakSession
{
    /**
     * The current admin's stored tokens, or null when the admin has no Keycloak session at all (not
     * logged in via OIDC) — which the provider turns into a loud failure, never a silent fallback.
     */
    public function tokens(): ?AdminKeycloakTokens;

    /**
     * Force a refresh: redeem the stored refresh token against the client that issued it, persist the
     * rotated pair, and return it. Returns null when no refresh is possible (no session, or the grant
     * failed) — the provider treats that as a loud failure. Production delegates this to the panel's OIDC
     * login library so the exact grant is owned in one place.
     */
    public function refresh(): ?AdminKeycloakTokens;
}
