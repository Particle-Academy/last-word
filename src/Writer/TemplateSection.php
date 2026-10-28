<?php

declare(strict_types=1);

namespace LastWord\Writer;

use DOMDocument;
use DOMElement;
use LastWord\Archive\SourceArchive;
use LastWord\Exceptions\TemplateException;

/**
 * A template's SECTION: its running header and footer parts (with their own
 * relationships and media) and its page size and margins -- the letterhead
 * (last-word#5).
 *
 * Taken from the template's body-level `w:sectPr` and nothing else. The body
 * itself is never read for content: a letterhead often arrives as a plain
 * `.docx` with a sample letter in it, and none of that may reach the output.
 * Only the parts the section's header/footer references name -- and the media
 * THOSE parts reference -- are carried.
 *
 * ## Copied exactly, renamed on the way
 *
 * Header and footer XML and their media are copied byte for byte. What is
 * renamed is everything a collision could happen in:
 *
 *   - parts become `word/header{n}.xml` / `word/footer{n}.xml`, numbered per
 *     kind in reference order. A kind is either wholly the document's own band
 *     or wholly the template's, so the writer's `header1.xml` is never
 *     written beside a template `header1.xml`;
 *   - media become `word/media/template{n}.{ext}`, a prefix the writer's own
 *     `image{n}` can never take;
 *   - the document-level relationship ids are `rIdTplHeader{n}` /
 *     `rIdTplFooter{n}`, a family the writer's `rId{n}` / `rIdHeader1` cannot
 *     reach.
 *
 * A part's OWN relationship ids are kept as they are: they are scoped to that
 * part's `.rels`, so they cannot collide with anything, and keeping them is what
 * lets the part's XML stay untouched. Only that `.rels` is regenerated, because
 * its targets point at the renamed media.
 *
 * ## Hostile input
 *
 * This is a customer upload, so it is read as hostile, and anything it cannot
 * carry safely is REFUSED with {@see TemplateException} rather than dropped:
 * dropping a relationship would leave its part pointing at an id that no longer
 * exists, which Word reports as an unreadable document, and rewriting the
 * customer's part to hide that would break the byte-for-byte promise. Refused:
 *
 *   - any XML part with a DOCTYPE, or that does not parse;
 *   - a header/footer part over {@see PART_LIMIT}, media over
 *     {@see MEDIA_LIMIT} each or {@see MEDIA_TOTAL_LIMIT} together;
 *   - an EXTERNAL relationship of any type but a hyperlink to http, https or
 *     mailto -- in particular an externally LINKED image, which Word would
 *     fetch every time the document opens (a tracking beacon, or a request
 *     from whatever server converts the document). Nothing here ever fetches;
 *   - an internal relationship that is not an image (OLE objects, alt-chunks,
 *     sub-documents, charts ...);
 *   - an image whose extension is not on {@see MEDIA} or whose bytes do not
 *     start the way that format starts -- so SVG (scriptable, can reference
 *     URLs) is not carried, and neither is an SVG renamed `.png`;
 *   - a field that pulls content in from elsewhere (INCLUDEPICTURE,
 *     INCLUDETEXT, LINK, DDE, DDEAUTO, IMPORT);
 *   - a URL-bearing VML attribute (`src`, `href`) -- a link that bypasses the
 *     relationship part altogether;
 *   - a relationship id the part uses that its `.rels` does not declare.
 *
 * @internal
 */
final class TemplateSection
{
    /** Largest header/footer XML part carried. A letterhead's is a few KiB. */
    public const PART_LIMIT = 2097152;

    /** Largest single media file carried. */
    public const MEDIA_LIMIT = 16777216;

    /** Largest total of media carried. */
    public const MEDIA_TOTAL_LIMIT = 33554432;

    /** Media extensions carried, and the content type each is declared with. */
    public const MEDIA = [
        'png' => 'image/png',
        'jpeg' => 'image/jpeg',
        'jpg' => 'image/jpeg',
        'gif' => 'image/gif',
        'bmp' => 'image/bmp',
        'tif' => 'image/tiff',
        'tiff' => 'image/tiff',
        'emf' => 'image/x-emf',
        'wmf' => 'image/x-wmf',
    ];

