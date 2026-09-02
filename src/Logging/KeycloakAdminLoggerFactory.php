<?php

declare(strict_types=1);

namespace Sandstorm\FilamentKeycloakAdmin\Logging;

use Illuminate\Contracts\Foundation\Application;
use Psr\Log\LoggerInterface;
use Sandstorm\FilamentKeycloakAdmin\Auth\AdminKeycloakSession;

/**
 * Resolves the logger this package writes audit lines to. Mirrors the
 * {@see AdminKeycloakSession} pattern: the package never registers
 * a binding for {@see KeycloakAdminLogger} itself, it only checks — at the point of use — whether the
 * host app has bound one, so there is no registration-order race between this package's provider and the
 * host's. Absent a binding, falls back to null, meaning: no audit logging at all.
 */
final class KeycloakAdminLoggerFactory
{
    public static function resolve(Application $app): ?LoggerInterface
    {
        if ($app->bound(KeycloakAdminLogger::class)) {
            return $app->make(KeycloakAdminLogger::class);
        }

        return null;
    }
}
