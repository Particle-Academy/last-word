<?php

declare(strict_types=1);

namespace LastWord\Exchange;

use InvalidArgumentException;

/**
 * Bad options or a malformed export policy: the CALLER's bug, so it throws
 * rather than returning a refusal (exchange SPEC sections 7 and 13). The
 * stable code is `options_invalid`.
 */
final class OptionsInvalid extends InvalidArgumentException
{
    public const CODE = 'options_invalid';

    public function __construct(string $message)
    {
        parent::__construct('[last-word] options_invalid: '.$message);
    }
}