    public const KINDS = ['header', 'footer'];

    public const REFERENCE_TYPES = ['default', 'first', 'even'];

    private const NS_W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    private const REL_PREFIX = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/';

    /** Fields that pull content from outside the document when updated. */
    private const FETCHING_FIELDS = '/\b(?:INCLUDEPICTURE|INCLUDETEXT|LINK|DDE|DDEAUTO|IMPORT)\b/i';

    /** Twips: Word's largest page edge is 22in. */
    private const MAX_TWIPS = 31680;

    /**
     * @param  array<string, list<array{type: string, rId: string}>>  $references  kind => references
     * @param  array<string, array<string, array{xml: string, rels: ?string, rId: string}>>  $parts  kind => output path => part
     * @param  array<string, array<string, string>>  $media  kind => output path => bytes
     * @param  array<string, int|bool|null>|null  $page
     */
    private function __construct(
        private readonly array $references,
        private readonly array $parts,
        private readonly array $media,
        private readonly ?array $page,
        private readonly bool $titlePg,
        private readonly bool $evenAndOdd,
        private readonly int $maxDrawingId = 0,
    ) {}

    /**
     * @param  list<string>  $kinds  which of `header` / `footer` to take
     *
     * @throws TemplateException
     */
    public static function from(SourceArchive $zip, array $kinds, bool $page): self
    {
        $documentXml = $zip->read('word/document.xml');
        if ($documentXml === null) {
            throw new TemplateException('The template has no word/document.xml, so it has no section to take.');
        }
        $document = self::dom($documentXml, 'word/document.xml');
        $sectPr = self::bodySectPr($document);

        $geometry = $page && $sectPr !== null ? self::geometry($sectPr) : null;
        $titlePg = $sectPr !== null && self::isOn(self::child($sectPr, 'titlePg'));

        $references = $parts = $media = [];
        $mediaNames = []; // source path => output path, shared by both kinds
        $mediaTotal = 0;
        $maxDrawingId = 0;
        if ($sectPr !== null && $kinds !== []) {
            $docRels = self::rels($zip, 'word/_rels/document.xml.rels');
            foreach ($kinds as $kind) {
                $sources = []; // source part => output path
                foreach ($sectPr->childNodes as $node) {
                    if (! $node instanceof DOMElement || $node->namespaceURI !== self::NS_W || $node->localName !== $kind . 'Reference') {
                        continue;
                    }
                    $type = $node->getAttributeNS(self::NS_W, 'type') ?: 'default';
                    if (! in_array($type, self::REFERENCE_TYPES, true)) {
                        throw new TemplateException("The template's {$kind} reference has an unknown type.");
                    }
                    $rel = $docRels[self::relId($node)] ?? null;
                    if ($rel === null || $rel['external'] || $rel['type'] !== $kind) {
                        throw new TemplateException("The template's {$kind} reference does not name an internal {$kind} part.");
                    }
                    $source = self::resolve('word/', $rel['target']);
                    if (! isset($sources[$source])) {
                        $n = count($sources) + 1;
                        $out = "word/{$kind}{$n}.xml";
                        $sources[$source] = $out;
                        $parts[$kind][$out] = self::part($zip, $source, $kind, $media[$kind], $mediaNames, $mediaTotal, $maxDrawingId)
                            + ['rId' => 'rIdTpl' . ucfirst($kind) . $n];
                    }
                    // One reference per type: Word keeps the first it meets.
                    foreach ($references[$kind] ?? [] as $existing) {
                        if ($existing['type'] === $type) {
                            continue 2;
                        }
                    }
                    $references[$kind][] = ['type' => $type, 'rId' => $parts[$kind][$sources[$source]]['rId']];
                }
            }
        }

        return new self(
            $references,
            $parts,
            $media,
            $geometry,
            $titlePg,
            self::readEvenAndOdd($zip),
            $maxDrawingId,
        );
    }

