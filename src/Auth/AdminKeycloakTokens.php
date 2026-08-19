<?php

declare(strict_types=1);

namespace Sandstorm\FilamentKeycloakAdmin\Auth;

/**
 * The logged-in admin's Keycloak tokens, as held by the panel's OIDC login. A plain value object so the
 * token source (production: heloufir's `keycloak_sessions` row; tests: an in-memory fake) and the
 * {@see FilamentSsoTokenProvider} that consumes it never share a framework type.
 *
 * No expiry field: heloufir does not store one, and the provider reads the access token's own JWT `exp`
 * instead — the tokens themselves are the source of truth for their validity.
 */
final readonly class AdminKeycloakTokens
{
    public function __construct(
        public string $accessToken,
        public string $refreshToken,
    ) {}
}
