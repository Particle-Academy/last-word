<?php

declare(strict_types=1);

namespace LastWord\Markdown;

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
 */
final class ToMarkdown
{
    /** Characters backslash-escaped in plain text. */
    private const ESCAPE = ['\\', '`', '*', '_', '~', '[', ']'];

    /**
     * @param  array<string, mixed>  $doc
     */
    public function convert(array $doc): string
    {
        $parts = [];

        $title = $doc['title'] ?? null;
        if (is_string($title) && $title !== '') {
            $parts[] = '# ' . $this->escape($title);
        }

        foreach (($doc['blocks'] ?? []) as $block) {
            if (!is_array($block)) {
                continue;
            }
            $md = $this->block($block);
            if ($md !== null && $md !== '') {
                $parts[] = $md;
            }
        }

        return implode("\n\n", $parts) . "\n";
    }

    /** @param  array<string, mixed>  $block */
    private function block(array $block): ?string
    {
        return match ($block['type'] ?? null) {
            'heading' => str_repeat('#', max(1, min(6, (int) ($block['level'] ?? 1)))) . ' ' . $this->inline($block['runs'] ?? []),
            'paragraph' => $this->inline($block['runs'] ?? []),
            'list' => implode("\n", $this->list($block['items'] ?? [], (bool) ($block['ordered'] ?? false), 0)),
            'table' => $this->table($block),
            'code' => $this->code($block),
            'quote' => $this->quote($block),
            'image' => $this->image($block),
            'pageBreak' => '<!-- pagebreak -->',
            'hr' => '---',
            default => null,
        };
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<string>
     */
    private function list(array $items, bool $ordered, int $depth): array
    {
        $lines = [];
        $indent = str_repeat('  ', $depth);
        $n = 1;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $marker = $ordered ? ($n++ . '.') : '-';
            $lines[] = $indent . $marker . ' ' . $this->inline($item['runs'] ?? []);
            if (!empty($item['children']) && is_array($item['children'])) {
                array_push($lines, ...$this->list($item['children'], $ordered, $depth + 1));
            }
        }

        return $lines;
    }

    /** @param  array<string, mixed>  $block */
    private function table(array $block): string
    {
        $rows = array_values(array_filter($block['rows'] ?? [], 'is_array'));
        if ($rows === []) {
            return '';
        }

        $renderRow = function (array $row): string {
            $cells = [];
            foreach (($row['cells'] ?? []) as $cell) {
                $cells[] = is_array($cell) ? $this->cellText($cell) : '';
            }

            return '| ' . implode(' | ', $cells) . ' |';
        };

        // GFM tables require a header row — the first row serves whether or
        // not it's flagged.
        $colCount = max(1, count($rows[0]['cells'] ?? []));
        $lines = [$renderRow($rows[0])];
        $lines[] = '| ' . implode(' | ', array_fill(0, $colCount, '---')) . ' |';
        foreach (array_slice($rows, 1) as $row) {
            $lines[] = $renderRow($row);
        }

        return implode("\n", $lines);
    }

    /** @param  array<string, mixed>  $cell */
    private function cellText(array $cell): string
    {
        $parts = [];
        foreach (($cell['blocks'] ?? []) as $block) {
            if (!is_array($block)) {
                continue;
            }
            $text = match ($block['type'] ?? null) {
                'paragraph', 'heading' => $this->inline($block['runs'] ?? [], true),
                'code' => '`' . str_replace(["\n", '|'], [' ', '\\|'], (string) ($block['text'] ?? '')) . '`',
                default => null,
            };
            if ($text !== null && $text !== '') {
                $parts[] = $text;
            }
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
    private function quote(array $block): string
    {
        $inner = [];
        foreach (($block['blocks'] ?? []) as $child) {
            if (!is_array($child)) {
                continue;
            }
            $md = $this->block($child);
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
    private function inline(array $runs, bool $inTable = false): string
    {
        $merged = [];
        foreach ($runs as $run) {
            if (!is_array($run) || !is_string($run['text'] ?? null)) {
                continue;
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
