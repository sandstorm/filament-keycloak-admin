<?php

declare(strict_types=1);

namespace Sandstorm\FilamentKeycloakAdmin\Tests\Feature;

use Filament\Facades\Filament;
use Filament\Notifications\NotificationsServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\ViewException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Sandstorm\FilamentKeycloakAdmin\Exceptions\KeycloakLoadErrorRenderer;
use Sandstorm\FilamentKeycloakAdmin\Exceptions\SsoAuthErrorRenderer;
use Sandstorm\FilamentKeycloakAdmin\Filament\Pages\KeycloakUsers;
use Sandstorm\FilamentKeycloakAdmin\FilamentKeycloakAdminServiceProvider;
use Sandstorm\FilamentKeycloakAdmin\Logging\KeycloakAdminLogger;
use Sandstorm\FilamentKeycloakAdmin\Logging\KeycloakAdminLoggerFactory;
use Sandstorm\FilamentKeycloakAdmin\Tests\Fixtures\TestPanelProvider;
use Sandstorm\FilamentKeycloakAdmin\Tests\Support\FakeAdminUser;
use Sandstorm\FilamentKeycloakAdmin\Tests\Support\InMemoryLogger;
use Sandstorm\FilamentKeycloakAdmin\Tests\TestCase;
use Sandstorm\KeycloakAdminApi\Connection\UnexpectedKeycloakResponseException;
use Sandstorm\KeycloakAdminApi\Features\KeycloakUsersApi;
use Sandstorm\KeycloakAdminApi\Features\KeycloakUsersApi\Dto\CreateKeycloakUserCommand;
use Sandstorm\KeycloakAdminApi\Features\KeycloakUsersApi\Dto\KeycloakUser;
use Sandstorm\KeycloakAdminApi\SharedModel\KeycloakUserId;

/**
 * Exercises {@see KeycloakLoadErrorRenderer} — the single place a Keycloak read failure becomes a
 * response, registered once as a Laravel exception renderable rather than caught by any page or
 * component. Read failures always surface from inside Blade evaluation (a table's `records()` closure,
 * an infolist, a nested tab component), which Laravel's compiler engine wraps in a
 * {@see ViewException} — so these tests prove the unwrap logic against a *real* one, not a hand-built
 * stand-in.
 */
