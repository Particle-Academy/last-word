<?php

declare(strict_types=1);

namespace LastWord\Layout;

/**
 * Greedy line breaking, as Chromium does it for last-word-ui's text:
 * `white-space: pre-wrap` and `overflow-wrap: break-word`, kerning and
 * ligatures off.
 *
 * - A line takes characters while their advances fit the width. Spaces at the
 *   end of a line hang: they never push a line over.
 * - It breaks at the last break opportunity that fits; with none, at the
 *   character that overflows (break-word).
 * - Opportunities, as measured in Chromium (UAX #14 as Blink applies it to
 *   Latin text): after a space or a tab, after "-" unless a digit follows,
 *   after an en dash, an em dash, U+2010, "?" and U+2026. Never after "/",
 *   "!", ",", ".", ")", "%", quotes or a no-break space.
 * - A newline always breaks.
 *
 * Heights are LayoutUnits. A line box holds a strut at the paragraph's own
 * size; a run at another size adds its own box, each with Blink's half-leading
 * (the ascent side floored to a whole px), and the line is as tall as the
 * highest ascent plus the deepest descent among them.
 */
final class LineBreaker
{
    private const BREAK_AFTER = [0x2D => true, 0x2013 => true, 0x2014 => true, 0x2010 => true, 0x3F => true, 0x2026 => true];

