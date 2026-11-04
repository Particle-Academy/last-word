<?php

declare(strict_types=1);

namespace LastWord\Layout;

use LastWord\Schema\Schema;

/**
 * The page layout last-word-ui draws for a Doc, computed without a browser.
 *
 * The viewer renders a hidden measuring copy, measures each block, derives one
 * body height per page class from the bands that class draws (page 1, even
 * pages, the other odd pages), and packs the measurements with `paginate()`.
 * This does the same: {@see BlockMeasurer} stands in for the browser, and
 * {@see Packer} is the packer. A document whose page changes partway does this
 * once per stretch, each at its own page and text width.
 *
 * Exact for text in Calibri (or no font named), which last-word-ui 0.3.0 draws
 * in a bundled Carlito so every machine agrees. Everything this cannot know
 * exactly is listed in the result's `approximate`.
 */
final class PageLayout
{
    /** Paper in points, as the viewer has it (`geometry.ts`). */
    private const PAPER = ['letter' => [612.0, 792.0], 'legal' => [612.0, 1008.0], 'a4' => [595.3, 841.9]];

    private const SPLITTABLE = ['paragraph', 'heading', 'list', 'quote', 'code'];

    private const BAND_KEYS = [
        'header' => ['default' => 'header', 'first' => 'firstHeader', 'even' => 'evenHeader'],
        'footer' => ['default' => 'footer', 'first' => 'firstFooter', 'even' => 'evenFooter'],
    ];

    /**
     * @param  array<string, mixed>  $doc  a valid Doc (the viewer's input)
     * @return array<string, mixed>
     */
    public static function of(array $doc): array
    {
        $x = self::measure($doc);
        $m = Metrics::load();
        $pages = Packer::paginate($x['items'], self::heights($x['bodies']) + ['footnoteSeparator' => $x['footnoteSeparator']]);
        [$size, $w, $h] = $x['paper'];

        $list = [];
        $starts = [];
        foreach ($pages as $p) {
            $first = $p['fragments'][0] ?? null;
            $starts[] = $first['item'] ?? null;
            $ids = [];
            foreach ($p['fragments'] as $f) {
                if (! in_array($f['item'], $ids, true)) {
                    $ids[] = $f['item'];
                }
            }
            $continued = $first !== null && (($first['type'] === 'block' && $first['start'] > 1e-6) || ($first['type'] === 'table' && $first['repeatedHeader']));
            $stretch = $x['stretches'][$p['stretch']];
            $list[] = [
                'page' => $p['index'] + 1,
                'section' => $p['stretch'] + 1,
                'width' => $stretch['paper'][1],
                'height' => $stretch['paper'][2],
                'blocks' => $ids,
                'continued' => $continued,
                'footnotes' => $p['footnotes'],
                'used' => round($p['used'] * 0.75, 2),
                'capacity' => round($p['capacity'] * 0.75, 2),
                'fill' => $p['capacity'] > 0 ? round(min(1.0, $p['used'] / $p['capacity']), 3) : 0.0,
            ];
        }

        return [
            'pages' => count($pages),
            'pageStarts' => $starts,
            'pageList' => $list,
            'page' => ['size' => $size, 'width' => $w, 'height' => $h],
            'font' => 'Carlito ' . preg_replace('/^Version\s+/', '', (string) ($m->provenance()['version'] ?? '')),
            'approximate' => Approximations::of($doc, $m),
        ];
    }