    /**
     * The largest `wp:docPr` id any carried part uses. The writer numbers its
     * own drawings above it: Word treats a repeated drawing id as damage.
     */
    public function maxDrawingId(): int
    {
        return $this->maxDrawingId;
    }

    /** Whether the template's section references any part of this kind (and it was asked for). */
    public function has(string $kind): bool
    {
        return ($this->references[$kind] ?? []) !== [];
    }

    /** @return list<array{type: string, rId: string}> */
    public function references(string $kind): array
    {
        return $this->references[$kind] ?? [];
    }

    /** @return array<string, array{xml: string, rels: ?string, rId: string}> output path => part */
    public function parts(string $kind): array
    {
        return $this->parts[$kind] ?? [];
    }

    /** @return array<string, string> output path => bytes */
    public function media(string $kind): array
    {
        return $this->media[$kind] ?? [];
    }

    /**
     * The template's page size and margins in twips, null when `page` was not
     * asked for or the section declares none. Any field may be null: it was
     * absent or out of range, and the writer's own value stands for it.
     *
     * @return array{w: ?int, h: ?int, landscape: bool, top: ?int, right: ?int, bottom: ?int, left: ?int, header: ?int, footer: ?int, gutter: ?int}|null
     */
    public function page(): ?array
    {
        return $this->page;
    }

    /** `w:titlePg`: the section has a distinct first-page header/footer. */
    public function titlePg(): bool
    {
        return $this->titlePg;
    }

    /** `w:evenAndOddHeaders` in the template's settings. */
    public function evenAndOdd(): bool
    {
        return $this->evenAndOdd;
    }

    // ─── Parts ───────────────────────────────────────────────────────────

    /**
     * One header/footer part, checked, with its regenerated `.rels` and its
     * media queued under their output names.
     *
     * @param  array<string, string>|null  $media  output path => bytes, for this kind
     * @param  array<string, string>  $mediaNames  source path => output path
     * @return array{xml: string, rels: ?string}
     *
     * @throws TemplateException
     */
    private static function part(SourceArchive $zip, string $source, string $kind, ?array &$media, array &$mediaNames, int &$mediaTotal, int &$maxDrawingId): array
    {
        $xml = $zip->read($source);
        if ($xml === null) {
            throw new TemplateException("The template's {$kind} part is missing from the package.");
        }
        if (strlen($xml) > self::PART_LIMIT) {
            throw new TemplateException("The template's {$kind} part is larger than " . self::PART_LIMIT . ' bytes.');
        }
        $dom = self::dom($xml, $kind);
        $root = $dom->documentElement;
        if ($root === null || $root->namespaceURI !== self::NS_W || $root->localName !== ($kind === 'header' ? 'hdr' : 'ftr')) {
            throw new TemplateException("The template's {$kind} part is not a WordprocessingML {$kind}.");
        }
        self::refuseFetchingContent($dom, $kind);
        foreach ($dom->getElementsByTagName('*') as $el) {
            if ($el->localName === 'docPr' && preg_match('/^\d{1,9}$/', $el->getAttribute('id')) === 1) {
                $maxDrawingId = max($maxDrawingId, (int) $el->getAttribute('id'));
            }
        }

        $relsPath = self::relsPathOf($source);
        $rels = $zip->read($relsPath) === null ? [] : self::rels($zip, $relsPath);
        foreach (self::usedRelIds($dom) as $id) {
            if (! isset($rels[$id])) {
                throw new TemplateException("The template's {$kind} part uses a relationship its package does not declare.");
            }
        }

        $base = substr($source, 0, (int) strrpos($source, '/') + 1);
        $out = '';
        foreach ($rels as $id => $rel) {
            if ($rel['external']) {
                if ($rel['type'] !== 'hyperlink' || preg_match('/^(?:https?:\/\/|mailto:)/i', $rel['target']) !== 1) {
                    throw new TemplateException("The template's {$kind} links to something outside the document that is not a web or mail link; a linked image would be fetched every time the document opens.");
                }
                $out .= '<Relationship Id="' . self::attr($id) . '" Type="' . self::REL_PREFIX . 'hyperlink" Target="' . self::attr($rel['target']) . '" TargetMode="External"/>';

                continue;
            }
            if ($rel['type'] !== 'image') {
                throw new TemplateException("The template's {$kind} embeds something other than an image, which is not carried.");
            }
            $from = self::resolve($base, $rel['target']);
            if (! isset($mediaNames[$from])) {
                $ext = strtolower((string) pathinfo($from, PATHINFO_EXTENSION));
                $bytes = $zip->read($from);
                if ($bytes === null) {
                    throw new TemplateException("An image in the template's {$kind} is missing from the package.");
                }
                if (! isset(self::MEDIA[$ext]) || ! self::looksLike($ext, $bytes)) {
                    throw new TemplateException("An image in the template's {$kind} is not a PNG, JPEG, GIF, BMP, TIFF, EMF or WMF.");
                }
                $mediaTotal += strlen($bytes);
                if (strlen($bytes) > self::MEDIA_LIMIT || $mediaTotal > self::MEDIA_TOTAL_LIMIT) {
                    throw new TemplateException("The images in the template's header and footer exceed the size limit.");
                }
                $mediaNames[$from] = 'word/media/template' . (count($mediaNames) + 1) . '.' . $ext;
            }
            $media ??= [];
            $media[$mediaNames[$from]] = (string) $zip->read($from);
            $out .= '<Relationship Id="' . self::attr($id) . '" Type="' . self::REL_PREFIX . 'image" Target="' . self::attr(substr($mediaNames[$from], strlen('word/'))) . '"/>';
        }

        return [
            'xml' => $xml,
            'rels' => $out === '' ? null
                : '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
                    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $out . '</Relationships>',
        ];
    }

