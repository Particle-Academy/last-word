<?php

declare(strict_types=1);

namespace LastWord\Markdown;

/**
 * GFM markdown → Doc model. The Editor bridge's inbound half — a
 * hand-rolled parser (no external markdown dependency) covering the subset
 * {@see ToMarkdown} emits:
 *
 *   `#` headings, paragraphs, `**bold**` / `*italic*` / `_italic_` /
 *   `~~strike~~` / `` `code` `` / `[text](url)` inline, ordered + unordered
 *   nested lists (2-space indent per level), GFM tables, fenced code blocks,
 *   `>` blockquotes, standalone `![alt](src)` images, `---` hr and the
 *   `<!-- pagebreak -->` comment convention.
 *
 * The result never has a `title` — markdown has no title slot; a leading
 * `#` line stays a level-1 heading block.
 */
final class FromMarkdown
{
    /**
     * @return array<string, mixed> Doc
     */
    public function convert(string $markdown): array
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $markdown);
        $lines = explode("\n", $normalized);

        return ['blocks' => $this->parseBlocks($lines)];
    }

    /**
     * @param  list<string>  $lines
     * @return list<array<string, mixed>>
     */
    private function parseBlocks(array $lines): array
    {
        $blocks = [];
        $i = 0;
        $count = count($lines);

        while ($i < $count) {
            $line = $lines[$i];

            if (trim($line) === '') {
                $i++;

                continue;
            }

            // Fenced code
            if (preg_match('/^```(.*)$/', $line, $m) === 1) {
                $language = trim($m[1]);
                $language = $language !== '' ? preg_split('/\s+/', $language)[0] : '';
                $codeLines = [];
                $i++;
                while ($i < $count && preg_match('/^```\s*$/', $lines[$i]) !== 1) {
                    $codeLines[] = $lines[$i];
                    $i++;
                }
                $i++; // consume closing fence (or EOF)
                $block = ['type' => 'code'];
                if ($language !== '') {
                    $block['language'] = $language;
                }
                $block['text'] = implode("\n", $codeLines);
                $blocks[] = $block;

                continue;
            }

            // Heading
            if (preg_match('/^(#{1,6})\s+(.*)$/', $line, $m) === 1) {
                $blocks[] = [
                    'type' => 'heading',
                    'level' => strlen($m[1]),
                    'runs' => $this->inline(rtrim($m[2])),
                ];
                $i++;

                continue;
            }

            // Page break comment
            if (preg_match('/^<!--\s*page\s?break\s*-->\s*$/i', $line) === 1) {
                $blocks[] = ['type' => 'pageBreak'];
                $i++;

                continue;
            }

            // Thematic break — must be checked before lists (`-` overlap)
            if (preg_match('/^ {0,3}(-{3,}|\*{3,}|_{3,})\s*$/', $line) === 1) {
                $blocks[] = ['type' => 'hr'];
                $i++;

                continue;
            }

            // Blockquote
            if (preg_match('/^ {0,3}>/', $line) === 1) {
                $quoteLines = [];
                while ($i < $count && preg_match('/^ {0,3}>/', $lines[$i]) === 1) {
                    $quoteLines[] = preg_replace('/^ {0,3}> ?/', '', $lines[$i], 1);
                    $i++;
                }
                $blocks[] = ['type' => 'quote', 'blocks' => $this->parseBlocks($quoteLines)];

                continue;
            }

            // List
            if ($this->matchListLine($line) !== null) {
                $entries = [];
                while ($i < $count && ($entry = $this->matchListLine($lines[$i])) !== null) {
                    $entries[] = $entry;
                    $i++;
                }
                $blocks[] = $this->assembleList($entries);

                continue;
            }

            // Table: a pipe row followed by a separator row
            if (str_contains($line, '|') && $i + 1 < $count && $this->isTableSeparator($lines[$i + 1])) {
                $rows = [];
                $header = $this->splitTableRow($line);
                $rows[] = ['header' => true, 'cells' => $header];
                $i += 2; // skip header + separator
                while ($i < $count && trim($lines[$i]) !== '' && str_contains($lines[$i], '|')) {
                    $rows[] = ['cells' => $this->splitTableRow($lines[$i])];
                    $i++;
                }
                $blocks[] = ['type' => 'table', 'rows' => $rows];

                continue;
            }

            // Standalone image
            if (preg_match('/^!\[((?:\\\\.|[^\]\\\\])*)\]\(([^)\s]+)(?:\s+"[^"]*")?\)\s*$/', trim($line), $m) === 1) {
                $block = ['type' => 'image', 'src' => $m[2]];
                $alt = str_replace(['\\[', '\\]'], ['[', ']'], $m[1]);
                if ($alt !== '') {
                    $block['alt'] = $alt;
                }
                $blocks[] = $block;
                $i++;

                continue;
            }

            // Paragraph: consume until a blank line or a new block form
            $paraLines = [$line];
            $i++;
            while ($i < $count && trim($lines[$i]) !== '' && !$this->startsBlock($lines[$i])) {
                $paraLines[] = $lines[$i];
                $i++;
            }
            $blocks[] = [
                'type' => 'paragraph',
                'runs' => $this->inline(implode(' ', array_map('trim', $paraLines))),
            ];
        }

        return $blocks;
    }

    private function startsBlock(string $line): bool
    {
        return preg_match('/^(#{1,6})\s+/', $line) === 1
            || str_starts_with($line, '```')
            || preg_match('/^ {0,3}>/', $line) === 1
            || preg_match('/^ {0,3}(-{3,}|\*{3,}|_{3,})\s*$/', $line) === 1
            || $this->matchListLine($line) !== null;
    }

    // ─── Lists ───────────────────────────────────────────────────────────

    /**
     * @return array{depth: int, ordered: bool, content: string}|null
     */
    private function matchListLine(string $line): ?array
    {
        if (preg_match('/^([ \t]*)([-*+])\s+(\S.*)$/', $line, $m) === 1) {
            return ['depth' => $this->indentDepth($m[1]), 'ordered' => false, 'content' => rtrim($m[3])];
        }
        if (preg_match('/^([ \t]*)(\d{1,9})[.)]\s+(\S.*)$/', $line, $m) === 1) {
            return ['depth' => $this->indentDepth($m[1]), 'ordered' => true, 'content' => rtrim($m[3])];
        }

        return null;
    }

    /** Nesting depth: 2 spaces (or one tab) per level. */
    private function indentDepth(string $indent): int
    {
        $width = 0;
        foreach (str_split($indent) as $char) {
            $width += $char === "\t" ? 2 : 1;
        }

        return intdiv($width, 2);
    }

    /**
     * @param  list<array{depth: int, ordered: bool, content: string}>  $entries
     * @return array<string, mixed>
     */
    private function assembleList(array $entries): array
    {
        $block = ['type' => 'list'];
        if ($entries[0]['ordered']) {
            $block['ordered'] = true;
        }
        $block['items'] = [];

        $stack = [&$block['items']];

        foreach ($entries as $entry) {
            $depth = min($entry['depth'], count($stack));
            while (count($stack) - 1 > $depth) {
                array_pop($stack);
            }
            $parent = &$stack[count($stack) - 1];
            if ($depth > count($stack) - 1 && $parent !== []) {
                $lastIndex = count($parent) - 1;
                if (!isset($parent[$lastIndex]['children'])) {
                    $parent[$lastIndex]['children'] = [];
                }
                $stack[] = &$parent[$lastIndex]['children'];
                unset($parent);
                $parent = &$stack[count($stack) - 1];
            }
            $parent[] = ['runs' => $this->inline($entry['content'])];
            unset($parent);
        }

        return $block;
    }

    // ─── Tables ──────────────────────────────────────────────────────────

    private function isTableSeparator(string $line): bool
    {
        $trimmed = trim($line);
        if ($trimmed === '' || !str_contains($trimmed, '-')) {
            return false;
        }

        return preg_match('/^\|?[\s:|-]+\|?$/', $trimmed) === 1 && str_contains($trimmed, '|');
    }

    /**
     * @return list<array{blocks: list<array<string, mixed>>}>
     */
    private function splitTableRow(string $line): array
    {
        $trimmed = trim($line);
        if (str_starts_with($trimmed, '|')) {
            $trimmed = substr($trimmed, 1);
        }
        if (str_ends_with($trimmed, '|')) {
            $trimmed = substr($trimmed, 0, -1);
        }

        $raw = preg_split('/(?<!\\\\)\|/', $trimmed);
        $cells = [];
        foreach ($raw as $cellText) {
            $cellText = trim(str_replace('\\|', '|', $cellText));
            $cells[] = ['blocks' => $cellText === ''
                ? []
                : [['type' => 'paragraph', 'runs' => $this->inline($cellText)]],
            ];
        }

        return $cells;
    }

    // ─── Inline ──────────────────────────────────────────────────────────

    /**
     * Recursive-descent inline parser. `$flags` accumulate through nesting
     * (`**bold *italic***` → bold+italic run).
     *
     * @param  array<string, mixed>  $flags
     * @return list<array<string, mixed>>
     */
    private function inline(string $text, array $flags = []): array
    {
        $runs = [];
        $plain = '';
        $i = 0;
        $len = strlen($text);

        $flush = function () use (&$runs, &$plain, $flags): void {
            if ($plain !== '') {
                $runs[] = array_merge(['text' => $plain], $flags);
                $plain = '';
            }
        };

        while ($i < $len) {
            $ch = $text[$i];

            // Backslash escape
            if ($ch === '\\' && $i + 1 < $len) {
                $plain .= $text[$i + 1];
                $i += 2;

                continue;
            }

            // Code span
            if ($ch === '`') {
                $end = strpos($text, '`', $i + 1);
                if ($end !== false && $end > $i + 1) {
                    $flush();
                    $runs[] = array_merge(
                        ['text' => substr($text, $i + 1, $end - $i - 1)],
                        $flags,
                        ['code' => true],
                    );
                    $i = $end + 1;

                    continue;
                }
            }

            // Bold / italic (asterisk)
            if ($ch === '*') {
                if (substr($text, $i, 2) === '**') {
                    $end = strpos($text, '**', $i + 2);
                    if ($end !== false && $end > $i + 2) {
                        $flush();
                        $inner = substr($text, $i + 2, $end - $i - 2);
                        array_push($runs, ...$this->inline($inner, array_merge($flags, ['bold' => true])));
                        $i = $end + 2;

                        continue;
                    }
                } else {
                    $end = strpos($text, '*', $i + 1);
                    if ($end !== false && $end > $i + 1) {
                        $flush();
                        $inner = substr($text, $i + 1, $end - $i - 1);
                        array_push($runs, ...$this->inline($inner, array_merge($flags, ['italic' => true])));
                        $i = $end + 1;

                        continue;
                    }
                }
            }

            // Italic (underscore)
            if ($ch === '_') {
                $end = strpos($text, '_', $i + 1);
                if ($end !== false && $end > $i + 1) {
                    $flush();
                    $inner = substr($text, $i + 1, $end - $i - 1);
                    array_push($runs, ...$this->inline($inner, array_merge($flags, ['italic' => true])));
                    $i = $end + 1;

                    continue;
                }
            }

            // Strikethrough
            if ($ch === '~' && substr($text, $i, 2) === '~~') {
                $end = strpos($text, '~~', $i + 2);
                if ($end !== false && $end > $i + 2) {
                    $flush();
                    $inner = substr($text, $i + 2, $end - $i - 2);
                    array_push($runs, ...$this->inline($inner, array_merge($flags, ['strike' => true])));
                    $i = $end + 2;

                    continue;
                }
            }

            // Link
            if ($ch === '[' && preg_match('/\[((?:\\\\.|[^\]\\\\])*)\]\(([^)\s]+)(?:\s+"[^"]*")?\)/A', $text, $m, 0, $i) === 1) {
                $flush();
                array_push($runs, ...$this->inline($m[1], array_merge($flags, ['link' => $m[2]])));
                $i += strlen($m[0]);

                continue;
            }

            // Inline image — the model has no inline-image run; degrade to alt text
            if ($ch === '!' && preg_match('/!\[((?:\\\\.|[^\]\\\\])*)\]\(([^)\s]+)(?:\s+"[^"]*")?\)/A', $text, $m, 0, $i) === 1) {
                $plain .= str_replace(['\\[', '\\]'], ['[', ']'], $m[1]);
                $i += strlen($m[0]);

                continue;
            }

            $plain .= $ch;
            $i++;
        }

        $flush();

        return $this->mergeRuns($runs);
    }

    /**
     * @param  list<array<string, mixed>>  $runs
     * @return list<array<string, mixed>>
     */
    private function mergeRuns(array $runs): array
    {
        $merged = [];
        foreach ($runs as $run) {
            if (($run['text'] ?? '') === '') {
                continue;
            }
            $last = count($merged) - 1;
            if ($last >= 0) {
                $aProps = $merged[$last];
                $bProps = $run;
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
}