    /**
     * What the viewer's measuring copy reports, before packing: the packer's
     * items (CSS px), each band slot's height (px; 0 when not drawn), each
     * footnote's height (px), the body height of each page class (pt) and the
     * paper. For checking a disagreement against the viewer, block by block.
     * `bands`, `bodies` and `paper` are the first stretch's; `stretches` holds them
     * for every stretch.
     *
     * @param  array<string, mixed>  $doc
     * @return array{items: list<array<string, mixed>>, bands: array<string, array<string, float>>, footnotes: array<int, float>, bodies: array<string, float>, footnoteSeparator: float, paper: array{0: string, 1: float, 2: float}, stretches: list<array{bands: array<string, array<string, float>>, bodies: array<string, float>, paper: array{0: string, 1: float, 2: float}}>}
     */
    public static function measure(array $doc): array
    {
        $m = Metrics::load();
        $defaultSize = (is_int($doc['defaultSize'] ?? null) || is_float($doc['defaultSize'] ?? null)) ? (float) $doc['defaultSize'] : 11.0;
        $base = [];
        $starts = [];
        $stretches = Schema::stretches($doc);
        foreach ($stretches as $k => $stretch) {
            $page = is_array($stretch['doc']['page'] ?? null) ? $stretch['doc']['page'] : [];
            $size = is_string($page['size'] ?? null) && isset(self::PAPER[$page['size']]) ? $page['size'] : 'letter';
            [$w, $h] = self::PAPER[$size];
            if (($page['orientation'] ?? null) === 'landscape') {
                [$w, $h] = [$h, $w];
            }
            $margins = Schema::pageMargins($stretch['doc']);
            $contentPt = max(1.0, $w - $margins['left'] - $margins['right']);
            $base[$k] = [
                'size' => $defaultSize,
                'spaceAfter' => 8.0,
                'width' => Metrics::lu($contentPt),
                'contentPt' => $contentPt,
                'bold' => false,
                'italic' => false,
            ];
            $stretches[$k]['paper'] = [$size, $w, $h];
            $stretches[$k]['margins'] = $margins;
            $stretches[$k]['page'] = $page;
            if ($stretch['break'] !== null) {
                $starts[$stretch['break']] = $k;
            }
        }

        // The body, in document order: the measurer numbers footnote marks as
        // it meets them, which is the viewer's numbering order too.
        $body = new BlockMeasurer($m);
        $blocks = is_array($doc['blocks'] ?? null) ? array_values($doc['blocks']) : [];
        $measured = [];
        $k = 0;
        foreach ($blocks as $i => $block) {
            $k = $starts[$i] ?? $k;
            $measured[] = is_array($block) && ($block['type'] ?? null) !== 'pageBreak' ? $body->block($block, $base[$k]) : null;
        }

        // A note measures at the text width of the page holding its mark.
        $noteHeights = [];
        $noteMeasurer = new BlockMeasurer($m, false);
        foreach ($stretches as $k => $stretch) {
            $offset = count($noteHeights);
            foreach (self::footnotes(array_values($stretch['blocks'])) as $id => $fn) {
                $noteHeights[$offset + $id] = $noteMeasurer->footnote($offset + $id, $fn, $base[$k]) / 64;
            }
        }

        // Bands, each slot measured as a page of its class draws it.
        $author = is_array($doc['meta'] ?? null) && is_string($doc['meta']['author'] ?? null) ? $doc['meta']['author'] : '';
        $title = is_string($doc['title'] ?? null) ? $doc['title'] : '';
        $out = [];
        foreach ($stretches as $k => $stretch) {
            $heights = ['header' => [], 'footer' => []];
            foreach (self::BAND_KEYS as $kind => $slots) {
                foreach ($slots as $slot => $key) {
                    $band = self::slotBand($stretch['page'], $key);
                    $n = $slot === 'even' ? '2' : '1';
                    $fields = ['page' => $n, 'pages' => $n, 'section' => (string) ($k + 1), 'sectionpages' => $n, 'author' => $author, 'title' => $title, 'filename' => ''];
                    $heights[$kind][$slot] = $band !== null && self::hasBand($band)
                        ? (new BlockMeasurer($m, false, $fields))->band($band, $base[$k]) / 64
                        : 0.0;
                }
            }
            [, , $h] = $stretch['paper'];
            $margins = $stretch['margins'];
            $boxes = [];
            foreach (['first', 'even', 'odd', 'firstEven'] as $class) {
                $hh = self::classHeight($stretch['page'], 'header', $class, $heights);
                $fh = self::classHeight($stretch['page'], 'footer', $class, $heights);
                $top = max($margins['top'], $hh > 0 ? $margins['header'] + $hh : 0.0);
                $bottom = max($margins['bottom'], $fh > 0 ? $margins['footer'] + $fh : 0.0);
                $boxes[$class] = max(1.0, $h - $top - $bottom);
            }
            $out[$k] = ['bands' => $heights, 'bodies' => $boxes, 'paper' => $stretch['paper']];
        }

        $items = [];
        foreach ($blocks as $i => $block) {
            $item = self::item(is_array($block) ? $block : [], $measured[$i], $noteHeights);
            if (isset($starts[$i])) {
                $item['stretch'] = self::heights($out[$starts[$i]]['bodies'], true);
            }
            $items[] = $item;
        }

        return [
            'items' => $items,
            'bands' => $out[0]['bands'],
            'footnotes' => $noteHeights,
            'bodies' => array_diff_key($out[0]['bodies'], ['firstEven' => true]),
            'footnoteSeparator' => $noteHeights === [] ? 0.0 : Metrics::lu(12.0) / 64,
            'paper' => $out[0]['paper'],
            'stretches' => $out,
        ];
    }

