<?php

declare(strict_types=1);

namespace LastWord\Reader;

/**
 * What are these bytes?
 *
 * Sniffs CONTENT, never the file extension. An extension is a claim by whoever
 * named the file; the signature is what the bytes actually are, and the two
 * disagree often enough that trusting the name is how a `.docx` that is really
 * a `.doc` gets reported as a corrupt archive.
 */
final class Format
{
    public const DOCX = 'docx';
    public const ODT = 'odt';
    public const DOC = 'doc';
    public const RTF = 'rtf';
    public const UNKNOWN = 'unknown';

    /** OLE2 / Compound File Binary — Word 97-2003 `.doc`, and much else. */
    private const OLE2 = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";

    public static function detect(string $bytes): string
    {
        if (str_starts_with($bytes, self::OLE2)) {
            return self::DOC;
        }

        if (self::looksLikeRtf($bytes)) {
            return self::RTF;
        }

        if (str_starts_with($bytes, "PK\x03\x04")) {
            return self::zipFlavour($bytes);
        }

        return self::UNKNOWN;
    }

    /**
     * An RTF opens `{\rtf`, possibly behind a BOM or stray whitespace.
     *
     * Deliberately not a regex. The first version was
     * `'/^\xEF\xBB\xBF?\s*\{\\rtf/'` and had two faults that cancelled into
     * "matches almost nothing":
     *
     * 1. `\xEF\xBB\xBF?` makes only the LAST byte optional, so the pattern
     *    REQUIRED a BOM's first two bytes — and most RTF has no BOM at all.
     * 2. The backslash count was wrong, so the engine saw `\r` — a carriage
     *    return — and looked for `{`, CR, `tf`.
     *
     * Both read as a working sniffer. String comparison has no escaping layer
     * to get wrong, which for a four-character signature is the better trade.
     */
    private static function looksLikeRtf(string $bytes): bool
    {
        $head = substr($bytes, 0, 16);

        if (str_starts_with($head, "\xEF\xBB\xBF")) {
            $head = substr($head, 3);
        }

        return str_starts_with(ltrim($head), '{'.chr(92).'rtf');
    }

    /**
     * Both docx and odt are zips, so the signature alone is not an answer.
     *
     * Reads the bytes rather than unzipping: no temp file, and this runs on
     * every upload.
     */
    private static function zipFlavour(string $bytes): string
    {
        // ODF stores an uncompressed `mimetype` entry FIRST, so its value sits
        // in plain sight near the head of the file. Checked before the entry
        // names because it is the format's own declaration of what it is.
        if (str_contains(substr($bytes, 0, 256), 'application/vnd.oasis.opendocument.text')) {
            return self::ODT;
        }

        if (str_contains($bytes, 'word/document.xml')) {
            return self::DOCX;
        }

        if (str_contains($bytes, 'content.xml')) {
            return self::ODT;
        }

        // A zip, and not one of ours. The caller still gets a refusal — it just
        // will not be told this is a document we could have read.
        return self::UNKNOWN;
    }
}
