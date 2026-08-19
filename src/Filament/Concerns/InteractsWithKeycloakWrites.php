<?php

declare(strict_types=1);

namespace Sandstorm\FilamentKeycloakAdmin\Filament\Concerns;

use Filament\Notifications\Notification;
use Sandstorm\KeycloakAdminApi\Connection\UnexpectedKeycloakResponseException;

/**
 * Shared write-guard for the detail-page components. Keycloak stays the authority on every write: even
 * though the UI gates edit controls off the caller-relative `access` map (plan §2.1), grants can change
 * between the read that drew the control and the write that follows, so a write can still be denied.
 *
 * This turns that denial (HTTP 401/403) into a friendly notice instead of the framework error page — the
 * one scoped exception to the plugin's "every failure propagates" rule (plan §8/§7.2). Any *other*
 * failure (network, 5xx, an unexpected 4xx) still propagates unchanged: those are not per-caller
 * authorization outcomes and must not be swallowed.
 */
trait InteractsWithKeycloakWrites
{
    /**
     * Run a Keycloak write. Returns true when it succeeded, false when Keycloak denied it (401/403) — in
     * which case a "not permitted" notice has been shown. Callers use the boolean to decide whether to
     * emit their success notice / cross-tab signal.
     *
     * @param  callable(): void  $write
     */
    protected function runKeycloakWrite(callable $write): bool
    {
        try {
            $write();

            return true;
        } catch (UnexpectedKeycloakResponseException $exception) {
            if ($exception->statusCode === 401 || $exception->statusCode === 403) {
                Notification::make()
                    ->title('You do not have permission to make this change.')
                    ->danger()
                    ->send();

                return false;
            }

            throw $exception;
        }
    }
}