    /**
     * @param  list<array{text: string, pt: float, bold?: bool, italic?: bool, ls?: float, mono?: bool, caps?: bool, mark?: int}>  $pieces
     *                                                                                                                                    A piece with `mark` is a footnote reference (a superscript): it takes width, never height.
     * @return array{height: int, lines: list<array{top: int, height: int, bottom: int}>, marks: array<int, int>}
     *                                                                                                            `bottom` is the bottom of the line's TEXT (what last-word-ui cuts a page at), from the block's top; `marks` maps a footnote id to the vertical centre of its line.
     */
    public static function layout(array $pieces, float $width, float $strutPt, float $multiple, Metrics $m): array
    {
        // One entry per character: codepoint, advance, the size it sets, and
        // whether it is a footnote mark.
        $chars = [];
        foreach ($pieces as $piece) {
            $pt = (float) $piece['pt'];
            $bold = (bool) ($piece['bold'] ?? false);
            $italic = (bool) ($piece['italic'] ?? false);
            $ls = (float) ($piece['ls'] ?? 0.0);
            $mono = (bool) ($piece['mono'] ?? false);
            $caps = (bool) ($piece['caps'] ?? false);
            $mark = $piece['mark'] ?? null;
            foreach (Metrics::codepoints((string) $piece['text']) as $cp) {
                if ($cp === 0x0D) {
                    continue;
                }
                if ($cp === 0x0A) {
                    $w = 0.0;
                } elseif ($mono) {
                    $w = $m->monoWidth($pt);
                } elseif ($caps && $cp >= 0x61 && $cp <= 0x7A) {
                    // Synthesised small caps: the capital at 70%.
                    $w = $m->charWidth($cp - 0x20, $pt * 0.7, $bold, $italic);
                } else {
                    $w = $m->charWidth($cp, $pt, $bold, $italic);
                }
                $chars[] = ['cp' => $cp, 'w' => $w + $ls, 'pt' => $pt, 'mark' => $mark, 'space' => $cp === 0x20 || $cp === 0x09];
            }
        }

        $n = count($chars);
        $breakBefore = array_fill(0, $n + 1, false);
        for ($i = 1; $i < $n; $i++) {
            $prev = $chars[$i - 1]['cp'];
            $cur = $chars[$i]['cp'];
            if ($chars[$i]['space'] || $cur === 0x0A) {
                continue;
            }
            if ($chars[$i - 1]['space']) {
                $breakBefore[$i] = true;
            } elseif (isset(self::BREAK_AFTER[$prev]) && ! ($prev === 0x2D && $cur >= 0x30 && $cur <= 0x39)) {
                $breakBefore[$i] = true;
            }
            // A footnote mark sits on its word: no break between them.
            if ($chars[$i]['mark'] !== null && $chars[$i - 1]['mark'] === null && ! $chars[$i - 1]['space']) {
                $breakBefore[$i] = false;
            }
        }

        $spaceW = $m->charWidth(0x20, $strutPt, false, false);
        $tabStop = 8 * $spaceW;

        /** @var list<array{0: int, 1: int}> $ranges [start, end) per line */
        $ranges = [];
        $start = 0;
        $i = 0;
        $x = 0.0;
        $lastBreak = null;
        $guard = 0;
        while ($i < $n && $guard++ < 10_000_000) {
            $c = $chars[$i];
            if ($c['cp'] === 0x0A) {
                $ranges[] = [$start, $i];
                $start = $i + 1;
                $i = $start;
                $x = 0.0;
                $lastBreak = null;

                continue;
            }
            if ($breakBefore[$i] && $i > $start) {
                $lastBreak = $i;
            }
            $w = $c['cp'] === 0x09 ? $tabStop - fmod($x, $tabStop) : $c['w'];
            if ($c['space']) {
                // Hanging: a space never overflows the line.
                $x += $w;
                $i++;

                continue;
            }
            if ($x + $w > $width + 1e-7 && $i > $start) {
                $at = $lastBreak !== null && $lastBreak > $start ? $lastBreak : $i;
                $ranges[] = [$start, $at];
                $start = $at;
                $i = $at;
                $x = 0.0;
                $lastBreak = null;

                continue;
            }
            $x += $w;
            $i++;
        }
        $ranges[] = [$start, $n];

        // Heights.
        $strut = self::box($strutPt, $multiple, $m);
        $lines = [];
        $marks = [];
        $top = 0;
        foreach ($ranges as $k => [$a, $b]) {
            $asc = $strut['asc'];
            $desc = $strut['desc'];
            $sizes = [];
            for ($j = $a; $j < $b; $j++) {
                if ($chars[$j]['mark'] === null) {
                    $sizes[(string) $chars[$j]['pt']] = $chars[$j]['pt'];
                }
            }
            foreach ($sizes as $pt) {
                if ($pt === $strutPt) {
                    continue;
                }
                $box = self::box($pt, $multiple, $m);
                $asc = max($asc, $box['asc']);
                $desc = max($desc, $box['desc']);
            }
            // The text's own rect ends at the baseline plus its descent; an
            // empty line still draws a zero-width space at the paragraph size.
            $textDesc = $sizes === [] ? $m->descent($strutPt) : 0;
            foreach ($sizes as $pt) {
                $textDesc = max($textDesc, $m->descent($pt));
            }
            $height = $asc + $desc;
            $lines[] = ['top' => $top, 'height' => $height, 'bottom' => $top + $asc + $textDesc];
            for ($j = $a; $j < $b; $j++) {
                if ($chars[$j]['mark'] !== null && ! isset($marks[$chars[$j]['mark']])) {
                    // The mark is a <sup> (vertical-align: super): its baseline
                    // rises by the paragraph's px size / 3 + 1, and last-word-ui
                    // takes the vertical centre of its text rect.
                    $markPt = $chars[$j]['pt'];
                    $shift = (int) floor((Metrics::px($strutPt) / 3 + 1) * 64 + 0.5);
                    $centre = intdiv($m->ascent($markPt) - $m->descent($markPt), 2);
                    $marks[$chars[$j]['mark']] = $top + $asc - $shift - $centre;
                }
            }
            $top += $height;
        }

        return ['height' => $top, 'lines' => $lines, 'marks' => $marks];
    }

    /**
     * One inline box: its ascent and descent sides around the baseline,
     * LayoutUnits, with Blink's half-leading split (the ascent side's share
     * floored to a whole px, the descent side taking the rest).
     *
     * @return array{asc: int, desc: int}
     */
    public static function box(float $pt, float $multiple, Metrics $m): array
    {
        $l = $m->lineBox($pt, $multiple);
        $a = $m->ascent($pt);
        $d = $m->descent($pt);
        $half = (int) floor(intdiv($l - $a - $d, 2) / 64) * 64;
        $asc = $a + $half;

        return ['asc' => $asc, 'desc' => $l - $asc];
    }
}
