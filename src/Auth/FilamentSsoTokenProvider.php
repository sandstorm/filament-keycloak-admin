<?php

declare(strict_types=1);

namespace Sandstorm\FilamentKeycloakAdmin\Auth;

use Sandstorm\FilamentKeycloakAdmin\Exceptions\SsoAuthException;
use Sandstorm\KeycloakAdminApi\Connection\Auth\KeycloakTokenProvider;

use function base64_decode;
use function count;
use function explode;
use function is_array;
use function is_int;
use function json_decode;
use function str_repeat;
use function strlen;
use function strtr;
use function time;

/**
 * `sso` act-as-user token provider: hands the Admin-API transport the *logged-in admin's own* Keycloak
 * bearer, so Keycloak evaluates that person's fine-grained permissions and attributes every admin-event
 * to them — instead of one shared service account.
 *
 * All I/O lives behind the {@see AdminKeycloakSession} seam (production: a heloufir-backed adapter that
 * owns the refresh grant; tests: an in-memory fake), so this class is pure and fully unit-testable. Its
 * only job is the decision: use the stored access token while its JWT `exp` is still valid, else ask the
 * seam to refresh — and, the load-bearing invariant, fail *loudly* when no valid token can be produced,
 * never falling back to another identity.
 */
final class FilamentSsoTokenProvider implements KeycloakTokenProvider
{
    /**
     * Treat the access token as expired this many seconds before its real `exp`, so a slow round-trip
     * can't send an about-to-die token.
     */
    private const int TOKEN_EXPIRY_SAFETY_MARGIN_SECONDS = 30;

    public function __construct(
        private readonly AdminKeycloakSession $session,
    ) {}

    public function currentBearer(): string
    {
        $tokens = $this->session->tokens();
        if ($tokens === null) {
            // No OIDC session for this admin → there is no identity to act as. Fail loudly; sso mode must
            // never silently use someone else's (e.g. service-account) authority.
            throw new SsoAuthException(
                'No Keycloak session for the current admin; sso mode cannot obtain a bearer to act as this user.',
                1755600001
            );
        }

        if ($this->isValidNow($tokens->accessToken)) {
            return $tokens->accessToken;
        }

        $refreshed = $this->session->refresh();
        if ($refreshed !== null && $this->isValidNow($refreshed->accessToken)) {
            return $refreshed->accessToken;
        }

        // Stored token expired and the refresh produced nothing usable → the admin's session is gone.
        // Loud and terminal, same no-fallback invariant.
        throw new SsoAuthException(
            'The current admin\'s Keycloak session could not be refreshed; sso mode has no valid bearer to act as this user.',
            1755600002
        );
    }

    /**
     * Whether the access token's own `exp` claim leaves more than the safety margin before expiry. A
     * token we cannot read an `exp` from is treated as invalid → forces a refresh rather than sending a
     * token of unknown validity.
     */
    private function isValidNow(string $accessToken): bool
    {
        $parts = explode('.', $accessToken);
        if (count($parts) !== 3) {
            return false;
        }

        $payload = json_decode(self::base64UrlDecode($parts[1]), true);
        if (! is_array($payload) || ! is_int($payload['exp'] ?? null)) {
            return false;
        }

        return $payload['exp'] > time() + self::TOKEN_EXPIRY_SAFETY_MARGIN_SECONDS;
    }

    private static function base64UrlDecode(string $segment): string
    {
        $padded = $segment . str_repeat('=', (4 - strlen($segment) % 4) % 4);

        return (string) base64_decode(strtr($padded, '-_', '+/'), true);
    }
}
