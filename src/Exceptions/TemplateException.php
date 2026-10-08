<?php

declare(strict_types=1);

namespace LastWord\Exceptions;

use RuntimeException;

/**
 * Thrown when a `template` passed to Agent::write() / Agent::toBytes() cannot
 * be used — not a zip, no `word/styles.xml`, or an unreadable path.
 *
 * It REFUSES rather than falling back to the built-in look, which is the whole
 * point of last-word#3: a document that silently comes out in the default style
 * is exactly the failure the template option exists to end, and a host that
 * asked for a house template would rather hear why than ship the wrong file.
 * It also lets a host validate a customer-supplied template at upload time by
 * trying to open it.
 */
final class TemplateException extends RuntimeException {}
