<?php

declare(strict_types=1);

namespace Sandstorm\FilamentKeycloakAdmin\Tests\Feature\Http;

use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\Attributes\Test;
use Sandstorm\FilamentKeycloakAdmin\Auth\AdminKeycloakSession;
use Sandstorm\FilamentKeycloakAdmin\FilamentKeycloakAdminServiceProvider;
use Sandstorm\FilamentKeycloakAdmin\Http\KeycloakAdminHttpHandlerStackCustomizer;
use Sandstorm\FilamentKeycloakAdmin\Http\KeycloakHttpClientName;
use Sandstorm\FilamentKeycloakAdmin\Tests\Fixtures\RecordingHandlerStackCustomizer;
use Sandstorm\FilamentKeycloakAdmin\Tests\TestCase;
use Sandstorm\KeycloakAdminApi\Features\KeycloakUsersApi;

/**
 * The Guzzle handler-stack extension point: if the host app binds a
 * {@see KeycloakAdminHttpHandlerStackCustomizer}, {@see FilamentKeycloakAdminServiceProvider::buildHttpClient()}
 * must hand it the real stack backing each Guzzle client the package builds, tagged with which client it
 * is via {@see KeycloakHttpClientName}, and use whatever it returns. `buildHttpClient()` only runs lazily,
 * the first time something resolves a client that needs one, so a plain `getEnvironmentSetUp()` binding
 * (same timing as {@see AdminKeycloakSession}) is all a host — or a
 * test — needs; no provider ordering trick required.
 *
 * In `service_account` auth mode, resolving the Keycloak API surface builds two independent Guzzle
 * clients — one for the token endpoint (inside the `KeycloakTokenProvider` singleton, tagged
 * `KEYCLOAK_TOKEN_PROVIDER`) and one for the Admin REST API itself (inside the `KeycloakTransport`
 * singleton, tagged `KEYCLOAK_TRANSPORT`) — so the customizer is consulted once per client, not once
 * overall, and can tell them apart by the tag.
 */
final class KeycloakAdminHttpHandlerStackCustomizerTest extends TestCase
{
    public function getEnvironmentSetUp($app): void
    {
        $app->instance(KeycloakAdminHttpHandlerStackCustomizer::class, new RecordingHandlerStackCustomizer);
    }

    #[Test]
    public function it_hands_the_bound_customizer_the_real_handler_stack_tagged_with_each_client_it_builds(): void
    {
        $this->app->make(KeycloakUsersApi::class);
        $customizer = $this->app->make(KeycloakAdminHttpHandlerStackCustomizer::class);

        self::assertSame(2, $customizer->callCount);
        self::assertEqualsCanonicalizing(
            [KeycloakHttpClientName::KEYCLOAK_TOKEN_PROVIDER, KeycloakHttpClientName::KEYCLOAK_TRANSPORT],
            $customizer->receivedClientNames,
        );
    }

    #[Test]
    public function middleware_pushed_by_the_customizer_actually_runs_admin_api_requests_through_the_transport_stack(): void
    {
        $this->app->make(KeycloakUsersApi::class);
        $customizer = $this->app->make(KeycloakAdminHttpHandlerStackCustomizer::class);

        $transportStack = $customizer->receivedStacksByClientName[KeycloakHttpClientName::KEYCLOAK_TRANSPORT->name];
        $handler = $transportStack->resolve();
        $response = $handler(new Request('GET', 'http://keycloak.test/admin/realms/test/users'), [])->wait();

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $customizer->interceptedRequests);
        self::assertSame('GET', $customizer->interceptedRequests[0]->getMethod());
    }
}
