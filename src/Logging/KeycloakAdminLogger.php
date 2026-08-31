<?php

declare(strict_types=1);

namespace Sandstorm\FilamentKeycloakAdmin\Logging;

use Psr\Log\LoggerInterface;

/**
 * Binding key only — the host app binds any PSR-3 logger instance it already has under this contract
 * (`$app->singleton(KeycloakAdminLogger::class, fn () => $existingLogger)`) to give this package its own
 * logger by instance rather than by config channel name. The bound instance does not need to implement
 * this interface itself; any real {@see LoggerInterface} satisfies it. Absent a binding, the package
 * falls back to `Log::channel(config('filament-keycloak-admin.logging.channel'))` — see
 * {@see KeycloakAdminLoggerFactory}.
 */
interface KeycloakAdminLogger extends LoggerInterface {}
