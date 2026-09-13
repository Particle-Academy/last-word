<?php

declare(strict_types=1);

namespace LastWord\Reader;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use RuntimeException;
use ZipArchive;

/**
 * OpenDocument Text -> the same document shape `DocxReader` returns.
 *
 * Same shape is the requirement, not a nicety. A consumer that already handles
 * our documents must not need a second code path because the upload happened to
 * be an `.odt`; the moment they do, the two paths drift and only one gets the
 * next fix.
 *
 * ## What comes through
 *
 * - headings (`text:h`, with their outline level) and paragraphs
 * - bold, italic, underline and strike from AUTOMATIC styles, which is where ODF
 *   keeps direct formatting; a named style's formatting (a heading style's bold,
 *   say) is the style's, as it is for the `.docx` and `.doc` readers
 * - hyperlinks (`text:a`)
 * - lists with nesting, numbered or bulleted by their list style
 * - tables, including header rows and merged cells (`colSpan` / `rowSpan`)
 * - spaces, tabs and line breaks written as `text:s`, `text:tab`,
 *   `text:line-break`
 * - page breaks set on a paragraph's automatic style
 * - the title from `meta.xml`
 *
 * ## What does not
 *
 * Images and frames, footnotes and endnotes, comments, tracked deletions (their
 * text is not the document's), fonts, sizes and colours, sections' layout and
 * page geometry.
 *
 * ## Hostile input
 *
 * A part carrying a DOCTYPE is refused before it is parsed (an ODT never has one,
 * and it is the entry point for entity expansion). A part larger than
 * {@see MAX_PART_BYTES} uncompressed is refused rather than inflated. Repeated
 * rows and columns are capped, each repeat at {@see MAX_REPEAT} and all of them
 * together at {@see MAX_REPEATED_CELLS} cells, and element nesting is walked with
 * a depth limit.
 */
final class OdtReader
{
    private const MAX_PART_BYTES = 64 * 1024 * 1024;

    private const MAX_REPEAT = 1000;

    private const MAX_DEPTH = 256;

    /** Cells a document's repeat attributes may add in total, beyond the ones written out. */
    private const MAX_REPEATED_CELLS = 100_000;

    private int $repeatedCells = 0;

    /** @var array<string, DOMElement> automatic styles from content.xml, by name */
    private array $automaticStyles = [];

    /** @var array<string, DOMElement> list styles from content.xml and styles.xml, by name */
    private array $listStyles = [];

    /** @return array<string,mixed> */
    public function read(string $bytes): array
    {
        $parts = $this->parts($bytes);
        $content = self::parse($parts['content.xml'] ?? null, 'content.xml', true);

        $this->automaticStyles = [];
        $this->listStyles = [];
        $this->repeatedCells = 0;
        $this->indexStyles($content, true);
        $styles = self::parse($parts['styles.xml'] ?? null, 'styles.xml', false);
        if ($styles !== null) {
            $this->indexStyles($styles, false);
        }

        $body = self::descendant($content->documentElement, 'text');
        $doc = [];

        $meta = self::parse($parts['meta.xml'] ?? null, 'meta.xml', false);
        $title = $meta !== null ? self::descendant($meta->documentElement, 'title') : null;
        if ($title !== null && trim($title->textContent) !== '') {
            $doc['title'] = trim($title->textContent);
        }

        $doc['blocks'] = $body !== null ? $this->blocks($body, 0) : [];

        return $doc;
    }

    // ─── Archive ──────────────────────────────────────────────────────────

    /** @return array<string, string> */
    private function parts(string $bytes): array
    {
        // ZipArchive needs a path. The temp file is created, read and removed
        // inside this method and never escapes it.
        $tmp = tempnam(sys_get_temp_dir(), 'lw_odt_');
        if ($tmp === false) {
            throw new RuntimeException('Could not create a temporary file to open the ODT.');
        }

        try {
            if (file_put_contents($tmp, $bytes) === false) {
                throw new RuntimeException('Could not write the ODT to a temporary file.');
            }

            $zip = new ZipArchive();
            if ($zip->open($tmp, ZipArchive::RDONLY) !== true) {
                throw new RuntimeException('Could not open the ODT archive.');
            }

            try {
                $parts = [];
                foreach (['content.xml', 'styles.xml', 'meta.xml'] as $name) {
                    $stat = $zip->statName($name);
                    if ($stat === false) {
                        continue;
                    }
                    if ($stat['size'] > self::MAX_PART_BYTES) {
                        throw new RuntimeException("ODT part {$name} is too large to read.");
                    }
                    $xml = $zip->getFromName($name);
                    if (is_string($xml)) {
                        $parts[$name] = $xml;
                    }
                }
            } finally {
                $zip->close();
            }

            if (! isset($parts['content.xml'])) {
                throw new RuntimeException('ODT archive has no content.xml.');
            }

            return $parts;
        } finally {
            @unlink($tmp);
        }
    }

