<?php

declare(strict_types=1);

namespace LastWord\Layout;

/**
 * Measures blocks the way last-word-ui renders them (`render.tsx` and the
 * measuring copy in `DocViewer.tsx`), in Blink LayoutUnits (1/64 px).
 *
 * Each method mirrors one renderer: the same defaults (8pt after a paragraph,
 * headings 20/16/14/13/12/11pt with 12pt before and 6pt after, lists indented
 * 36pt, cell padding 3/5.4pt, 0.5pt borders), the same box model (paragraph
 * borders with 1pt / 4pt padding, border widths snapped to whole px as Blink
 * draws them) and the same line boxes. A change to a renderer there needs the
 * same change here; the shared agreement fixtures are what notice when it
 * does not happen.
 *
 * What is NOT exact, and why: text in a font other than Calibri (measured with
 * Carlito's advances, but the browser uses whatever that font is), code
 * (Consolas is not bundled), characters Carlito does not map, and date fields
 * in a band (their text depends on the reader's clock and locale).
 */
final class BlockMeasurer
{
    public const HEADING_SIZES = [20.0, 16.0, 14.0, 13.0, 12.0, 11.0];

    public const BAND_SIZE = 9.0;

    public const FOOTNOTE_SIZE = 10.0;

    private const LIST_INDENT = 36.0;

    private int $nextMark = 0;

    /** @param array<string, string>|null $fields band field values, by token name; null in the body */
    public function __construct(
        private readonly Metrics $m,
        private readonly bool $marks = true,
        private readonly ?array $fields = null,
    ) {}

    /**
     * Context: `size` (pt), `spaceAfter` (pt), `width` (LayoutUnits of the box
     * the block lays out in), `contentPt` (the column the renderer was told, in
     * points: images and charts are capped at it), `bold`, `italic`.
     *
     * @param  array<string, mixed>  $block
     * @param  array{size: float, spaceAfter: float, width: int, contentPt: float, bold: bool, italic: bool}  $ctx
     * @return array{h: int, bottoms: list<int>, marks: array<int, int>, rows?: list<array{h: int, marks: array<int, int>}>}
     */
    public function block(array $block, array $ctx): array
    {
        return match ($block['type'] ?? null) {
            'paragraph' => $this->paragraph($block, $ctx['size'], $ctx['spaceAfter'], 0.0, $ctx, false),
            'heading' => $this->paragraph(
                $block,
                self::HEADING_SIZES[(in_array($block['level'] ?? 1, [1, 2, 3, 4, 5, 6], true) ? $block['level'] : 1) - 1],
                6.0,
                12.0,
                $ctx,
                true,
            ),
            'list' => $this->listLevel($block['items'] ?? [], $ctx),
            'table' => $this->table($block, $ctx),
            'code' => $this->code($block, $ctx),
            'quote' => $this->quote($block, $ctx),
            'image' => $this->image($block, $ctx),
            'chart' => $this->chart($block, $ctx),
            'hr' => ['h' => Metrics::lu($ctx['spaceAfter']) + Metrics::lu($ctx['size'] * Metrics::SINGLE_LINE), 'bottoms' => [], 'marks' => []],
            default => ['h' => 0, 'bottoms' => [], 'marks' => []],
        };
    }

    private static function num(mixed $v, float $fallback): float
    {
        return (is_int($v) || is_float($v)) && is_finite((float) $v) ? (float) $v : $fallback;
    }

    /** A CSS border width in points as Blink draws it: under 1px is 1px, else whole px. */
    public static function borderLu(float $pt): int
    {
        $px = Metrics::px($pt);
        if ($px <= 0) {
            return 0;
        }

        return $px < 1 ? 64 : (int) floor($px + 1e-6) * 64;
    }

    /**
     * The drawn width of one border edge (`borderCss`), LayoutUnits; 0 for none.
     *
     * @param  mixed  $b
     */
    public static function edge($b): int
    {
        if (! is_array($b)) {
            return 0;
        }
        $style = $b['style'] ?? 'single';
        if (! in_array($style, ['single', 'double', 'dashed', 'dotted'], true)) {
            return 0;
        }

        return self::borderLu(max($style === 'double' ? 2.25 : 0.25, self::num($b['width'] ?? null, 0.5)));
    }

