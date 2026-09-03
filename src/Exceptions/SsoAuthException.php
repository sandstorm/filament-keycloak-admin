<?php

declare(strict_types=1);

namespace Sandstorm\FilamentKeycloakAdmin\Exceptions;

use RuntimeException;

/**
 * @phpstan-ignore ergebnis.final
 */
class SsoAuthException extends RuntimeException
{
    public function __construct(string $message, int $code, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