    /** @throws TemplateException */
    private static function refuseFetchingContent(DOMDocument $dom, string $kind): void
    {
        $stack = [];
        foreach ($dom->getElementsByTagName('*') as $el) {
            foreach ($el->attributes ?? [] as $attribute) {
                if (in_array(strtolower((string) $attribute->localName), ['src', 'href'], true)
                    && preg_match('/^(?:[a-z][a-z0-9+.\-]*:|\/\/|\\\\)/i', trim($attribute->value)) === 1) {
                    throw new TemplateException("The template's {$kind} carries a direct link to an outside resource.");
                }
            }
            if ($el->namespaceURI !== self::NS_W) {
                continue;
            }
            $instr = null;
            if ($el->localName === 'fldSimple') {
                $instr = $el->getAttributeNS(self::NS_W, 'instr');
            } elseif ($el->localName === 'fldChar') {
                $type = $el->getAttributeNS(self::NS_W, 'fldCharType');
                if ($type === 'begin') {
                    $stack[] = '';
                } elseif ($type === 'end' || $type === 'separate') {
                    $instr = $stack === [] ? null : ($type === 'end' ? array_pop($stack) : $stack[count($stack) - 1]);
                }
            } elseif ($el->localName === 'instrText' && $stack !== []) {
                $stack[count($stack) - 1] .= $el->textContent;
            }
            if (is_string($instr) && preg_match(self::FETCHING_FIELDS, $instr) === 1) {
                throw new TemplateException("The template's {$kind} has a field that pulls content in from outside the document.");
            }
        }
        foreach ($stack as $instr) {
            if (preg_match(self::FETCHING_FIELDS, $instr) === 1) {
                throw new TemplateException("The template's {$kind} has a field that pulls content in from outside the document.");
            }
        }
    }

