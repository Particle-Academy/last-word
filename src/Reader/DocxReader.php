<?php

declare(strict_types=1);

namespace LastWord\Reader;

use DOMDocument;
use DOMElement;
use DOMNode;
use LastWord\Writer\DocxWriter;
use RuntimeException;
use ZipArchive;

/**
 * DOCX reader — parses .docx bytes back into the Doc model.
 *
 * Handles this package's own writer output (lossless round-trip of the
 * semantic model), the Node mirror's output (@particle-academy/last-word —
 * same metadata slots since 0.2.0) AND tolerates Word-authored files:
 *
 *   - title from docProps/core.xml (dc:title, the cross-language slot);
 *     falls back to the pre-0.2.0 Title-styled paragraph
 *   - code blocks via `lastword:code[:{lang}]` w:sdt content controls
 *     (canonical), pre-0.2.0 `LastWordCode_{lang}` bookmarks, or bare
 *     CodeBlock-styled paragraphs; quotes via `lastword:quote` sdt or bare
 *     Quote-styled paragraphs
 *   - headings via pStyle Heading1-9 (clamped to 6) OR outlineLvl
 *   - run formatting: b / i / u / strike / color / highlight (named colors
 *     mapped to hex) / run shading fills / InlineCode char style
 *   - hyperlinks resolved through document.xml.rels
 *   - numPr lists with ilvl nesting; decimal numFmt → ordered, unknown
 *     numIds bucketed as unordered
 *   - tables (nested blocks in cells), header rows via w:tblHeader
 *   - images via a:blip r:embed → data URLs, extents → widthPx/heightPx
 *   - page breaks, bottom-border-only paragraphs → hr
 *   - unknown constructs degrade to plain paragraphs / get skipped —
 *     the reader never throws on strange XML
 *
 * Elements are matched by localName only, so files with unusual namespace
 * prefixes still parse.
 */
final class DocxReader
{
    /**
     * Faces that mean `code` rather than a font choice. Word-authored files
     * mark inline code by picking a monospace family and nothing else, so the
     * reader has to recognise the family; the writer's own output is
     * unambiguous (it also carries the InlineCode character style).
     */
    private const MONO_FONTS = ['consolas', 'courier new', 'courier', 'menlo', 'monaco', 'source code pro'];

    /** The header-cell grey, shared with the writer and both sibling engines. */
    private const HEADER_FILL = 'E7E7E7';

    /** Box edges, in the order every CT_ border and margin type declares them. */
    private const BOX_EDGES = ['top', 'left', 'bottom', 'right'];

    private const TABLE_EDGES = ['top', 'left', 'bottom', 'right', 'insideH', 'insideV'];

    /** Page sizes in twips, portrait — mirrors the writer's table. */
    private const PAGE_SIZES = [
        'letter' => [12240, 15840],
        'legal' => [12240, 20160],
        'a4' => [11906, 16838],
    ];

    /** w:highlight named colors → hex. */
    private const HIGHLIGHT_COLORS = [
        'yellow' => '#FFFF00', 'green' => '#00FF00', 'cyan' => '#00FFFF',
        'magenta' => '#FF00FF', 'blue' => '#0000FF', 'red' => '#FF0000',
        'darkBlue' => '#00008B', 'darkCyan' => '#008B8B', 'darkGreen' => '#006400',
        'darkMagenta' => '#8B008B', 'darkRed' => '#8B0000', 'darkYellow' => '#808000',
        'darkGray' => '#A9A9A9', 'lightGray' => '#D3D3D3',
        'black' => '#000000', 'white' => '#FFFFFF',
    ];

    /** rId → ['target' => string, 'external' => bool] */
    private array $rels = [];

    /** numId → ordered? (level-0 numFmt === decimal-ish) */
    private array $numbering = [];

    /** zip entry name (without leading word/) → bytes, for media resolution. */
    private array $media = [];

    /** docProps/core.xml contents, when the archive has one. */
    private ?string $coreXml = null;

    private ?string $stylesXml = null;

    private ?string $title = null;

    public function __construct(
        private ?string $tempDir = null,
    ) {
    }

