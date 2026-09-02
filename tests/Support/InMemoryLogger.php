<?php

declare(strict_types=1);

namespace Sandstorm\FilamentKeycloakAdmin\Tests\Support;

use Psr\Log\AbstractLogger;
use Sandstorm\FilamentKeycloakAdmin\Logging\KeycloakAdminLogger;
use Stringable;

/**
 * A PSR-3 logger that records every call instead of writing anywhere — bound as
 * {@see KeycloakAdminLogger} in tests that assert on the audit
 * log's level/message/context, without touching Laravel's real log channels.
 */
final class InMemoryLogger extends AbstractLogger
{
    /**
     * @var list<array{level: mixed, message: string, context: array<string, mixed>}>
     */
    public array $records = [];

    /**
     * @param  array<string, mixed>  $context
     */
    public function log($level, string | Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }
}
