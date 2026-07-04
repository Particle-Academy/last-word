<?php

declare(strict_types=1);

namespace LastWord\Exceptions;

use RuntimeException;

/**
 * Thrown by Agent::write() / Agent::toBytes() when the document is invalid
 * and cannot be written.
 *
 * Carries the structured error list from {@see \LastWord\Schema\Validator}
 * so callers can render per-field feedback without re-running validation.
 */
final class SchemaException extends RuntimeException
{
    /**
     * @param  list<array{path: string, message: string}>  $errors
     */
    public function __construct(
        string $message,
        public readonly array $errors,
    ) {
        parent::__construct($message);
    }
}