    private static function parse(?string $xml, string $name, bool $required): ?DOMDocument
    {
        if ($xml === null) {
            return null;
        }
        if (stripos($xml, '<!DOCTYPE') !== false) {
            throw new RuntimeException("ODT part {$name} carries a DOCTYPE, which an ODT never does; refusing to parse it.");
        }

        $dom = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if (! $loaded || $dom->documentElement === null) {
            if ($required) {
                throw new RuntimeException("Could not parse {$name}.");
            }

            return null;
        }

        return $dom;
    }

    // ─── Styles ───────────────────────────────────────────────────────────

    private function indexStyles(DOMDocument $dom, bool $isContent): void
    {
        foreach ($this->elements($dom->documentElement, 0) as $el) {
            $name = $el->getAttribute('style:name');
            if ($name === '') {
                continue;
            }
            $local = self::local($el);
            if ($local === 'list-style') {
                $this->listStyles[$name] ??= $el;
            } elseif ($local === 'style' && $isContent && self::local($el->parentNode) === 'automatic-styles') {
                $this->automaticStyles[$name] = $el;
            }
        }
    }

    /** @return list<DOMElement> every element, depth-first, depth-limited */
    private function elements(DOMNode $node, int $depth): array
    {
        if ($depth > self::MAX_DEPTH) {
            return [];
        }
        $out = [];
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $out[] = $child;
                array_push($out, ...$this->elements($child, $depth + 1));
            }
        }

        return $out;
    }

    /**
     * Direct formatting from an automatic style and its automatic parents.
     *
     * @return array<string, bool>
     */
    private function flags(string $styleName): array
    {
        $flags = [];
        $seen = [];
        while ($styleName !== '' && isset($this->automaticStyles[$styleName]) && ! isset($seen[$styleName])) {
            $seen[$styleName] = true;
            $style = $this->automaticStyles[$styleName];
            $props = self::child($style, 'text-properties');
            if ($props !== null) {
                $weight = $props->getAttribute('fo:font-weight');
                if ($weight !== '' && ! isset($flags['bold'])) {
                    $flags['bold'] = $weight === 'bold' || (is_numeric($weight) && (int) $weight >= 600);
                }
                $italic = $props->getAttribute('fo:font-style');
                if ($italic !== '' && ! isset($flags['italic'])) {
                    $flags['italic'] = $italic === 'italic' || $italic === 'oblique';
                }
                $underline = $props->getAttribute('style:text-underline-style');
                if ($underline !== '' && ! isset($flags['underline'])) {
                    $flags['underline'] = $underline !== 'none';
                }
                $strike = $props->getAttribute('style:text-line-through-style');
                if ($strike !== '' && ! isset($flags['strike'])) {
                    $flags['strike'] = $strike !== 'none';
                }
            }
            $styleName = $style->getAttribute('style:parent-style-name');
        }

        return array_filter($flags);
    }

    private function breaks(string $styleName, string $attribute): bool
    {
        $style = $this->automaticStyles[$styleName] ?? null;
        $props = $style !== null ? self::child($style, 'paragraph-properties') : null;

        return $props !== null && $props->getAttribute($attribute) === 'page';
    }

    private function listIsOrdered(string $listStyleName): bool
    {
        $style = $this->listStyles[$listStyleName] ?? null;
        if ($style === null) {
            return false;
        }
        foreach ($style->childNodes as $level) {
            if ($level instanceof DOMElement && $level->getAttribute('text:level') === '1') {
                return self::local($level) === 'list-level-style-number';
            }
        }

        return false;
    }

    // ─── Blocks ───────────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    private function blocks(DOMElement $container, int $depth): array
    {
        $blocks = [];
        foreach ($container->childNodes as $node) {
            if ($node instanceof DOMElement && $depth <= self::MAX_DEPTH) {
                array_push($blocks, ...$this->block($node, $depth + 1));
            }
        }

        return $blocks;
    }

    /** @return list<array<string, mixed>> */
    private function block(DOMElement $el, int $depth): array
    {
        switch (self::local($el)) {
            case 'h':
            case 'p':
                return $this->paragraph($el);
            case 'list':
                $entries = [];
                $this->listEntries($el, 0, $this->listIsOrdered($el->getAttribute('text:style-name')), $entries, $depth);

                return Structure::lists($entries);
            case 'table':
                return [$this->table($el, $depth)];
            case 'section':
            case 'index-body':
            case 'soft-page-break':
                return $this->blocks($el, $depth);
            default:
                // Tracked changes, sequence declarations, frames anchored to the
                // page: none of them are body text.
                return [];
        }
    }

    /** @return list<array<string, mixed>> */
    private function paragraph(DOMElement $el): array
    {
        $style = $el->getAttribute('text:style-name');
        $runs = $this->runs($el, $this->flags($style), null);
        $out = [];

        if ($this->breaks($style, 'fo:break-before')) {
            $out[] = ['type' => 'pageBreak'];
        }
        if (trim(Structure::text($runs)) !== '') {
            if (self::local($el) === 'h') {
                $level = (int) ($el->getAttribute('text:outline-level') ?: '1');
                $out[] = ['type' => 'heading', 'level' => max(1, min(6, $level)), 'runs' => $runs];
            } else {
                $out[] = ['type' => 'paragraph', 'runs' => $runs];
            }
        }
        if ($this->breaks($style, 'fo:break-after')) {
            $out[] = ['type' => 'pageBreak'];
        }

        return $out;
    }

    /**
     * @param  list<array{ilvl: int, ordered: bool, runs: list<array<string, mixed>>}>  $entries
     */
    private function listEntries(DOMElement $list, int $level, bool $ordered, array &$entries, int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            return;
        }
        foreach ($list->childNodes as $item) {
            if (! $item instanceof DOMElement || ! in_array(self::local($item), ['list-item', 'list-header'], true)) {
                continue;
            }
            foreach ($item->childNodes as $child) {
                if (! $child instanceof DOMElement) {
                    continue;
                }
                $name = self::local($child);
                if ($name === 'p' || $name === 'h') {
                    $runs = $this->runs($child, $this->flags($child->getAttribute('text:style-name')), null);
                    if (trim(Structure::text($runs)) !== '') {
                        $entries[] = ['ilvl' => min(8, $level), 'ordered' => $ordered, 'runs' => $runs];
                    }
                } elseif ($name === 'list') {
                    $this->listEntries($child, $level + 1, $ordered, $entries, $depth + 1);
                }
            }
        }
    }

    /** @return array<string, mixed> */
    private function table(DOMElement $table, int $depth): array
    {
        $rows = [];
        $this->rows($table, false, $rows, $depth);

        return ['type' => 'table', 'rows' => $rows];
    }

    /** @param list<array<string, mixed>> $rows */
    private function rows(DOMElement $container, bool $header, array &$rows, int $depth): void
    {
        foreach ($container->childNodes as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }
            $name = self::local($node);
            if ($name === 'table-header-rows') {
                $this->rows($node, true, $rows, $depth);
            } elseif ($name === 'table-rows' || $name === 'table-row-group') {
                $this->rows($node, $header, $rows, $depth);
            } elseif ($name === 'table-row') {
                $cells = [];
                foreach ($node->childNodes as $cell) {
                    if (! $cell instanceof DOMElement || self::local($cell) !== 'table-cell') {
                        continue; // covered cells belong to the cell that spans them
                    }
                    $out = ['blocks' => $this->blocks($cell, $depth + 1)];
                    $colSpan = (int) $cell->getAttribute('table:number-columns-spanned');
                    $rowSpan = (int) $cell->getAttribute('table:number-rows-spanned');
                    if ($colSpan > 1) {
                        $out['colSpan'] = min($colSpan, self::MAX_REPEAT);
                    }
                    if ($rowSpan > 1) {
                        $out['rowSpan'] = min($rowSpan, self::MAX_REPEAT);
                    }
                    $repeat = max(1, min((int) $cell->getAttribute('table:number-columns-repeated'), self::MAX_REPEAT));
                    $cells[] = $out;
                    for ($i = 1; $i < $repeat && $this->repeatedCells < self::MAX_REPEATED_CELLS; $i++) {
                        $cells[] = $out;
                        $this->repeatedCells++;
                    }
                }
                $row = $header ? ['header' => true, 'cells' => $cells] : ['cells' => $cells];
                // A repeated row is usually spreadsheet-style filler; only a row
                // with content is worth repeating, and never unboundedly.
                $hasContent = trim(implode('', array_map(static fn (array $c): string => json_encode($c['blocks']) ?: '', $cells))) !== str_repeat('[]', count($cells));
                $repeat = $hasContent ? max(1, min((int) $node->getAttribute('table:number-rows-repeated'), self::MAX_REPEAT)) : 1;
                $rows[] = $row;
                for ($i = 1; $i < $repeat && $this->repeatedCells < self::MAX_REPEATED_CELLS; $i++) {
                    $rows[] = $row;
                    $this->repeatedCells += max(1, count($cells));
                }
            }
        }
    }

    // ─── Inline ───────────────────────────────────────────────────────────

    /**
     * @param  array<string, bool>  $flags
     * @return list<array<string, mixed>>
     */
    private function runs(DOMElement $el, array $flags, ?string $link): array
    {
        $raw = [];
        $this->collect($el, $flags, $link, $raw, 0);

        // ODF collapses white space in text nodes and ignores it at the edges of
        // a paragraph; spaces written as text:s are real.
        if ($raw !== []) {
            if ($raw[0]['collapsible']) {
                $raw[0]['text'] = ltrim($raw[0]['text'], ' ');
            }
            $last = count($raw) - 1;
            if ($raw[$last]['collapsible']) {
                $raw[$last]['text'] = rtrim($raw[$last]['text'], ' ');
            }
        }

        return Structure::mergeRuns(array_map(static function (array $r): array {
            unset($r['collapsible']);

            return $r;
        }, $raw));
    }

    /**
     * @param  array<string, bool>  $flags
     * @param  list<array<string, mixed>>  $out
     */
    private function collect(DOMNode $node, array $flags, ?string $link, array &$out, int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            return;
        }
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMText) {
                $text = (string) preg_replace('/[ \t\r\n]+/', ' ', $child->data);
                // A collapsed space right after another one is a single space.
                if ($out !== [] && str_ends_with($out[count($out) - 1]['text'], ' ') && str_starts_with($text, ' ') && $out[count($out) - 1]['collapsible']) {
                    $text = ltrim($text, ' ');
                }
                self::push($out, $text, $flags, $link, true);

                continue;
            }
            if (! $child instanceof DOMElement) {
                continue;
            }

            switch (self::local($child)) {
                case 'span':
                    $this->collect($child, [...$flags, ...$this->flags($child->getAttribute('text:style-name'))], $link, $out, $depth + 1);
                    break;
                case 'a':
                    $href = $child->getAttribute('xlink:href');
                    $this->collect($child, $flags, $href !== '' ? $href : $link, $out, $depth + 1);
                    break;
                case 's':
                    $count = max(1, min((int) ($child->getAttribute('text:c') ?: '1'), self::MAX_REPEAT));
                    self::push($out, str_repeat(' ', $count), $flags, $link, false);
                    break;
                case 'tab':
                    self::push($out, "\t", $flags, $link, false);
                    break;
                case 'line-break':
                    self::push($out, "\n", $flags, $link, false);
                    break;
                case 'note':
                case 'annotation':
                case 'annotation-end':
                case 'bookmark':
                case 'bookmark-start':
                case 'bookmark-end':
                case 'reference-mark':
                case 'change':
                case 'change-start':
                case 'change-end':
                case 'frame':
                case 'soft-page-break':
                    break;
                default:
                    // Fields and other inline wrappers (text:date, text:page-number,
                    // text:meta, ...) display their text content.
                    $this->collect($child, $flags, $link, $out, $depth + 1);
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $out
     * @param  array<string, bool>  $flags
     */
    private static function push(array &$out, string $text, array $flags, ?string $link, bool $collapsible): void
    {
        if ($text === '') {
            return;
        }
        $run = ['text' => $text, ...$flags, 'collapsible' => $collapsible];
        if ($link !== null) {
            $run['link'] = $link;
        }
        $out[] = $run;
    }

    // ─── DOM helpers ──────────────────────────────────────────────────────

    private static function local(?DOMNode $node): string
    {
        if (! $node instanceof DOMElement) {
            return '';
        }

        return $node->localName ?? '';
    }

    private static function child(DOMElement $parent, string $local): ?DOMElement
    {
        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === $local) {
                return $child;
            }
        }

        return null;
    }

    private static function descendant(?DOMElement $root, string $local): ?DOMElement
    {
        if ($root === null) {
            return null;
        }
        $stack = [$root];
        $visited = 0;
        while ($stack !== [] && $visited++ < 1_000_000) {
            $node = array_shift($stack);
            foreach ($node->childNodes as $child) {
                if ($child instanceof DOMElement) {
                    if ($child->localName === $local) {
                        return $child;
                    }
                    $stack[] = $child;
                }
            }
        }

        return null;
    }
}
