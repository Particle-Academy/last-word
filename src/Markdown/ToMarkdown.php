<?php

declare(strict_types=1);

namespace LastWord\Markdown;

use LastWord\Exchange\Report;
use LastWord\Helpers\Chart;

/**
 * Doc model → GFM markdown. The Editor bridge's outbound half — hand-rolled
 * (no external markdown dependency), emitting the same canonical form
 * {@see FromMarkdown} parses so the two form a fixpoint:
 *
 *   headings `#`, emphasis `**` / `*` / `~~`, inline code, links, ordered +
 *   unordered nested lists (2-space indent per level), tables, fenced code,
 *   blockquotes, images `![alt](src)`, `---` hr, and an `<!-- pagebreak -->`
 *   comment so page breaks survive the round-trip.
 *
 * Lossy by nature of GFM: underline / color / highlight / alignment are
 * dropped; the doc title is emitted as a leading `#` heading.
 *
 * {@see convertWithReport()} says what was lost, in the exchange report shape
 * (fancy-conformance `suites/exchange/SPEC.md` section 5): kinds from the
 * closed list, each located by a JSON Pointer into the document, never by its
 * text. The markdown is the same string {@see convert()} returns.
 */
final class ToMarkdown
{
    /** Characters backslash-escaped in plain text. */
    private const ESCAPE = ['\\', '`', '*', '_', '~', '[', ']'];

    /** Paragraph properties markdown has no slot for. */
    private const PARA_PROPS = ['align', 'borders', 'indentLeft', 'indentRight', 'keepNext', 'lineHeight', 'shading', 'spaceAfter', 'spaceBefore'];

    /** Run styling markdown has no slot for (underline and smallCaps count only when true). */
    private const RUN_STYLE = ['color', 'highlight', 'size', 'font', 'letterSpacing'];

    private const CELL_STYLE = ['shading', 'borders', 'padding', 'valign'];

    private const TABLE_LAYOUT = ['widths', 'width', 'align', 'borders', 'cellPadding'];

    /** Collecting a report, or null for the plain bridge. */
    private ?Report $report = null;

    /**
     * @param  array<string, mixed>  $doc
     */
    public function convert(array $doc): string
    {
        $this->report = null;

        return $this->render($doc, null);
    }

    /**
     * The markdown {@see convert()} returns, plus what it could not carry.
     * `$indexes` limits the output to those top-level blocks (an export's
     * selection) while every `where` still points into the whole document.
     *
     * @param  array<string, mixed>  $doc
     * @param  list<int>|null  $indexes
     * @return array{markdown: string, report: array<string, mixed>}
     */
    public function convertWithReport(array $doc, ?array $indexes = null): array
    {
        $this->report = new Report;
        try {
            $markdown = $this->render($doc, $indexes);

            return ['markdown' => $markdown, 'report' => $this->report->toArray()];
        } finally {
            $this->report = null;
        }
    }

    /**
     * @param  array<string, mixed>  $doc
     * @param  list<int>|null  $indexes
     */
    private function render(array $doc, ?array $indexes): string
    {
        $parts = [];

        $title = $doc['title'] ?? null;
        if ($indexes === null && is_string($title) && $title !== '') {
            $parts[] = '# ' . $this->escape($title);
        }

        $blocks = is_array($doc['blocks'] ?? null) ? $doc['blocks'] : [];
        foreach ($indexes ?? array_keys($blocks) as $i) {
            $block = $blocks[$i] ?? null;
            if (!is_array($block)) {
                continue;
            }
            $md = $this->block($block, '/blocks/' . $i);
            if ($md !== null && $md !== '') {
                $parts[] = $md;
            }
        }

        return implode("\n\n", $parts) . "\n";
    }

    /** Record a loss at a location; null locations are synthetic (a chart's table). */
    private function note(string $kind, ?string $where): void
    {
        if ($this->report !== null && $where !== null) {
            $this->report->add($kind, $where);
        }
    }

