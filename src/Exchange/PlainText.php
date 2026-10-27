<?php

declare(strict_types=1);

namespace LastWord\Exchange;

/**
 * Portable content to UTF-8 plain text: the `text` export.
 *
 * Items are separated by a blank line. A heading or paragraph is its run
 * text; a list is one line per item, `- ` or `1. ` after two spaces per
 * depth; a table is one line per row, its display strings separated by TAB,
 * a span's covered slots written as empty fields. A plain-text file has no
 * slot for run styles, links or images, so each is reported, never silently
 * dropped.
 */
final class PlainText
{
    private Report $report;

    /**
     * @param  array<string, mixed>  $content  portable content that validated
     * @return array{text: string, report: array<string, mixed>}
     */
    public static function fromPortable(array $content): array
    {
        $x = new self;
        $x->report = new Report;
        $parts = [];
        foreach ($content['items'] as $item) {
            $text = match ($item['type']) {
                'text' => $x->runs($item['runs']),
                'list' => implode("\n", $x->listLines($item['items'], ($item['ordered'] ?? false) === true, 0)),
                'table' => $x->table($item),
                default => null,
            };
            if ($item['type'] === 'image') {
                $x->report->add('image');
            }
            if ($text !== null) {
                $parts[] = $text;
            }
        }

        return ['text' => $parts === [] ? '' : implode("\n\n", $parts)."\n", 'report' => $x->report->toArray()];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<string>
     */
    private function listLines(array $items, bool $ordered, int $depth): array
    {
        $lines = [];
        foreach ($items as $n => $entry) {
            $lines[] = str_repeat('  ', $depth).($ordered ? ($n + 1).'. ' : '- ').$this->runs($entry['runs']);
            if (! empty($entry['children'])) {
                array_push($lines, ...$this->listLines($entry['children'], $ordered, $depth + 1));
            }
        }

        return $lines;
    }

    /** @param array<string, mixed> $table */
    private function table(array $table): string
    {
        $anchors = Content::anchors($table['rows']);
        $width = 0;
        foreach ($table['rows'][0]['cells'] as $cell) {
            $width += (int) ($cell['colSpan'] ?? 1);
        }
        $grid = [];
        foreach ($table['rows'] as $r => $row) {
            $fields = array_fill(0, $width, '');
            foreach ($row['cells'] as $k => $cell) {
                $col = $anchors[$r][$k];
                $cs = (int) ($cell['colSpan'] ?? 1);
                $rs = (int) ($cell['rowSpan'] ?? 1);
                if ($cs > 1 || $rs > 1) {
                    $this->report->add('merge-flattened');
                }
                if ($cell['t'] !== 'empty' && $cell['t'] !== 'string') {
                    $this->report->add('typed-as-text');
                }
                if (isset($cell['runs'])) {
                    $this->styles($cell['runs']);
                }
                $display = $cell['text'] ?? Content::canonical($cell['t'], $cell['v'] ?? null);
                $field = str_replace(["\r\n", "\r", "\n", "\t"], ' ', $display);
                if ($field !== $display) {
                    $this->report->add('layout-not-preserved'); // a line break or tab inside a field
                }
                if ($col < $width) {
                    $fields[$col] = $field;
                }
            }
            $grid[] = implode("\t", $fields);
        }
        $lines = $grid;
        if (isset($table['caption'])) {
            array_unshift($lines, $table['caption']);
        }

        return implode("\n", $lines);
    }

    /** @param list<array<string, mixed>> $runs */
    private function runs(array $runs): string
    {
        $this->styles($runs);

        return implode('', array_column($runs, 'text'));
    }

    /** @param list<array<string, mixed>> $runs */
    private function styles(array $runs): void
    {
        foreach ($runs as $run) {
            if (isset($run['link'])) {
                $this->report->add('link');
            }
            foreach (['bold', 'italic', 'underline', 'strike', 'code'] as $flag) {
                if (($run[$flag] ?? false) === true) {
                    $this->report->add('run-style-dropped');

                    break;
                }
            }
        }
    }
}
