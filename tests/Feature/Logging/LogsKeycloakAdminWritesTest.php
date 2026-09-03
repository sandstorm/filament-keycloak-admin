<?php

declare(strict_types=1);

namespace Sandstorm\FilamentKeycloakAdmin\Tests\Feature\Logging;

use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Auth\GenericUser;
use PHPUnit\Framework\Attributes\Test;
use Sandstorm\FilamentKeycloakAdmin\Logging\KeycloakAdminLogger;
use Sandstorm\FilamentKeycloakAdmin\Logging\LogsKeycloakAdminWrites;
use Sandstorm\FilamentKeycloakAdmin\Tests\Fixtures\LogsKeycloakAdminWritesHost;
use Sandstorm\FilamentKeycloakAdmin\Tests\Support\InMemoryLogger;
use Sandstorm\FilamentKeycloakAdmin\Tests\TestCase;

/**
 * The write-actions audit log ({@see LogsKeycloakAdminWrites}) is an optional extension point: a host app
 * opts in by binding a PSR-3 instance under {@see KeycloakAdminLogger}. These tests exercise the trait
 * directly through {@see LogsKeycloakAdminWritesHost}, a bare host, so no real Keycloak write is needed.
 */
final class LogsKeycloakAdminWritesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Filament::auth() resolves the *current* panel's guard, so one must exist even for this
        // component-free test.
        $panel = Panel::make()->id('logging-test')->path('logging-test');
        Filament::registerPanel($panel);
        Filament::setCurrentPanel($panel);
    }

    #[Test]
    public function it_does_not_log_or_throw_when_no_logger_is_bound(): void
    {
        (new LogsKeycloakAdminWritesHost)->write(['target_user_id' => 'user-1']);

        $this->addToAssertionCount(1); // reaching this line without an exception is the assertion
    }

    #[Test]
    public function it_logs_a_succeeded_write_at_info_with_the_admin_id_added(): void
    {
        $logger = $this->bindLogger();
        $this->loginAsAdmin(42);

        (new LogsKeycloakAdminWritesHost)->write([
            LogsKeycloakAdminWritesHost::LOG_CONTEXT_ACTION => 'group.add',
            'target_user_id' => 'user-1',
        ]);

        self::assertCount(1, $logger->records);
        self::assertSame('info', $logger->records[0]['level']);
        self::assertSame('Keycloak admin write succeeded', $logger->records[0]['message']);
        self::assertSame([
            'admin_id' => 42,
            LogsKeycloakAdminWritesHost::LOG_CONTEXT_ACTION => 'group.add',
            'target_user_id' => 'user-1',
        ], $logger->records[0]['context']);
    }

    #[Test]
    public function it_logs_a_denied_write_at_warning_with_the_admin_id_added(): void
    {
        $logger = $this->bindLogger();
        $this->loginAsAdmin(7);

        (new LogsKeycloakAdminWritesHost)->writeDenied([
            LogsKeycloakAdminWritesHost::LOG_CONTEXT_ACTION => 'user.set_enabled',
        ]);

        self::assertCount(1, $logger->records);
        self::assertSame('warning', $logger->records[0]['level']);
        self::assertSame('Keycloak admin write denied', $logger->records[0]['message']);
        self::assertSame(7, $logger->records[0]['context']['admin_id']);
    }

    private function bindLogger(): InMemoryLogger
    {
        $logger = new InMemoryLogger;
        $this->app->instance(KeycloakAdminLogger::class, $logger);

        return $logger;
    }

    private function loginAsAdmin(int $id): void
    {
        Filament::auth()->setUser(new GenericUser(['id' => $id]));
    }
}
