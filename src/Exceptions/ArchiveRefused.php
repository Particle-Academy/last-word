<?php

declare(strict_types=1);

namespace LastWord\Exceptions;

use RuntimeException;

/** Structured refusal without echoing archive names or document content. */
final class ArchiveRefused extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('Source archive refused: '.$reason);
    }
}
