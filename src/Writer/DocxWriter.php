<?php

declare(strict_types=1);

namespace LastWord\Writer;

use LastWord\Helpers\ImageSize;
use LastWord\Helpers\Xml;
use RuntimeException;
use ZipArchive;

/**
 * DOCX (Office Open XML / WordprocessingML) writer. Takes a Doc model and
 * produces a `.docx` file Word / Pages / Google Docs / LibreOffice Writer
 * can open.
 *
 * The DOCX format is a zip archive of XML parts following ECMA-376. This
 * writer ships the minimal viable set, in a FIXED entry order:
 *
 *   [Content_Types].xml
 *   _rels/.rels
 *   docProps/core.xml      (dc:title — only when the doc has a title)
 *   word/document.xml
 *   word/styles.xml        (Normal, Title, Heading1-6, Quote, CodeBlock,
 *                           ListParagraph + InlineCode / Hyperlink char styles)
 *   word/numbering.xml     (bullet + decimal abstract numbering, 6 indent
 *                           levels; one fresh instance per ordered list so
 *                           numbering restarts)
 *   word/_rels/document.xml.rels  (styles, numbering, hyperlinks, images)
 *   word/media/imageN.png|jpeg    (decoded data-URL images)
 *
 * Determinism: no timestamps anywhere in the XML, fixed entry order, and
 * every zip entry's mtime pinned via ZipArchive::setMtimeIndex() — calling
 * toBytes() twice on the same doc yields identical bytes.
 *
 * Cross-language parity: the metadata slots match the Node mirror
 * (@particle-academy/last-word) byte-for-byte — the title lives in
 * docProps/core.xml (dc:title) and code/quote blocks are wrapped in w:sdt
 * content controls tagged `lastword:code[:{lang}]` / `lastword:quote`, so a
 * file written by either engine reads identically in the other. (Before
 * 0.2.0 this writer used a Title-styled paragraph and an invisible
 * `LastWordCode_{lang}` bookmark; the reader still accepts both.)
 *
 * Block → OOXML mapping:
 *   heading    — w:p with pStyle Heading{n}
 *   paragraph  — w:p (+ w:jc for align)
 *   list       — w:p per item with numPr (ilvl per nesting depth)
 *   table      — w:tbl with grid; header rows get w:tblHeader + shading +
 *                forced-bold runs
 *   code       — w:sdt tagged `lastword:code[:{lang}]` wrapping one
 *                CodeBlock-styled w:p per line
 *   quote      — w:sdt tagged `lastword:quote` wrapping Quote-styled
 *                paragraphs
 *   image      — w:drawing inline; extents from widthPx/heightPx or sniffed
 *                from the bytes (PNG IHDR / JPEG SOF), capped at 6.5in width
 *   pageBreak  — w:br w:type="page"
 *   hr         — empty paragraph with a bottom border
 */
final class DocxWriter
{
    private const NS_W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    private const NS_R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    private const NS_WP = 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing';
    private const NS_A = 'http://schemas.openxmlformats.org/drawingml/2006/main';
    private const NS_PIC = 'http://schemas.openxmlformats.org/drawingml/2006/picture';

    /** EMU per pixel at 96dpi. */
    private const EMU_PER_PX = 9525;

    /** Max image width: 6.5in (letter width minus 1in margins) in EMU. */
    private const MAX_IMAGE_WIDTH_EMU = 5943600;

    /**
     * Fixed mtime (1980-01-02 UTC) stamped on every zip entry so archive
     * bytes are deterministic. The XML content itself carries no timestamps.
     */
    private const FIXED_MTIME = 315619200;

    /** Shaded fill for header cells + code blocks (hex, no #). */
    private const HEADER_FILL = 'E7E7E7';

    /**
     * SDT tag prefixes used to round-trip block metadata that OOXML has no
     * slot for. Shared verbatim with the Node mirror (`SDT_TAG_CODE` /
     * `SDT_TAG_QUOTE` in @particle-academy/last-word).
     */
    public const SDT_TAG_CODE = 'lastword:code';

    public const SDT_TAG_QUOTE = 'lastword:quote';

    /** Relationships beyond styles(rId1) + numbering(rId2), keyed by rId. */
    private array $rels = [];

    /** Media files queued for the archive, keyed by archive path. */
    private array $mediaFiles = [];

    private int $relCounter = 2;

    private int $imageCounter = 0;

    /** Number of ordered-list numbering instances allocated (numIds 2..N+1). */
    private int $orderedListCount = 0;

    /**
     * Twips of horizontal space between the page margins, set from the doc's
     * `page` block before any block renders. Table grids are laid out against
     * it.
     */
    private int $contentWidth = 9360;

    /** Page sizes in twips (1/1440in), portrait. */
    private const PAGE_SIZES = [
        'letter' => [12240, 15840],
        'legal' => [12240, 20160],
        'a4' => [11906, 16838],
    ];

    /**
     * Cell margins every table gets unless it says otherwise, in POINTS —
     * 3 / 5.4 / 3 / 5.4, which is 60 / 108 / 60 / 108 twips. 108 is the value
     * Word itself uses for default side margins, which is why it reads as an
     * odd number rather than a round one.
     */
    private const DEFAULT_CELL_MARGINS_PT = ['top' => 3, 'left' => 5.4, 'bottom' => 3, 'right' => 5.4];

    /** The border every table edge gets unless it says otherwise. */
    private const DEFAULT_BORDER = ['style' => 'single', 'sz' => 4, 'color' => 'auto'];

    /**
     * @param  string|null  $tempDir  Override the temp dir used while ZipArchive
     *                                assembles the archive. Defaults to
     *                                {@see sys_get_temp_dir()}; callers running in
     *                                sandboxes where that path isn't writable can
     *                                pass their own.
     */
    public function __construct(
        private ?string $tempDir = null,
    ) {
    }

    /**
     * Write a document to disk.
     *
     * @param  array<string, mixed>  $doc
     * @return array{path: string, bytes: int, blocks: int}
     */
    public function write(array $doc, string $path): array
    {
        $bytes = $this->toBytes($doc);
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new RuntimeException("Could not create directory: {$dir}");
        }
        $ok = file_put_contents($path, $bytes);
        if ($ok === false) {
            throw new RuntimeException("Could not write file: {$path}");
        }

