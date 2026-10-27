<?php

declare(strict_types=1);

namespace LastWord\Archive;

use DOMDocument;
use DOMElement;
use LastWord\Exceptions\OfficeTemplateRefused;
use LogicException;

/**
 * Office and LibreOffice TEMPLATE packages: `.dotx` / `.ott` for this package.
 *
 * A template is its document twin with a different declared type, nothing more:
 *
 * - OOXML (ECMA-376 Part 2 content types): the main part's Override in
 *   `[Content_Types].xml` reads `...wordprocessingml.template.main+xml` instead
 *   of `...document.main+xml`.
 * - ODF (1.3 Part 2 section 3.3): the first, STORED `mimetype` entry reads
 *   `...opendocument.text-template`, and that value SHALL equal the
 *   `manifest:media-type` of the manifest's root (`/`) entry. Rewriting the
 *   `mimetype` alone would produce a package that contradicts itself.
 *
 * So reading one is classifying it, and converting one is rewriting those
 * declarations while every other member is copied as its original raw zip
 * record: same compressed bytes, same CRC, same timestamps.
 *
 * Macro-enabled templates (`.dotm`, `.potm`, `.xltm`) are refused, never read
 * around: the VBA is not ours to carry and not something to silently drop.
 *
 * The family table is shared verbatim by last-word, dark-slide and holy-sheet
 * (PHP, Node and Python). Every family is listed in each so that a template
 * declaring ANOTHER family is recognised as such and refused, rather than read
 * as whatever this package's sniffer guessed.
 *
 * @internal
 */
final class OfficeTemplate
{
    public const DOCUMENT = 'document';

    public const TEMPLATE = 'template';

    /** The family this package reads and converts. */
    public const FAMILY = 'word';

    /** Whether this package reads (and so converts) the family's ODF form. */
    private const ODF = true;

    private const VBA = 'application/vnd.ms-office.vbaProject';

    private const FAMILIES = [
        'word' => [
            'part' => '/word/document.xml',
            'document' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml',
            'template' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.template.main+xml',
            'macroDocument' => 'application/vnd.ms-word.document.macroEnabled.main+xml',
            'macroTemplate' => 'application/vnd.ms-word.template.macroEnabledTemplate.main+xml',
            'odfDocument' => 'application/vnd.oasis.opendocument.text',
            'odfTemplate' => 'application/vnd.oasis.opendocument.text-template',
            'odfBody' => 'text',
        ],
        'presentation' => [
            'part' => '/ppt/presentation.xml',
            'document' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml',
            'template' => 'application/vnd.openxmlformats-officedocument.presentationml.template.main+xml',
            'macroDocument' => 'application/vnd.ms-powerpoint.presentation.macroEnabled.main+xml',
            'macroTemplate' => 'application/vnd.ms-powerpoint.template.macroEnabled.main+xml',
            'odfDocument' => 'application/vnd.oasis.opendocument.presentation',
            'odfTemplate' => 'application/vnd.oasis.opendocument.presentation-template',
            'odfBody' => 'presentation',
        ],
        'spreadsheet' => [
            'part' => '/xl/workbook.xml',
            'document' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml',
            'template' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.template.main+xml',
            'macroDocument' => 'application/vnd.ms-excel.sheet.macroEnabled.main+xml',
            'macroTemplate' => 'application/vnd.ms-excel.template.macroEnabled.main+xml',
            'odfDocument' => 'application/vnd.oasis.opendocument.spreadsheet',
            'odfTemplate' => 'application/vnd.oasis.opendocument.spreadsheet-template',
            'odfBody' => 'spreadsheet',
        ],
    ];

    /**
     * `document` or `template`, for a package this package's reader is about to
     * read. A package that declares nothing a template would is a `document`,
     * exactly as before this class existed: only templates are new.
     *
     * @throws OfficeTemplateRefused `macro_enabled` or `family_mismatch`
     */
    public static function kind(SourceArchive $zip): string
    {
        $types = $zip->read('[Content_Types].xml');
        if ($types !== null) {
            return self::ooxmlKind($zip, $types);
        }
        $mimetype = $zip->read('mimetype');
        if ($mimetype !== null) {
            return self::odfKind($zip, $mimetype);
        }

        return self::DOCUMENT;
    }

