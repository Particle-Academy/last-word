<?php

declare(strict_types=1);

namespace LastWord\Exchange;

/**
 * The input to catalog / extract / export could not be read. `reason` is a
 * stable code: an archive refusal (`archive_limit`, `archive_path`, ...), a
 * template refusal (`macro_enabled`, ...), `not_a_document`, or `corrupt`.
 * extract returns it as `refused.reason`; an export reports it as
 * `export_refused` with that `cause`.
 *
 * @internal
 */
final class InputRefused extends \RuntimeException
{
    public function __construct(public readonly string $reason, ?\Throwable $previous = null)
    {
        parent::__construct('[last-word] input refused: '.$reason, 0, $previous);
    }
}
