<?php

declare(strict_types=1);

namespace LastWord\Exceptions;

use InvalidArgumentException;

/**
 * The bytes are a document we recognise and cannot read.
 *
 * Deliberately distinct from a generic `InvalidArgumentException`, because the
 * two need different things said to a person. "This file is damaged" and "this
 * is a format we do not read, save it as .docx" lead to different actions, and
 * collapsing them leaves a host guessing which it has.
 *
 * It exists because of a specific failure: a consumer removed `phpoffice/phpword`
 * and their uploads of `.doc` began storing fine and contributing NO TEXT.
 * Nothing raised. An agent then answered questions about a document nobody had
 * read. **A refusal an author can act on is worth more than silence**, and
 * silence is what an unrecognised format used to produce here.
 *
 * `format()` carries the detected format so a host can branch without parsing
 * the message — messages are for people, and a host that greps one is a host
 * that breaks when the wording improves.
 */
final class UnsupportedFormatException extends InvalidArgumentException
{
    public function __construct(
        private readonly string $format,
        string $message,
    ) {
        parent::__construct($message);
    }

    /** The detected format: `doc`, `rtf`, `odt`, … Never a message to grep. */
    public function format(): string
    {
        return $this->format;
    }
}
