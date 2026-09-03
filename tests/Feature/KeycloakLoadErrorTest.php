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
use Sandstorm\FilamentKeycloakAdmin\Tests\Fixtures\TestPanelProvider;
use Sandstorm\FilamentKeycloakAdmin\Tests\TestCase;
use Sandstorm\KeycloakAdminApi\Connection\UnexpectedKeycloakResponseException;

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

    #[Test]
    public function it_finds_the_cause_through_a_real_view_exception_and_renders_the_panel_chrome_around_one_notice(): void
    {
        $this->usePanel();

        $exception = new UnexpectedKeycloakResponseException('permission denied', 0, statusCode: 403);
        $wrapped = $this->wrapAsViewExceptionByActuallyRenderingAFailingView($exception);

        $response = (new KeycloakLoadErrorRenderer)($wrapped, Request::create('/admin/keycloak-users'));

        self::assertNotNull($response);
        self::assertSame(503, $response->getStatusCode());

        $body = $response->getContent();
        self::assertStringContainsString(
            __('filament-keycloak-admin::filament-keycloak-admin.load_error.forbidden'),
            $body,
        );
        // The panel's own layout, not a bare fragment — proves the topbar/sidebar chrome is still there.
        self::assertStringContainsString('fi-body', $body);
        self::assertStringContainsString('fi-main', $body);
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
}