    /**
     * Every relationship id the part uses: any attribute in a relationships
     * namespace (`r:id`, `r:embed`, `r:link`, `r:pict` ...).
     *
     * @return list<string>
     */
    private static function usedRelIds(DOMDocument $dom): array
    {
        $ids = [];
        foreach ($dom->getElementsByTagName('*') as $el) {
            foreach ($el->attributes ?? [] as $attribute) {
                if (is_string($attribute->namespaceURI) && str_ends_with($attribute->namespaceURI, '/relationships') && $attribute->value !== '') {
                    $ids[$attribute->value] = true;
                }
            }
        }

        return array_keys($ids);
    }

    /** Magic bytes for each carried media format. */
    private static function looksLike(string $ext, string $bytes): bool
    {
        return match ($ext) {
            'png' => str_starts_with($bytes, "\x89PNG\r\n\x1a\n"),
            'jpeg', 'jpg' => str_starts_with($bytes, "\xFF\xD8\xFF"),
            'gif' => str_starts_with($bytes, 'GIF87a') || str_starts_with($bytes, 'GIF89a'),
            'bmp' => str_starts_with($bytes, 'BM'),
            'tif', 'tiff' => str_starts_with($bytes, "II*\x00") || str_starts_with($bytes, "MM\x00*"),
            'emf' => str_starts_with($bytes, "\x01\x00\x00\x00") && substr($bytes, 40, 4) === ' EMF',
            'wmf' => str_starts_with($bytes, "\xD7\xCD\xC6\x9A")
                || ((str_starts_with($bytes, "\x01\x00") || str_starts_with($bytes, "\x02\x00")) && substr($bytes, 2, 2) === "\x09\x00"),
            default => false,
        };
    }

    // ─── Section ─────────────────────────────────────────────────────────

    /** The body's own (last) `w:sectPr` -- the section the document ends with. */
    private static function bodySectPr(DOMDocument $document): ?DOMElement
    {
        $root = $document->documentElement;
        $body = $root !== null ? self::child($root, 'body') : null;
        if ($body === null) {
            return null;
        }
        $last = null;
        foreach ($body->childNodes as $node) {
            if ($node instanceof DOMElement) {
                $last = $node;
            }
        }

        return $last !== null && $last->namespaceURI === self::NS_W && $last->localName === 'sectPr' ? $last : null;
    }

    /** @return array{w: ?int, h: ?int, landscape: bool, top: ?int, right: ?int, bottom: ?int, left: ?int, header: ?int, footer: ?int, gutter: ?int} */
    private static function geometry(DOMElement $sectPr): array
    {
        $pgSz = self::child($sectPr, 'pgSz');
        $pgMar = self::child($sectPr, 'pgMar');
        $twips = static function (?DOMElement $el, string $name, int $min): ?int {
            if ($el === null) {
                return null;
            }
            $value = $el->getAttributeNS(self::NS_W, $name);
            if (preg_match('/^-?\d{1,6}$/', $value) !== 1) {
                return null;
            }
            $value = (int) $value;

            return $value >= $min && $value <= self::MAX_TWIPS ? $value : null;
        };

        return [
            'w' => $twips($pgSz, 'w', 1),
            'h' => $twips($pgSz, 'h', 1),
            'landscape' => $pgSz !== null && $pgSz->getAttributeNS(self::NS_W, 'orient') === 'landscape',
            // Word allows a negative top/bottom: "exactly this, even if text overlaps".
            'top' => $twips($pgMar, 'top', -self::MAX_TWIPS),
            'right' => $twips($pgMar, 'right', 0),
            'bottom' => $twips($pgMar, 'bottom', -self::MAX_TWIPS),
            'left' => $twips($pgMar, 'left', 0),
            'header' => $twips($pgMar, 'header', 0),
            'footer' => $twips($pgMar, 'footer', 0),
            'gutter' => $twips($pgMar, 'gutter', 0),
        ];
    }

    /** @throws TemplateException */
    private static function readEvenAndOdd(SourceArchive $zip): bool
    {
        $xml = $zip->read('word/settings.xml');
        if ($xml === null) {
            return false;
        }
        $root = self::dom($xml, 'settings')->documentElement;

        return $root !== null && self::isOn(self::child($root, 'evenAndOddHeaders'));
    }