    /**
     * Turn a template into its document twin, or a document into its template.
     * `$to` null flips whichever it is.
     *
     * @throws OfficeTemplateRefused
     * @throws \LastWord\Exceptions\ArchiveRefused through the archive guard
     */
    public static function convert(string $bytes, ?string $to = null): string
    {
        if ($to !== null && $to !== self::DOCUMENT && $to !== self::TEMPLATE) {
            throw new OfficeTemplateRefused('bad_target');
        }
        $zip = SourceArchive::open($bytes);
        $kind = self::kind($zip);
        $to ??= $kind === self::TEMPLATE ? self::DOCUMENT : self::TEMPLATE;
        if ($to === $kind) {
            throw new OfficeTemplateRefused('already_'.$kind);
        }
        $own = self::FAMILIES[self::FAMILY];

        $types = $zip->read('[Content_Types].xml');
        if ($types !== null) {
            $declared = self::declarations($types);
            if ($declared === null) {
                throw new OfficeTemplateRefused('not_convertible');
            }
            if (self::hasVba($zip, $declared) || in_array($own['macroDocument'], self::typesOf($declared, $own['part']), true)) {
                throw new OfficeTemplateRefused('macro_enabled');
            }
            [$from, $target] = $to === self::TEMPLATE ? [$own['document'], $own['template']] : [$own['template'], $own['document']];
            $replacements = ['[Content_Types].xml' => self::rewriteOverride($types, $own['part'], $from, $target)];
        } elseif (self::ODF && ($mimetype = $zip->read('mimetype')) !== null) {
            // First in the central directory AND first in the file, stored, no
            // extra field: ODF 1.3 Part 2 section 3.3.
            $entries = $zip->entries();
            $first = $entries[0] ?? null;
            usort($entries, static fn (array $a, array $b): int => $a['local'] <=> $b['local']);
            if ($first === null || $first['name'] !== 'mimetype' || $entries[0]['name'] !== 'mimetype'
                || $first['method'] !== 0 || $first['localExtra'] !== 0) {
                throw new OfficeTemplateRefused('odf_mimetype');
            }
            [$from, $target] = $to === self::TEMPLATE ? [$own['odfDocument'], $own['odfTemplate']] : [$own['odfTemplate'], $own['odfDocument']];
            if ($mimetype !== $from) {
                throw new OfficeTemplateRefused('not_convertible');
            }
            $replacements = ['mimetype' => $target];
            $manifest = $zip->read('META-INF/manifest.xml');
            if ($manifest !== null) {
                $rewritten = self::rewriteManifestRoot($manifest, $from, $target);
                if ($rewritten !== null) {
                    $replacements['META-INF/manifest.xml'] = $rewritten;
                }
            }
        } else {
            throw new OfficeTemplateRefused('not_convertible');
        }

        $out = self::rebuild($zip, $replacements);
        if (self::kind(SourceArchive::open($out)) !== $to) {
            throw new LogicException('Template conversion did not produce the requested kind.');
        }

        return $out;
    }

    // ─── Classification ──────────────────────────────────────────────────

    private static function ooxmlKind(SourceArchive $zip, string $types): string
    {
        $declared = self::declarations($types);
        if ($declared === null) {
            // Unparsable content types were never looked at before; the
            // reader's own handling of the package is unchanged.
            return self::DOCUMENT;
        }
        $own = self::FAMILIES[self::FAMILY];
        $kind = self::DOCUMENT;
        foreach ($declared as [$part, $type]) {
            foreach (self::FAMILIES as $name => $family) {
                if ($type !== $family['template'] && $type !== $family['macroTemplate']) {
                    continue;
                }
                // A template type on a Default, on another part, or of another
                // family: whatever this is, it is not the template it claims.
                if ($name !== self::FAMILY || $part !== strtolower($own['part'])) {
                    throw new OfficeTemplateRefused('family_mismatch');
                }
                if ($type === $family['macroTemplate']) {
                    throw new OfficeTemplateRefused('macro_enabled');
                }
                $kind = self::TEMPLATE;
            }
        }
        if ($kind === self::TEMPLATE && self::hasVba($zip, $declared)) {
            throw new OfficeTemplateRefused('macro_enabled');
        }

        return $kind;
    }