    /** @param  array<string, mixed>  $block */
    private function paragraphLayout(array $block, ?string $p): void
    {
        foreach (self::PARA_PROPS as $key) {
            if (array_key_exists($key, $block)) {
                $this->note('layout-not-preserved', $p);

                return;
            }
        }
    }

    /** @param  array<string, mixed>  $block */
    private function block(array $block, ?string $p = null): ?string
    {
        $type = $block['type'] ?? null;
        if ($type === 'heading' || $type === 'paragraph') {
            $this->paragraphLayout($block, $p);
        }
        if ($type === 'image' && (array_key_exists('widthPx', $block) || array_key_exists('heightPx', $block))) {
            $this->note('layout-not-preserved', $p);
        }

        return match ($type) {
            'heading' => str_repeat('#', max(1, min(6, (int) ($block['level'] ?? 1)))) . ' ' . $this->inline($block['runs'] ?? [], false, $p === null ? null : $p . '/runs'),
            'paragraph' => $this->inline($block['runs'] ?? [], false, $p === null ? null : $p . '/runs'),
            'list' => implode("\n", $this->list($block['items'] ?? [], (bool) ($block['ordered'] ?? false), 0, $p === null ? null : $p . '/items')),
            'table' => $this->table($block, $p),
            'code' => $this->code($block),
            'quote' => $this->quote($block, $p),
            'image' => $this->image($block),
            'chart' => $this->chart($block, $p),
            'pageBreak' => '<!-- pagebreak -->',
            'hr' => '---',
            default => null,
        };
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<string>
     */
    private function list(array $items, bool $ordered, int $depth, ?string $p = null): array
    {
        $lines = [];
        $indent = str_repeat('  ', $depth);
        $n = 1;
        foreach ($items as $k => $item) {
            if (!is_array($item)) {
                continue;
            }
            $ip = $p === null ? null : $p . '/' . $k;
            $this->paragraphLayout($item, $ip);
            $marker = $ordered ? ($n++ . '.') : '-';
            $lines[] = $indent . $marker . ' ' . $this->inline($item['runs'] ?? [], false, $ip === null ? null : $ip . '/runs');
            if (!empty($item['children']) && is_array($item['children'])) {
                array_push($lines, ...$this->list($item['children'], $ordered, $depth + 1, $ip === null ? null : $ip . '/children'));
            }
        }

        return $lines;
    }

    /** @param  array<string, mixed>  $block */
    private function table(array $block, ?string $p = null): string
    {
        $rows = array_values(array_filter($block['rows'] ?? [], 'is_array'));
        if ($rows === []) {
            return '';
        }

        foreach (self::TABLE_LAYOUT as $key) {
            if (array_key_exists($key, $block)) {
                $this->note('layout-not-preserved', $p);

                break;
            }
        }
        if ($p !== null) {
            foreach ($rows as $r => $row) {
                // GFM has one header row, the first. A later header row is a
                // body row there, and a first row that is not a header becomes one.
                if ($r > 0 && ($row['header'] ?? false) === true) {
                    $this->note('header-rows-flattened', $p . '/rows/' . $r);
                } elseif ($r === 0 && ($row['header'] ?? false) !== true) {
                    $this->note('layout-not-preserved', $p . '/rows/0');
                }
            }
        }

        $renderRow = function (array $row, ?string $rp): string {
            $cells = [];
            foreach (($row['cells'] ?? []) as $c => $cell) {
                $cells[] = is_array($cell) ? $this->cellText($cell, $rp === null ? null : $rp . '/cells/' . $c) : '';
            }

            return '| ' . implode(' | ', $cells) . ' |';
        };

        // GFM tables require a header row — the first row serves whether or
        // not it's flagged.
        $colCount = max(1, count($rows[0]['cells'] ?? []));
        $rp = static fn (int $r): ?string => $p === null ? null : $p . '/rows/' . $r;
        $lines = [$renderRow($rows[0], $rp(0))];
        $lines[] = '| ' . implode(' | ', array_fill(0, $colCount, '---')) . ' |';
        foreach (array_slice($rows, 1, null, true) as $r => $row) {
            $lines[] = $renderRow($row, $rp($r));
        }

        return implode("\n", $lines);
    }

    /** @param  array<string, mixed>  $cell */
    private function cellText(array $cell, ?string $cp = null): string
    {
        foreach (self::CELL_STYLE as $key) {
            if (array_key_exists($key, $cell)) {
                $this->note('cell-style-dropped', $cp);

                break;
            }
        }
        if (array_key_exists('colSpan', $cell) || array_key_exists('rowSpan', $cell)) {
            $this->note('merge-flattened', $cp); // GFM has no spans
        }
        $parts = [];
        $paragraphs = 0;
        foreach (($cell['blocks'] ?? []) as $k => $block) {
            if (!is_array($block)) {
                continue;
            }
            $bp = $cp === null ? null : $cp . '/blocks/' . $k;
            $type = $block['type'] ?? null;
            if ($type === 'paragraph' || $type === 'heading') {
                $this->paragraphLayout($block, $bp);
                $paragraphs++;
            } elseif ($type === 'code') {
                $this->note('code-as-text', $bp);
            } else {
                $this->note('nested-block', $bp);
            }
            $text = match ($type) {
                'paragraph', 'heading' => $this->inline($block['runs'] ?? [], true, $bp === null ? null : $bp . '/runs'),
                'code' => '`' . str_replace(["\n", '|'], [' ', '\\|'], (string) ($block['text'] ?? '')) . '`',
                default => null,
            };
            if ($text !== null && $text !== '') {
                $parts[] = $text;
            }
        }
        if ($paragraphs > 1) {
            $this->note('layout-not-preserved', $cp); // a cell's paragraphs share one line
        }

        return implode(' ', $parts);
    }

    /** @param  array<string, mixed>  $block */
    private function code(array $block): string
    {
        $language = $block['language'] ?? '';
        $text = str_replace("\r\n", "\n", (string) ($block['text'] ?? ''));

        return '```' . (is_string($language) ? $language : '') . "\n" . $text . "\n```";
    }

    /** @param  array<string, mixed>  $block */
    private function quote(array $block, ?string $p = null): string
    {
        $inner = [];
        foreach (($block['blocks'] ?? []) as $k => $child) {
            if (!is_array($child)) {
                continue;
            }
            $md = $this->block($child, $p === null ? null : $p . '/blocks/' . $k);
            if ($md !== null && $md !== '') {
                $inner[] = $md;
            }
        }
        $lines = explode("\n", implode("\n\n", $inner));

        return implode("\n", array_map(
            static fn (string $line): string => $line === '' ? '>' : '> ' . $line,
            $lines,
        ));
    }

    /** @param  array<string, mixed>  $block */
    /**
     * A chart as the data it plots: its title in bold, then a table with a
     * column of categories and one column per series. Markdown cannot hold a
     * chart; it can hold the numbers, which is what a reader of the markdown
     * needs. (Read back, this is a paragraph and a table -- not a chart.)
     *
     * @param  array<string, mixed>  $block
     */
    private function chart(array $block, ?string $p = null): ?string
    {
        $spec = Chart::spec($block['option'] ?? null);
        if ($spec === null) {
            $this->note('chart', $p);

            return null;
        }
        $this->note('chart-as-table', $p);
        $cell = static fn (string $text): array => ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => $text]]]]];

        $header = [$cell($spec['categoryTitle'])];
        foreach ($spec['series'] as $series) {
            $header[] = $cell($series['name']);
        }
        $rows = [['cells' => $header]];
        $count = count($spec['categories']);
        foreach ($spec['series'] as $series) {
            $count = max($count, count($series['values']));
        }
        for ($r = 0; $r < $count; $r++) {
            $cells = [$cell($spec['categories'][$r] ?? ($spec['categories'] === [] ? (string) ($r + 1) : ''))];
            foreach ($spec['series'] as $series) {
                $cells[] = $cell(array_key_exists($r, $series['values']) ? Chart::num($series['values'][$r]) : '');
            }
            $rows[] = ['cells' => $cells];
        }

        $table = $this->table(['rows' => $rows]);

        return $spec['title'] !== '' ? $this->inline([['text' => $spec['title'], 'bold' => true]]) . "\n\n" . $table : $table;
    }

    private function image(array $block): string
    {
        $alt = is_string($block['alt'] ?? null) ? $block['alt'] : '';

        return '![' . str_replace(['[', ']'], ['\\[', '\\]'], $alt) . '](' . (string) ($block['src'] ?? '') . ')';
    }

    // ─── Inline ──────────────────────────────────────────────────────────

    /**
     * Render runs as inline markdown, merging adjacent same-format runs so
     * we never emit `**a****b**`.
     *
     * @param  list<array<string, mixed>>  $runs
     */
    private function inline(array $runs, bool $inTable = false, ?string $p = null): string
    {
        $merged = [];
        foreach ($runs as $i => $run) {
            if (!is_array($run) || !is_string($run['text'] ?? null)) {
                continue;
            }
            if ($p !== null) {
                $styled = ($run['underline'] ?? false) === true || ($run['smallCaps'] ?? false) === true;
                foreach (self::RUN_STYLE as $key) {
                    $styled = $styled || array_key_exists($key, $run);
                }
                if ($styled) {
                    $this->note('run-style-dropped', $p . '/' . $i);
                }
                if (str_contains($run['text'], "\n") || str_contains($run['text'], "\r")) {
                    $this->note('layout-not-preserved', $p . '/' . $i); // a line break becomes a space
                }
            }
            $key = $this->formatKey($run);
            $last = count($merged) - 1;
            if ($last >= 0 && $merged[$last]['key'] === $key) {
                $merged[$last]['run']['text'] .= $run['text'];
            } else {
                $merged[] = ['key' => $key, 'run' => $run];
            }
        }

        $out = '';
        foreach ($merged as $entry) {
            $out .= $this->run($entry['run'], $inTable);
        }

        return $out;
    }

    /** @param  array<string, mixed>  $run */
    private function formatKey(array $run): string
    {
        return json_encode([
            (bool) ($run['bold'] ?? false),
            (bool) ($run['italic'] ?? false),
            (bool) ($run['strike'] ?? false),
            (bool) ($run['code'] ?? false),
            $run['link'] ?? null,
        ], JSON_THROW_ON_ERROR);
    }

    /** @param  array<string, mixed>  $run */
    private function run(array $run, bool $inTable): string
    {
        // Markdown can't nest across lines; flatten hard breaks to spaces.
        $text = str_replace(["\r\n", "\n"], ' ', (string) ($run['text'] ?? ''));

        if (!empty($run['code'])) {
            $s = '`' . str_replace('`', ' ', $text) . '`';
        } else {
            $s = $this->escape($text, $inTable);
        }
        if (!empty($run['strike'])) {
            $s = '~~' . $s . '~~';
        }
        if (!empty($run['italic'])) {
            $s = '*' . $s . '*';
        }
        if (!empty($run['bold'])) {
            $s = '**' . $s . '**';
        }
        if (isset($run['link']) && is_string($run['link']) && $run['link'] !== '') {
            $s = '[' . $s . '](' . $run['link'] . ')';
        }

        return $s;
    }

    private function escape(string $text, bool $inTable = false): string
    {
        $chars = self::ESCAPE;
        if ($inTable) {
            $chars[] = '|';
        }
        foreach ($chars as $char) {
            $text = str_replace($char, '\\' . $char, $text);
        }

        return $text;
    }
}