    /**
     * A stretch's body heights as the packer takes them, in px. Page 1 is odd, so
     * the first stretch has no first-page-on-an-even-page height.
     *
     * @param  array<string, float>  $bodies  pt
     * @return array<string, float>
     */
    private static function heights(array $bodies, bool $later = false): array
    {
        return [
            'pageHeight' => Metrics::px($bodies['odd']),
            'firstPageHeight' => Metrics::px($bodies['first']),
            'evenPageHeight' => Metrics::px($bodies['even']),
        ] + ($later ? ['firstEvenPageHeight' => Metrics::px($bodies['firstEven'])] : []);
    }

    /**
     * The packer's item for one top-level block (`measure.ts`).
     *
     * @param  array<string, mixed>  $block
     * @param  array{h: int, bottoms: list<int>, marks: array<int, int>, rows?: list<array{h: int, marks: array<int, int>}>}|null  $r
     * @param  array<int, float>  $noteHeights
     * @return array<string, mixed>
     */
    private static function item(array $block, ?array $r, array $noteHeights): array
    {
        if (($block['type'] ?? null) === 'pageBreak' || $r === null) {
            return ['type' => 'break'];
        }
        if ($block['type'] === 'table') {
            $grid = TableGrid::of(is_array($block['rows'] ?? null) ? array_values($block['rows']) : []);
            $rows = [];
            foreach ($r['rows'] ?? [] as $i => $row) {
                $notes = [];
                foreach (array_keys($row['marks']) as $id) {
                    $notes[] = ['id' => $id, 'height' => $noteHeights[$id] ?? 0.0];
                }
                $rows[] = ['height' => $row['h'] / 64, 'joinPrevious' => $grid['joinPrevious'][$i] ?? false, 'footnotes' => $notes];
            }

            return ['type' => 'table', 'rows' => $rows, 'headerRows' => $grid['headerRows']];
        }

        $height = $r['h'];
        $bps = [];
        if (in_array($block['type'] ?? null, self::SPLITTABLE, true)) {
            // `lineBreakpoints`: the bottom of every text line but the last,
            // inside the block.
            $bottoms = $r['bottoms'];
            sort($bottoms);
            array_pop($bottoms);
            $bps = array_values(array_filter($bottoms, static fn (int $b): bool => $b > 0 && $b < $height - 32));
            if ($block['type'] === 'paragraph' || $block['type'] === 'heading') {
                // Widow and orphan control: never one line alone at a foot or a head.
                $bps = array_slice($bps, 1, -1);
            }
        }
        $notes = [];
        foreach ($r['marks'] as $id => $at) {
            $notes[] = ['id' => $id, 'at' => min($height, max(0, $at)) / 64, 'height' => $noteHeights[$id] ?? 0.0];
        }

        return [
            'type' => 'block',
            'height' => $height / 64,
            'breakpoints' => array_map(static fn (int $b): float => $b / 64, $bps),
            'keepNext' => $block['type'] === 'heading' || ($block['keepNext'] ?? false) === true,
            'footnotes' => $notes,
        ];
    }