    /**
     * A paragraph or a heading: `spacingStyle` padding around a `paragraphStyle` box.
     *
     * @param  array<string, mixed>  $p
     * @param  array{size: float, spaceAfter: float, width: int, contentPt: float, bold: bool, italic: bool}  $ctx
     * @return array{h: int, bottoms: list<int>, marks: array<int, int>}
     */
    private function paragraph(array $p, float $size, float $defaultAfter, float $defaultBefore, array $ctx, bool $bold, bool $noIndent = false): array
    {
        $padTop = Metrics::lu(max(0.0, self::num($p['spaceBefore'] ?? null, $defaultBefore)));
        $padBottom = Metrics::lu(max(0.0, self::num($p['spaceAfter'] ?? null, $defaultAfter)));
        $box = $this->paraBox($p, $noIndent);
        $width = ($ctx['width'] - $box['ml'] - $box['mr'] - $box['bl'] - $box['br'] - $box['pl'] - $box['pr']) / 64;
        $pieces = $this->pieces($p['runs'] ?? [], $size, $ctx['bold'] || $bold, $ctx['italic']);
        $multiple = self::num($p['lineHeight'] ?? null, Metrics::DEFAULT_LINE_MULTIPLE) * Metrics::SINGLE_LINE;
        $text = LineBreaker::layout($pieces, $width, $size, $multiple, $this->m);
        $offset = $padTop + $box['bt'] + $box['pt'];

        return [
            'h' => $offset + $text['height'] + $box['pb'] + $box['bb'] + $padBottom,
            'bottoms' => array_map(static fn (array $l): int => $offset + $l['bottom'], $text['lines']),
            'marks' => array_map(static fn (int $at): int => $offset + $at, $text['marks']),
        ];
    }

    /**
     * Indents, borders and the padding a border brings, LayoutUnits.
     *
     * @param  array<string, mixed>  $p
     * @return array{ml: int, mr: int, bt: int, bb: int, bl: int, br: int, pt: int, pb: int, pl: int, pr: int}
     */
    private function paraBox(array $p, bool $noIndent): array
    {
        $b = is_array($p['borders'] ?? null) ? $p['borders'] : [];
        $bt = self::edge($b['top'] ?? null);
        $bb = self::edge($b['bottom'] ?? null);
        $bl = self::edge($b['left'] ?? null);
        $br = self::edge($b['right'] ?? null);

        return [
            'ml' => $noIndent ? 0 : Metrics::lu(self::num($p['indentLeft'] ?? null, 0.0)),
            'mr' => Metrics::lu(self::num($p['indentRight'] ?? null, 0.0)),
            'bt' => $bt, 'bb' => $bb, 'bl' => $bl, 'br' => $br,
            'pt' => $bt > 0 ? Metrics::lu(1.0) : 0,
            'pb' => $bb > 0 ? Metrics::lu(1.0) : 0,
            'pl' => $bl > 0 ? Metrics::lu(4.0) : 0,
            'pr' => $br > 0 ? Metrics::lu(4.0) : 0,
        ];
    }

    /**
     * Runs as line-breaker pieces: each run's size, weight, slant and letter
     * spacing, its footnote mark (a superscript at 0.65em) after it.
     *
     * @return list<array<string, mixed>>
     */
    private function pieces(mixed $runs, float $size, bool $bold, bool $italic): array
    {
        $out = [];
        $visible = false;
        foreach (is_array($runs) ? $runs : [] as $run) {
            if (! is_array($run)) {
                continue;
            }
            $text = is_string($run['text'] ?? null) ? $run['text'] : '';
            if ($this->fields !== null) {
                $text = $this->substitute($text);
            }
            if ($text !== '') {
                $visible = true;
                $pt = (is_int($run['size'] ?? null) || is_float($run['size'] ?? null)) && $run['size'] > 0 ? (float) $run['size'] : $size;
                $ls = (is_int($run['letterSpacing'] ?? null) || is_float($run['letterSpacing'] ?? null)) ? Metrics::px((float) $run['letterSpacing']) : 0.0;
                $out[] = [
                    'text' => $text,
                    'pt' => $pt,
                    'bold' => $bold || ! empty($run['bold']),
                    'italic' => $italic || ! empty($run['italic']),
                    'ls' => $ls,
                    'mono' => ! empty($run['code']),
                    'caps' => ! empty($run['smallCaps']),
                ];
            }
            if ($this->marks && is_array($run['footnote'] ?? null) && is_array($run['footnote']['blocks'] ?? null)) {
                $visible = true;
                $id = ++$this->nextMark;
                $out[] = ['text' => (string) $id, 'pt' => $size * 0.65, 'bold' => $bold, 'italic' => $italic, 'mark' => $id];
            }
        }
        if (! $visible) {
            // An empty paragraph still draws a line (a zero-width space).
            $out[] = ['text' => '', 'pt' => $size];
        }

        return $out;
    }

