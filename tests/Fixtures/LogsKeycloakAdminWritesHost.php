<?php

declare(strict_types=1);

namespace Sandstorm\FilamentKeycloakAdmin\Tests\Fixtures;

use Sandstorm\FilamentKeycloakAdmin\Logging\LogsKeycloakAdminWrites;

/**
 * {@see LogsKeycloakAdminWrites}'s two log methods are protected (call sites are the trait's own users,
 * not the outside world) — this fixture exposes them publicly so tests can exercise the trait in
 * isolation, without a real Filament write action in the way.
 */
final class LogsKeycloakAdminWritesHost
{
    use LogsKeycloakAdminWrites;

    /**
     * @param  array<string, mixed>  $context
     */
    public function write(array $context): void
    {
        $this->logKeycloakWrite($context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function writeDenied(array $context): void
    {
        $this->logKeycloakWriteDenied($context);
    }
}