    /**
     * Every footnote in the body, keyed by its number, in the viewer's order
     * (`footnotes.ts`): reading order through paragraphs, headings, list items
     * (and their children), quotes and table cells.
     *
     * @param  list<mixed>  $blocks
     * @return array<int, array<string, mixed>>
     */
    private static function footnotes(array $blocks): array
    {
        $out = [];
        $runs = static function (mixed $list) use (&$out): void {
            foreach (is_array($list) ? $list : [] as $run) {
                if (is_array($run) && is_array($run['footnote'] ?? null) && is_array($run['footnote']['blocks'] ?? null)) {
                    $out[count($out) + 1] = $run['footnote'];
                }
            }
        };
        $items = static function (mixed $list) use (&$items, $runs): void {
            foreach (is_array($list) ? $list : [] as $item) {
                if (is_array($item)) {
                    $runs($item['runs'] ?? null);
                    $items($item['children'] ?? null);
                }
            }
        };
        $walk = static function (mixed $list) use (&$walk, $runs, $items): void {
            foreach (is_array($list) ? $list : [] as $b) {
                if (! is_array($b)) {
                    continue;
                }
                match ($b['type'] ?? null) {
                    'heading', 'paragraph' => $runs($b['runs'] ?? null),
                    'list' => $items($b['items'] ?? null),
                    'quote' => $walk($b['blocks'] ?? null),
                    'table' => array_map(
                        static fn ($row) => array_map(static fn ($cell) => $walk(is_array($cell) ? ($cell['blocks'] ?? null) : null), is_array($row) && is_array($row['cells'] ?? null) ? $row['cells'] : []),
                        is_array($b['rows'] ?? null) ? $b['rows'] : [],
                    ),
                    default => null,
                };
            }
        };
        $walk($blocks);

        return $out;
    }

    /**
     * The band stored under a page key, or null when the key is not set. An
     * object (a PHP array that is not a non-empty list) counts as set.
     *
     * @param  array<string, mixed>  $page
     * @return array<string, mixed>|null
     */
    private static function slotBand(array $page, string $key): ?array
    {
        $v = $page[$key] ?? null;

        return is_array($v) && ! ($v !== [] && array_is_list($v)) ? $v : null;
    }

    /** @param array<string, mixed> $band */
    private static function hasBand(array $band): bool
    {
        if (is_array($band['blocks'] ?? null)) {
            return $band['blocks'] !== [];
        }
        foreach (['left', 'center', 'right'] as $s) {
            if (is_string($band[$s] ?? null) && $band[$s] !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * The band height (pt) a page class draws for one kind (`bands.ts`).
     *
     * @param  array<string, mixed>  $page
     * @param  array<string, array<string, float>>  $heights  px
     */
    private static function classHeight(array $page, string $kind, string $class, array $heights): float
    {
        $slot = 'default';
        if (($class === 'first' || $class === 'firstEven') && self::slotBand($page, self::BAND_KEYS[$kind]['first']) !== null) {
            $slot = 'first';
        } elseif (($class === 'even' || $class === 'firstEven') && self::slotBand($page, self::BAND_KEYS[$kind]['even']) !== null) {
            $slot = 'even';
        }
        $band = self::slotBand($page, self::BAND_KEYS[$kind][$slot]);

        return $band !== null && self::hasBand($band) ? max(0.0, $heights[$kind][$slot]) * 0.75 : 0.0;
    }
}
