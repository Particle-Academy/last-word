<?php

declare(strict_types=1);

namespace LastWord\Archive;

use DOMDocument;

/**
 * The one way an XML part of an uploaded file gets parsed.
 *
 * A part carrying a DOCTYPE is refused, because no Office or ODF part has one and
 * an internal subset is the entry point for entity expansion. A refusal looks
 * exactly like malformed XML, so each caller keeps its own handling of a part it
 * cannot read.
 *
 * The check has two halves, and the second is the one that decides:
 *
 *  - {@see prologDeclaresDoctype()} looks at the bytes before anything is parsed,
 *    so the ordinary case never reaches the entity machinery. It reads only the
 *    prolog: searching the whole part for the word would refuse legitimate text.
 *  - The parsed document is then asked whether it has a doctype. Nothing external
 *    is loaded in this parse, and the document is dropped before any value is read.
 *
 * Element nesting is limited to {@see MAX_DEPTH}, counted here rather than left to
 * libxml, whose own ceiling without XML_PARSE_HUGE differs between versions (2.11
 * parses 257 levels, newer releases refuse at 257). {@see nestingDepth()} walks the
 * bytes of a part it can read and refuses one past the limit before it is parsed;
 * only such a part, vouched for as free of any declaration, is parsed with
 * LIBXML_PARSEHUGE so libxml's ceiling cannot refuse it first. A part the walk
 * cannot vouch for (UTF-16, an encoding that is not ASCII-compatible, anything
 * odd) gets the strict parse, whose ceiling is never above ours.
 *
 * @internal
 */
final class PartXml
{
    /** The deepest element nesting read, the root counting as 1. Node and Python refuse at the same depth. */
    public const MAX_DEPTH = 257;

    /**
     * @param int $maxDepth for tests only: moves the limit, to show libxml is not what enforces it
     *
     * @return DOMDocument|null null when absent, empty, malformed or refused
     */
    public static function dom(?string $xml, int $maxDepth = self::MAX_DEPTH): ?DOMDocument
    {
        if ($xml === null || $xml === '' || self::prologDeclaresDoctype($xml)) {
            return null;
        }
        $depth = self::nestingDepth($xml);
        if ($depth !== null && $depth > $maxDepth) {
            return null;
        }
        $dom = self::parse($xml, huge: $depth !== null);

        return $dom !== null && $dom->doctype === null ? $dom : null;
    }

    /**
     * The deepest element nesting in a part, or null when a byte walk cannot vouch
     * for it: not UTF-8 or a declared ASCII-compatible encoding, any `<!` other
     * than a comment or CDATA (so no DOCTYPE and no declaration), text before the
     * root, or markup that does not close. Null is never a refusal; it sends the
     * part to the strict parse.
     */
    public static function nestingDepth(string $xml): ?int
    {
        if (str_contains(substr($xml, 0, 4), "\0") || str_starts_with($xml, "\xFF\xFE") || str_starts_with($xml, "\xFE\xFF")) {
            return null;
        }
        $at = str_starts_with($xml, "\xEF\xBB\xBF") ? 3 : 0;
        if (preg_match('/\G<\?xml\s[^?]*?encoding\s*=\s*["\']([^"\']*)["\']/A', $xml, $m, 0, $at) === 1
            && preg_match('/^(utf-?8|us-ascii|ascii|iso-8859-\d{1,2}|windows-125\d)$/i', $m[1]) !== 1) {
            return null;
        }
        $at += strspn($xml, " \t\r\n", $at);
        $len = strlen($xml);
        if ($at >= $len || $xml[$at] !== '<') {
            return null;
        }
        $depth = 0;
        $max = 0;
        $seenRoot = false;
        while ($at < $len) {
            if ($xml[$at] !== '<') {
                if (! $seenRoot && strspn($xml, " \t\r\n", $at) === 0) {
                    return null;
                }
                $next = strpos($xml, '<', $at);
                if ($next === false) {
                    break;
                }
                $at = $next;

                continue;
            }
            $close = match (true) {
                substr_compare($xml, '<?', $at, 2) === 0 => '?>',
                substr_compare($xml, '<!--', $at, 4) === 0 => '-->',
                substr_compare($xml, '<![CDATA[', $at, 9) === 0 => ']]>',
                default => null,
            };
            if ($close !== null) {
                $end = strpos($xml, $close, $at + 2);
                if ($end === false) {
                    return null;
                }
                $at = $end + strlen($close);

                continue;
            }
            $kind = $xml[$at + 1] ?? '';
            if ($kind === '!' || $kind === '') {
                return null;
            }
            if ($kind === '/') {
                $end = strpos($xml, '>', $at);
                if ($end === false || --$depth < 0) {
                    return null;
                }
                $at = $end + 1;

                continue;
            }
            $i = $at + 1;
            while (true) {
                $i += strcspn($xml, '"\'>', $i);
                if ($i >= $len) {
                    return null;
                }
                if ($xml[$i] === '>') {
                    break;
                }
                $quote = strpos($xml, $xml[$i], $i + 1);
                if ($quote === false) {
                    return null;
                }
                $i = $quote + 1;
            }
            $seenRoot = true;
            $max = max($max, ++$depth);
            if ($xml[$i - 1] === '/') {
                $depth--;
            }
            $at = $i + 1;
        }

        return $depth === 0 && $seenRoot ? $max : null;
    }

    /**
     * Whether a part declares a DOCTYPE, for a part that is carried rather than
     * read: malformed XML is not a refusal here, because nothing parses it.
     */
    public static function declaresDoctype(string $xml): bool
    {
        if ($xml === '') {
            return false;
        }

        return self::prologDeclaresDoctype($xml) || self::parse($xml)?->doctype !== null;
    }

    /** Whether a DOCTYPE comes before the root element, for the encodings a byte walk can read. */
    public static function prologDeclaresDoctype(string $xml): bool
    {
        if (str_contains(substr($xml, 0, 4), "\0")) {
            $xml = str_replace("\0", '', $xml);
        }
        $at = 0;
        foreach (["\xEF\xBB\xBF", "\xFF\xFE", "\xFE\xFF"] as $bom) {
            if (str_starts_with($xml, $bom)) {
                $at = strlen($bom);
                break;
            }
        }
        while (true) {
            $at += strspn($xml, " \t\r\n", $at);
            $close = match (true) {
                substr($xml, $at, 2) === '<?' => '?>',
                substr($xml, $at, 4) === '<!--' => '-->',
                default => null,
            };
            if ($close === null) {
                return strcasecmp(substr($xml, $at, 9), '<!DOCTYPE') === 0;
            }
            $end = strpos($xml, $close, $at);
            if ($end === false) {
                return false;
            }
            $at = $end + strlen($close);
        }
    }

    /**
     * Never with LIBXML_NOENT or LIBXML_DTDLOAD: no entity is substituted and
     * nothing external is loaded. `huge` lifts libxml's own depth ceiling and is
     * only passed for a part {@see nestingDepth()} vouched for, which has no
     * DOCTYPE and so no entity to amplify.
     */
    private static function parse(string $xml, bool $huge = false): ?DOMDocument
    {
        $dom = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | ($huge ? LIBXML_PARSEHUGE : 0));
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        return $loaded ? $dom : null;
    }
}