    /**
     * Parse DOCX bytes into the Doc model.
     *
     * @return array<string, mixed>
     */
    public function read(string $bytes): array
    {
        $documentXml = $this->openArchive($bytes);

        $dom = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($documentXml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$loaded) {
            throw new RuntimeException('Could not parse word/document.xml.');
        }

        $body = $this->firstChildByName($dom->documentElement, 'body');

        // Canonical title slot: docProps/core.xml dc:title (shared with the
        // Node mirror). When absent, the pre-0.2.0 legacy slot — the first
        // top-level Title-styled paragraph — is consumed instead (see
        // parseBlockContainer()).
        $this->title = $this->parseCoreTitle();
        $blocks = $body !== null ? $this->parseBlockContainer($body, true) : [];

        $doc = [];
        if ($this->title !== null && $this->title !== '') {
            $doc['title'] = $this->title;
        }

        // Section geometry and document defaults are surfaced ONLY when they
        // differ from what the writer produces unasked, for the same reason
        // table options are: a Letter portrait page at one-inch margins is
        // what every document written before `page` existed contains.
        $page = $body !== null ? $this->parsePage($this->firstChildByName($body, 'sectPr')) : null;
        if ($page !== null) {
            $doc['page'] = $page;
        }
        foreach ($this->parseDocDefaults() as $key => $value) {
            $doc[$key] = $value;
        }

        $doc['blocks'] = $blocks;

        return $doc;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parsePage(?DOMElement $sectPr): ?array
    {
        if ($sectPr === null) {
            return null;
        }
        $out = [];

        $pgSz = $this->firstChildByName($sectPr, 'pgSz');
        $w = $pgSz !== null && is_numeric($this->wAttr($pgSz, 'w')) ? (int) $this->wAttr($pgSz, 'w') : 12240;
        $h = $pgSz !== null && is_numeric($this->wAttr($pgSz, 'h')) ? (int) $this->wAttr($pgSz, 'h') : 15840;
        if ($pgSz !== null && $this->wAttr($pgSz, 'orient') === 'landscape') {
            $out['orientation'] = 'landscape';
            [$w, $h] = [$h, $w];
        }
        foreach (self::PAGE_SIZES as $name => [$pw, $ph]) {
            if ($pw === $w && $ph === $h && $name !== 'letter') {
                $out['size'] = $name;
            }
        }

        $pgMar = $this->firstChildByName($sectPr, 'pgMar');
        $margins = [];
        if ($pgMar !== null) {
            foreach (self::BOX_EDGES as $side) {
                $value = $this->wAttr($pgMar, $side);
                if (is_numeric($value) && (int) $value !== 1440) {
                    $margins[$side] = self::points((float) $value);
                }
            }
        }
        if ($margins !== []) {
            $out['margins'] = $margins;
        }

        return $out === [] ? null : $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseDocDefaults(): array
    {
        if ($this->stylesXml === null) {
            return [];
        }
        $dom = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($this->stylesXml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$loaded || $dom->documentElement === null) {
            return [];
        }

        $rPr = $this->firstDescendantByName($dom->documentElement, 'rPrDefault');
        $rPr = $rPr !== null ? $this->firstChildByName($rPr, 'rPr') : null;
        if ($rPr === null) {
            return [];
        }

        $out = [];
        $rFonts = $this->firstChildByName($rPr, 'rFonts');
        $ascii = $rFonts !== null ? $this->wAttr($rFonts, 'ascii') : null;
        if (is_string($ascii) && $ascii !== '' && $ascii !== 'Calibri') {
            $out['defaultFont'] = $ascii;
        }
        $sz = $this->firstChildByName($rPr, 'sz');
        $val = $sz !== null ? $this->wAttr($sz, 'val') : null;
        if (is_numeric($val) && (int) $val !== 22) {
            $size = ((float) $val) / 2;
            $out['defaultSize'] = $size == (int) $size ? (int) $size : $size;
        }

        return $out;
    }

    /**
     * Open the zip (ZipArchive requires a real path — write the bytes to a
     * temp file first), pull the parts we need, and return document.xml.
     */
    private function openArchive(string $bytes): string
    {
        $base = rtrim($this->tempDir ?? sys_get_temp_dir(), DIRECTORY_SEPARATOR);
        $tmpDir = $base . DIRECTORY_SEPARATOR . 'last-word-read-' . bin2hex(random_bytes(8));
        if (!is_dir($tmpDir) && !@mkdir($tmpDir, 0700, true) && !is_dir($tmpDir)) {
            throw new RuntimeException("Could not allocate temp dir for reading at: {$tmpDir}.");
        }
        $tmp = $tmpDir . DIRECTORY_SEPARATOR . 'document.docx';

        try {
            if (file_put_contents($tmp, $bytes) === false) {
                throw new RuntimeException('Could not stage DOCX bytes for reading.');
            }

            $zip = new ZipArchive();
            if ($zip->open($tmp, ZipArchive::RDONLY) !== true) {
                throw new RuntimeException('Not a readable DOCX (zip) archive.');
            }

            try {
                $documentXml = $zip->getFromName('word/document.xml');
                if ($documentXml === false) {
                    throw new RuntimeException('No word/document.xml part — not a DOCX file.');
                }

                $relsXml = $zip->getFromName('word/_rels/document.xml.rels');
                $this->rels = is_string($relsXml) ? $this->parseRels($relsXml) : [];

                $numberingXml = $zip->getFromName('word/numbering.xml');
                $this->numbering = is_string($numberingXml) ? $this->parseNumbering($numberingXml) : [];

                $coreXml = $zip->getFromName('docProps/core.xml');
                $this->coreXml = is_string($coreXml) ? $coreXml : null;

                $stylesXml = $zip->getFromName('word/styles.xml');
                $this->stylesXml = is_string($stylesXml) ? $stylesXml : null;

                $this->media = [];
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $name = $zip->getNameIndex($i);
                    if (is_string($name) && str_starts_with($name, 'word/media/')) {
                        $data = $zip->getFromIndex($i);
                        if (is_string($data)) {
                            $this->media[substr($name, strlen('word/'))] = $data;
                        }
                    }
                }
            } finally {
                $zip->close();
            }

            return $documentXml;
        } finally {
            @unlink($tmp);
            @rmdir($tmpDir);
        }
    }

    /**
     * dc:title from docProps/core.xml — null when the part is missing,
     * unparsable, or the title element is absent/empty.
     */
    private function parseCoreTitle(): ?string
    {
        if ($this->coreXml === null) {
            return null;
        }

        $dom = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $ok = $dom->loadXML($this->coreXml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$ok || $dom->documentElement === null) {
            return null;
        }

        $title = $this->firstChildByName($dom->documentElement, 'title');
        if ($title === null || $title->textContent === '') {
            return null;
        }

        return $title->textContent;
    }

    /**
     * @return array<string, array{target: string, external: bool}>
     */
    private function parseRels(string $xml): array
    {
        $dom = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $ok = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$ok || $dom->documentElement === null) {
            return [];
        }

        $rels = [];
        foreach ($dom->documentElement->childNodes as $node) {
            if ($node instanceof DOMElement && $node->localName === 'Relationship') {
                $id = $node->getAttribute('Id');
                if ($id !== '') {
                    $rels[$id] = [
                        'target' => $node->getAttribute('Target'),
                        'external' => strcasecmp($node->getAttribute('TargetMode'), 'External') === 0,
                    ];
                }
            }
        }

        return $rels;
    }

    /**
     * numbering.xml → numId => ordered bool (from the level-0 numFmt of the
     * referenced abstract numbering). Unknown numIds read as unordered.
     *
     * @return array<int, bool>
     */
    private function parseNumbering(string $xml): array
    {
        $dom = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $ok = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$ok || $dom->documentElement === null) {
            return [];
        }

        $orderedFormats = ['decimal', 'decimalZero', 'lowerLetter', 'upperLetter', 'lowerRoman', 'upperRoman', 'ordinal', 'cardinalText', 'ordinalText'];

        $abstractOrdered = []; // abstractNumId → bool
        foreach ($dom->documentElement->childNodes as $node) {
            if (!($node instanceof DOMElement) || $node->localName !== 'abstractNum') {
                continue;
            }
            $abstractId = $this->wAttr($node, 'abstractNumId');
            $isOrdered = false;
            foreach ($node->childNodes as $lvl) {
                if ($lvl instanceof DOMElement && $lvl->localName === 'lvl' && $this->wAttr($lvl, 'ilvl') === '0') {
                    $fmt = $this->firstChildByName($lvl, 'numFmt');
                    $isOrdered = $fmt !== null && in_array($this->wAttr($fmt, 'val'), $orderedFormats, true);

                    break;
                }
            }
            if ($abstractId !== null) {
                $abstractOrdered[$abstractId] = $isOrdered;
            }
        }

