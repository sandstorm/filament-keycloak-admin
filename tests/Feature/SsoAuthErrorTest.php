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
use Sandstorm\FilamentKeycloakAdmin\Exceptions\SsoAuthException;
use Sandstorm\FilamentKeycloakAdmin\Filament\Pages\KeycloakUsers;
use Sandstorm\FilamentKeycloakAdmin\Tests\Fixtures\TestPanelProvider;
use Sandstorm\FilamentKeycloakAdmin\Tests\TestCase;
use Sandstorm\KeycloakAdminApi\Features\KeycloakUsersApi;
use Sandstorm\KeycloakAdminApi\Features\KeycloakUsersApi\Dto\CreateKeycloakUserCommand;
use Sandstorm\KeycloakAdminApi\Features\KeycloakUsersApi\Dto\KeycloakUser;
use Sandstorm\KeycloakAdminApi\SharedModel\KeycloakUserId;

/**
 * Exercises {@see SsoAuthErrorRenderer} — the single place an `sso`-mode auth failure (the admin has no
 * usable Keycloak session to act as) becomes a response, registered as a Laravel exception renderable
 * exactly like {@see KeycloakLoadErrorRenderer}. Mirrors
 * {@see KeycloakLoadErrorTest} in structure, including proving the unwrap logic against a *real*
 * {@see ViewException}.
 */
final class SsoAuthErrorTest extends TestCase
{
    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), NotificationsServiceProvider::class, TestPanelProvider::class];
    }

    #[Test]
    public function a_real_http_request_reaches_the_renderer_through_laravels_actual_exception_handling(): void
    {
        $this->app->instance(KeycloakUsersApi::class, new class implements KeycloakUsersApi
        {
            public function list(?string $search, int $first, int $max, ?bool $enabled): KeycloakUsersApi\Dto\KeycloakUsers
            {
                throw new SsoAuthException('no session', 1755600001);
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

        // 200, not 401: Filament only boots Alpine on the client for a 2xx response, and this page's
        // navigation/search need Alpine to work. The real failure is still logged explicitly elsewhere,
        // independently of this HTTP status code.
        $response->assertStatus(200);
        $response->assertSee(__('filament-keycloak-admin::filament-keycloak-admin.sso_auth_error.message'));
    }

    #[Test]
    public function it_finds_the_cause_through_a_real_view_exception_and_renders_the_panel_chrome_around_one_notice(): void
    {
        $this->usePanel();

        $exception = new SsoAuthException('the refresh produced nothing usable', 1755600002);
        $wrapped = $this->wrapAsViewExceptionByActuallyRenderingAFailingView($exception);

        $response = (new SsoAuthErrorRenderer)($wrapped, Request::create('/admin/keycloak-users'));

        self::assertNotNull($response);
        // 200, not 401 — see the comment on SsoAuthErrorRenderer for why.
        self::assertSame(200, $response->getStatusCode());

        $body = $response->getContent();
        self::assertStringContainsString(
            __('filament-keycloak-admin::filament-keycloak-admin.sso_auth_error.message'),
            $body,
        );
        // The panel's own layout, not a bare fragment — proves the topbar/sidebar chrome is still there.
        self::assertStringContainsString('fi-body', $body);
        self::assertStringContainsString('fi-main', $body);
        // The CSS override that keeps the content area visible without depending on Alpine ever running.
        self::assertStringContainsString('.fi-main-ctn', $body);
        self::assertStringContainsString('opacity: 1 !important', $body);
    }

    #[Test]
    public function it_ignores_exceptions_unrelated_to_sso_auth(): void
    {
        $this->usePanel();

        $response = (new SsoAuthErrorRenderer)(new RuntimeException('unrelated'), Request::create('/admin/keycloak-users'));

        self::assertNull($response);
    }

    #[Test]
    public function it_defers_outside_a_panel_request(): void
    {
        Filament::setCurrentPanel(null);

        $response = (new SsoAuthErrorRenderer)(
            new SsoAuthException('no session', 1755600001),
            Request::create('/console'),
        );

        self::assertNull($response);
    }

    /**
     * Renders a throwaway Blade view whose only content throws $exception, exactly as a failing
     * `records()` closure or nested tab component would mid-render — so the resulting exception is
     * genuinely wrapped by Laravel's compiler engine the same way it would be in production, rather than
     * a hand-built {@see ViewException} that might not match what actually gets thrown.
     */
    private function wrapAsViewExceptionByActuallyRenderingAFailingView(SsoAuthException $exception): ViewException
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
}
