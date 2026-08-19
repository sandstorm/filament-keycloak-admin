<?php

declare(strict_types=1);

namespace Sandstorm\FilamentKeycloakAdmin\Tests\Support;

use Sandstorm\FilamentKeycloakAdmin\Auth\AdminKeycloakSession;
use Sandstorm\FilamentKeycloakAdmin\Auth\AdminKeycloakTokens;

/**
 * A heloufir-free {@see AdminKeycloakSession} for tests: holds the admin's tokens in memory and replays a
 * preset refresh result. Unit tests seed it with crafted JWTs; the E2E suite seeds `tokens()` with a real
 * user token obtained by direct-grant — so no test ever loads heloufir or its `keycloak_sessions` table.
 */
final class InMemoryAdminKeycloakSession implements AdminKeycloakSession
{
    public int $refreshCallCount = 0;

    /**
     * @param  AdminKeycloakTokens|null  $tokens         the currently stored tokens (null = no session)
     * @param  AdminKeycloakTokens|null  $refreshResult  what refresh() returns/persists (null = refresh fails)
     */
    public function __construct(
        private ?AdminKeycloakTokens $tokens,
        private readonly ?AdminKeycloakTokens $refreshResult = null,
    ) {}

    public function tokens(): ?AdminKeycloakTokens
    {
        return $this->tokens;
    }

    public function refresh(): ?AdminKeycloakTokens
    {
        $this->refreshCallCount++;
        $this->tokens = $this->refreshResult;

        return $this->refreshResult;
    }
}
