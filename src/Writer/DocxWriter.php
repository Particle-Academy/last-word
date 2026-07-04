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
 * Block → OOXML mapping:
 *   heading    — w:p with pStyle Heading{n}
 *   paragraph  — w:p (+ w:jc for align)
 *   list       — w:p per item with numPr (ilvl per nesting depth)
 *   table      — w:tbl with grid; header rows get w:tblHeader + shading +
 *                forced-bold runs
 *   code       — one CodeBlock-styled w:p per line; the language survives
 *                round-trips via an invisible `LastWordCode_{lang}` bookmark
 *   quote      — inner paragraphs styled Quote
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

    /** Relationships beyond styles(rId1) + numbering(rId2), keyed by rId. */
    private array $rels = [];

    /** Media files queued for the archive, keyed by archive path. */
    private array $mediaFiles = [];

    private int $relCounter = 2;

    private int $imageCounter = 0;

    private int $bookmarkCounter = 0;

    /** Number of ordered-list numbering instances allocated (numIds 2..N+1). */
    private int $orderedListCount = 0;

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
        $this->bookmarkCounter = 0;
        $this->orderedListCount = 0;

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
            $zip->addFromString('[Content_Types].xml', $this->buildContentTypes());
            $zip->addFromString('_rels/.rels', $this->buildTopRels());
            $zip->addFromString('word/document.xml', $documentXml);
            $zip->addFromString('word/styles.xml', $this->buildStyles());
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

    private function buildContentTypes(): string
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
        $xml .= '</Types>';

        return $xml;
    }

    private function buildTopRels(): string
    {
        $xml = Xml::declaration();
        $xml .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        $xml .= '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>';
        $xml .= '</Relationships>';

        return $xml;
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
        $body = '';

        $title = $doc['title'] ?? null;
        if (is_string($title) && $title !== '') {
            $body .= '<w:p><w:pPr><w:pStyle w:val="Title"/></w:pPr>'
                . $this->renderRuns([['text' => $title]])
                . '</w:p>';
        }

        $body .= $this->renderBlocks($doc['blocks'] ?? []);

        $body .= '<w:sectPr>'
            . '<w:pgSz w:w="12240" w:h="15840"/>'
            . '<w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440" w:header="720" w:footer="720" w:gutter="0"/>'
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
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            $xml .= match ($block['type'] ?? null) {
                'heading' => $this->renderHeading($block),
                'paragraph' => $this->renderParagraph($block, $paragraphStyle),
                'list' => $this->renderList($block),
                'table' => $this->renderTable($block),
                'code' => $this->renderCode($block),
                'quote' => $this->renderBlocks($block['blocks'] ?? [], 'Quote'),
                'image' => $this->renderImage($block),
                'pageBreak' => '<w:p><w:r><w:br w:type="page"/></w:r></w:p>',
                'hr' => '<w:p><w:pPr><w:pBdr><w:bottom w:val="single" w:sz="6" w:space="1" w:color="auto"/></w:pBdr></w:pPr></w:p>',
                default => '',
            };
        }

        return $xml;
    }

    /** @param  array<string, mixed>  $block */
    private function renderHeading(array $block): string
    {
        $level = max(1, min(6, (int) ($block['level'] ?? 1)));

        return '<w:p><w:pPr><w:pStyle w:val="Heading' . $level . '"/></w:pPr>'
            . $this->renderRuns($block['runs'] ?? [])
            . '</w:p>';
    }

    /** @param  array<string, mixed>  $block */
    private function renderParagraph(array $block, ?string $styleOverride = null): string
    {
        $pPr = '';
        if ($styleOverride !== null) {
            $pPr .= '<w:pStyle w:val="' . $styleOverride . '"/>';
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
                $pPr .= '<w:jc w:val="' . $jc . '"/>';
            }
        }

        return '<w:p>' . ($pPr !== '' ? "<w:pPr>{$pPr}</w:pPr>" : '')
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

    /** @param  array<string, mixed>  $block */
    private function renderTable(array $block): string
    {
        $rows = array_values(array_filter($block['rows'] ?? [], 'is_array'));
        if ($rows === []) {
            return '';
        }
        $colCount = 1;
        foreach ($rows as $row) {
            $colCount = max($colCount, count($row['cells'] ?? []));
        }
        $colWidth = intdiv(9360, $colCount); // 6.5in of twips split evenly

        $xml = '<w:tbl><w:tblPr><w:tblW w:w="0" w:type="auto"/><w:tblBorders>'
            . '<w:top w:val="single" w:sz="4" w:space="0" w:color="auto"/>'
            . '<w:left w:val="single" w:sz="4" w:space="0" w:color="auto"/>'
            . '<w:bottom w:val="single" w:sz="4" w:space="0" w:color="auto"/>'
            . '<w:right w:val="single" w:sz="4" w:space="0" w:color="auto"/>'
            . '<w:insideH w:val="single" w:sz="4" w:space="0" w:color="auto"/>'
            . '<w:insideV w:val="single" w:sz="4" w:space="0" w:color="auto"/>'
            . '</w:tblBorders></w:tblPr>';
        $xml .= '<w:tblGrid>' . str_repeat('<w:gridCol w:w="' . $colWidth . '"/>', $colCount) . '</w:tblGrid>';

        foreach ($rows as $row) {
            $isHeader = (bool) ($row['header'] ?? false);
            $xml .= '<w:tr>';
            if ($isHeader) {
                $xml .= '<w:trPr><w:tblHeader/></w:trPr>';
            }
            $cells = array_values(array_filter($row['cells'] ?? [], 'is_array'));
            for ($c = 0; $c < $colCount; $c++) {
                $cell = $cells[$c] ?? ['blocks' => []];
                $tcPr = '<w:tcW w:w="' . $colWidth . '" w:type="dxa"/>';
                if ($isHeader) {
                    $tcPr .= '<w:shd w:val="clear" w:color="auto" w:fill="' . self::HEADER_FILL . '"/>';
                }
                $content = $this->renderCellBlocks($cell['blocks'] ?? [], $isHeader);
                $xml .= '<w:tc><w:tcPr>' . $tcPr . '</w:tcPr>' . $content . '</w:tc>';
            }
            $xml .= '</w:tr>';
        }
        $xml .= '</w:tbl>';

        // OOXML requires a paragraph between/after tables so consecutive
        // tables don't merge — the reader recognises and drops this pad.
        return $xml . '<w:p/>';
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

        // The model's `language` has no native WordprocessingML slot; stash it
        // in an invisible bookmark on the first line so it survives the
        // round-trip. Only simple identifiers — bookmark names are restricted.
        $marker = '';
        if (is_string($language) && preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $language) === 1) {
            $id = ++$this->bookmarkCounter;
            $marker = '<w:bookmarkStart w:id="' . $id . '" w:name="LastWordCode_' . Xml::attr($language) . '"/><w:bookmarkEnd w:id="' . $id . '"/>';
        }

        $xml = '';
        foreach ($lines as $i => $line) {
            $xml .= '<w:p><w:pPr><w:pStyle w:val="CodeBlock"/></w:pPr>'
                . ($i === 0 ? $marker : '')
                . '<w:r><w:t xml:space="preserve">' . Xml::text($line) . '</w:t></w:r>'
                . '</w:p>';
        }

        return $xml;
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
        // rPr children in CT_RPr schema order:
        // rStyle, rFonts, b, i, strike, color, u, shd
        $rPr = '';
        if (!empty($run['code'])) {
            $rPr .= '<w:rStyle w:val="InlineCode"/>';
            $rPr .= '<w:rFonts w:ascii="Consolas" w:hAnsi="Consolas" w:cs="Consolas"/>';
        } elseif (isset($run['link']) && is_string($run['link']) && $run['link'] !== '') {
            $rPr .= '<w:rStyle w:val="Hyperlink"/>';
        }
        if (!empty($run['bold'])) {
            $rPr .= '<w:b/>';
        }
        if (!empty($run['italic'])) {
            $rPr .= '<w:i/>';
        }
        if (!empty($run['strike'])) {
            $rPr .= '<w:strike/>';
        }
        if (isset($run['color']) && is_string($run['color']) && preg_match('/^#([0-9A-Fa-f]{6})$/', $run['color'], $m) === 1) {
            $rPr .= '<w:color w:val="' . strtoupper($m[1]) . '"/>';
        }
        if (!empty($run['underline'])) {
            $rPr .= '<w:u w:val="single"/>';
        }
        if (isset($run['highlight']) && is_string($run['highlight']) && preg_match('/^#([0-9A-Fa-f]{6})$/', $run['highlight'], $m) === 1) {
            // Exact-hex highlight via run shading — w:highlight only takes
            // named colors; the reader maps both back to `highlight`.
            $rPr .= '<w:shd w:val="clear" w:color="auto" w:fill="' . strtoupper($m[1]) . '"/>';
        }

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

    // ─── Static parts ────────────────────────────────────────────────────

    private function buildStyles(): string
    {
        $headingSizes = [1 => 36, 2 => 32, 3 => 28, 4 => 26, 5 => 24, 6 => 22];

        $xml = Xml::declaration();
        $xml .= '<w:styles xmlns:w="' . self::NS_W . '">';
        $xml .= '<w:docDefaults>'
            . '<w:rPrDefault><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri" w:cs="Calibri"/><w:sz w:val="22"/><w:szCs w:val="22"/></w:rPr></w:rPrDefault>'
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
