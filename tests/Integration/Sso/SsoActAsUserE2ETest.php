<?php

declare(strict_types=1);

namespace Sandstorm\FilamentKeycloakAdmin\Tests\Integration\Sso;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Sandstorm\FilamentKeycloakAdmin\Auth\AdminKeycloakTokens;
use Sandstorm\FilamentKeycloakAdmin\Auth\FilamentSsoTokenProvider;
use Sandstorm\FilamentKeycloakAdmin\Tests\Support\InMemoryAdminKeycloakSession;
use Sandstorm\KeycloakAdminApi\Connection\KeycloakSettings;
use Sandstorm\KeycloakAdminApi\Connection\KeycloakSettingsProvider;
use Sandstorm\KeycloakAdminApi\Connection\KeycloakTransport;
use Sandstorm\KeycloakAdminApi\Connection\UnexpectedKeycloakResponseException;
use Sandstorm\KeycloakAdminApi\Features\KeycloakUsersApi\KeycloakUsersApiImplementation;

use function getenv;
use function is_string;
use function json_decode;

/**
 * End-to-end proof of the `sso` act-as-user path against a real Keycloak: a bearer produced by
 * {@see FilamentSsoTokenProvider} from a *user's* token is accepted by the Admin API **as that user**, so
 * Keycloak (not our code) decides what the caller may do. The provider is fed the token through the same
 * {@see InMemoryAdminKeycloakSession} fake the unit tests use, so heloufir is never involved.
 *
 * The suite runs against **both** seeded realms via the {@see realms()} data provider — `test-realm`
 * (Admin Permissions off, classic roles) and `test-realm-fgap` (Admin Permissions on) — so the same
 * behaviour is proven in both authorization modes without duplicated test classes. The mode-specific case
 * (the fine-grained group-based staff policy) is a single FGAP-only test, not a second class/realm.
 *
 * Opt-in: skips unless `KEYCLOAK_E2E_BASE_URL` is set (see ../docker-compose.yml).
 */
#[Group('integration')]
final class SsoActAsUserE2ETest extends TestCase
{
    private string $baseUrl;

    protected function setUp(): void
    {
        $baseUrl = getenv('KEYCLOAK_E2E_BASE_URL');
        if ($baseUrl === false || $baseUrl === '') {
            self::markTestSkipped('Set KEYCLOAK_E2E_BASE_URL to run the Keycloak end-to-end tests (see tests/Integration/docker-compose.yml).');
        }

        $this->baseUrl = $baseUrl;
    }

    /**
     * Every realm the act-as-user contract must hold in. Named cases so a failure says which mode broke.
     *
     * @return array<string, array{string}>
     */
    public static function realms(): array
    {
        return [
            'FGAP off (classic roles)' => ['test-realm'],
            'FGAP on (admin permissions)' => ['test-realm-fgap'],
        ];
    }

    #[Test]
    #[DataProvider('realms')]
    public function a_privileged_user_token_is_accepted_and_acts_as_that_user(string $realm): void
    {
        // admin-user carries realm-management roles → the act-as-user bearer is authorised to read users.
        $users = new KeycloakUsersApiImplementation($this->transportActingAs($realm, $this->realUserAccessToken($realm, 'admin-user')));

        $found = $users->list('jane', 0, 1, null);

        self::assertFalse($found->isEmpty(), "the privileged admin token should be allowed to list users in $realm");
        self::assertSame('jane', $found->all()[0]->username);
    }

    #[Test]
    #[DataProvider('realms')]
    public function an_unprivileged_user_token_is_rejected_by_keycloak_not_silently_replaced(string $realm): void
    {
        // login-user has no admin rights. If sso ever fell back to the service account this would wrongly
        // succeed; instead Keycloak must reject the *user's own* token with 403 — the proof that the call
        // is really made as that user, in both authorization modes.
        $users = new KeycloakUsersApiImplementation($this->transportActingAs($realm, $this->realUserAccessToken($realm, 'login-user')));

        try {
            $users->list('jane', 0, 1, null);
            self::fail("expected an unprivileged user token to be denied by Keycloak in $realm");
        } catch (UnexpectedKeycloakResponseException $exception) {
            self::assertSame(403, $exception->statusCode, 'an unprivileged act-as-user call must be a 403, not a fallback success');
        }
    }