    private static function odfKind(SourceArchive $zip, string $mimetype): string
    {
        $own = self::FAMILIES[self::FAMILY];
        if ($mimetype !== $own['odfTemplate']) {
            if (str_starts_with($mimetype, 'application/vnd.oasis.opendocument.') && str_ends_with($mimetype, '-template')) {
                throw new OfficeTemplateRefused('family_mismatch');
            }

            return self::DOCUMENT;
        }
        $content = self::dom($zip->read('content.xml'));
        $body = $content !== null ? self::firstByLocalName($content, 'body') : null;
        $first = null;
        foreach ($body?->childNodes ?? [] as $child) {
            if ($child instanceof DOMElement) {
                $first = $child;
                break;
            }
        }
        if ($first === null || $first->localName !== $own['odfBody']) {
            throw new OfficeTemplateRefused('family_mismatch');
        }
        $root = self::manifestRootType($zip->read('META-INF/manifest.xml'));
        if ($root !== null && $root !== $own['odfTemplate'] && $root !== $own['odfDocument']) {
            throw new OfficeTemplateRefused('family_mismatch');
        }

        return self::TEMPLATE;
    }

    /**
     * Every content-type declaration as [lower-cased part name or `*.ext`, type].
     * Matched by local name, so a namespace trick cannot hide one.
     *
     * @return list<array{0: string, 1: string}>|null
     */
    private static function declarations(string $types): ?array
    {
        $dom = self::dom($types);
        if ($dom === null) {
            return null;
        }
        $out = [];
        foreach ($dom->getElementsByTagName('*') as $el) {
            if ($el->localName === 'Override') {
                $out[] = [strtolower($el->getAttribute('PartName')), $el->getAttribute('ContentType')];
            } elseif ($el->localName === 'Default') {
                $out[] = ['*.'.strtolower($el->getAttribute('Extension')), $el->getAttribute('ContentType')];
            }
        }

        return $out;
    }

    /** @return list<string> */
    private static function typesOf(array $declared, string $part): array
    {
        $types = [];
        foreach ($declared as [$name, $type]) {
            if ($name === strtolower($part)) {
                $types[] = $type;
            }
        }

        return $types;
    }

    private static function hasVba(SourceArchive $zip, array $declared): bool
    {
        foreach ($declared as [, $type]) {
            if (strcasecmp($type, self::VBA) === 0) {
                return true;
            }
        }
        foreach ($zip->names() as $name) {
            if (preg_match('#(^|/)vbaProject\.bin$#i', $name) === 1) {
                return true;
            }
        }

        return false;
    }

    private static function manifestRootType(?string $manifest): ?string
    {
        $dom = self::dom($manifest);
        if ($dom === null) {
            return null;
        }
        foreach ($dom->getElementsByTagName('*') as $el) {
            if ($el->localName === 'file-entry' && self::attr($el, 'full-path') === '/') {
                return self::attr($el, 'media-type');
            }
        }

        return null;
    }

    // ─── Rewriting ───────────────────────────────────────────────────────

    /**
     * Replace one attribute VALUE in place, so every other byte of the part
     * survives. A text scan finds the candidate; the DOM then has to agree on
     * the result, which is what stops a decoy in a comment or an entity-encoded
     * name from steering the edit.
     */
    private static function rewriteOverride(string $xml, string $part, string $from, string $to): string
    {
        $hits = [];
        preg_match_all('/<(?:[A-Za-z_][\w.\-]*:)?Override\b[^>]*>/', $xml, $tags, PREG_OFFSET_CAPTURE);
        foreach ($tags[0] as [$tag, $offset]) {
            if (preg_match('/\sPartName\s*=\s*(["\'])(.*?)\1/s', $tag, $name) !== 1 || strtolower($name[2]) !== strtolower($part)) {
                continue;
            }
            if (preg_match('/\sContentType\s*=\s*(["\'])(.*?)\1/s', $tag, $type, PREG_OFFSET_CAPTURE) === 1 && $type[2][0] === $from) {
                $hits[] = $offset + $type[2][1];
            }
        }
        if (count($hits) !== 1) {
            throw new OfficeTemplateRefused('not_convertible');
        }
        $out = substr_replace($xml, $to, $hits[0], strlen($from));
        $declared = self::declarations($out);
        if ($declared === null || self::typesOf($declared, $part) !== [$to]) {
            throw new OfficeTemplateRefused('not_convertible');
        }

        return $out;
    }

