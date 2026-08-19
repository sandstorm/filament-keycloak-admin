<?php

declare(strict_types=1);

namespace Sandstorm\FilamentKeycloakAdmin\Tests\Integration;

use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Sandstorm\FilamentKeycloakAdmin\Filament\Pages\InspectKeycloakUser\KeycloakUserCredentialsTable;
use Sandstorm\FilamentKeycloakAdmin\Filament\Pages\InspectKeycloakUser\KeycloakUserGroupsTable;
use Sandstorm\FilamentKeycloakAdmin\Filament\Pages\InspectKeycloakUser\KeycloakUserIdentity;
use Sandstorm\FilamentKeycloakAdmin\Filament\Pages\InspectKeycloakUser\KeycloakUserSessionsTable;
use Sandstorm\KeycloakAdminApi\Features\KeycloakCredentialsApi;
use Sandstorm\KeycloakAdminApi\Features\KeycloakGroupsApi;
use Sandstorm\KeycloakAdminApi\Features\KeycloakSessionsApi;
use Sandstorm\KeycloakAdminApi\Features\KeycloakUsersApi;
use Sandstorm\KeycloakAdminApi\SharedModel\KeycloakUserId;

use function app;
use function count;

/**
 * E2E coverage for the *write* actions the plugin surfaces in the UI, driven through the real Filament
 * action lifecycle (mount → fill → confirm → call) against a live Keycloak, then verified server-side
 * via the bound API. The pure-API counterparts live in the client lib's Integration suite; these prove
 * the Livewire/Filament layer on top actually reaches Keycloak.
 *
 * Every mutation is kept net-zero so the read-focused E2E tests keep seeing the same seed: group adds
 * are undone, the removed group is added back first, and the deletable second factor belongs to a
 * dedicated `mfa-user` (jane is never disturbed).
 */
#[Group('integration')]
final class KeycloakUserWriteActionsE2ETest extends IntegrationTestCase
{
    /**
     * Credentials-table header action → `executeActionsEmail(['UPDATE_PASSWORD'])`. Succeeds only
     * because the realm's SMTP points at MailPit; no action error = Keycloak accepted the request.
     */
    #[Test]
    public function password_reset_email_action_is_accepted(): void
    {
        $userId = (string) $this->seededUserId()->value;

        Livewire::test(KeycloakUserCredentialsTable::class, ['userId' => $userId])
            ->callAction(TestAction::make('triggerPasswordReset')->table())
            ->assertHasNoActionErrors();
    }

    /**
     * Sessions-table header action → `logoutAll`. Log jane in first so a real session exists, then prove
     * the action clears it server-side.
     */
    #[Test]
    public function logout_all_action_clears_active_sessions(): void
    {
        $this->loginAsUser('jane');
        $janeId = $this->seededUserId();
        $sessions = app(KeycloakSessionsApi::class);

        self::assertGreaterThan(0, count($sessions->getSessions($janeId)->all()), 'precondition: jane should have a live session after login');

        Livewire::test(KeycloakUserSessionsTable::class, ['userId' => (string) $janeId->value])
            ->callAction(TestAction::make('logoutAll')->table())
            ->assertHasNoFormErrors();

        self::assertSame(0, count($sessions->getSessions($janeId)->all()), 'every session should be gone after log-out-all');
    }

    /**
     * Groups-table header action → `addGroups` (multi-select of groups the user is not yet in). Adds jane
     * to /admins, then undoes it so the seed stays net-zero.
     */
    #[Test]
    public function add_to_group_action_adds_a_membership(): void
    {
        $groups = app(KeycloakGroupsApi::class);
        $janeId = $this->seededUserId();
        $adminsId = $this->realmGroupId('admins');

        self::assertNotContains('/admins', $this->membershipPaths($groups, $janeId), 'precondition: jane must not already be in /admins');

        try {
            Livewire::test(KeycloakUserGroupsTable::class, ['userId' => (string) $janeId->value])
                ->callAction(TestAction::make('addGroups')->table(), ['groupIds' => [$adminsId]])
                ->assertHasNoFormErrors();

            self::assertContains('/admins', $this->membershipPaths($groups, $janeId));
        } finally {
            $groups->removeUserFromGroup($janeId, $adminsId);
        }
    }

    /**
     * Groups-table per-row action → `removeGroup`. Add jane to /admins first (via the API) so there is a
     * membership to remove through the UI; the read tests still see only /staff afterwards.
     */
    #[Test]
    public function remove_group_action_removes_a_membership(): void
    {
        $groups = app(KeycloakGroupsApi::class);
        $janeId = $this->seededUserId();
        $adminsId = $this->realmGroupId('admins');

        $groups->addUserToGroup($janeId, $adminsId);
        self::assertContains('/admins', $this->membershipPaths($groups, $janeId));

        Livewire::test(KeycloakUserGroupsTable::class, ['userId' => (string) $janeId->value])
            ->callAction(TestAction::make('removeGroup')->table($this->userGroupRecordKey($groups, $janeId, '/admins')))
            ->assertHasNoFormErrors();

        self::assertNotContains('/admins', $this->membershipPaths($groups, $janeId));
    }

