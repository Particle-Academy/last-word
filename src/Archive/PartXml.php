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
 * @internal
 */
final class PartXml
{
    /** @return DOMDocument|null null when absent, empty, malformed or refused */
    public static function dom(?string $xml): ?DOMDocument
    {
        if ($xml === null || $xml === '' || self::prologDeclaresDoctype($xml)) {
            return null;
        }
        $dom = self::parse($xml);

        return $dom !== null && $dom->doctype === null ? $dom : null;
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

    private static function parse(string $xml): ?DOMDocument
    {
        $dom = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        return $loaded ? $dom : null;
    }
}