    /** Band field tokens as text, for measuring (see the class note on dates). */
    private function substitute(string $text): string
    {
        $fields = $this->fields ?? [];

        return (string) preg_replace_callback(
            '/\{(page|pages|section|sectionpages|author|title|filename)\}|\{(date|time)(?::([^{}"\\\\]+))?\}/',
            static function (array $m) use ($fields): string {
                if (($m[1] ?? '') !== '') {
                    return $fields[$m[1]] ?? '';
                }

                return $m[2] === 'time' ? '9:00:00 AM' : '11/1/2026';
            },
            $text,
        );
    }

    /**
     * @param  array{size: float, spaceAfter: float, width: int, contentPt: float, bold: bool, italic: bool}  $ctx
     * @return array{h: int, bottoms: list<int>, marks: array<int, int>}
     */
    private function listLevel(mixed $items, array $ctx): array
    {
        $indent = Metrics::lu(self::LIST_INDENT);
        $inner = ['width' => $ctx['width'] - $indent] + $ctx;
        $h = 0;
        $bottoms = [];
        $marks = [];
        foreach (is_array($items) ? $items : [] as $item) {
            $item = is_array($item) ? $item : [];
            $para = $this->paragraph($item, $ctx['size'], $ctx['spaceAfter'], 0.0, $inner, false, true);
            $this->place($para, $h, $bottoms, $marks);
            if (is_array($item['children'] ?? null) && $item['children'] !== []) {
                $this->place($this->listLevel($item['children'], $inner), $h, $bottoms, $marks);
            }
        }

        return ['h' => $h, 'bottoms' => $bottoms, 'marks' => $marks];
    }