    /**
     * Credentials-table per-row action → `removeCredential`, whitelisted to second factors. Operates on
     * the dedicated `mfa-user`'s seeded OTP so jane's credentials are never touched.
     */
    #[Test]
    public function remove_credential_action_deletes_the_second_factor(): void
    {
        $credentials = app(KeycloakCredentialsApi::class);
        $mfaId = $this->seededUserId('mfa-user');

        [$recordKey, $otpId] = $this->secondFactorRecord($credentials, $mfaId);

        Livewire::test(KeycloakUserCredentialsTable::class, ['userId' => (string) $mfaId->value])
            ->callAction(TestAction::make('removeCredential')->table($recordKey))
            ->assertHasNoFormErrors();

        $remainingIds = [];
        foreach ($credentials->get($mfaId)->all() as $credential) {
            $remainingIds[] = $credential->id;
        }
        self::assertNotContains($otpId, $remainingIds, 'the OTP second factor should be gone after removal');
    }

    /**
     * Identity-tab `editIdentity` action → read-modify-write `update()`. Edits jane's first name through
     * the real Filament action lifecycle, verifies it persisted server-side, then restores the seed.
     */
    #[Test]
    public function edit_identity_action_updates_the_name(): void
    {
        $users = app(KeycloakUsersApi::class);
        $janeId = $this->seededUserId();
        $before = $users->getById($janeId);

        try {
            Livewire::test(KeycloakUserIdentity::class, ['userId' => (string) $janeId->value])
                ->callAction('editIdentity', [
                    'firstName' => 'JaneEdited',
                    'lastName' => $before->lastName,
                    'emailVerified' => $before->emailVerified,
                ])
                ->assertHasNoActionErrors();

            self::assertSame('JaneEdited', $users->getById($janeId)->firstName, 'the edited first name should persist in Keycloak');
        } finally {
            $users->update($users->getById($janeId)->withFirstName($before->firstName));
        }
    }

    /**
     * Identity-tab live enable/disable toggle → `setEnabled()` → `update()`. Deactivates jane, verifies
     * server-side, then reactivates so the read tests keep seeing an enabled user.
     */
    #[Test]
    public function enable_toggle_deactivates_then_reactivates_the_user(): void
    {
        $users = app(KeycloakUsersApi::class);
        $janeId = $this->seededUserId();

        self::assertTrue($users->getById($janeId)->enabled, 'precondition: jane should start enabled');

        try {
            Livewire::test(KeycloakUserIdentity::class, ['userId' => (string) $janeId->value])
                ->call('setEnabled', false)
                ->assertHasNoErrors();

            self::assertFalse($users->getById($janeId)->enabled, 'the user should be disabled after the toggle');
        } finally {
            $users->update($users->getById($janeId)->withEnabled(true));
        }
    }

    private function realmGroupId(string $name): string
    {
        foreach (app(KeycloakGroupsApi::class)->listRealmGroups($name)->all() as $group) {
            if ($group->name === $name) {
                return $group->id;
            }
        }

        self::fail("seed group \"$name\" is missing from the imported realm");
    }

    /**
     * The group paths the user currently belongs to (path preferred, name as fallback) — the same shape
     * the UI renders, so membership assertions read naturally.
     *
     * @return list<string>
     */
    private function membershipPaths(KeycloakGroupsApi $groups, KeycloakUserId $userId): array
    {
        $paths = [];
        foreach ($groups->getUserGroups($userId)->all() as $group) {
            $paths[] = $group->path ?? $group->name;
        }

        return $paths;
    }

    /**
     * The Filament table record key for a membership row. The table keys rows by their position in
     * `getUserGroups()` (see `KeycloakUserGroupsTable::loadGroups()`), so the per-row action must be
     * invoked with that same index.
     */
    private function userGroupRecordKey(KeycloakGroupsApi $groups, KeycloakUserId $userId, string $path): string
    {
        foreach ($groups->getUserGroups($userId)->all() as $index => $group) {
            if (($group->path ?? $group->name) === $path) {
                return (string) $index;
            }
        }

        self::fail("user is not a member of \"$path\"");
    }

    /**
     * Locate the removable second factor: its table record key (position in `get()`, matching
     * `KeycloakUserCredentialsTable::loadCredentials()`) and its credential id for the after-check.
     *
     * @return array{0: string, 1: string}
     */
    private function secondFactorRecord(KeycloakCredentialsApi $credentials, KeycloakUserId $userId): array
    {
        foreach ($credentials->get($userId)->all() as $index => $credential) {
            if ($credential->isSecondFactor() && $credential->id !== null) {
                return [(string) $index, $credential->id];
            }
        }

        self::fail('mfa-user has no removable second-factor credential — check the realm import');
    }
}