        return [
            'path' => $path,
            'bytes' => strlen($bytes),
            'blocks' => count($doc['blocks'] ?? []),
        ];
    }

    /**
     * Build the DOCX archive and return its bytes.
     *
     * @param  array<string, mixed>  $doc
     */
    public function toBytes(array $doc): string
    {
        $this->rels = [];
        $this->mediaFiles = [];
        $this->relCounter = 2;
        $this->imageCounter = 0;
        $this->orderedListCount = 0;

        $hasTitle = array_key_exists('title', $doc);

        // Build document.xml first — it registers hyperlink/image rels and
        // media files, and counts the ordered-list numbering instances the
        // numbering part must declare.
        $documentXml = $this->buildDocumentXml($doc);

        // ZipArchive needs a real path; assemble in a dedicated per-call
        // subdirectory (same pattern as dark-slide) so its internal scratch
        // file has a clean place to live.
        $base = rtrim($this->tempDir ?? sys_get_temp_dir(), DIRECTORY_SEPARATOR);
        $tmpDir = $base . DIRECTORY_SEPARATOR . 'last-word-' . bin2hex(random_bytes(8));
        if (!is_dir($tmpDir) && !@mkdir($tmpDir, 0700, true) && !is_dir($tmpDir)) {
            throw new RuntimeException("Could not allocate temp dir for DOCX archive at: {$tmpDir}. Override the temp directory by passing it to the DocxWriter constructor.");
        }
        $tmp = $tmpDir . DIRECTORY_SEPARATOR . 'document.docx';

        try {
            $zip = new ZipArchive();
            if ($zip->open($tmp, ZipArchive::OVERWRITE | ZipArchive::CREATE) !== true) {
                throw new RuntimeException('Could not open zip archive for writing.');
            }

            // Fixed entry order — part of the determinism contract.
            $zip->addFromString('[Content_Types].xml', $this->buildContentTypes($hasTitle));
            $zip->addFromString('_rels/.rels', $this->buildTopRels($hasTitle));
            if ($hasTitle) {
                $zip->addFromString('docProps/core.xml', $this->buildCoreXml((string) $doc['title']));
            }
            $zip->addFromString('word/document.xml', $documentXml);
            $zip->addFromString('word/styles.xml', $this->buildStyles($doc));
            $zip->addFromString('word/numbering.xml', $this->buildNumbering());
            $zip->addFromString('word/_rels/document.xml.rels', $this->buildDocumentRels());
            foreach ($this->mediaFiles as $archivePath => $bytes) {
                $zip->addFromString($archivePath, $bytes);
            }

            // Pin every entry's mtime so the archive bytes are reproducible.
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $zip->setMtimeIndex($i, self::FIXED_MTIME);
            }

            $zip->close();

            $contents = file_get_contents($tmp);
            if ($contents === false) {
                throw new RuntimeException('Could not read back the assembled DOCX archive.');
            }

            return $contents;
        } finally {
            @unlink($tmp);
            @rmdir($tmpDir);
        }
    }

    // ─── Parts ───────────────────────────────────────────────────────────

    private function buildContentTypes(bool $hasTitle): string
    {
        $xml = Xml::declaration();
        $xml .= '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">';
        $xml .= '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>';
        $xml .= '<Default Extension="xml" ContentType="application/xml"/>';
        $xml .= '<Default Extension="png" ContentType="image/png"/>';
        $xml .= '<Default Extension="jpeg" ContentType="image/jpeg"/>';
        $xml .= '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>';
        $xml .= '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>';
        $xml .= '<Override PartName="/word/numbering.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.numbering+xml"/>';
        if ($hasTitle) {
            $xml .= '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>';
        }
        $xml .= '</Types>';

        return $xml;
    }

    private function buildTopRels(bool $hasTitle): string
    {
        $xml = Xml::declaration();
        $xml .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        $xml .= '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>';
        if ($hasTitle) {
            $xml .= '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>';
        }
        $xml .= '</Relationships>';

        return $xml;
    }

    /**
     * docProps/core.xml carrying dc:title — the cross-language title slot.
     * Byte-for-byte identical to the Node mirror's coreXml() output so the
     * canonical fixtures match across engines. Deterministic: no dcterms
     * created/modified timestamps.
     */
    private function buildCoreXml(string $title): string
    {
        return Xml::declaration()
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
            . 'xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" '
            . 'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:title>' . Xml::text($title) . '</dc:title>'
            . '</cp:coreProperties>';
    }

    private function buildDocumentRels(): string
    {
        $xml = Xml::declaration();
        $xml .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        $xml .= '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        $xml .= '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/numbering" Target="numbering.xml"/>';
        foreach ($this->rels as $rId => $rel) {
            if ($rel['type'] === 'hyperlink') {
                $xml .= '<Relationship Id="' . $rId . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink" Target="' . Xml::attr($rel['target']) . '" TargetMode="External"/>';
            } else {
                $xml .= '<Relationship Id="' . $rId . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="' . Xml::attr($rel['target']) . '"/>';
            }
        }
        $xml .= '</Relationships>';

        return $xml;
    }

    /**
     * @param  array<string, mixed>  $doc
     */
    private function buildDocumentXml(array $doc): string
    {
        $page = self::pageGeometry($doc['page'] ?? null);
        // Table grids are laid out against the section's content width, so it
        // has to be known before any block renders. Carrying 9360 as a literal
        // — which all three engines did — silently gives a document with
        // narrowed margins a table that no longer matches its own page.
        $this->contentWidth = $page['w'] - $page['left'] - $page['right'];

        // The title lives in docProps/core.xml (dc:title) — the cross-language
        // slot shared with the Node mirror — not in a body paragraph.
        $body = $this->renderBlocks($doc['blocks'] ?? []);

        $orient = $page['orientation'] === 'landscape' ? ' w:orient="landscape"' : '';
        $body .= '<w:sectPr>'
            . '<w:pgSz w:w="' . $page['w'] . '" w:h="' . $page['h'] . '"' . $orient . '/>'
            . '<w:pgMar w:top="' . $page['top'] . '" w:right="' . $page['right'] . '"'
            . ' w:bottom="' . $page['bottom'] . '" w:left="' . $page['left'] . '"'
            . ' w:header="720" w:footer="720" w:gutter="0"/>'
            . '</w:sectPr>';

        return Xml::declaration()
            . '<w:document'
            . ' xmlns:w="' . self::NS_W . '"'
            . ' xmlns:r="' . self::NS_R . '"'
            . ' xmlns:wp="' . self::NS_WP . '"'
            . ' xmlns:a="' . self::NS_A . '"'
            . ' xmlns:pic="' . self::NS_PIC . '">'
            . '<w:body>' . $body . '</w:body>'
            . '</w:document>';
    }

    // ─── Blocks ──────────────────────────────────────────────────────────

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @param  string|null  $paragraphStyle  Style override for plain paragraphs
     *                                       (used by quote rendering).
     */
    private function renderBlocks(array $blocks, ?string $paragraphStyle = null): string
    {
        $xml = '';
        $prevWasTable = false;
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            $type = $block['type'] ?? null;
            // OOXML merges adjacent tables — pad with an empty paragraph
            // (both readers recognise and drop content-free paragraphs).
            if ($type === 'table' && $prevWasTable) {
                $xml .= '<w:p/>';
            }
            $xml .= match ($type) {
                'heading' => $this->renderHeading($block),
                'paragraph' => $this->renderParagraph($block, $paragraphStyle),
                'list' => $this->renderList($block),
                'table' => $this->renderTable($block),
                'code' => $this->renderCode($block),
                'quote' => $this->renderQuote($block),
                'image' => $this->renderImage($block),
                'pageBreak' => '<w:p><w:r><w:br w:type="page"/></w:r></w:p>',
                'hr' => '<w:p><w:pPr><w:pBdr><w:bottom w:val="single" w:sz="6" w:space="1" w:color="auto"/></w:pBdr></w:pPr></w:p>',
                default => '',
            };
            $prevWasTable = $type === 'table';
        }

        return $xml;
    }

    /** @param  array<string, mixed>  $block */
    private function renderHeading(array $block): string
    {
        $level = max(1, min(6, (int) ($block['level'] ?? 1)));

        // A heading is a paragraph and takes the same properties. Without
        // that, a section label that needed spacing or alignment had to be a
        // bold paragraph impersonating a heading — and so appeared in no
        // navigation pane and no table of contents.
        return '<w:p>' . self::pPrXml($block, 'Heading' . $level)
            . $this->renderRuns($block['runs'] ?? [])
            . '</w:p>';
    }

    /** @param  array<string, mixed>  $block */
    private function renderParagraph(array $block, ?string $styleOverride = null): string
    {
        return '<w:p>' . self::pPrXml($block, $styleOverride)
            . $this->renderRuns($block['runs'] ?? [])
            . '</w:p>';
    }

    /** @param  array<string, mixed>  $block */
    private function renderList(array $block): string
    {
        $ordered = (bool) ($block['ordered'] ?? false);
        // Bullets share numbering instance 1; every ordered list gets a fresh
        // instance so its numbering restarts at 1.
        $numId = $ordered ? 2 + $this->orderedListCount++ : 1;

        return $this->renderListItems($block['items'] ?? [], $numId, 0);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function renderListItems(array $items, int $numId, int $ilvl): string
    {
        $xml = '';
        $lvl = min($ilvl, 5);
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $xml .= '<w:p><w:pPr><w:pStyle w:val="ListParagraph"/>'
                . '<w:numPr><w:ilvl w:val="' . $lvl . '"/><w:numId w:val="' . $numId . '"/></w:numPr>'
                . '</w:pPr>'
                . $this->renderRuns($item['runs'] ?? [])
                . '</w:p>';
            if (!empty($item['children']) && is_array($item['children'])) {
                $xml .= $this->renderListItems($item['children'], $numId, $ilvl + 1);
            }
        }

        return $xml;
    }

    /**
     * Lay a table's authored rows onto a grid, resolving both merge
     * directions.
     *
     * The author writes cells HTML-style: a `rowSpan` cell appears ONCE, and
     * the rows it covers list only their own remaining cells. OOXML has no
     * such shorthand — every row must carry a cell for every grid column, and
     * a row that is short is a malformed table Word repairs by shifting
     * everything left. So the covered rows get a synthesised `w:vMerge`
     * continuation here.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{0: list<list<array<string, mixed>>>, 1: int}
     *         The laid-out rows, and the column count they need.
     */
    private static function layoutRows(array $rows): array
    {
        /** @var array<int, array{rows: int, span: int, shading: mixed}> $pending */
        $pending = [];
        $out = [];
        $colCount = 1;

        foreach ($rows as $row) {
            $authored = array_values(array_filter($row['cells'] ?? [], 'is_array'));
            $line = [];
            $col = 0;
            $next = 0;

            while (true) {
                if (isset($pending[$col]) && $pending[$col]['rows'] > 0) {
                    // A continuation carries the origin's shading and nothing
                    // else: without the fill the merged block renders striped,
                    // and with the origin's borders it would draw a rule
                    // straight through its own middle.
                    $line[] = [
                        'cell' => ['blocks' => []],
                        'span' => $pending[$col]['span'],
                        'vMerge' => 'continue',
                        'shading' => $pending[$col]['shading'],
                    ];
                    $pending[$col]['rows']--;
                    $col += $pending[$col]['span'];

                    continue;
                }

                if ($next < count($authored)) {
                    $cell = $authored[$next++];
                    $span = max(1, (int) ($cell['colSpan'] ?? 1));
                    $rowSpan = max(1, (int) ($cell['rowSpan'] ?? 1));
                    $line[] = [
                        'cell' => $cell,
                        'span' => $span,
                        'vMerge' => $rowSpan > 1 ? 'restart' : null,
                        'shading' => $cell['shading'] ?? null,
                    ];
                    if ($rowSpan > 1) {
                        $pending[$col] = [
                            'rows' => $rowSpan - 1,
                            'span' => $span,
                            'shading' => $cell['shading'] ?? null,
                        ];
                    }
                    $col += $span;

                    continue;
                }

                // Nothing authored left — but a merge started further right
                // still owes this row a continuation.
                $ahead = null;
                foreach ($pending as $at => $p) {
                    if ($at > $col && $p['rows'] > 0 && ($ahead === null || $at < $ahead)) {
                        $ahead = $at;
                    }
                }
                if ($ahead === null) {
                    break;
                }
                $col = $ahead;
            }

            $colCount = max($colCount, $col);
            $out[] = $line;
        }

        return [$out, $colCount];
    }

    /** @param  array<string, mixed>  $block */
    private function renderTable(array $block): string
    {
        $rows = array_values(array_filter($block['rows'] ?? [], 'is_array'));
        if ($rows === []) {
            return '';
        }

        [$laid, $colCount] = self::layoutRows($rows);

        // A table narrower than the text column narrows its grid too: emitting
        // w:tblW pct while leaving the grid at full width hands Word two
        // contradictory answers.
        $tableWidth = $this->contentWidth;
        $tblW = '<w:tblW w:w="0" w:type="auto"/>';
        if (is_numeric($block['width'] ?? null) && (float) $block['width'] > 0) {
            $pct = min(100.0, (float) $block['width']);
            $tableWidth = (int) round($this->contentWidth * ($pct / 100));
            $tblW = '<w:tblW w:w="' . (int) round($pct * 50) . '" w:type="pct"/>';
        }

        $weights = null;
        if (is_array($block['widths'] ?? null) && count($block['widths']) === $colCount) {
            $weights = array_map(static fn ($w) => is_numeric($w) ? (float) $w : 0.0, array_values($block['widths']));
        }
        $grid = self::splitColumns($tableWidth, $colCount, $weights);

        $tblPr = $tblW;
        $align = $block['align'] ?? null;
        if (is_string($align) && in_array($align, ['center', 'right'], true)) {
            $tblPr .= '<w:jc w:val="' . $align . '"/>';
        }
        $borders = is_array($block['borders'] ?? null) ? $block['borders'] : null;
        $tblPr .= self::bordersXml(
            'tblBorders',
            $borders ?? array_fill_keys(['top', 'left', 'bottom', 'right', 'insideH', 'insideV'], self::DEFAULT_BORDER),
            ['top', 'left', 'bottom', 'right', 'insideH', 'insideV'],
        );
        if ($weights !== null) {
            // Without w:tblLayout fixed, Word re-fits columns to their content
            // and the requested proportions are advisory.
            $tblPr .= '<w:tblLayout w:type="fixed"/>';
        }
        $padding = is_array($block['cellPadding'] ?? null) ? $block['cellPadding'] : self::DEFAULT_CELL_MARGINS_PT;
        $tblPr .= self::marginsXml('tblCellMar', $padding);

        $xml = '<w:tbl><w:tblPr>' . $tblPr . '</w:tblPr><w:tblGrid>';
        foreach ($grid as $w) {
            $xml .= '<w:gridCol w:w="' . $w . '"/>';
        }
        $xml .= '</w:tblGrid>';

        foreach ($laid as $r => $line) {
            $isHeader = (bool) ($rows[$r]['header'] ?? false);
            $xml .= '<w:tr>';
            if ($isHeader) {
                $xml .= '<w:trPr><w:tblHeader/></w:trPr>';
            }
            $col = 0;
            foreach ($line as $slot) {
                $span = $slot['span'];
                $width = 0;
                for ($i = $col; $i < min($col + $span, count($grid)); $i++) {
                    $width += $grid[$i];
                }
                $col += $span;
                $xml .= '<w:tc>' . $this->tcPrXml($slot, $span, $width, $isHeader) . '</w:tc>';
            }
            $xml .= '</w:tr>';
        }

        return $xml . '</w:tbl>';
    }

    /**
     * Cell properties, in CT_TcPr order:
     * tcW, gridSpan, vMerge, tcBorders, shd, tcMar, vAlign — followed by the
     * cell's content, which every cell must end with a w:p of.
     *
     * @param  array<string, mixed>  $slot
     */
    private function tcPrXml(array $slot, int $span, int $width, bool $isHeader): string
    {
        /** @var array<string, mixed> $cell */
        $cell = $slot['cell'];
        $continuation = $slot['vMerge'] === 'continue';

        $tcPr = '<w:tcW w:w="' . $width . '" w:type="dxa"/>';
        if ($span > 1) {
            $tcPr .= '<w:gridSpan w:val="' . $span . '"/>';
        }
        if ($slot['vMerge'] === 'restart') {
            $tcPr .= '<w:vMerge w:val="restart"/>';
        } elseif ($continuation) {
            $tcPr .= '<w:vMerge/>';
        }

        if (!$continuation && is_array($cell['borders'] ?? null)) {
            $tcPr .= self::bordersXml('tcBorders', $cell['borders'], ['top', 'left', 'bottom', 'right']);
        }

        $shading = $slot['shading'] ?? null;
        if ($shading === null && $isHeader && !$continuation) {
            $shading = '#' . self::HEADER_FILL;
        }
        $tcPr .= self::shadingXml($shading);

        if (!$continuation && is_array($cell['padding'] ?? null)) {
            $tcPr .= self::marginsXml('tcMar', $cell['padding']);
        }
        if (!$continuation && is_string($cell['valign'] ?? null)
            && in_array($cell['valign'], ['top', 'center', 'bottom'], true)) {
            $tcPr .= '<w:vAlign w:val="' . $cell['valign'] . '"/>';
        }

        return '<w:tcPr>' . $tcPr . '</w:tcPr>'
            . $this->renderCellBlocks($cell['blocks'] ?? [], $isHeader && !$continuation);
    }

    /**
     * Render cell content; every cell must end with a w:p per OOXML.
     * Header cells force bold on their runs.
     *
     * @param  list<array<string, mixed>>  $blocks
     */
    private function renderCellBlocks(array $blocks, bool $forceBold): string
    {
        if ($forceBold) {
            $blocks = array_map(function ($block) {
                if (is_array($block) && in_array($block['type'] ?? null, ['paragraph', 'heading'], true)) {
                    $block['runs'] = array_map(
                        fn ($run) => is_array($run) ? array_merge($run, ['bold' => true]) : $run,
                        $block['runs'] ?? [],
                    );
                }

                return $block;
            }, $blocks);
        }

        $xml = $this->renderBlocks($blocks);
        if ($xml === '' || !str_ends_with($xml, '</w:p>')) {
            $xml .= '<w:p/>';
        }

        return $xml;
    }

    /** @param  array<string, mixed>  $block */
    private function renderCode(array $block): string
    {
        $lines = explode("\n", str_replace("\r\n", "\n", (string) ($block['text'] ?? '')));
        $language = $block['language'] ?? null;

        // The model's `language` has no native WordprocessingML slot; carry it
        // in the w:sdt content control's tag — the canonical cross-language
        // slot (survives Word edits; same shape as the Node mirror). The
        // pre-0.2.0 `LastWordCode_{lang}` bookmark is still read for
        // back-compat but no longer written.
        $tag = is_string($language) && $language !== ''
            ? self::SDT_TAG_CODE . ':' . $language
            : self::SDT_TAG_CODE;

        $body = '';
        foreach ($lines as $line) {
            $run = $line === '' ? '' : '<w:r><w:t xml:space="preserve">' . Xml::text($line) . '</w:t></w:r>';
            $body .= '<w:p><w:pPr><w:pStyle w:val="CodeBlock"/></w:pPr>' . $run . '</w:p>';
        }

        return '<w:sdt><w:sdtPr><w:alias w:val="Code"/><w:tag w:val="' . Xml::attr($tag) . '"/></w:sdtPr>'
            . '<w:sdtContent>' . $body . '</w:sdtContent></w:sdt>';
    }

    /** @param  array<string, mixed>  $block */
    private function renderQuote(array $block): string
    {
        $body = $this->renderBlocks($block['blocks'] ?? [], 'Quote');

        return '<w:sdt><w:sdtPr><w:alias w:val="Quote"/><w:tag w:val="' . self::SDT_TAG_QUOTE . '"/></w:sdtPr>'
            . '<w:sdtContent>' . ($body === '' ? '<w:p/>' : $body) . '</w:sdtContent></w:sdt>';
    }

    /** @param  array<string, mixed>  $block */
    private function renderImage(array $block): string
    {
        $parsed = $this->parseDataUrl((string) ($block['src'] ?? ''));
        if ($parsed === null) {
            // Unusable src — degrade to the alt text so nothing is lost silently.
            $alt = (string) ($block['alt'] ?? 'image');

            return '<w:p><w:r><w:t xml:space="preserve">' . Xml::text("[image: {$alt}]") . '</w:t></w:r></w:p>';
        }
        [$ext, $bytes] = $parsed;

        $n = ++$this->imageCounter;
        $mediaPath = "word/media/image{$n}.{$ext}";
        $this->mediaFiles[$mediaPath] = $bytes;

        $rId = 'rId' . ++$this->relCounter;
        $this->rels[$rId] = ['type' => 'image', 'target' => "media/image{$n}.{$ext}"];

        [$cx, $cy] = $this->computeExtents($block, $bytes);

        $alt = (string) ($block['alt'] ?? '');
        $descr = $alt !== '' ? ' descr="' . Xml::attr($alt) . '"' : '';
        $name = "Picture {$n}";

        return '<w:p><w:r><w:drawing>'
            . '<wp:inline distT="0" distB="0" distL="0" distR="0">'
            . '<wp:extent cx="' . $cx . '" cy="' . $cy . '"/>'
            . '<wp:effectExtent l="0" t="0" r="0" b="0"/>'
            . '<wp:docPr id="' . $n . '" name="' . $name . '"' . $descr . '/>'
            . '<wp:cNvGraphicFramePr><a:graphicFrameLocks noChangeAspect="1"/></wp:cNvGraphicFramePr>'
            . '<a:graphic><a:graphicData uri="' . self::NS_PIC . '">'
            . '<pic:pic>'
            . '<pic:nvPicPr><pic:cNvPr id="' . $n . '" name="' . $name . '"' . $descr . '/><pic:cNvPicPr/></pic:nvPicPr>'
            . '<pic:blipFill><a:blip r:embed="' . $rId . '"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
            . '<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm>'
            . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr>'
            . '</pic:pic>'
            . '</a:graphicData></a:graphic>'
            . '</wp:inline>'
            . '</w:drawing></w:r></w:p>';
    }

    /**
     * @return array{0: string, 1: string}|null [extension, bytes]
     */
    private function parseDataUrl(string $src): ?array
    {
        if (preg_match('#^data:image/(png|jpe?g);base64,(.+)$#s', $src, $m) !== 1) {
            return null;
        }
        $bytes = base64_decode($m[2], true);
        if ($bytes === false || $bytes === '') {
            return null;
        }
        $ext = $m[1] === 'png' ? 'png' : 'jpeg';

        return [$ext, $bytes];
    }

    /**
     * Compute the drawing extents in EMU: explicit widthPx/heightPx wins,
     * one-sided values keep the sniffed aspect, otherwise intrinsic pixel
     * dimensions sniffed from the bytes. Width capped at 6.5in.
     *
     * @param  array<string, mixed>  $block
     * @return array{0: int, 1: int}
     */
    private function computeExtents(array $block, string $bytes): array
    {
        $w = isset($block['widthPx']) && is_numeric($block['widthPx']) ? (float) $block['widthPx'] : null;
        $h = isset($block['heightPx']) && is_numeric($block['heightPx']) ? (float) $block['heightPx'] : null;

        $sniffed = ImageSize::sniff($bytes);
        $aspect = $sniffed !== null && $sniffed['width'] > 0
            ? $sniffed['height'] / $sniffed['width']
            : (2 / 3);

        if ($w === null && $h === null) {
            $w = (float) ($sniffed['width'] ?? 300);
            $h = (float) ($sniffed['height'] ?? 200);
        } elseif ($w === null) {
            $w = $aspect > 0 ? $h / $aspect : $h;
        } elseif ($h === null) {
            $h = $w * $aspect;
        }

        $cx = (int) round(max(1.0, $w) * self::EMU_PER_PX);
        $cy = (int) round(max(1.0, $h) * self::EMU_PER_PX);

        if ($cx > self::MAX_IMAGE_WIDTH_EMU) {
            $cy = (int) round($cy * self::MAX_IMAGE_WIDTH_EMU / $cx);
            $cx = self::MAX_IMAGE_WIDTH_EMU;
        }

        return [$cx, max(1, $cy)];
    }

    // ─── Runs ────────────────────────────────────────────────────────────

    /**
     * @param  list<array<string, mixed>>  $runs
     */
    private function renderRuns(array $runs): string
    {
        $xml = '';
        foreach ($runs as $run) {
            if (!is_array($run)) {
                continue;
            }
            $link = $run['link'] ?? null;
            $runXml = $this->renderRun($run);
            if (is_string($link) && $link !== '') {
                $rId = 'rId' . ++$this->relCounter;
                $this->rels[$rId] = ['type' => 'hyperlink', 'target' => $link];
                $xml .= '<w:hyperlink r:id="' . $rId . '">' . $runXml . '</w:hyperlink>';
            } else {
                $xml .= $runXml;
            }
        }

        return $xml;
    }

    /** @param  array<string, mixed>  $run */
    private function renderRun(array $run): string
    {
        // rPr children in CT_RPr schema order — it is an xsd:sequence, so this
        // is the schema's order and not a preference:
        // rStyle, rFonts, b, i, smallCaps, strike, color, spacing, sz, szCs, u, shd
        $rPr = '';
        $font = is_string($run['font'] ?? null) && $run['font'] !== '' ? $run['font'] : null;
        if (!empty($run['code'])) {
            // `code` wins over an explicit font: it is the more specific request.
            $rPr .= '<w:rStyle w:val="InlineCode"/>';
            $font = 'Consolas';
        } elseif (isset($run['link']) && is_string($run['link']) && $run['link'] !== '') {
            $rPr .= '<w:rStyle w:val="Hyperlink"/>';
        }
        if ($font !== null) {
            // Three attributes, not one: with only w:ascii, Word picks its own
            // face for anything it classes as high-ANSI or complex-script and
            // one run renders in two fonts.
            $f = Xml::attr($font);
            $rPr .= '<w:rFonts w:ascii="' . $f . '" w:hAnsi="' . $f . '" w:cs="' . $f . '"/>';
        }
        if (!empty($run['bold'])) {
            $rPr .= '<w:b/>';
        }
        if (!empty($run['italic'])) {
            $rPr .= '<w:i/>';
        }
        if (!empty($run['smallCaps'])) {
            $rPr .= '<w:smallCaps/>';
        }
        if (!empty($run['strike'])) {
            $rPr .= '<w:strike/>';
        }
        $color = self::hex($run['color'] ?? null);
        if ($color !== null) {
            $rPr .= '<w:color w:val="' . $color . '"/>';
        }
        // Tracking of zero is already the default, so it is absent rather than
        // w:val="0" — otherwise every untracked run would differ from the same
        // run written before this feature existed. Negative is legal, and is
        // how a large display size gets tightened.
        if (is_numeric($run['letterSpacing'] ?? null) && (float) $run['letterSpacing'] != 0.0) {
            $rPr .= '<w:spacing w:val="' . self::twips($run['letterSpacing']) . '"/>';
        }
        $size = self::halfPoints($run['size'] ?? null);
        if ($size !== null) {
            // szCs is not decoration: omit it and a complex-script run
            // silently keeps the default size.
            $rPr .= '<w:sz w:val="' . $size . '"/><w:szCs w:val="' . $size . '"/>';
        }
        if (!empty($run['underline'])) {
            $rPr .= '<w:u w:val="single"/>';
        }
        // Exact-hex highlight via run shading — w:highlight only takes named
        // colors; the reader maps both back to `highlight`.
        $rPr .= self::shadingXml($run['highlight'] ?? null);

        $text = (string) ($run['text'] ?? '');
        $parts = explode("\n", str_replace("\r\n", "\n", $text));
        $body = '';
        foreach ($parts as $i => $part) {
            if ($i > 0) {
                $body .= '<w:br/>';
            }
            if ($part !== '') {
                $body .= '<w:t xml:space="preserve">' . Xml::text($part) . '</w:t>';
            }
        }
        if ($body === '') {
            $body = '<w:t xml:space="preserve"></w:t>';
        }

        return '<w:r>' . ($rPr !== '' ? "<w:rPr>{$rPr}</w:rPr>" : '') . $body . '</w:r>';
    }

    // ─── Units and property fragments ────────────────────────────────────
    //
    // WordprocessingML measures four different things in four different
    // units, and getting one wrong produces a document that opens fine and is
    // the wrong size. They are collected here, once, so the three engines can
    // be compared line for line:
    //
    //   points → TWENTIETHS of a point (twips)  spacing, indents, margins
    //   points → HALF-points                    font size
    //   points → EIGHTHS of a point             border width
    //   percent → FIFTIETHS of a percent        table width
    //
    // Suite: fancy-conformance `last-word/docx-constructs`.

    /** Points → twips. */
    private static function twips(mixed $pt): ?int
    {
        return is_numeric($pt) ? (int) round(((float) $pt) * 20) : null;
    }

    /** Points → half-points (w:sz on a run). */
    private static function halfPoints(mixed $pt): ?int
    {
        return is_numeric($pt) && (float) $pt > 0 ? (int) round(((float) $pt) * 2) : null;
    }

    /** #RRGGBB → RRGGBB, upper-cased. Anything else is null. */
    private static function hex(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^#([0-9A-Fa-f]{6})$/', $value, $m) === 1
            ? strtoupper($m[1])
            : null;
    }

    /** `<w:shd>` — the one spelling used for runs, paragraphs and cells alike. */
    private static function shadingXml(mixed $color): string
    {
        $hex = self::hex($color);

        return $hex === null ? '' : '<w:shd w:val="clear" w:color="auto" w:fill="' . $hex . '"/>';
    }

    /**
     * One border edge.
     *
     * `style: none` becomes `w:val="nil"` with no width and no colour, because
     * nil is the only way to REMOVE a border — a zero width is not it, and a
     * white one only hides it against a white page.
     *
     * @param  array<string, mixed>  $border
     */
    private static function borderEdgeXml(string $tag, array $border): string
    {
        $style = is_string($border['style'] ?? null) ? $border['style'] : 'single';
        if ($style === 'none') {
            return '<w:' . $tag . ' w:val="nil"/>';
        }
        $width = is_numeric($border['width'] ?? null) ? (float) $border['width'] : 0.5;
        $sz = max(2, min(96, (int) round($width * 8)));
        $color = self::hex($border['color'] ?? null) ?? 'auto';

        return '<w:' . $tag . ' w:val="' . $style . '" w:sz="' . $sz . '" w:space="0" w:color="' . $color . '"/>';
    }

    /**
     * A border container (`w:pBdr`, `w:tblBorders`, `w:tcBorders`) in the
     * edge order its CT_ type declares. Absent edges are omitted, so a
     * partial `borders` stays partial.
     *
     * @param  list<string>  $edges
     * @param  array<string, mixed>  $borders
     */
    private static function bordersXml(string $wrapper, array $borders, array $edges): string
    {
        $inner = '';
        foreach ($edges as $edge) {
            $spec = $borders[$edge] ?? null;
            if (is_array($spec)) {
                $inner .= self::borderEdgeXml($edge, $spec);
            }
        }

        return $inner === '' ? '' : '<w:' . $wrapper . '>' . $inner . '</w:' . $wrapper . '>';
    }

    /**
     * A margin container (`w:tblCellMar`, `w:tcMar`). Sides are twips and a
     * side that was not given is not emitted.
     *
     * @param  array<string, mixed>  $sides
     */
    private static function marginsXml(string $wrapper, array $sides): string
    {
        $inner = '';
        foreach (['top', 'left', 'bottom', 'right'] as $side) {
            $twips = self::twips($sides[$side] ?? null);
            if ($twips !== null) {
                $inner .= '<w:' . $side . ' w:w="' . $twips . '" w:type="dxa"/>';
            }
        }

        return $inner === '' ? '' : '<w:' . $wrapper . '>' . $inner . '</w:' . $wrapper . '>';
    }

    /**
     * Page size and margins in twips.
     *
     * @param  mixed  $page
     * @return array{w: int, h: int, top: int, right: int, bottom: int, left: int, orientation: string}
     */
    private static function pageGeometry(mixed $page): array
    {
        $page = is_array($page) ? $page : [];
        $size = is_string($page['size'] ?? null) ? strtolower($page['size']) : 'letter';
        [$w, $h] = self::PAGE_SIZES[$size] ?? self::PAGE_SIZES['letter'];

        $orientation = ($page['orientation'] ?? null) === 'landscape' ? 'landscape' : 'portrait';
        if ($orientation === 'landscape') {
            // Swapping the axes without w:orient gives a page that is the
            // right shape and prints portrait. Both are required.
            [$w, $h] = [$h, $w];
        }

        $margins = is_array($page['margins'] ?? null) ? $page['margins'] : [];
        $side = static fn (string $k): int => self::twips($margins[$k] ?? null) ?? 1440;

        return [
            'w' => $w,
            'h' => $h,
            'top' => $side('top'),
            'right' => $side('right'),
            'bottom' => $side('bottom'),
            'left' => $side('left'),
            'orientation' => $orientation,
        ];
    }

    /**
     * Split a width into `$count` columns by relative weight, giving any
     * rounding remainder to the LAST column so the grid sums to the content
     * width exactly. Three engines rounding independently is how one language
     * ends up with a table a twip narrower than the other two.
     *
     * @param  list<float>|null  $weights
     * @return list<int>
     */
    private static function splitColumns(int $total, int $count, ?array $weights = null): array
    {
        if ($count < 1) {
            return [];
        }
        $weights = $weights !== null && count($weights) === $count ? $weights : array_fill(0, $count, 1.0);
        $sum = array_sum($weights);
        if ($sum <= 0) {
            $weights = array_fill(0, $count, 1.0);
            $sum = (float) $count;
        }

        $out = [];
        $used = 0;
        for ($i = 0; $i < $count - 1; $i++) {
            $w = (int) round($total * ($weights[$i] / $sum));
            $out[] = $w;
            $used += $w;
        }
        $out[] = $total - $used;

        return $out;
    }

    /**
     * The column split for a grid of `$count` equal columns.
     *
     * Public so the READER can ask the writer what it would have produced,
     * rather than carrying a second copy of the rounding rule. Two copies of
     * a rounding rule is how a reader decides a grid is uneven and hands back
     * explicit widths for a table that never asked for any.
     *
     * @return list<int>
     */
    public static function splitColumnsFor(int $total, int $count): array
    {
        return self::splitColumns($total, $count);
    }

    /**
     * Paragraph properties, in CT_PPr order:
     * pStyle, keepNext, numPr, pBdr, shd, spacing, ind, jc, outlineLvl.
     *
     * @param  array<string, mixed>  $block
     */
    private static function pPrXml(array $block, ?string $style = null, string $numPr = ''): string
    {
        $inner = '';
        if ($style !== null) {
            $inner .= '<w:pStyle w:val="' . $style . '"/>';
        }
        if (!empty($block['keepNext'])) {
            $inner .= '<w:keepNext/>';
        }
        $inner .= $numPr;

        if (is_array($block['borders'] ?? null)) {
            $inner .= self::bordersXml('pBdr', $block['borders'], ['top', 'left', 'bottom', 'right']);
        }
        $inner .= self::shadingXml($block['shading'] ?? null);

        // before, after, line and lineRule all live on ONE w:spacing element;
        // emitting two would be invalid.
        $spacing = '';
        $before = self::twips($block['spaceBefore'] ?? null);
        if ($before !== null) {
            $spacing .= ' w:before="' . $before . '"';
        }
        $after = self::twips($block['spaceAfter'] ?? null);
        if ($after !== null) {
            $spacing .= ' w:after="' . $after . '"';
        }
        if (is_numeric($block['lineHeight'] ?? null) && (float) $block['lineHeight'] > 0) {
            $spacing .= ' w:line="' . (int) round(((float) $block['lineHeight']) * 240) . '" w:lineRule="auto"';
        }
        if ($spacing !== '') {
            $inner .= '<w:spacing' . $spacing . '/>';
        }

        $ind = '';
        $left = self::twips($block['indentLeft'] ?? null);
        if ($left !== null) {
            $ind .= ' w:left="' . $left . '"';
        }
        $right = self::twips($block['indentRight'] ?? null);
        if ($right !== null) {
            $ind .= ' w:right="' . $right . '"';
        }
        if ($ind !== '') {
            $inner .= '<w:ind' . $ind . '/>';
        }

        $align = $block['align'] ?? null;
        if (is_string($align) && $align !== 'left') {
            $jc = match ($align) {
                'center' => 'center',
                'right' => 'right',
                'justify' => 'both',
                default => null,
            };
            if ($jc !== null) {
                $inner .= '<w:jc w:val="' . $jc . '"/>';
            }
        }

        return $inner === '' ? '' : '<w:pPr>' . $inner . '</w:pPr>';
    }

    // ─── Static parts ────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $doc
     */
    private function buildStyles(array $doc = []): string
    {
        $headingSizes = [1 => 36, 2 => 32, 3 => 28, 4 => 26, 5 => 24, 6 => 22];

        $font = is_string($doc['defaultFont'] ?? null) && $doc['defaultFont'] !== ''
            ? Xml::attr($doc['defaultFont'])
            : 'Calibri';
        $size = self::halfPoints($doc['defaultSize'] ?? null) ?? 22;

        $xml = Xml::declaration();
        $xml .= '<w:styles xmlns:w="' . self::NS_W . '">';
        $xml .= '<w:docDefaults>'
            . '<w:rPrDefault><w:rPr><w:rFonts w:ascii="' . $font . '" w:hAnsi="' . $font . '"'
            . ' w:eastAsia="' . $font . '" w:cs="' . $font . '"/>'
            . '<w:sz w:val="' . $size . '"/><w:szCs w:val="' . $size . '"/></w:rPr></w:rPrDefault>'
            . '<w:pPrDefault><w:pPr><w:spacing w:after="160" w:line="259" w:lineRule="auto"/></w:pPr></w:pPrDefault>'
            . '</w:docDefaults>';

        $xml .= '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:qFormat/></w:style>';

        $xml .= '<w:style w:type="paragraph" w:styleId="Title">'
            . '<w:name w:val="Title"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:qFormat/>'
            . '<w:pPr><w:spacing w:after="240"/></w:pPr>'
            . '<w:rPr><w:b/><w:sz w:val="56"/><w:szCs w:val="56"/></w:rPr>'
            . '</w:style>';

        foreach ($headingSizes as $level => $sz) {
            $xml .= '<w:style w:type="paragraph" w:styleId="Heading' . $level . '">'
                . '<w:name w:val="heading ' . $level . '"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:qFormat/>'
                . '<w:pPr><w:keepNext/><w:spacing w:before="240" w:after="120"/><w:outlineLvl w:val="' . ($level - 1) . '"/></w:pPr>'
                . '<w:rPr><w:b/><w:sz w:val="' . $sz . '"/><w:szCs w:val="' . $sz . '"/></w:rPr>'
                . '</w:style>';
        }

        $xml .= '<w:style w:type="paragraph" w:styleId="Quote">'
            . '<w:name w:val="Quote"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:qFormat/>'
            . '<w:pPr><w:ind w:left="720"/></w:pPr>'
            . '<w:rPr><w:i/><w:color w:val="595959"/></w:rPr>'
            . '</w:style>';

        $xml .= '<w:style w:type="paragraph" w:styleId="CodeBlock">'
            . '<w:name w:val="Code Block"/><w:basedOn w:val="Normal"/><w:qFormat/>'
            . '<w:pPr><w:spacing w:after="0" w:line="240" w:lineRule="auto"/><w:shd w:val="clear" w:color="auto" w:fill="F2F2F2"/></w:pPr>'
            . '<w:rPr><w:rFonts w:ascii="Consolas" w:hAnsi="Consolas" w:cs="Consolas"/><w:sz w:val="20"/><w:szCs w:val="20"/></w:rPr>'
            . '</w:style>';

        $xml .= '<w:style w:type="paragraph" w:styleId="ListParagraph">'
            . '<w:name w:val="List Paragraph"/><w:basedOn w:val="Normal"/><w:qFormat/>'
            . '<w:pPr><w:contextualSpacing/></w:pPr>'
            . '</w:style>';

        $xml .= '<w:style w:type="character" w:styleId="InlineCode">'
            . '<w:name w:val="Inline Code"/><w:qFormat/>'
            . '<w:rPr><w:rFonts w:ascii="Consolas" w:hAnsi="Consolas" w:cs="Consolas"/><w:sz w:val="20"/><w:shd w:val="clear" w:color="auto" w:fill="F2F2F2"/></w:rPr>'
            . '</w:style>';

        $xml .= '<w:style w:type="character" w:styleId="Hyperlink">'
            . '<w:name w:val="Hyperlink"/><w:qFormat/>'
            . '<w:rPr><w:color w:val="0563C1"/><w:u w:val="single"/></w:rPr>'
            . '</w:style>';

        $xml .= '</w:styles>';

        return $xml;
    }

    private function buildNumbering(): string
    {
        $xml = Xml::declaration();
        $xml .= '<w:numbering xmlns:w="' . self::NS_W . '">';

        // Abstract 0: bullets, 6 indent levels.
        $xml .= '<w:abstractNum w:abstractNumId="0">';
        for ($lvl = 0; $lvl < 6; $lvl++) {
            $indent = 720 * ($lvl + 1);
            $xml .= '<w:lvl w:ilvl="' . $lvl . '">'
                . '<w:start w:val="1"/>'
                . '<w:numFmt w:val="bullet"/>'
                . '<w:lvlText w:val="&#8226;"/>'
                . '<w:lvlJc w:val="left"/>'
                . '<w:pPr><w:ind w:left="' . $indent . '" w:hanging="360"/></w:pPr>'
                . '</w:lvl>';
        }
        $xml .= '</w:abstractNum>';

        // Abstract 1: decimal, 6 indent levels.
        $xml .= '<w:abstractNum w:abstractNumId="1">';
        for ($lvl = 0; $lvl < 6; $lvl++) {
            $indent = 720 * ($lvl + 1);
            $xml .= '<w:lvl w:ilvl="' . $lvl . '">'
                . '<w:start w:val="1"/>'
                . '<w:numFmt w:val="decimal"/>'
                . '<w:lvlText w:val="%' . ($lvl + 1) . '."/>'
                . '<w:lvlJc w:val="left"/>'
                . '<w:pPr><w:ind w:left="' . $indent . '" w:hanging="360"/></w:pPr>'
                . '</w:lvl>';
        }
        $xml .= '</w:abstractNum>';

        // Instance 1: shared bullet list. Instances 2..N+1: one per ordered
        // list in the document so each restarts its numbering.
        $xml .= '<w:num w:numId="1"><w:abstractNumId w:val="0"/></w:num>';
        for ($i = 0; $i < $this->orderedListCount; $i++) {
            $xml .= '<w:num w:numId="' . (2 + $i) . '"><w:abstractNumId w:val="1"/></w:num>';
        }
        $xml .= '</w:numbering>';

        return $xml;
    }
}