final class KeycloakLoadErrorTest extends TestCase
{
    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), NotificationsServiceProvider::class, TestPanelProvider::class];
    }

    /**
     * The one test that exercises the *actual* registration in
     * {@see FilamentKeycloakAdminServiceProvider::packageBooted()} —
     * every other test in this file calls {@see KeycloakLoadErrorRenderer} directly, which proves the
     * renderer's own logic but not that Laravel's exception handler actually dispatches to it. Resolving
     * the wrong exception-handler instance to register against (a fresh, unused one instead of the real
     * singleton the kernel renders through) would pass every other test here and still 500 in production —
     * this is the regression that ships the fix for.
     */
    #[Test]
    public function a_real_http_request_reaches_the_renderer_through_laravels_actual_exception_handling(): void
    {
        $this->app->instance(KeycloakUsersApi::class, new class implements KeycloakUsersApi
        {
            public function list(?string $search, int $first, int $max, ?bool $enabled): KeycloakUsersApi\Dto\KeycloakUsers
            {
                throw new UnexpectedKeycloakResponseException('boom', 0, statusCode: 403);
            }

            public function count(?string $search, ?bool $enabled): int
            {
                return 0;
            }

            public function getById(KeycloakUserId $id): KeycloakUser
            {
                throw new RuntimeException('not used by this test');
            }

            public function findByUsername(string $username): ?KeycloakUser
            {
                throw new RuntimeException('not used by this test');
            }

            public function create(CreateKeycloakUserCommand $command): KeycloakUser
            {
                throw new RuntimeException('not used by this test');
            }

            public function update(KeycloakUser $user): void
            {
                throw new RuntimeException('not used by this test');
            }
        });

        $response = $this->get(KeycloakUsers::getUrl(panel: 'admin'));

        // 200, not 503: Filament only boots Alpine on the client for a 2xx response, and this page's
        // navigation/search need Alpine to work. The real failure is still logged explicitly elsewhere,
        // independently of this HTTP status code.
        $response->assertStatus(200);
        $response->assertSee(__('filament-keycloak-admin::filament-keycloak-admin.load_error.forbidden'));
    }

    #[Test]
    public function it_finds_the_cause_through_a_real_view_exception_and_renders_the_panel_chrome_around_one_notice(): void
    {
        $this->usePanel();

        $exception = new UnexpectedKeycloakResponseException('permission denied', 0, statusCode: 403);
        $wrapped = $this->wrapAsViewExceptionByActuallyRenderingAFailingView($exception);

        $response = (new KeycloakLoadErrorRenderer)($wrapped, Request::create('/admin/keycloak-users'));

        self::assertNotNull($response);
        // 200, not 503 — see the comment on KeycloakLoadErrorRenderer for why.
        self::assertSame(200, $response->getStatusCode());

        $body = $response->getContent();
        self::assertStringContainsString(
            __('filament-keycloak-admin::filament-keycloak-admin.load_error.forbidden'),
            $body,
        );
        // The panel's own layout, not a bare fragment — proves the topbar/sidebar chrome is still there.
        self::assertStringContainsString('fi-body', $body);
        self::assertStringContainsString('fi-main', $body);
    }

    /**
     * A read failure is an unexpected condition worth an operator's attention, so it logs at `error` —
     * unlike an sso auth failure ({@see SsoAuthErrorRenderer}),
     * which is most likely just an expired admin session rather than a system problem, and logs at
     * `warning`. Logging is optional — see {@see KeycloakAdminLoggerFactory} — so absence of a binding
     * must not break the renderer; that is covered separately below.
     */
    #[Test]
    public function it_logs_the_failure_at_error_level_with_the_admin_id(): void
    {
        $this->usePanel();
        $logger = $this->bindLogger();
        Filament::auth()->setUser(new FakeAdminUser(['id' => 42]));

        $exception = new UnexpectedKeycloakResponseException('permission denied', 0, statusCode: 403);
        $wrapped = $this->wrapAsViewExceptionByActuallyRenderingAFailingView($exception);

        (new KeycloakLoadErrorRenderer)($wrapped, Request::create('/admin/keycloak-users'));

        self::assertCount(1, $logger->records);
        self::assertSame('error', $logger->records[0]['level']);
        self::assertSame('Keycloak load error', $logger->records[0]['message']);
        self::assertSame(42, $logger->records[0]['context']['admin_id']);
        self::assertSame($wrapped, $logger->records[0]['context']['exception']);
    }

    #[Test]
    public function it_does_not_log_or_throw_when_no_logger_is_bound(): void
    {
        $this->usePanel();

        $exception = new UnexpectedKeycloakResponseException('permission denied', 0, statusCode: 403);
        $wrapped = $this->wrapAsViewExceptionByActuallyRenderingAFailingView($exception);

        $response = (new KeycloakLoadErrorRenderer)($wrapped, Request::create('/admin/keycloak-users'));

        self::assertNotNull($response);
    }

    #[Test]
    public function it_ignores_exceptions_unrelated_to_keycloak(): void
    {
        $this->usePanel();

        $response = (new KeycloakLoadErrorRenderer)(new RuntimeException('unrelated'), Request::create('/admin/keycloak-users'));

        self::assertNull($response);
    }

    #[Test]
    public function it_defers_outside_a_panel_request(): void
    {
        Filament::setCurrentPanel(null);

        $response = (new KeycloakLoadErrorRenderer)(
            new UnexpectedKeycloakResponseException('boom', 0, statusCode: null),
            Request::create('/console'),
        );

        self::assertNull($response);
    }

    #[Test]
    public function it_describes_every_status_bucket(): void
    {
        $this->usePanel();

        $describe = function (UnexpectedKeycloakResponseException $exception): string {
            $response = (new KeycloakLoadErrorRenderer)($exception, Request::create('/admin/keycloak-users'));
            self::assertNotNull($response);

            return (string) $response->getContent();
        };

        self::assertStringContainsString(
            __('filament-keycloak-admin::filament-keycloak-admin.load_error.unreachable'),
            $describe(new UnexpectedKeycloakResponseException('boom', 0, statusCode: null)),
        );
        self::assertStringContainsString(
            __('filament-keycloak-admin::filament-keycloak-admin.load_error.forbidden'),
            $describe(new UnexpectedKeycloakResponseException('boom', 0, statusCode: 401)),
        );
        self::assertStringContainsString(
            str_replace(':status', '502', __('filament-keycloak-admin::filament-keycloak-admin.load_error.server_error', ['status' => 502])),
            $describe(new UnexpectedKeycloakResponseException('boom', 0, statusCode: 502)),
        );
        self::assertStringContainsString(
            str_replace(':status', '418', __('filament-keycloak-admin::filament-keycloak-admin.load_error.unexpected', ['status' => 418])),
            $describe(new UnexpectedKeycloakResponseException('boom', 0, statusCode: 418)),
        );
    }

    /**
     * Renders a throwaway Blade view whose only content throws $exception, exactly as a failing
     * `records()` closure or nested tab component would mid-render — so the resulting exception is
     * genuinely wrapped by Laravel's compiler engine the same way it would be in production, rather than
     * a hand-built {@see ViewException} that might not match what actually gets thrown.
     */
    private function wrapAsViewExceptionByActuallyRenderingAFailingView(UnexpectedKeycloakResponseException $exception): ViewException
    {
        try {
            Blade::render('@php throw $exception; @endphp', ['exception' => $exception]);
        } catch (ViewException $wrapped) {
            return $wrapped;
        }

        self::fail('expected the view to throw');
    }

    /**
     * {@see TestPanelProvider} registers a real panel (with real routes — the sidebar renders
     * `getUrl()` links, which need routes to actually resolve) rather than an ad hoc one built here.
     */
    private function usePanel(): void
    {
        Filament::setCurrentPanel('admin');
        Filament::bootCurrentPanel();
    }

    private function bindLogger(): InMemoryLogger
    {
        $logger = new InMemoryLogger;
        $this->app->instance(KeycloakAdminLogger::class, $logger);

        return $logger;
    }
}
