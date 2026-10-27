<?php

declare(strict_types=1);

namespace LastWord\Exchange;

use RuntimeException;

/**
 * The first failure {@see Content::validate()} found: a stable code and a
 * JSON Pointer into the content. Internal control flow only; it never leaves
 * this namespace as an exception.
 *
 * @internal
 */
final class Invalid extends RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly string $pointer)
    {
        parent::__construct($errorCode.' at '.$pointer);
    }
}