    // ─── Relationships ───────────────────────────────────────────────────

    /**
     * A `.rels` part as id => {type (last path segment), target, external}.
     *
     * @return array<string, array{type: string, target: string, external: bool}>
     *
     * @throws TemplateException
     */
    private static function rels(SourceArchive $zip, string $path): array
    {
        $xml = $zip->read($path);
        if ($xml === null) {
            return [];
        }
        if (strlen($xml) > self::PART_LIMIT) {
            throw new TemplateException('A relationship part in the template is too large.');
        }
        $out = [];
        foreach (self::dom($xml, 'relationships')->getElementsByTagName('*') as $el) {
            if ($el->localName !== 'Relationship') {
                continue;
            }
            $id = $el->getAttribute('Id');
            $type = $el->getAttribute('Type');
            if ($id === '' || isset($out[$id])) {
                throw new TemplateException('A relationship part in the template has a missing or repeated id.');
            }
            $out[$id] = [
                'type' => substr($type, (int) strrpos($type, '/') + 1),
                'target' => $el->getAttribute('Target'),
                'external' => strcasecmp($el->getAttribute('TargetMode'), 'External') === 0,
            ];
        }

        return $out;
    }

    private static function relId(DOMElement $el): string
    {
        foreach ($el->attributes ?? [] as $attribute) {
            if ($attribute->localName === 'id' && is_string($attribute->namespaceURI) && str_ends_with($attribute->namespaceURI, '/relationships')) {
                return $attribute->value;
            }
        }

        return '';
    }

    /** `word/header1.xml` => `word/_rels/header1.xml.rels`. */
    private static function relsPathOf(string $part): string
    {
        $slash = strrpos($part, '/');
        $dir = $slash === false ? '' : substr($part, 0, $slash + 1);
        $name = $slash === false ? $part : substr($part, $slash + 1);

        return $dir . '_rels/' . $name . '.rels';
    }

    /**
     * A relationship target as a package path. Absolute targets start at the
     * root; `..` may climb, but never out of the package.
     *
     * @throws TemplateException
     */
    private static function resolve(string $base, string $target): string
    {
        $target = rawurldecode($target);
        if ($target === '' || str_contains($target, "\0") || str_contains($target, '\\') || preg_match('/^[a-z][a-z0-9+.\-]*:/i', $target) === 1) {
            throw new TemplateException('A relationship in the template has a target that is not a part of the package.');
        }
        $path = str_starts_with($target, '/') ? substr($target, 1) : $base . $target;
        $out = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($out === []) {
                    throw new TemplateException('A relationship in the template points outside the package.');
                }
                array_pop($out);

                continue;
            }
            $out[] = $segment;
        }

        return implode('/', $out);
    }

    // ─── XML ─────────────────────────────────────────────────────────────

    /** @throws TemplateException */
    private static function dom(string $xml, string $what): DOMDocument
    {
        // Checked on the bytes as well as the tree: a DOCTYPE is refused before
        // libxml is asked to do anything with it.
        if (preg_match('/<!DOCTYPE/i', $xml) === 1) {
            throw new TemplateException("The template's {$what} part declares a DOCTYPE, which is refused.");
        }
        $dom = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (! $loaded || $dom->doctype !== null || $dom->documentElement === null) {
            throw new TemplateException("The template's {$what} part is not well-formed XML.");
        }

        return $dom;
    }

    private static function child(DOMElement $parent, string $local): ?DOMElement
    {
        foreach ($parent->childNodes as $node) {
            if ($node instanceof DOMElement && $node->namespaceURI === self::NS_W && $node->localName === $local) {
                return $node;
            }
        }

        return null;
    }

    /** An on/off property: present and not `false` / `0` / `off`. */
    private static function isOn(?DOMElement $el): bool
    {
        if ($el === null) {
            return false;
        }
        $val = strtolower($el->getAttributeNS(self::NS_W, 'val'));

        return ! in_array($val, ['false', '0', 'off'], true);
    }

    private static function attr(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
