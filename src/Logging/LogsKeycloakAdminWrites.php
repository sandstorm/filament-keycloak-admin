<?php

declare(strict_types=1);

namespace Sandstorm\FilamentKeycloakAdmin\Logging;

use Filament\Facades\Filament;

use function app;

/**
 * Shared audit-log emission for the write actions (identity, groups, credentials, sessions). Every call
 * site supplies a stable `action` name and a context array of non-PII identifiers (ids, flags, counts) —
 * never request bodies, never target-user names/emails/attribute values. The acting admin's id is added
 * here so every call site gets it automatically. The level/message pairing is fixed per outcome (info for
 * a succeeded write, warning for a denied one) so call sites cannot drift onto inconsistent levels.
 */
trait LogsKeycloakAdminWrites
{
    /**
     * @param  array<string, mixed>  $context
     */
    protected function logKeycloakWrite(string $action, array $context = []): void
    {
        $this->emitKeycloakAdminLog('info', 'Keycloak admin write succeeded', $action, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function logKeycloakWriteDenied(string $action, array $context = []): void
    {
        $this->emitKeycloakAdminLog('warning', 'Keycloak admin write denied', $action, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function emitKeycloakAdminLog(string $level, string $message, string $action, array $context): void
    {
        KeycloakAdminLoggerFactory::resolve(app())->log($level, $message, [
            'action' => $action,
            'admin_id' => Filament::auth()->id(),
            ...$context,
        ]);
    }
}
