<?php

declare(strict_types=1);

namespace LastWord\Helpers;

/**
 * Tiny XML-escaping helpers. The DOCX writer builds XML by string
 * concatenation rather than DOMDocument — WordprocessingML output is verbose
 * and mostly literal, so string templates are much clearer than a tree of
 * `createElementNS` calls. These helpers cover the few places we
 * interpolate user-supplied content.
 */
final class Xml
{
    /** Escape for XML text content. */
    public static function text(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }

    /** Escape for XML attribute values (also escapes quotes). */
    public static function attr(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** Produce the standard XML declaration used at the top of every DOCX part. */
    public static function declaration(bool $standalone = true): string
    {
        $sa = $standalone ? ' standalone="yes"' : '';

        return "<?xml version=\"1.0\" encoding=\"UTF-8\"{$sa}?>" . "\n";
    }
}