    /** The manifest with its root entry's media-type moved; null when it has no root entry. */
    private static function rewriteManifestRoot(string $xml, string $from, string $to): ?string
    {
        $current = self::manifestRootType($xml);
        if ($current === null) {
            return null;
        }
        if ($current !== $from) {
            throw new OfficeTemplateRefused('not_convertible');
        }
        $hits = [];
        preg_match_all('/<(?:[A-Za-z_][\w.\-]*:)?file-entry\b[^>]*>/', $xml, $tags, PREG_OFFSET_CAPTURE);
        foreach ($tags[0] as [$tag, $offset]) {
            if (preg_match('/\s(?:[A-Za-z_][\w.\-]*:)?full-path\s*=\s*(["\'])\/\1/', $tag) !== 1) {
                continue;
            }
            if (preg_match('/\s(?:[A-Za-z_][\w.\-]*:)?media-type\s*=\s*(["\'])(.*?)\1/s', $tag, $type, PREG_OFFSET_CAPTURE) === 1 && $type[2][0] === $from) {
                $hits[] = $offset + $type[2][1];
            }
        }
        if (count($hits) !== 1) {
            throw new OfficeTemplateRefused('not_convertible');
        }
        $out = substr_replace($xml, $to, $hits[0], strlen($from));
        if (self::manifestRootType($out) !== $to) {
            throw new OfficeTemplateRefused('not_convertible');
        }

        return $out;
    }

    /**
     * Re-assemble the archive. Untouched members are their original local
     * records, verbatim; a replaced member is written STORED with its original
     * timestamp and no extra field (which is what ODF requires of `mimetype`).
     * Local order and central order are both kept.
     *
     * @param  array<string, string>  $replacements
     */
    private static function rebuild(SourceArchive $zip, array $replacements): string
    {
        $original = $zip->toBytes();
        $entries = $zip->entries();
        $byLocal = $entries;
        usort($byLocal, static fn (array $a, array $b): int => $a['local'] <=> $b['local']);

        $u16 = static fn (int $at): int => unpack('v', substr($original, $at, 2))[1];
        $u32 = static fn (int $at): int => unpack('V', substr($original, $at, 4))[1];

        $locals = '';
        $offsets = [];
        foreach ($byLocal as $entry) {
            $name = $entry['name'];
            $offsets[$name] = strlen($locals);
            if (! array_key_exists($name, $replacements)) {
                $locals .= substr($original, $entry['local'], $entry['finish'] - $entry['local']);

                continue;
            }
            $data = $replacements[$name];
            $locals .= pack('VvvvvvVVVvv', 0x04034B50, 10, $entry['flags'] & 0x0800, 0,
                $u16($entry['local'] + 10), $u16($entry['local'] + 12),
                crc32($data), strlen($data), strlen($data), strlen($name), 0).$name.$data;
        }

        $central = '';
        foreach ($entries as $entry) {
            $name = $entry['name'];
            if (! array_key_exists($name, $replacements)) {
                $record = substr($original, $entry['central'], $entry['centralLength']);
                $central .= substr($record, 0, 42).pack('V', $offsets[$name]).substr($record, 46);

                continue;
            }
            $data = $replacements[$name];
            $at = $entry['central'];
            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014B50, $u16($at + 4), 10, $entry['flags'] & 0x0800, 0,
                $u16($at + 12), $u16($at + 14), crc32($data), strlen($data), strlen($data),
                strlen($name), 0, 0, 0, $u16($at + 36), $u32($at + 38), $offsets[$name]).$name;
        }

        $comment = $zip->comment();

        return $locals.$central.pack('VvvvvVVv', 0x06054B50, 0, 0, count($entries), count($entries),
            strlen($central), strlen($locals), strlen($comment)).$comment;
    }

    // ─── XML helpers ─────────────────────────────────────────────────────

    private static function dom(?string $xml): ?DOMDocument
    {
        if ($xml === null || $xml === '') {
            return null;
        }
        $dom = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        return $loaded && $dom->doctype === null ? $dom : null;
    }

    private static function firstByLocalName(DOMDocument $dom, string $local): ?DOMElement
    {
        foreach ($dom->getElementsByTagName('*') as $el) {
            if ($el->localName === $local) {
                return $el;
            }
        }

        return null;
    }

    /** An attribute by local name, whatever its prefix. */
    private static function attr(DOMElement $el, string $local): ?string
    {
        foreach ($el->attributes ?? [] as $attribute) {
            if ($attribute->localName === $local) {
                return $attribute->value;
            }
        }

        return null;
    }
}
