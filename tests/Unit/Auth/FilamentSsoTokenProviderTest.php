<?php

declare(strict_types=1);

namespace Sandstorm\FilamentKeycloakAdmin\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sandstorm\FilamentKeycloakAdmin\Auth\AdminKeycloakTokens;
use Sandstorm\FilamentKeycloakAdmin\Auth\FilamentSsoTokenProvider;
use Sandstorm\FilamentKeycloakAdmin\Tests\Support\InMemoryAdminKeycloakSession;

use function base64_encode;
use function json_encode;
use function rtrim;
use function strtr;
use function time;

/**
 * Unit-tests the sso act-as-user provider through its one public method, {@see currentBearer()}. Pure:
 * the token source is an in-memory {@see InMemoryAdminKeycloakSession} (no heloufir, no Laravel, no HTTP).
 * "Valid" is decided from the access token's own JWT `exp`, so tests mint tokens with a chosen expiry.
 */
final class FilamentSsoTokenProviderTest extends TestCase
{
    #[Test]
    public function returns_the_stored_access_token_unchanged_when_it_is_still_valid(): void
    {
        $stillValid = self::jwtExpiringIn(3600);
        $session = new InMemoryAdminKeycloakSession(new AdminKeycloakTokens($stillValid, 'refresh-token'));

        self::assertSame($stillValid, (new FilamentSsoTokenProvider($session))->currentBearer());
        self::assertSame(0, $session->refreshCallCount, 'a valid token must not trigger a refresh');
    }

    #[Test]
    public function fails_loudly_when_the_admin_has_no_keycloak_session(): void
    {
        $provider = new FilamentSsoTokenProvider(new InMemoryAdminKeycloakSession(null));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(1755600001);
        $provider->currentBearer();
    }

    #[Test]
    public function refreshes_and_returns_the_new_access_token_when_the_stored_one_is_expired(): void
    {
        $expired = self::jwtExpiringIn(-10);
        $refreshedAccess = self::jwtExpiringIn(3600);
        $session = new InMemoryAdminKeycloakSession(
            new AdminKeycloakTokens($expired, 'old-refresh'),
            new AdminKeycloakTokens($refreshedAccess, 'new-refresh'),
        );

        self::assertSame($refreshedAccess, (new FilamentSsoTokenProvider($session))->currentBearer());
        self::assertSame(1, $session->refreshCallCount);
    }

    #[Test]
    public function treats_a_token_within_the_safety_margin_as_expired_and_refreshes(): void
    {
        // 10s left — inside the 30s safety margin, so it must refresh rather than send an about-to-die token.
        $aboutToExpire = self::jwtExpiringIn(10);
        $refreshedAccess = self::jwtExpiringIn(3600);
        $session = new InMemoryAdminKeycloakSession(
            new AdminKeycloakTokens($aboutToExpire, 'old-refresh'),
            new AdminKeycloakTokens($refreshedAccess, 'new-refresh'),
        );

        self::assertSame($refreshedAccess, (new FilamentSsoTokenProvider($session))->currentBearer());
        self::assertSame(1, $session->refreshCallCount);
    }

    #[Test]
    public function fails_loudly_when_the_expired_token_cannot_be_refreshed(): void
    {
        $session = new InMemoryAdminKeycloakSession(
            new AdminKeycloakTokens(self::jwtExpiringIn(-10), 'dead-refresh'),
            null, // refresh yields nothing
        );
        $provider = new FilamentSsoTokenProvider($session);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(1755600002);
        $provider->currentBearer();
    }

    #[Test]
    public function treats_an_unparseable_token_as_invalid_and_refreshes(): void
    {
        $refreshedAccess = self::jwtExpiringIn(3600);
        $session = new InMemoryAdminKeycloakSession(
            new AdminKeycloakTokens('not-a-jwt', 'old-refresh'),
            new AdminKeycloakTokens($refreshedAccess, 'new-refresh'),
        );

        self::assertSame($refreshedAccess, (new FilamentSsoTokenProvider($session))->currentBearer());
        self::assertSame(1, $session->refreshCallCount);
    }

    /**
     * A minimal signed-looking JWT (header.payload.signature) whose payload carries an `exp` the given
     * number of seconds from now (negative = already expired). Only the payload is read; the signature
     * is irrelevant to the provider.
     */
    private static function jwtExpiringIn(int $seconds): string
    {
        $header = self::base64Url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $payload = self::base64Url((string) json_encode(['exp' => time() + $seconds]));

        return $header . '.' . $payload . '.' . self::base64Url('signature');
    }

    private static function base64Url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
