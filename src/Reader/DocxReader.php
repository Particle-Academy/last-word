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
        $doc['blocks'] = $blocks;

        return $doc;
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
                $blocks[] = ['type' => 'heading', 'level' => $level, 'runs' => $p['runs']];
            } elseif ($hasText || ($p['runs'] !== [] && $p['images'] === [])) {
                $para = ['type' => 'paragraph', 'runs' => $p['runs']];
                if ($p['align'] !== null) {
                    $para['align'] = $p['align'];
                }
                $blocks[] = $para;
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
            'outlineLvl' => $outlineLvl,
            'numPr' => $numPr,
            'bottomBorder' => $bottomBorder,
            'pageBreak' => $state['pageBreak'],
            'codeLanguage' => $codeLanguage,
            'runs' => $this->mergeRuns($runs),
            'images' => $state['images'],
        ];
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
        foreach (['bold', 'italic', 'underline', 'strike', 'code'] as $flag) {
            if (!empty($props[$flag])) {
                $run[$flag] = true;
            }
        }
        if ($link !== null && $link !== '') {
            $run['link'] = $link;
        }
        if (isset($props['color'])) {
            $run['color'] = $props['color'];
        }
        if (isset($props['highlight'])) {
            $run['highlight'] = $props['highlight'];
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
                default:
                    break;
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
    private function parseTable(DOMElement $tbl, bool $insideQuote = false): array
    {
        $rows = [];
        foreach ($tbl->childNodes as $node) {
            if (!($node instanceof DOMElement) || $node->localName !== 'tr') {
                continue;
            }
            $header = false;
            $trPr = $this->firstChildByName($node, 'trPr');
            if ($trPr !== null && $this->firstChildByName($trPr, 'tblHeader') !== null) {
                $header = true;
            }
            $cells = [];
            foreach ($node->childNodes as $tc) {
                if ($tc instanceof DOMElement && $tc->localName === 'tc') {
                    $cellBlocks = $this->parseBlockContainer($tc, false, $insideQuote);
                    // The writer pads cells/tables with empty paragraphs to
                    // satisfy OOXML; parseBlockContainer already drops
                    // content-free paragraphs, so this is what remains.
                    $cells[] = ['blocks' => $cellBlocks];
                }
            }
            $row = [];
            if ($header) {
                $row['header'] = true;
            }
            $row['cells'] = $cells;
            $rows[] = $row;
        }

        return ['type' => 'table', 'rows' => $rows];
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