        $map = [];
        foreach ($dom->documentElement->childNodes as $node) {
            if (!($node instanceof DOMElement) || $node->localName !== 'num') {
                continue;
            }
            $numId = $this->wAttr($node, 'numId');
            $ref = $this->firstChildByName($node, 'abstractNumId');
            $abstractId = $ref !== null ? $this->wAttr($ref, 'val') : null;
            if ($numId !== null) {
                $map[(int) $numId] = $abstractOrdered[$abstractId ?? ''] ?? false;
            }
        }

        return $map;
    }

    // ─── Block-level parsing ─────────────────────────────────────────────

    /**
     * Walk the children of a block container (w:body, w:tc, w:sdtContent, …)
     * and assemble model blocks, grouping consecutive list / code / quote
     * paragraphs. `$insideQuote` marks content already wrapped by a
     * `lastword:quote` sdt so its Quote-styled paragraphs read as plain
     * paragraphs instead of nesting another quote.
     *
     * @return list<array<string, mixed>>
     */
    private function parseBlockContainer(DOMElement $container, bool $topLevel = false, bool $insideQuote = false): array
    {
        $blocks = [];
        /** @var list<array{ilvl: int, ordered: bool, runs: list<array<string, mixed>>}> $pendingList */
        $pendingList = [];
        /** @var list<string> $pendingCode */
        $pendingCode = [];
        $pendingCodeLanguage = null;
        /** @var list<array<string, mixed>> $pendingQuote */
        $pendingQuote = [];

        $flushList = function () use (&$blocks, &$pendingList): void {
            if ($pendingList !== []) {
                $blocks[] = $this->assembleList($pendingList);
                $pendingList = [];
            }
        };
        $flushCode = function () use (&$blocks, &$pendingCode, &$pendingCodeLanguage): void {
            if ($pendingCode !== []) {
                $block = ['type' => 'code'];
                if ($pendingCodeLanguage !== null) {
                    $block['language'] = $pendingCodeLanguage;
                }
                $block['text'] = implode("\n", $pendingCode);
                $blocks[] = $block;
                $pendingCode = [];
                $pendingCodeLanguage = null;
            }
        };
        $flushQuote = function () use (&$blocks, &$pendingQuote): void {
            if ($pendingQuote !== []) {
                $blocks[] = ['type' => 'quote', 'blocks' => $pendingQuote];
                $pendingQuote = [];
            }
        };
        $flushAll = function () use ($flushList, $flushCode, $flushQuote): void {
            $flushList();
            $flushCode();
            $flushQuote();
        };

        foreach ($this->blockChildren($container) as $node) {
            if ($node->localName === 'tbl') {
                $flushAll();
                $blocks[] = $this->parseTable($node, $insideQuote);

                continue;
            }
            if ($node->localName === 'sdt') {
                // Only lastword-tagged sdts surface here (blockChildren
                // flattens the rest) — the canonical code / quote carriers.
                $flushAll();
                array_push($blocks, ...$this->parseTaggedSdt($node));

                continue;
            }
            if ($node->localName !== 'p') {
                continue; // unknown body-level construct — skip
            }

            $p = $this->parseParagraphNode($node);

            // Lists group before style handling — numPr wins.
            if ($p['numPr'] !== null) {
                $flushCode();
                $flushQuote();
                [$ilvl, $numId] = $p['numPr'];
                $ordered = $this->numbering[$numId] ?? false;
                // A change of orderedness at the top level starts a new list.
                if ($pendingList !== [] && $ilvl === 0 && $pendingList[0]['ordered'] !== $ordered) {
                    $flushList();
                }
                $pendingList[] = ['ilvl' => $ilvl, 'ordered' => $ordered, 'runs' => $p['runs']];

                continue;
            }

            if ($p['style'] === 'CodeBlock') {
                $flushList();
                $flushQuote();
                if ($pendingCode === [] && $p['codeLanguage'] !== null) {
                    $pendingCodeLanguage = $p['codeLanguage'];
                }
                // Soft line breaks inside a single code paragraph are lines too.
                $text = implode('', array_map(static fn (array $r): string => $r['text'], $p['runs']));
                foreach (explode("\n", $text) as $line) {
                    $pendingCode[] = $line;
                }

                continue;
            }

            if ($p['style'] === 'Quote' && !$insideQuote) {
                $flushList();
                $flushCode();
                $para = ['type' => 'paragraph', 'runs' => $p['runs']];
                if ($p['align'] !== null) {
                    $para['align'] = $p['align'];
                }
                $pendingQuote[] = $para;

                continue;
            }

            $flushAll();

            if ($topLevel && $p['style'] === 'Title' && $this->title === null) {
                $this->title = $this->plainText($p['runs']);

                continue;
            }

            // Images render as their own blocks; text in the same paragraph
            // (unusual, but legal) still becomes a paragraph first.
            $hasText = $this->plainText($p['runs']) !== '';

            if ($p['pageBreak'] && !$hasText && $p['images'] === []) {
                $blocks[] = ['type' => 'pageBreak'];

                continue;
            }

            $level = null;
            if ($p['style'] !== null && preg_match('/^Heading([1-9])$/', $p['style'], $m) === 1) {
                $level = min((int) $m[1], 6);
            } elseif ($p['outlineLvl'] !== null) {
                $level = min($p['outlineLvl'] + 1, 6);
            }

            if ($level !== null && $hasText) {
                $heading = ['type' => 'heading', 'level' => $level, 'runs' => $p['runs']];
                if ($p['align'] !== null) {
                    $heading['align'] = $p['align'];
                }
                $blocks[] = $heading + $p['props'];
            } elseif ($hasText || ($p['runs'] !== [] && $p['images'] === [])) {
                $para = ['type' => 'paragraph', 'runs' => $p['runs']];
                if ($p['align'] !== null) {
                    $para['align'] = $p['align'];
                }
                $blocks[] = $para + $p['props'];
            } elseif (!$hasText && $p['images'] === [] && $p['bottomBorder']) {
                $blocks[] = ['type' => 'hr'];
            }

            foreach ($p['images'] as $image) {
                $blocks[] = $image;
            }

            if ($p['pageBreak'] && ($hasText || $p['images'] !== [])) {
                $blocks[] = ['type' => 'pageBreak'];
            }
        }

        $flushAll();

        return $blocks;
    }

    /**
     * Children of a block container, descending into w:customXml and
     * unknown w:sdt wrappers so wrapped content degrades gracefully instead
     * of vanishing. Sdts carrying a `lastword:` tag (the canonical code /
     * quote metadata slots, shared with the Node mirror) are returned as-is
     * for {@see parseTaggedSdt()}.
     *
     * @return list<DOMElement>
     */
    private function blockChildren(DOMElement $container): array
    {
        $out = [];
        foreach ($container->childNodes as $node) {
            if (!($node instanceof DOMElement)) {
                continue;
            }
            switch ($node->localName) {
                case 'p':
                case 'tbl':
                    $out[] = $node;

                    break;
                case 'sdt':
                    if ($this->lastWordSdtTag($node) !== null) {
                        $out[] = $node;

                        break;
                    }
                    $content = $this->firstChildByName($node, 'sdtContent');
                    if ($content !== null) {
                        array_push($out, ...$this->blockChildren($content));
                    }

                    break;
                case 'customXml':
                    array_push($out, ...$this->blockChildren($node));

                    break;
                default:
                    break; // sectPr, bookmarkStart, proofErr, altChunk, …
            }
        }

        return $out;
    }

    /**
     * The sdt's w:tag value when it is one of ours (`lastword:code[:{lang}]`
     * or `lastword:quote`); null for foreign / untagged sdts.
     */
    private function lastWordSdtTag(DOMElement $sdt): ?string
    {
        $sdtPr = $this->firstChildByName($sdt, 'sdtPr');
        $tagNode = $sdtPr !== null ? $this->firstChildByName($sdtPr, 'tag') : null;
        $tag = $tagNode !== null ? $this->wAttr($tagNode, 'val') : null;
        if ($tag === null) {
            return null;
        }

        $isCode = $tag === DocxWriter::SDT_TAG_CODE || str_starts_with($tag, DocxWriter::SDT_TAG_CODE . ':');
        if ($isCode || $tag === DocxWriter::SDT_TAG_QUOTE) {
            return $tag;
        }

        return null;
    }

    /**
     * A `lastword:`-tagged sdt → the code or quote block it carries. The
     * sdt tag is the canonical cross-language slot for the code block's
     * `language` (the pre-0.2.0 bookmark is still honoured for old files
     * via {@see parseParagraphNode()}).
     *
     * @return list<array<string, mixed>>
     */
    private function parseTaggedSdt(DOMElement $sdt): array
    {
        $tag = $this->lastWordSdtTag($sdt);
        $content = $this->firstChildByName($sdt, 'sdtContent');
        if ($tag === null || $content === null) {
            return [];
        }

        if ($tag === DocxWriter::SDT_TAG_QUOTE) {
            return [[
                'type' => 'quote',
                'blocks' => $this->parseBlockContainer($content, false, true),
            ]];
        }

        // Code: one line per direct w:p child; language from the tag suffix.
        $lines = [];
        foreach ($content->childNodes as $node) {
            if ($node instanceof DOMElement && $node->localName === 'p') {
                $lines[] = $this->plainText($this->parseParagraphNode($node)['runs']);
            }
        }

        $block = ['type' => 'code'];
        $prefix = DocxWriter::SDT_TAG_CODE . ':';
        if (str_starts_with($tag, $prefix) && strlen($tag) > strlen($prefix)) {
            $block['language'] = substr($tag, strlen($prefix));
        }
        $block['text'] = implode("\n", $lines);

        return [$block];
    }

    /**
     * @return array{
     *   style: ?string, align: ?string, outlineLvl: ?int,
     *   numPr: ?array{0: int, 1: int}, bottomBorder: bool, pageBreak: bool,
     *   codeLanguage: ?string,
     *   runs: list<array<string, mixed>>, images: list<array<string, mixed>>
     * }
     */
    private function parseParagraphNode(DOMElement $p): array
    {
        $style = null;
        $align = null;
        $outlineLvl = null;
        $numPr = null;
        $bottomBorder = false;
        $codeLanguage = null;

        $pPr = $this->firstChildByName($p, 'pPr');
        if ($pPr !== null) {
            $styleNode = $this->firstChildByName($pPr, 'pStyle');
            if ($styleNode !== null) {
                $style = $this->wAttr($styleNode, 'val');
            }
            $jc = $this->firstChildByName($pPr, 'jc');
            if ($jc !== null) {
                $align = match ($this->wAttr($jc, 'val')) {
                    'center' => 'center',
                    'right', 'end' => 'right',
                    'both', 'distribute' => 'justify',
                    default => null,
                };
            }
            $outline = $this->firstChildByName($pPr, 'outlineLvl');
            if ($outline !== null && is_numeric($this->wAttr($outline, 'val'))) {
                $outlineLvl = (int) $this->wAttr($outline, 'val');
            }
            $numPrNode = $this->firstChildByName($pPr, 'numPr');
            if ($numPrNode !== null) {
                $ilvlNode = $this->firstChildByName($numPrNode, 'ilvl');
                $numIdNode = $this->firstChildByName($numPrNode, 'numId');
                $numId = $numIdNode !== null ? (int) ($this->wAttr($numIdNode, 'val') ?? 0) : 0;
                if ($numId > 0) {
                    $ilvl = $ilvlNode !== null ? (int) ($this->wAttr($ilvlNode, 'val') ?? 0) : 0;
                    $numPr = [max(0, min(5, $ilvl)), $numId];
                }
            }
            $pBdr = $this->firstChildByName($pPr, 'pBdr');
            if ($pBdr !== null && $this->firstChildByName($pBdr, 'bottom') !== null) {
                $bottomBorder = true;
            }
        }

        $props = $this->parseParagraphProperties($pPr);

        // Pre-0.2.0 code-language bookmark convention (`LastWordCode_{lang}`)
        // — kept for back-compat; the canonical slot is the sdt tag.
        foreach ($p->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === 'bookmarkStart') {
                $name = $this->wAttr($child, 'name') ?? '';
                if (str_starts_with($name, 'LastWordCode_')) {
                    $codeLanguage = substr($name, strlen('LastWordCode_'));
                }
            }
        }

        $state = ['pageBreak' => false, 'images' => []];
        $runs = $this->parseInlineContainer($p, null, $state);

        return [
            'style' => $style,
            'align' => $align,
            'props' => $props,
            'outlineLvl' => $outlineLvl,
            'numPr' => $numPr,
            'bottomBorder' => $bottomBorder,
            'pageBreak' => $state['pageBreak'],
            'codeLanguage' => $codeLanguage,
            'runs' => $this->mergeRuns($runs),
            'images' => $state['images'],
        ];
    }

    /**
     * Paragraph properties back into the model — the same set `paragraph`,
     * `heading` and a list item all accept. `align` is left to the caller,
     * which already computed it.
     *
     * @return array<string, mixed>
     */
    private function parseParagraphProperties(?DOMElement $pPr): array
    {
        if ($pPr === null) {
            return [];
        }
        $out = [];

        $spacing = $this->firstChildByName($pPr, 'spacing');
        if ($spacing !== null) {
            foreach (['before' => 'spaceBefore', 'after' => 'spaceAfter'] as $attr => $key) {
                $value = $this->wAttr($spacing, $attr);
                if (is_numeric($value)) {
                    $out[$key] = self::points((float) $value);
                }
            }
            $line = $this->wAttr($spacing, 'line');
            if (is_numeric($line) && $this->wAttr($spacing, 'lineRule') === 'auto') {
                $out['lineHeight'] = round(((float) $line) / 240, 3);
            }
        }

        $ind = $this->firstChildByName($pPr, 'ind');
        if ($ind !== null) {
            foreach (['left' => 'indentLeft', 'right' => 'indentRight'] as $attr => $key) {
                $value = $this->wAttr($ind, $attr);
                if (is_numeric($value)) {
                    $out[$key] = self::points((float) $value);
                }
            }
        }

        $keepNext = $this->firstChildByName($pPr, 'keepNext');
        if ($keepNext !== null && $this->toggleOn($this->wAttr($keepNext, 'val'))) {
            $out['keepNext'] = true;
        }

        $shd = $this->firstChildByName($pPr, 'shd');
        if ($shd !== null) {
            $fill = $this->wAttr($shd, 'fill');
            if (is_string($fill) && preg_match('/^[0-9A-Fa-f]{6}$/', $fill) === 1) {
                $out['shading'] = '#' . strtoupper($fill);
            }
        }

        $borders = $this->parseBorders($this->firstChildByName($pPr, 'pBdr'), self::BOX_EDGES);
        if ($borders !== null) {
            $out['borders'] = $borders;
        }

        return $out;
    }

    /** Twips → points, kept exact for the halves the writer can emit. */
    private static function points(float $twips): float|int
    {
        $pt = round($twips / 20, 2);

        return $pt == (int) $pt ? (int) $pt : $pt;
    }

    /**
     * One border edge back into the model.
     *
     * Defaults are NOT surfaced: `style` only when it is not `single`,
     * `color` only when it is not `auto`. Otherwise reading a document written
     * from a model returns a bigger model than went in, and writing that model
     * back produces a different file.
     *
     * @return array<string, mixed>|null
     */
    private function parseBorderEdge(?DOMElement $edge): ?array
    {
        if ($edge === null) {
            return null;
        }
        $val = $this->wAttr($edge, 'val') ?? 'single';
        if ($val === 'nil' || $val === 'none') {
            return ['style' => 'none'];
        }
        $out = [];
        if ($val !== 'single') {
            $out['style'] = $val;
        }
        $sz = $this->wAttr($edge, 'sz');
        if (is_numeric($sz)) {
            $width = round(((float) $sz) / 8, 3);
            $out['width'] = $width == (int) $width ? (int) $width : $width;
        }
        $color = $this->wAttr($edge, 'color');
        if (is_string($color) && preg_match('/^[0-9A-Fa-f]{6}$/', $color) === 1) {
            $out['color'] = '#' . strtoupper($color);
        }

        return $out;
    }

    /**
     * @param  list<string>  $edges
     * @return array<string, mixed>|null
     */
    private function parseBorders(?DOMElement $container, array $edges): ?array
    {
        if ($container === null) {
            return null;
        }
        $out = [];
        foreach ($edges as $edge) {
            $border = $this->parseBorderEdge($this->firstChildByName($container, $edge));
            if ($border !== null) {
                $out[$edge] = $border;
            }
        }

        return $out === [] ? null : $out;
    }

    /**
     * A margin container (`w:tblCellMar`, `w:tcMar`) back into points.
     *
     * @return array<string, float|int>|null
     */
    private function parseSides(?DOMElement $container): ?array
    {
        if ($container === null) {
            return null;
        }
        $out = [];
        foreach (self::BOX_EDGES as $side) {
            $node = $this->firstChildByName($container, $side);
            if ($node === null) {
                continue;
            }
            $w = $this->wAttr($node, 'w');
            if (is_numeric($w)) {
                $out[$side] = self::points((float) $w);
            }
        }

        return $out === [] ? null : $out;
    }

    // ─── Inline parsing ──────────────────────────────────────────────────

    /**
     * Parse the inline content of a paragraph-like element into runs.
     * Recurses through hyperlinks, ins, fldSimple, smartTag and any other
     * unknown inline wrappers so their text degrades instead of dropping.
     *
     * @param  array{pageBreak: bool, images: list<array<string, mixed>>}  $state
     * @return list<array<string, mixed>>
     */
    private function parseInlineContainer(DOMElement $container, ?string $link, array &$state): array
    {
        $runs = [];
        foreach ($container->childNodes as $node) {
            if (!($node instanceof DOMElement)) {
                continue;
            }
            switch ($node->localName) {
                case 'r':
                    $run = $this->parseRun($node, $link, $state);
                    if ($run !== null) {
                        $runs[] = $run;
                    }

                    break;
                case 'hyperlink':
                    $rId = $this->rAttr($node, 'id');
                    $target = $rId !== null ? ($this->rels[$rId]['target'] ?? null) : null;
                    $anchor = $this->wAttr($node, 'anchor');
                    if ($target === null && $anchor !== null && $anchor !== '') {
                        $target = '#' . $anchor;
                    }
                    array_push($runs, ...$this->parseInlineContainer($node, $target ?? $link, $state));

                    break;
                case 'pPr':
                case 'bookmarkStart':
                case 'bookmarkEnd':
                case 'proofErr':
                case 'del':
                case 'commentRangeStart':
                case 'commentRangeEnd':
                    break;
                default:
                    // ins, fldSimple, smartTag, sdt(run-level), … — descend.
                    array_push($runs, ...$this->parseInlineContainer($node, $link, $state));

                    break;
            }
        }

        return $runs;
    }

    /**
     * @param  array{pageBreak: bool, images: list<array<string, mixed>>}  $state
     * @return array<string, mixed>|null
     */
    private function parseRun(DOMElement $r, ?string $link, array &$state): ?array
    {
        $props = [];
        $rPr = $this->firstChildByName($r, 'rPr');
        if ($rPr !== null) {
            $props = $this->parseRunProperties($rPr);
        }

        $text = '';
        foreach ($r->childNodes as $node) {
            if (!($node instanceof DOMElement)) {
                continue;
            }
            switch ($node->localName) {
                case 't':
                    $text .= $node->textContent;

                    break;
                case 'br':
                    if ($this->wAttr($node, 'type') === 'page') {
                        $state['pageBreak'] = true;
                    } else {
                        $text .= "\n";
                    }

                    break;
                case 'tab':
                    $text .= "\t";

                    break;
                case 'drawing':
                    $image = $this->parseDrawing($node);
                    if ($image !== null) {
                        $state['images'][] = $image;
                    }

                    break;
                default:
                    break;
            }
        }

        if ($text === '') {
            return null;
        }

        $run = ['text' => $text];
        foreach (['bold', 'italic', 'underline', 'strike', 'code', 'smallCaps'] as $flag) {
            if (!empty($props[$flag])) {
                $run[$flag] = true;
            }
        }
        if ($link !== null && $link !== '') {
            $run['link'] = $link;
        }
        // Every non-boolean run property, listed once. A key missing from
        // here is read out of the XML and then dropped on the floor — which is
        // exactly how `size`, `font` and `letterSpacing` were invisible on the
        // way back before this list grew.
        foreach (['color', 'highlight', 'size', 'font', 'letterSpacing'] as $key) {
            if (isset($props[$key])) {
                $run[$key] = $props[$key];
            }
        }

        return $run;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseRunProperties(DOMElement $rPr): array
    {
        $props = [];
        foreach ($rPr->childNodes as $node) {
            if (!($node instanceof DOMElement)) {
                continue;
            }
            $val = $this->wAttr($node, 'val');
            switch ($node->localName) {
                case 'b':
                    $props['bold'] = $this->toggleOn($val);

                    break;
                case 'i':
                    $props['italic'] = $this->toggleOn($val);

                    break;
                case 'strike':
                    $props['strike'] = $this->toggleOn($val);

                    break;
                case 'u':
                    $props['underline'] = $val !== 'none' && $val !== '0';

                    break;
                case 'color':
                    if (is_string($val) && preg_match('/^[0-9A-Fa-f]{6}$/', $val) === 1) {
                        $props['color'] = '#' . strtoupper($val);
                    }

                    break;
                case 'highlight':
                    if (is_string($val) && isset(self::HIGHLIGHT_COLORS[$val])) {
                        $props['highlight'] = self::HIGHLIGHT_COLORS[$val];
                    }

                    break;
                case 'shd':
                    $fill = $this->wAttr($node, 'fill');
                    if (is_string($fill) && preg_match('/^[0-9A-Fa-f]{6}$/', $fill) === 1 && strcasecmp($fill, 'auto') !== 0) {
                        $props['highlight'] = '#' . strtoupper($fill);
                    }

                    break;
                case 'rStyle':
                    if ($val === 'InlineCode') {
                        $props['code'] = true;
                    }

                    break;
                case 'smallCaps':
                    if ($this->toggleOn($val)) {
                        $props['smallCaps'] = true;
                    }

                    break;
                case 'sz':
                    if (is_numeric($val)) {
                        $props['size'] = ((float) $val) / 2;
                    }

                    break;
                case 'spacing':
                    // Tracking of zero is the writer's "absent", so a zero
                    // here would be a property nobody asked for.
                    if (is_numeric($val) && (float) $val != 0.0) {
                        $props['letterSpacing'] = ((float) $val) / 20;
                    }

                    break;
                case 'rFonts':
                    $ascii = $this->wAttr($node, 'ascii');
                    if (is_string($ascii) && $ascii !== '') {
                        $props['font'] = $ascii;
                    }

                    break;
                default:
                    break;
            }
        }

        // A `code` run's Consolas came FROM `code`, so surfacing it as `font`
        // too would hand back a bigger model than went in — and the next write
        // would then differ from the one just read.
        if (isset($props['font'])) {
            if (in_array(strtolower($props['font']), self::MONO_FONTS, true)) {
                $props['code'] = true;
                unset($props['font']);
            }
        }

        // The InlineCode style's own shading is presentation, not a highlight.
        if (!empty($props['code']) && isset($props['highlight']) && strcasecmp($props['highlight'], '#F2F2F2') === 0) {
            unset($props['highlight']);
        }

        return $props;
    }

    /**
     * Merge adjacent runs with identical formatting and drop empties —
     * Word fragments runs freely (spell-check, edit history), the model
     * doesn't care.
     *
     * @param  list<array<string, mixed>>  $runs
     * @return list<array<string, mixed>>
     */
    private function mergeRuns(array $runs): array
    {
        $merged = [];
        foreach ($runs as $run) {
            if ($run['text'] === '') {
                continue;
            }
            $last = $merged !== [] ? count($merged) - 1 : null;
            if ($last !== null) {
                $a = $merged[$last];
                $b = $run;
                $aProps = $a;
                $bProps = $b;
                unset($aProps['text'], $bProps['text']);
                if ($aProps == $bProps) {
                    $merged[$last]['text'] .= $run['text'];

                    continue;
                }
            }
            $merged[] = $run;
        }

        return $merged;
    }

    // ─── Tables ──────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    /**
     * A table back into the model.
     *
     * Two things make this the hardest read in the package. First, the writer
     * emits borders, cell margins and a `w:tcW` for every cell whether or not
     * the model asked — so anything it would have produced anyway is NOT
     * surfaced, or every document written before these options existed would
     * come back carrying options nobody set. Second, the file contains the
     * `w:vMerge` continuation cells the writer synthesised, and they have to
     * be dropped and turned back into a `rowSpan` on the cell above.
     *
     * @return array<string, mixed>
     */
    private function parseTable(DOMElement $tbl, bool $insideQuote = false): array
    {
        $tblPr = $this->firstChildByName($tbl, 'tblPr');
        $table = ['type' => 'table'];

        $grid = [];
        $tblGrid = $this->firstChildByName($tbl, 'tblGrid');
        if ($tblGrid !== null) {
            foreach ($tblGrid->childNodes as $col) {
                if ($col instanceof DOMElement && $col->localName === 'gridCol') {
                    $grid[] = (int) ($this->wAttr($col, 'w') ?? 0);
                }
            }
        }

        if ($tblPr !== null) {
            $tblW = $this->firstChildByName($tblPr, 'tblW');
            if ($tblW !== null && $this->wAttr($tblW, 'type') === 'pct') {
                $w = $this->wAttr($tblW, 'w');
                if (is_numeric($w)) {
                    $pct = round(((float) $w) / 50, 2);
                    $table['width'] = $pct == (int) $pct ? (int) $pct : $pct;
                }
            }
            $jc = $this->firstChildByName($tblPr, 'jc');
            if ($jc !== null && in_array($this->wAttr($jc, 'val'), ['center', 'right'], true)) {
                $table['align'] = $this->wAttr($jc, 'val');
            }
            $borders = $this->parseBorders($this->firstChildByName($tblPr, 'tblBorders'), self::TABLE_EDGES);
            if ($borders !== null && !$this->isDefaultTableBorders($borders)) {
                $table['borders'] = $borders;
            }
            $padding = $this->parseSides($this->firstChildByName($tblPr, 'tblCellMar'));
            if ($padding !== null && !$this->isDefaultCellMargins($padding)) {
                $table['cellPadding'] = $padding;
            }
        }

        // Weights are only surfaced when the grid is NOT what an equal split
        // would have produced — compared against the split the writer
        // computes, not tested for exact equality, so a three-column table
        // whose width does not divide by three is still recognised as equal.
        $total = array_sum($grid);
        if ($grid !== [] && $total > 0 && $grid !== DocxWriter::splitColumnsFor($total, count($grid))) {
            $table['widths'] = array_map(
                static function (int $w) use ($total): float|int {
                    $pct = round(($w / $total) * 100, 2);

                    return $pct == (int) $pct ? (int) $pct : $pct;
                },
                $grid,
            );
        }

        // Pass 1: read every emitted cell, keeping its grid column and merge
        // state.
        $laid = [];
        $headers = [];
        foreach ($tbl->childNodes as $node) {
            if (!($node instanceof DOMElement) || $node->localName !== 'tr') {
                continue;
            }
            $trPr = $this->firstChildByName($node, 'trPr');
            $header = $trPr !== null && $this->firstChildByName($trPr, 'tblHeader') !== null;
            $headers[] = $header;

            $slots = [];
            $col = 0;
            foreach ($node->childNodes as $tc) {
                if (!($tc instanceof DOMElement) || $tc->localName !== 'tc') {
                    continue;
                }
                $tcPr = $this->firstChildByName($tc, 'tcPr');
                $span = 1;
                $vMerge = null;
                if ($tcPr !== null) {
                    $gridSpan = $this->firstChildByName($tcPr, 'gridSpan');
                    if ($gridSpan !== null && is_numeric($this->wAttr($gridSpan, 'val'))) {
                        $span = max(1, (int) $this->wAttr($gridSpan, 'val'));
                    }
                    $vMergeNode = $this->firstChildByName($tcPr, 'vMerge');
                    if ($vMergeNode !== null) {
                        $vMerge = $this->wAttr($vMergeNode, 'val') ?? 'continue';
                    }
                }

                $cell = null;
                if ($vMerge !== 'continue') {
                    $cell = ['blocks' => $this->parseBlockContainer($tc, false, $insideQuote)];
                    if ($tcPr !== null) {
                        $shd = $this->firstChildByName($tcPr, 'shd');
                        $fill = $shd !== null ? $this->wAttr($shd, 'fill') : null;
                        // A header row's grey came FROM `header`, so it is
                        // attributable and not surfaced. Any other fill is the
                        // author's.
                        if (is_string($fill) && preg_match('/^[0-9A-Fa-f]{6}$/', $fill) === 1
                            && !($header && strcasecmp($fill, self::HEADER_FILL) === 0)) {
                            $cell['shading'] = '#' . strtoupper($fill);
                        }
                        $cellBorders = $this->parseBorders($this->firstChildByName($tcPr, 'tcBorders'), self::BOX_EDGES);
                        if ($cellBorders !== null) {
                            $cell['borders'] = $cellBorders;
                        }
                        $cellPadding = $this->parseSides($this->firstChildByName($tcPr, 'tcMar'));
                        if ($cellPadding !== null) {
                            $cell['padding'] = $cellPadding;
                        }
                        $vAlign = $this->firstChildByName($tcPr, 'vAlign');
                        if ($vAlign !== null && in_array($this->wAttr($vAlign, 'val'), ['top', 'center', 'bottom'], true)) {
                            $cell['valign'] = $this->wAttr($vAlign, 'val');
                        }
                    }
                    if ($span > 1) {
                        $cell['colSpan'] = $span;
                    }
                }

                $slots[] = ['col' => $col, 'span' => $span, 'vMerge' => $vMerge, 'cell' => $cell];
                $col += $span;
            }
            $laid[] = $slots;
        }

        // Pass 2: fold each run of continuations back into the cell that
        // started it.
        foreach ($laid as $r => $line) {
            foreach ($line as $i => $slot) {
                if ($slot['vMerge'] !== 'restart' || $slot['cell'] === null) {
                    continue;
                }
                $covered = 1;
                for ($below = $r + 1; $below < count($laid); $below++) {
                    $found = false;
                    foreach ($laid[$below] as $candidate) {
                        if ($candidate['col'] === $slot['col'] && $candidate['vMerge'] === 'continue') {
                            $found = true;

                            break;
                        }
                    }
                    if (!$found) {
                        break;
                    }
                    $covered++;
                }
                if ($covered > 1) {
                    $laid[$r][$i]['cell']['rowSpan'] = $covered;
                }
            }
        }

        $rows = [];
        foreach ($laid as $r => $line) {
            $cells = [];
            foreach ($line as $slot) {
                if ($slot['cell'] !== null) {
                    $cells[] = $slot['cell'];
                }
            }
            $row = [];
            if ($headers[$r]) {
                $row['header'] = true;
            }
            $row['cells'] = $cells;
            $rows[] = $row;
        }

        $table['rows'] = $rows;

        return $table;
    }

    /**
     * Is this exactly what the writer emits for a table that asked for
     * nothing? Not a tolerance and not a heuristic: the writer's defaults are
     * a fixed set, and anything differing by one edge or one twip is the
     * author's and must survive the read.
     *
     * @param  array<string, mixed>  $borders
     */
    private function isDefaultTableBorders(array $borders): bool
    {
        if (count($borders) !== count(self::TABLE_EDGES)) {
            return false;
        }
        foreach (self::TABLE_EDGES as $edge) {
            // The default edge is single / 0.5pt / auto, which reads back as
            // width alone — style and colour are both the omitted default.
            if (($borders[$edge] ?? null) !== ['width' => 0.5]) {
                return false;
            }
        }

        return true;
    }

    /** @param  array<string, float|int>  $sides */
    private function isDefaultCellMargins(array $sides): bool
    {
        return $sides == ['top' => 3, 'left' => 5.4, 'bottom' => 3, 'right' => 5.4];
    }

    // ─── Images ──────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>|null
     */
    private function parseDrawing(DOMElement $drawing): ?array
    {
        $blip = $this->firstDescendantByName($drawing, 'blip');
        if ($blip === null) {
            return null;
        }
        $rId = $this->rAttr($blip, 'embed') ?? $this->rAttr($blip, 'link');
        $target = $rId !== null ? ($this->rels[$rId]['target'] ?? null) : null;
        if ($target === null) {
            return null;
        }
        $target = ltrim($target, '/');
        if (str_starts_with($target, 'word/')) {
            $target = substr($target, strlen('word/'));
        }
        $bytes = $this->media[$target] ?? null;
        if ($bytes === null) {
            return null;
        }

        $ext = strtolower((string) pathinfo($target, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            default => null,
        };
        if ($mime === null) {
            return null; // gif/emf/… — outside the model, degrade by dropping
        }

        $image = ['type' => 'image', 'src' => "data:{$mime};base64," . base64_encode($bytes)];

        $extent = $this->firstDescendantByName($drawing, 'extent');
        if ($extent !== null) {
            $cx = (int) $extent->getAttribute('cx');
            $cy = (int) $extent->getAttribute('cy');
            if ($cx > 0) {
                $image['widthPx'] = (int) round($cx / 9525);
            }
            if ($cy > 0) {
                $image['heightPx'] = (int) round($cy / 9525);
            }
        }

        $docPr = $this->firstDescendantByName($drawing, 'docPr');
        $descr = $docPr?->getAttribute('descr');
        if (is_string($descr) && $descr !== '') {
            $image['alt'] = $descr;
        }

        return $image;
    }

    // ─── Lists ───────────────────────────────────────────────────────────

    /**
     * Assemble a flat run of numbered paragraphs into the nested list model.
     *
     * @param  list<array{ilvl: int, ordered: bool, runs: list<array<string, mixed>>}>  $entries
     * @return array<string, mixed>
     */
    private function assembleList(array $entries): array
    {
        $block = ['type' => 'list'];
        if ($entries[0]['ordered']) {
            $block['ordered'] = true;
        }
        $block['items'] = [];

        // Stack of references into the growing tree, one per depth.
        $items = &$block['items'];
        $stack = [&$items];

        foreach ($entries as $entry) {
            $depth = min($entry['ilvl'], count($stack)); // clamp level jumps
            while (count($stack) - 1 > $depth) {
                array_pop($stack);
            }
            $parent = &$stack[count($stack) - 1];
            if ($depth > count($stack) - 1) {
                // Deeper than current: attach to the last item's children.
                if ($parent === []) {
                    $depth = count($stack) - 1; // no parent item yet — clamp
                } else {
                    $lastIndex = count($parent) - 1;
                    if (!isset($parent[$lastIndex]['children'])) {
                        $parent[$lastIndex]['children'] = [];
                    }
                    $stack[] = &$parent[$lastIndex]['children'];
                    $parent = &$stack[count($stack) - 1];
                }
            }
            $parent[] = ['runs' => $entry['runs']];
            unset($parent);
        }

        return $block;
    }

    // ─── DOM helpers ─────────────────────────────────────────────────────

    private function firstChildByName(?DOMElement $parent, string $localName): ?DOMElement
    {
        if ($parent === null) {
            return null;
        }
        foreach ($parent->childNodes as $node) {
            if ($node instanceof DOMElement && $node->localName === $localName) {
                return $node;
            }
        }

        return null;
    }

    private function firstDescendantByName(DOMElement $parent, string $localName): ?DOMElement
    {
        foreach ($parent->childNodes as $node) {
            if (!($node instanceof DOMNode) || !($node instanceof DOMElement)) {
                continue;
            }
            if ($node->localName === $localName) {
                return $node;
            }
            $found = $this->firstDescendantByName($node, $localName);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /** Read a w:-namespaced (or namespace-less) attribute by local name. */
    private function wAttr(DOMElement $el, string $name): ?string
    {
        foreach ($el->attributes as $attr) {
            if ($attr->localName === $name) {
                return $attr->value;
            }
        }

        return null;
    }

    /** Read an r:-namespaced attribute (id/embed/link) by local name. */
    private function rAttr(DOMElement $el, string $name): ?string
    {
        return $this->wAttr($el, $name);
    }

    /** True unless the toggle attribute explicitly disables the property. */
    private function toggleOn(?string $val): bool
    {
        return $val === null || !in_array(strtolower($val), ['0', 'false', 'none', 'off'], true);
    }

    /**
     * @param  list<array<string, mixed>>  $runs
     */
    private function plainText(array $runs): string
    {
        return implode('', array_map(static fn (array $r): string => (string) ($r['text'] ?? ''), $runs));
    }
}
