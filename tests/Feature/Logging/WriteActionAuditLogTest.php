<?php

declare(strict_types=1);

namespace Sandstorm\FilamentKeycloakAdmin\Tests\Feature\Logging;

use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\GenericUser;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Sandstorm\FilamentKeycloakAdmin\Filament\Pages\InspectKeycloakUser\KeycloakUserCredentialsTable;
use Sandstorm\FilamentKeycloakAdmin\Filament\Pages\InspectKeycloakUser\KeycloakUserGroupsTable;
use Sandstorm\FilamentKeycloakAdmin\Filament\Pages\InspectKeycloakUser\KeycloakUserIdentity;
use Sandstorm\FilamentKeycloakAdmin\Filament\Pages\InspectKeycloakUser\KeycloakUserSessionsTable;
use Sandstorm\FilamentKeycloakAdmin\Logging\KeycloakAdminLogger;
use Sandstorm\FilamentKeycloakAdmin\Tests\Fixtures\FakeKeycloakCredentialsApi;
use Sandstorm\FilamentKeycloakAdmin\Tests\Fixtures\FakeKeycloakGroupsApi;
use Sandstorm\FilamentKeycloakAdmin\Tests\Fixtures\FakeKeycloakRealmApi;
use Sandstorm\FilamentKeycloakAdmin\Tests\Fixtures\FakeKeycloakSessionsApi;
use Sandstorm\FilamentKeycloakAdmin\Tests\Fixtures\FakeKeycloakUsersApi;
use Sandstorm\FilamentKeycloakAdmin\Tests\Fixtures\TestPanelProvider;
use Sandstorm\FilamentKeycloakAdmin\Tests\Integration\KeycloakUserWriteActionsE2ETest;
use Sandstorm\FilamentKeycloakAdmin\Tests\Support\InMemoryLogger;
use Sandstorm\FilamentKeycloakAdmin\Tests\TestCase;
use Sandstorm\KeycloakAdminApi\Connection\UnexpectedKeycloakResponseException;
use Sandstorm\KeycloakAdminApi\Features\KeycloakCredentialsApi;
use Sandstorm\KeycloakAdminApi\Features\KeycloakGroupsApi;
use Sandstorm\KeycloakAdminApi\Features\KeycloakRealmApi;
use Sandstorm\KeycloakAdminApi\Features\KeycloakSessionsApi;
use Sandstorm\KeycloakAdminApi\Features\KeycloakUsersApi;
use Sandstorm\KeycloakAdminApi\Features\KeycloakUsersApi\Dto\KeycloakUser;

/**
 * One audit-log assertion per write action the plugin surfaces in the UI, driven through the real
 * Filament/Livewire action lifecycle like {@see KeycloakUserWriteActionsE2ETest}
 * but against in-memory fakes (`tests/Fixtures/FakeKeycloak*Api.php`) instead of a live Keycloak, so this
 * suite stays hermetic. Only the identity actions go through {@see
 * \Sandstorm\FilamentKeycloakAdmin\Filament\Concerns\InteractsWithKeycloakWrites::runKeycloakWrite()} and
 * therefore have a denial (warning-level) path; the other five log success only (plan §8: an unexpected
 * failure there still propagates unlogged, same as before audit logging existed).
 */
final class WriteActionAuditLogTest extends TestCase
{
    private const USER_ID = 'user-1';

