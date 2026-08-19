<?php

declare(strict_types=1);

namespace Sandstorm\FilamentKeycloakAdmin\Tests\Integration;

use Sandstorm\FilamentKeycloakAdmin\Tests\Fixtures\TestPanelProvider;
use Sandstorm\FilamentKeycloakAdmin\Tests\TestCase;
use Filament\Facades\Filament;
use GuzzleHttp\Client;
use Sandstorm\KeycloakAdminApi\Features\KeycloakUsersApi;
use Sandstorm\KeycloakAdminApi\SharedModel\KeycloakUserId;

use function getenv;

/**
 * Base for the end-to-end suite that drives the Filament/Livewire layer against a real Keycloak (see
 * docker-compose.yml). Unlike the hermetic unit suite, these exercise the **real ServiceProvider
 * wiring** — config → Guzzle → token provider → transport → the Keycloak*Api implementations — with no
 * fakes, proving the plugin speaks to Keycloak 26.5.3 exactly as shipped.
 *
 * Opt-in: skips unless `KEYCLOAK_E2E_BASE_URL` is set, so a normal run (and CI without the service)
 * stays fast and hermetic. Connection defaults match realm-import.json.
 */
abstract class IntegrationTestCase extends TestCase
{
    private string $e2eBaseUrl;

    private string $e2eRealm;

    protected function setUp(): void
    {
        $baseUrl = getenv('KEYCLOAK_E2E_BASE_URL');
        if ($baseUrl === false || $baseUrl === '') {
            self::markTestSkipped('Set KEYCLOAK_E2E_BASE_URL to run the Keycloak end-to-end tests (see tests/Integration/docker-compose.yml).');
        }

        $this->e2eBaseUrl = $baseUrl;
        $this->e2eRealm = self::env('KEYCLOAK_E2E_REALM', 'test-realm');

        parent::setUp();

        // The plugin's Filament components resolve their table/schema state from the *current* panel;
        // `Livewire::test(...)` on a bare component doesn't enter a panel route, so set + boot it here.
        Filament::setCurrentPanel('admin');
        Filament::bootCurrentPanel();
    }

    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), TestPanelProvider::class];
    }

    public function getEnvironmentSetUp($app): void
    {
        $app['config']->set('filament-keycloak-admin', [
            'connection' => [
                'base_url' => getenv('KEYCLOAK_E2E_BASE_URL') ?: null,
                'realm' => self::env('KEYCLOAK_E2E_REALM', 'test-realm'),
                'client_id' => self::env('KEYCLOAK_E2E_CLIENT_ID', 'admin-api'),
                'client_secret' => self::env('KEYCLOAK_E2E_CLIENT_SECRET', 'e2e-secret'),
            ],
            'auth_mode' => 'service_account',
            'http' => ['connect_timeout' => 5, 'timeout' => 15],
            'pw_reset' => ['lifespan' => 43200, 'client_id' => null, 'redirect_uri' => null],
        ]);
    }

    private static function env(string $name, string $default): string
    {
        $value = getenv($name);

        return $value === false || $value === '' ? $default : $value;
    }

    /**
     * Log a seeded user in via the public `e2e-login` direct-access-grant client, so a real user
     * **session** and a **LOGIN event** exist server-side — the deterministic precondition the sessions
     * and events E2E tests read back.
     */
    protected function loginAsUser(string $username, string $password = 'changeit'): void
    {
        (new Client(['http_errors' => true, 'timeout' => 10]))->post(
            $this->e2eBaseUrl . '/realms/' . $this->e2eRealm . '/protocol/openid-connect/token',
            [
                'form_params' => [
                    'grant_type' => 'password',
                    'client_id' => 'e2e-login',
                    'username' => $username,
                    'password' => $password,
                    'scope' => 'openid',
                ],
            ],
        );
    }

    /**
     * Resolve a seeded user's id by exact username through the plugin's bound users API — the shared
     * starting point for the per-feature tests that operate on the imported user "jane".
     */
    protected function seededUserId(string $username = 'jane'): KeycloakUserId
    {
        $match = app(KeycloakUsersApi::class)->list($username, 0, 1, null);

        self::assertFalse($match->isEmpty(), "seed user \"$username\" is missing from the imported realm");

        return $match->all()[0]->id;
    }
}
