<?php

declare(strict_types=1);

namespace Sandstorm\FilamentKeycloakAdmin\Tests\Feature\Http;

use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\Attributes\Test;
use Sandstorm\FilamentKeycloakAdmin\FilamentKeycloakAdminServiceProvider;
use Sandstorm\FilamentKeycloakAdmin\Http\KeycloakAdminHttpHandlerStackCustomizer;
use Sandstorm\FilamentKeycloakAdmin\Tests\Fixtures\BindsRecordingHandlerStackCustomizerProvider;
use Sandstorm\FilamentKeycloakAdmin\Tests\TestCase;

/**
 * The Guzzle handler-stack extension point: if the host app binds a
 * {@see KeycloakAdminHttpHandlerStackCustomizer}, {@see FilamentKeycloakAdminServiceProvider::buildHttpClient()}
 * must hand it the real stack backing the Keycloak Admin API client and use whatever it returns. The
 * binding must happen before the package provider registers — a plain `getEnvironmentSetUp()` override
 * runs too late, since `buildHttpClient()` executes synchronously while providers are registered, before
 * `getEnvironmentSetUp()` fires — so a small provider ahead of the package provider does the binding
 * instead (see {@see BindsRecordingHandlerStackCustomizerProvider}).
 */
final class KeycloakAdminHttpHandlerStackCustomizerTest extends TestCase
{
    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [BindsRecordingHandlerStackCustomizerProvider::class, ...parent::getPackageProviders($app)];
    }

    #[Test]
    public function it_hands_the_bound_customizer_the_real_handler_stack_exactly_once(): void
    {
        $customizer = $this->app->make(KeycloakAdminHttpHandlerStackCustomizer::class);

        self::assertSame(1, $customizer->callCount);
        self::assertInstanceOf(HandlerStack::class, $customizer->receivedStack);
    }

    #[Test]
    public function middleware_pushed_by_the_customizer_actually_runs_requests_through_the_stack(): void
    {
        $customizer = $this->app->make(KeycloakAdminHttpHandlerStackCustomizer::class);

        $handler = $customizer->receivedStack->resolve();
        $response = $handler(new Request('GET', 'http://keycloak.test/admin/realms/test/users'), [])->wait();

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $customizer->interceptedRequests);
        self::assertSame('GET', $customizer->interceptedRequests[0]->getMethod());
    }
}