    #[Test]
    public function a_staff_member_may_edit_an_enduser_but_not_another_staff_member(): void
    {
        // FGAP-only: proves the fine-grained group policy end-to-end through a real *write*. sarah is in
        // /staff and has NO realm-management roles — her authority comes purely from the admin-permissions
        // model: view all users, but manage-members only on /endusers. So she may edit emma (an enduser)
        // and must be refused on jane (another staff member).
        $realm = 'test-realm-fgap';

        // admin-user resolves the target ids (sarah is not asserted-on here, just the actor for the writes).
        $admin = new KeycloakUsersApiImplementation($this->transportActingAs($realm, $this->realUserAccessToken($realm, 'admin-user')));
        $emmaId = $admin->list('emma', 0, 1, null)->all()[0]->id;
        $janeId = $admin->list('jane', 0, 1, null)->all()[0]->id;

        $staff = new KeycloakUsersApiImplementation($this->transportActingAs($realm, $this->realUserAccessToken($realm, 'sarah')));

        // Enduser: read-modify-write succeeds and persists (manage-members on /endusers).
        $staff->update($staff->getById($emmaId)->withFirstName('EmmaEdited'));
        self::assertSame('EmmaEdited', $staff->getById($emmaId)->firstName, 'a staff member should be able to edit an enduser');

        // Staff peer: viewable (view-all) but not manageable — Keycloak denies the write with 403.
        $jane = $staff->getById($janeId);
        self::assertSame('jane', $jane->username, 'a staff member should still be able to *view* another staff member');

        try {
            $staff->update($jane->withFirstName('ShouldNotStick'));
            self::fail('a staff member must not be allowed to manage another staff member');
        } catch (UnexpectedKeycloakResponseException $exception) {
            self::assertSame(403, $exception->statusCode, 'managing a staff peer must be a 403, not a silent success');
        }
    }

    /**
     * A real user access token from the realm's public direct-grant client `e2e-login` — the E2E stand-in
     * for "the token heloufir stashed for the logged-in admin". The provider treats it exactly the same.
     */
    private function realUserAccessToken(string $realm, string $username, string $password = 'changeit'): string
    {
        $response = (new Client(['http_errors' => true, 'timeout' => 10]))->post(
            $this->baseUrl . '/realms/' . $realm . '/protocol/openid-connect/token',
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

        $token = json_decode((string) $response->getBody(), true);
        self::assertIsArray($token);
        self::assertTrue(is_string($token['access_token'] ?? null), 'direct-grant did not return an access_token');

        return $token['access_token'];
    }

    /**
     * A transport whose bearer comes from {@see FilamentSsoTokenProvider} seeded with the given user
     * token — i.e. every Admin-API call is made *as that user*.
     */
    private function transportActingAs(string $realm, string $accessToken): KeycloakTransport
    {
        $settings = new KeycloakSettings($this->baseUrl, $realm, 'unused-by-transport', 'unused-by-transport');

        $settingsProvider = new readonly class($settings) implements KeycloakSettingsProvider
        {
            public function __construct(private KeycloakSettings $settings) {}

            public function get(): KeycloakSettings
            {
                return $this->settings;
            }
        };

        $provider = new FilamentSsoTokenProvider(
            new InMemoryAdminKeycloakSession(new AdminKeycloakTokens($accessToken, 'unused-refresh')),
        );

        $httpFactory = new HttpFactory;

        return new KeycloakTransport($settingsProvider, new Client(['timeout' => 10]), $httpFactory, $httpFactory, $provider);
    }
}
