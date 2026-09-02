<?php

declare(strict_types=1);

namespace Sandstorm\FilamentKeycloakAdmin\Logging;

use Filament\Facades\Filament;

use function app;

/**
 * Shared audit-log emission for the write actions (identity, groups, credentials, sessions). The acting
 * admin's id (`admin_id`) is added automatically; the caller supplies the rest of the context — the
 * action name ({@see self::LOG_CONTEXT_ACTION}), target id, and any other non-PII identifiers (ids,
 * flags, counts) — never request bodies, never target-user names/emails/attribute values. The
 * level/message pairing is fixed per outcome (info for a succeeded write, warning for a denied one) so
 * call sites cannot drift onto inconsistent levels.
 */
trait LogsKeycloakAdminWrites
{
    /**
     * Context key for the write's action name, so call sites don't repeat the string literal.
     */
    public const string LOG_CONTEXT_ACTION = 'action';

    /**
     * @param  array<string, mixed>  $context
     */
    protected function logKeycloakWrite(array $context): void
    {
        $this->emitKeycloakAdminLog('info', 'Keycloak admin write succeeded', $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function logKeycloakWriteDenied(array $context): void
    {
        $this->emitKeycloakAdminLog('warning', 'Keycloak admin write denied', $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function emitKeycloakAdminLog(string $level, string $message, array $context): void
    {
        KeycloakAdminLoggerFactory::resolve(app())->log($level, $message, [
            'admin_id' => Filament::auth()->id(),
            ...$context,
        ]);
    }
}