    private InMemoryLogger $logger;

    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), TestPanelProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        Filament::bootCurrentPanel();
        Filament::auth()->setUser(new GenericUser(['id' => 99]));

        $this->logger = new InMemoryLogger;
        $this->app->instance(KeycloakAdminLogger::class, $this->logger);

        // Any KeycloakUserIdentity render (even just after the enable toggle) reads the realm's
        // User-Profile schema to build the attribute list — bind an empty one so every identity test
        // works without needing to know about it individually.
        $this->app->instance(KeycloakRealmApi::class, new FakeKeycloakRealmApi);
    }

    #[Test]
    public function enabling_or_disabling_a_user_logs_the_outcome(): void
    {
        $this->app->instance(KeycloakUsersApi::class, new FakeKeycloakUsersApi($this->fakeUser()));

        Livewire::test(KeycloakUserIdentity::class, ['userId' => self::USER_ID])
            ->call('setEnabled', false);

        $this->assertLoggedOnce('info', 'Keycloak admin write succeeded', [
            'action' => 'user.set_enabled',
            'admin_id' => 99,
            'target_user_id' => self::USER_ID,
            'enabled' => false,
        ]);
    }

    #[Test]
    public function a_denied_enable_toggle_logs_a_warning_instead_of_throwing(): void
    {
        $users = new FakeKeycloakUsersApi($this->fakeUser());
        $users->throwOnUpdate(new UnexpectedKeycloakResponseException('denied', 1, 403));
        $this->app->instance(KeycloakUsersApi::class, $users);

        Livewire::test(KeycloakUserIdentity::class, ['userId' => self::USER_ID])
            ->call('setEnabled', false)
            ->assertHasNoErrors();

        $this->assertLoggedOnce('warning', 'Keycloak admin write denied', [
            'action' => 'user.set_enabled',
            'admin_id' => 99,
            'target_user_id' => self::USER_ID,
            'enabled' => false,
        ]);
    }

    #[Test]
    public function editing_identity_fields_logs_the_updated_field_names_only(): void
    {
        $this->app->instance(KeycloakUsersApi::class, new FakeKeycloakUsersApi($this->fakeUser()));
        Livewire::test(KeycloakUserIdentity::class, ['userId' => self::USER_ID])
            ->callAction('editIdentity', [
                'firstName' => 'Jane',
                'lastName' => 'Doe',
                'emailVerified' => true,
            ])
            ->assertHasNoActionErrors();

        $this->assertLoggedOnce('info', 'Keycloak admin write succeeded', [
            'action' => 'user.update_identity',
            'admin_id' => 99,
            'target_user_id' => self::USER_ID,
            'updated_fields' => ['firstName', 'lastName', 'emailVerified'],
        ]);
    }

    #[Test]
    public function a_denied_identity_edit_logs_a_warning_instead_of_throwing(): void
    {
        $users = new FakeKeycloakUsersApi($this->fakeUser());
        $users->throwOnUpdate(new UnexpectedKeycloakResponseException('denied', 1, 401));
        $this->app->instance(KeycloakUsersApi::class, $users);
        Livewire::test(KeycloakUserIdentity::class, ['userId' => self::USER_ID])
            ->callAction('editIdentity', [
                'firstName' => 'Jane',
                'lastName' => 'Doe',
                'emailVerified' => true,
            ])
            ->assertHasNoActionErrors();

        self::assertCount(1, $this->logger->records);
        self::assertSame('warning', $this->logger->records[0]['level']);
        self::assertSame('user.update_identity', $this->logger->records[0]['context']['action']);
    }

    #[Test]
    public function adding_a_user_to_groups_logs_the_group_ids(): void
    {
        $this->app->instance(KeycloakGroupsApi::class, new FakeKeycloakGroupsApi);

        Livewire::test(KeycloakUserGroupsTable::class, ['userId' => self::USER_ID])
            ->callAction(TestAction::make('addGroups')->table(), ['groupIds' => ['group-admins']])
            ->assertHasNoFormErrors();

        $this->assertLoggedOnce('info', 'Keycloak admin write succeeded', [
            'action' => 'group.add',
            'admin_id' => 99,
            'target_user_id' => self::USER_ID,
            'group_ids' => ['group-admins'],
        ]);
    }

    #[Test]
    public function removing_a_user_from_a_group_logs_the_group_id(): void
    {
        $this->app->instance(KeycloakGroupsApi::class, new FakeKeycloakGroupsApi);

        Livewire::test(KeycloakUserGroupsTable::class, ['userId' => self::USER_ID])
            ->callAction(TestAction::make('removeGroup')->table('0'))
            ->assertHasNoFormErrors();

        $this->assertLoggedOnce('info', 'Keycloak admin write succeeded', [
            'action' => 'group.remove',
            'admin_id' => 99,
            'target_user_id' => self::USER_ID,
            'group_id' => 'group-staff',
        ]);
    }

    #[Test]
    public function sending_a_password_reset_email_logs_the_action(): void
    {
        $this->app->instance(KeycloakCredentialsApi::class, new FakeKeycloakCredentialsApi);

        Livewire::test(KeycloakUserCredentialsTable::class, ['userId' => self::USER_ID])
            ->callAction(TestAction::make('triggerPasswordReset')->table());

        $this->assertLoggedOnce('info', 'Keycloak admin write succeeded', [
            'action' => 'credential.send_password_reset_email',
            'admin_id' => 99,
            'target_user_id' => self::USER_ID,
        ]);
    }

    #[Test]
    public function removing_a_credential_logs_its_type(): void
    {
        $this->app->instance(KeycloakCredentialsApi::class, new FakeKeycloakCredentialsApi);

        Livewire::test(KeycloakUserCredentialsTable::class, ['userId' => self::USER_ID])
            ->callAction(TestAction::make('removeCredential')->table('0'))
            ->assertHasNoFormErrors();

        $this->assertLoggedOnce('info', 'Keycloak admin write succeeded', [
            'action' => 'credential.remove',
            'admin_id' => 99,
            'target_user_id' => self::USER_ID,
            'credential_type' => 'otp',
        ]);
    }

    #[Test]
    public function logging_out_all_sessions_logs_the_action(): void
    {
        $this->app->instance(KeycloakSessionsApi::class, new FakeKeycloakSessionsApi);

        Livewire::test(KeycloakUserSessionsTable::class, ['userId' => self::USER_ID])
            ->callAction(TestAction::make('logoutAll')->table());

        $this->assertLoggedOnce('info', 'Keycloak admin write succeeded', [
            'action' => 'session.logout_all',
            'admin_id' => 99,
            'target_user_id' => self::USER_ID,
        ]);
    }

    private function fakeUser(): KeycloakUser
    {
        return KeycloakUser::fromRawResponse([
            'id' => self::USER_ID,
            'username' => 'jane',
            'enabled' => true,
            'access' => ['manage' => true],
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function assertLoggedOnce(string $level, string $message, array $context): void
    {
        self::assertCount(1, $this->logger->records);
        self::assertSame($level, $this->logger->records[0]['level']);
        self::assertSame($message, $this->logger->records[0]['message']);
        // assertEquals, not assertSame: key order is an emission detail, not part of the contract.
        self::assertEquals($context, $this->logger->records[0]['context']);
    }
}
