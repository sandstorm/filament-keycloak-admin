<?php

declare(strict_types=1);

namespace Sandstorm\FilamentKeycloakAdmin\Tests\Feature\Http;

use PHPUnit\Framework\Attributes\Test;
use Sandstorm\FilamentKeycloakAdmin\Http\KeycloakAdminHttpHandlerStackCustomizer;
use Sandstorm\FilamentKeycloakAdmin\Tests\TestCase;
use Sandstorm\KeycloakAdminApi\Features\KeycloakUsersApi;

/**
 * The {@see KeycloakAdminHttpHandlerStackCustomizer} extension point is optional — a host app that never
 * binds one (the default {@see TestCase} setup) must still get a working client.
 */
final class KeycloakAdminHttpHandlerStackCustomizerAbsentTest extends TestCase
{
    #[Test]
    public function the_client_still_builds_and_resolves_without_any_customizer_bound(): void
    {
        self::assertFalse($this->app->has(KeycloakAdminHttpHandlerStackCustomizer::class));
        self::assertInstanceOf(KeycloakUsersApi::class, $this->app->make(KeycloakUsersApi::class));
    }
}