    /**
     * Stack a measured piece under what is already there.
     *
     * @param  array{h: int, bottoms: list<int>, marks: array<int, int>}  $piece
     * @param  list<int>  $bottoms
     * @param  array<int, int>  $marks
     */
    private function place(array $piece, int &$h, array &$bottoms, array &$marks): void
    {
        foreach ($piece['bottoms'] as $b) {
            $bottoms[] = $h + $b;
        }
        foreach ($piece['marks'] as $id => $at) {
            $marks[$id] = $h + $at;
        }
        $h += $piece['h'];
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  array{size: float, spaceAfter: float, width: int, contentPt: float, bold: bool, italic: bool}  $ctx
     * @return array{h: int, bottoms: list<int>, marks: array<int, int>}
     */
    private function quote(array $block, array $ctx): array
    {
        $indent = Metrics::lu(36.0);
        $inner = ['width' => $ctx['width'] - $indent, 'contentPt' => $ctx['contentPt'] - 36, 'italic' => true] + $ctx;
        $h = 0;
        $bottoms = [];
        $marks = [];
        foreach (is_array($block['blocks'] ?? null) ? $block['blocks'] : [] as $child) {
            if (is_array($child)) {
                $this->place($this->block($child, $inner), $h, $bottoms, $marks);
            }
        }

        return ['h' => $h, 'bottoms' => $bottoms, 'marks' => $marks];
    }

    /**
     * Code: 10pt monospace at single spacing. Consolas is not bundled, so the
     * wrap is an estimate on its advance; the line height is Carlito's.
     *
     * @param  array<string, mixed>  $block
     * @param  array{size: float, spaceAfter: float, width: int, contentPt: float, bold: bool, italic: bool}  $ctx
     * @return array{h: int, bottoms: list<int>, marks: array<int, int>}
     */
    private function code(array $block, array $ctx): array
    {
        $text = is_string($block['text'] ?? null) ? $block['text'] : '';
        $r = LineBreaker::layout([['text' => $text, 'pt' => 10.0, 'mono' => true]], $ctx['width'] / 64, 10.0, Metrics::SINGLE_LINE, $this->m);

        return ['h' => $r['height'], 'bottoms' => array_map(static fn (array $l): int => $l['bottom'], $r['lines']), 'marks' => []];
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  array{size: float, spaceAfter: float, width: int, contentPt: float, bold: bool, italic: bool}  $ctx
     * @return array{h: int, bottoms: list<int>, marks: array<int, int>}
     */
    private function image(array $block, array $ctx): array
    {
        $size = ImageSize::resolve(
            is_string($block['src'] ?? null) ? $block['src'] : '',
            $block['widthPx'] ?? null,
            $block['heightPx'] ?? null,
            Metrics::px($ctx['contentPt']),
        );

        return ['h' => $size['height'] * 64 + Metrics::lu($ctx['spaceAfter']), 'bottoms' => [], 'marks' => []];
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  array{size: float, spaceAfter: float, width: int, contentPt: float, bold: bool, italic: bool}  $ctx
     * @return array{h: int, bottoms: list<int>, marks: array<int, int>}
     */
    private function chart(array $block, array $ctx): array
    {
        $max = Metrics::px($ctx['contentPt']);
        $pct = (is_int($block['width'] ?? null) || is_float($block['width'] ?? null)) && $block['width'] > 0 ? $max * min(100, (float) $block['width']) / 100 : $max;
        $width = min($max, self::num($block['widthPx'] ?? null, $pct));
        $height = self::num($block['heightPx'] ?? null, $width * 0.6);

        return ['h' => Metrics::luPx($height) + Metrics::lu($ctx['spaceAfter']), 'bottoms' => [], 'marks' => []];
    }

    // ── Tables ──────────────────────────────────────────────────────────

    /**
     * A table: collapsed borders, fixed column widths from `widths`, each row
     * as tall as its tallest single-row cell. Each edge between two rows is
     * shared: a row carries half of the border above it and half below.
     *
     * @param  array<string, mixed>  $block
     * @param  array{size: float, spaceAfter: float, width: int, contentPt: float, bold: bool, italic: bool}  $ctx
     * @return array{h: int, bottoms: list<int>, marks: array<int, int>, rows: list<array{h: int, marks: array<int, int>}>}
     */
    private function table(array $block, array $ctx): array
    {
        $rows = is_array($block['rows'] ?? null) ? array_values($block['rows']) : [];
        $grid = TableGrid::of($rows);
        $pct = TableGrid::columnPercents($grid['colCount'], $block['widths'] ?? null);
        $widthPct = (is_int($block['width'] ?? null) || is_float($block['width'] ?? null)) && $block['width'] > 0 ? min(100.0, (float) $block['width']) : 100.0;
        $tableW = (int) floor($ctx['width'] * $widthPct / 100);
        $hasBorders = is_array($block['borders'] ?? null);
        $tb = $hasBorders ? $block['borders'] : array_fill_keys(['top', 'right', 'bottom', 'left', 'insideH', 'insideV'], ['style' => 'single', 'width' => 0.5]);
        $padBase = is_array($block['cellPadding'] ?? null)
            ? self::sides(['top' => 0.0, 'right' => 5.4, 'bottom' => 0.0, 'left' => 5.4], $block['cellPadding'])
            : ['top' => 3.0, 'right' => 5.4, 'bottom' => 3.0, 'left' => 5.4];
        $n = count($rows);

        $rowOut = [];
        $total = 0;
        foreach ($rows as $r => $row) {
            $row = is_array($row) ? $row : [];
            $header = ($row['header'] ?? false) === true;
            $rowH = 0;
            $rowMarks = [];
            foreach ($grid['rows'][$r] ?? [] as $gc) {
                $cell = is_array($row['cells'][$gc['cell']] ?? null) ? $row['cells'][$gc['cell']] : [];
                $cb = is_array($cell['borders'] ?? null) ? $cell['borders'] : [];
                $edgeOf = static fn ($own, $outer, $inner, bool $isOuter): int => self::edge($own ?? ($isOuter ? $outer : $inner));
                $top = $edgeOf($cb['top'] ?? null, $tb['top'] ?? null, $tb['insideH'] ?? null, $gc['row'] === 0);
                $bottom = $edgeOf($cb['bottom'] ?? null, $tb['bottom'] ?? null, $tb['insideH'] ?? null, $gc['row'] + $gc['rowSpan'] >= $n);
                $left = $edgeOf($cb['left'] ?? null, $tb['left'] ?? null, $tb['insideV'] ?? null, $gc['col'] === 0);
                $right = $edgeOf($cb['right'] ?? null, $tb['right'] ?? null, $tb['insideV'] ?? null, $gc['col'] + $gc['colSpan'] >= $grid['colCount']);
                $pad = self::sides($padBase, $cell['padding'] ?? null);
                $share = array_sum(array_slice($pct, $gc['col'], $gc['colSpan'])) / 100;
                $colW = (int) floor($tableW * $share);
                $innerW = $colW - intdiv($left, 2) - intdiv($right + 1, 2) - Metrics::lu($pad['left']) - Metrics::lu($pad['right']);
                $inner = [
                    'width' => $innerW,
                    'contentPt' => $ctx['contentPt'] * $widthPct * $share / 100,
                    'bold' => $header || $ctx['bold'],
                ] + $ctx;
                $h = 0;
                $bottoms = [];
                $marks = [];
                foreach (is_array($cell['blocks'] ?? null) ? $cell['blocks'] : [] as $b) {
                    if (is_array($b)) {
                        $this->place($this->block($b, $inner), $h, $bottoms, $marks);
                    }
                }
                $cellH = intdiv($top, 2) + Metrics::lu($pad['top']) + $h + Metrics::lu($pad['bottom']) + ($bottom - intdiv($bottom, 2));
                foreach ($marks as $id => $at) {
                    $rowMarks[$id] = $at;
                }
                if ($gc['rowSpan'] === 1) {
                    $rowH = max($rowH, $cellH);
                }
            }
            $rowOut[] = ['h' => $rowH, 'marks' => $rowMarks];
            $total += $rowH;
        }

        return ['h' => $total, 'bottoms' => [], 'marks' => [], 'rows' => $rowOut];
    }

    /**
     * @param  array{top: float, right: float, bottom: float, left: float}  $base
     * @return array{top: float, right: float, bottom: float, left: float}
     */
    private static function sides(array $base, mixed $over): array
    {
        if (! is_array($over)) {
            return $base;
        }

        return [
            'top' => self::num($over['top'] ?? null, $base['top']),
            'right' => self::num($over['right'] ?? null, $base['right']),
            'bottom' => self::num($over['bottom'] ?? null, $base['bottom']),
            'left' => self::num($over['left'] ?? null, $base['left']),
        ];
    }

    // ── Bands + footnotes ───────────────────────────────────────────────

    /**
     * A header or footer's height: a one-line band (9pt at single spacing,
     * one line per text line) or its blocks.
     *
     * @param  array<string, mixed>  $band
     * @param  array{size: float, spaceAfter: float, width: int, contentPt: float, bold: bool, italic: bool}  $ctx
     */
    public function band(array $band, array $ctx): int
    {
        if (is_array($band['blocks'] ?? null)) {
            $h = 0;
            foreach ($band['blocks'] as $b) {
                if (is_array($b) && ! in_array($b['type'] ?? null, ['pageBreak', 'chart'], true)) {
                    $h += $this->block($b, $ctx)['h'];
                }
            }

            return $h;
        }
        $size = self::num($band['size'] ?? null, self::BAND_SIZE);
        $lines = 1;
        foreach (['left', 'center', 'right'] as $slot) {
            if (is_string($band[$slot] ?? null)) {
                $lines = max($lines, substr_count($this->substitute($band[$slot]), "\n") + 1);
            }
        }

        return $lines * $this->m->lineBox($size, Metrics::SINGLE_LINE);
    }

    /**
     * One footnote at the foot of a page: its number (6.5pt, line-height 1,
     * 2pt above) beside its blocks at 10pt with no space after, 3pt apart.
     *
     * @param  array<string, mixed>  $footnote
     * @param  array{size: float, spaceAfter: float, width: int, contentPt: float, bold: bool, italic: bool}  $ctx
     */
    public function footnote(int $id, array $footnote, array $ctx): int
    {
        $numPt = self::FOOTNOTE_SIZE * 0.65;
        $numW = (int) ceil($this->m->textWidth((string) $id, $numPt, false, false) * 64);
        $numH = $this->m->lineBox($numPt, 1.0) + Metrics::lu(2.0);
        $inner = ['size' => self::FOOTNOTE_SIZE, 'spaceAfter' => 0.0, 'width' => $ctx['width'] - $numW - Metrics::lu(3.0)] + $ctx;
        $h = 0;
        foreach (is_array($footnote['blocks'] ?? null) ? $footnote['blocks'] : [] as $b) {
            if (is_array($b)) {
                $h += $this->block($b, $inner)['h'];
            }
        }

        return max($h, $numH);
    }
}
