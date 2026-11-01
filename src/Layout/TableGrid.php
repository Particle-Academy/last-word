<?php

declare(strict_types=1);

namespace LastWord\Layout;

/**
 * Where each table cell sits on the column grid (last-word-ui `table-grid.ts`):
 * HTML-style spans, a row a span reaches into joined to the row above (no page
 * break before it), and the leading run of header rows.
 */
final class TableGrid
{
    /**
     * @param  list<mixed>  $rows
     * @return array{colCount: int, rows: list<list<array{row: int, cell: int, col: int, colSpan: int, rowSpan: int}>>, joinPrevious: list<bool>, headerRows: int}
     */
    public static function of(array $rows): array
    {
        $n = count($rows);
        $occupied = array_fill(0, $n, []);
        $join = array_fill(0, $n, false);
        $span = static fn (mixed $v): int => is_int($v) && $v > 1 ? $v : 1;
        $out = [];
        foreach ($rows as $r => $row) {
            $placed = [];
            $col = 0;
            $cells = is_array($row) && is_array($row['cells'] ?? null) ? array_values($row['cells']) : [];
            foreach ($cells as $c => $cell) {
                while (isset($occupied[$r][$col])) {
                    $col++;
                }
                $colSpan = $span(is_array($cell) ? ($cell['colSpan'] ?? null) : null);
                $rowSpan = min($span(is_array($cell) ? ($cell['rowSpan'] ?? null) : null), $n - $r);
                for ($dr = 0; $dr < $rowSpan; $dr++) {
                    for ($dc = 0; $dc < $colSpan; $dc++) {
                        $occupied[$r + $dr][$col + $dc] = true;
                    }
                    if ($dr > 0) {
                        $join[$r + $dr] = true;
                    }
                }
                $placed[] = ['row' => $r, 'cell' => $c, 'col' => $col, 'colSpan' => $colSpan, 'rowSpan' => $rowSpan];
                $col += $colSpan;
            }
            $out[] = $placed;
        }
        $colCount = 0;
        foreach ($occupied as $set) {
            foreach (array_keys($set) as $c) {
                $colCount = max($colCount, $c + 1);
            }
        }
        $headerRows = 0;
        while ($headerRows < $n && is_array($rows[$headerRows]) && ($rows[$headerRows]['header'] ?? false) === true) {
            $headerRows++;
        }

        return ['colCount' => max(1, $colCount), 'rows' => $out, 'joinPrevious' => $join, 'headerRows' => $headerRows];
    }

    /**
     * Column widths as percentages: `widths` as relative weights when there is
     * one per column, else even.
     *
     * @return list<float>
     */
    public static function columnPercents(int $colCount, mixed $widths): array
    {
        $n = max(1, $colCount);
        $even = array_fill(0, $n, 100 / $n);
        if (! is_array($widths) || count($widths) !== $n) {
            return $even;
        }
        $w = array_map(static fn ($x): float => (is_int($x) || is_float($x)) && is_finite((float) $x) && $x > 0 ? (float) $x : 0.0, array_values($widths));
        $total = array_sum($w);
        if ($total <= 0) {
            return $even;
        }

        return array_map(static fn (float $x): float => floor($x / $total * 100 * 1e6 + 0.5) / 1e6, $w);
    }
}
