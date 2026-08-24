<?php

declare(strict_types=1);

namespace Sandstorm\FilamentKeycloakAdmin\Tests\Integration;

use Filament\Notifications\Notification;
use GuzzleHttp\Client;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Sandstorm\FilamentKeycloakAdmin\Auth\AdminKeycloakSession;
use Sandstorm\FilamentKeycloakAdmin\Auth\AdminKeycloakTokens;
use Sandstorm\FilamentKeycloakAdmin\Filament\Pages\InspectKeycloakUser\KeycloakUserIdentity;
use Sandstorm\FilamentKeycloakAdmin\Tests\Support\InMemoryAdminKeycloakSession;
use Sandstorm\KeycloakAdminApi\Features\KeycloakUsersApi;

use function app;
use function getenv;
use function is_array;
use function is_string;
use function json_decode;

/**
 * Proves the Identity write surface honours **per-admin** Keycloak permissions (FGAP) end-to-end through
 * the Livewire component: the whole reason for the `user.access.manage` gate (plan §2.1). The panel runs
 * in `sso` (act-as-user) mode as **sarah** — an FGAP-scoped staff admin who may *view all* but *manage
 * only /endusers* — against the `test-realm-fgap` realm.
 *
 * So the Edit action is **enabled** on `emma` (an enduser sarah may manage) and shown-but-**disabled** on
 * `jane` (a staff peer she may not), driven purely by Keycloak's own `access` map — no permission is
 * re-implemented client-side. And the race backstop holds: forcing a write sarah isn't allowed (as if her grant changed
 * after the control rendered) surfaces a friendly notice, not the framework error page, and does not
 * mutate the user.
 *
 * sarah carries no manage-level admin role: only the base `query-users`/`query-groups`
 * realm-management roles, because admin endpoints outside the FGAP evaluation (e.g.
 * `GET users/profile`, which the identity infolist reads for the custom attributes) require the
 * caller to hold *some* realm-management role. Neither role grants view or manage on any user, so
 * everything sarah may actually do still comes from the FGAP policies alone.
 *
 * sarah's identity is fed through the same {@see InMemoryAdminKeycloakSession} fake the unit/SSO tests
 * use (seeded with a real direct-grant token), so heloufir is never involved.
 *
 * Opt-in: skips unless `KEYCLOAK_E2E_BASE_URL` is set (see docker-compose.yml).
 */
#[Group('integration')]
final class KeycloakIdentityGatingFgapE2ETest extends IntegrationTestCase
{
    private const string FGAP_REALM = 'test-realm-fgap';

    /**
     * Run the panel as sarah in `sso` mode against the FGAP realm: force the config and bind an
     * {@see AdminKeycloakSession} that lazily direct-grants sarah's token, resolved only once the token
     * provider is first used (after the skip check).
     */
    public function getEnvironmentSetUp($app): void
    {
        $baseUrl = getenv('KEYCLOAK_E2E_BASE_URL') ?: null;

        $app['config']->set('filament-keycloak-admin', [
            'connection' => [
                'backchannel_url' => $baseUrl,
                'realm' => self::FGAP_REALM,
                // In sso mode the client_id/secret are unused by the transport (the bearer comes from the
                // admin's session), but the settings object still requires them.
                'client_id' => 'unused-in-sso',
                'client_secret' => 'unused-in-sso',
            ],
            'auth_mode' => 'sso',
            'http' => ['connect_timeout' => 5, 'timeout' => 15],
            'pw_reset' => ['lifespan' => 43200, 'client_id' => null, 'redirect_uri' => null],
        ]);

        $app->singleton(AdminKeycloakSession::class, static function () use ($baseUrl): AdminKeycloakSession {
            $token = self::directGrantAccessToken((string) $baseUrl, self::FGAP_REALM, 'sarah');

            return new InMemoryAdminKeycloakSession(new AdminKeycloakTokens($token, 'unused-refresh'));
        });
    }

    #[Test]
    public function edit_is_offered_for_a_manageable_enduser_and_withheld_for_a_staff_peer(): void
    {
        $users = app(KeycloakUsersApi::class);
        $emmaId = $users->list('emma', 0, 1, null)->all()[0]->id;
        $janeId = $users->list('jane', 0, 1, null)->all()[0]->id;

        // emma ∈ /endusers → sarah may manage → Edit enabled.
        Livewire::test(KeycloakUserIdentity::class, ['userId' => (string) $emmaId->value])
            ->assertActionVisible('editIdentity')
            ->assertActionEnabled('editIdentity');

        // jane ∈ /staff → sarah may view but not manage → Edit shown but disabled (with a tooltip).
        Livewire::test(KeycloakUserIdentity::class, ['userId' => (string) $janeId->value])
            ->assertActionVisible('editIdentity')
            ->assertActionDisabled('editIdentity');
    }

    #[Test]
    public function a_denied_write_surfaces_a_friendly_notice_and_does_not_mutate(): void
    {
        $users = app(KeycloakUsersApi::class);
        $janeId = $users->list('jane', 0, 1, null)->all()[0]->id;

        self::assertTrue($users->getById($janeId)->enabled, 'precondition: jane starts enabled');

        // Force the write the gate would normally prevent (as if sarah's grant changed after render).
        // Keycloak 403s; the component must catch it into a notice, not blow up, and jane stays enabled.
        Livewire::test(KeycloakUserIdentity::class, ['userId' => (string) $janeId->value])
            ->call('setEnabled', false)
            ->assertHasNoErrors();

        Notification::assertNotified('You do not have permission to make this change.');
        self::assertTrue($users->getById($janeId)->enabled, 'a denied write must not change the user');
    }

    /**
     * A real user access token via the realm's public `e2e-login` direct-grant client — the E2E stand-in
     * for the token heloufir would have stashed for the logged-in admin.
     */
    private static function directGrantAccessToken(string $baseUrl, string $realm, string $username, string $password = 'changeit'): string
    {
        $response = (new Client(['http_errors' => true, 'timeout' => 10]))->post(
            $baseUrl . '/realms/' . $realm . '/protocol/openid-connect/token',
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
        $accessToken = is_array($token) ? ($token['access_token'] ?? null) : null;
        self::assertTrue(is_string($accessToken) && $accessToken !== '', 'direct-grant did not return an access_token');

        return $accessToken;
    }
}
