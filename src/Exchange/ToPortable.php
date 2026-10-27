<?php

declare(strict_types=1);

namespace LastWord\Exchange;

use stdClass;

/**
 * A last-word Doc to portable content (exchange SPEC section 10.2, pinned by
 * `exchange/from-last-word`). Only `blocks` is content: the title, page and
 * defaults are document properties and are neither extracted nor reported.
 *
 * Guard v1: every string taken from the document (run text, code, alt text)
 * is stripped of the characters {@see Guard::INVISIBLE} matches, and each
 * maximal run removed is counted. No report carries text: every `where` is
 * a JSON Pointer built here from indexes and fixed key names.
 *
 * A pure function of its arguments.
 */
final class ToPortable
{
    private const OPTION_KEYS = ['includeHidden', 'headerRows', 'limits'];

    /** Paragraph properties a portable text item has no slot for. */
    private const PARA_PROPS = ['align', 'borders', 'indentLeft', 'indentRight', 'keepNext', 'lineHeight', 'shading', 'spaceAfter', 'spaceBefore'];

    /** Run styling a portable run has no slot for (smallCaps counts only when true). */
    private const RUN_STYLE = ['color', 'highlight', 'size', 'font', 'letterSpacing'];

    private const RUN_FLAGS = ['bold', 'italic', 'underline', 'strike', 'code'];

    private const CELL_STYLE = ['shading', 'borders', 'padding', 'valign'];

    private const TABLE_LAYOUT = ['width', 'align', 'borders', 'cellPadding'];

    private Guard $guard;

    private Report $report;

    /** @var list<array<string, mixed>> */
    private array $items = [];

    /** @var array<string, array{mime: string, data: string}> */
    private array $images = [];

    private int $cellBytes;

    private function __construct(int $cellBytes)
    {
        $this->guard = new Guard;
        $this->report = new Report;
        $this->cellBytes = $cellBytes;
    }

    /**
     * @param  array<string, mixed>  $doc
     * @param  array<string, mixed>  $options  `{includeHidden?, headerRows?, limits?}`; the first two have nothing to act on in a Doc
     * @return array{ok: true, content: array<string, mixed>}|array{ok: false, refused: array{reason: string}}
     *
     * @throws OptionsInvalid
     */
    public static function fromDoc(array $doc, mixed $selector = null, array $options = []): array
    {
        $limits = self::options($options);
        $blocks = is_array($doc['blocks'] ?? null) ? array_values($doc['blocks']) : [];

        $picked = self::select($blocks, $selector);
        if (isset($picked['refused'])) {
            return ['ok' => false, 'refused' => $picked['refused']];
        }

        $x = new self($limits['cellBytes']);
        foreach ($picked['indexes'] as $i) {
            if (is_array($blocks[$i])) {
                $x->block($blocks[$i], '/blocks/'.$i);
            } else {
                $x->report->add('layout-not-preserved', '/blocks/'.$i);
            }
        }
        $x->guard->into($x->report);

        $source = ['package' => 'last-word'];
        if ($selector !== null) {
            $source['selector'] = Content::toArray($selector); // the selector as given, in either decoding
        }
        $content = ['fancyContent' => 1, 'source' => $source, 'items' => $x->items];
        if ($x->images !== []) {
            $content['images'] = $x->images;
        }
        $content['report'] = $x->report->toArray();

        // A reader whose output is over a limit refuses with the code; it never cuts.
        $check = Content::validate($content, ['limits' => $limits]);
        if (! $check['ok']) {
            return ['ok' => false, 'refused' => ['reason' => $check['code']]];
        }

        return ['ok' => true, 'content' => $content];
    }

    /**
     * The top-level block indexes a selector names, in order, or a refusal
     * (SPEC 8.5): `null` (every block), `{table: n}`, `{underHeading, nth?}`,
     * `{blocks: [a, b]}`. 0-based, inclusive.
     *
     * @param  list<mixed>  $blocks
     * @return array{indexes?: list<int>, refused?: array{reason: string}}
     */
    public static function select(array $blocks, mixed $selector): array
    {
        $no = static fn (string $reason): array => ['refused' => ['reason' => $reason]];
        if ($selector === null) {
            return ['indexes' => array_keys($blocks)];
        }
        if (! Content::isObject($selector)) {
            return $no('selector_invalid');
        }
        $s = Content::props($selector);
        $typeOf = static fn (mixed $b): ?string => is_array($b) && is_string($b['type'] ?? null) ? $b['type'] : null;

        if (array_key_exists('table', $s)) {
            $n = $s['table'];
            if (! Content::isInteger($n) || $n < 0 || count($s) !== 1) {
                return $no('selector_invalid');
            }
            $tables = array_keys(array_filter($blocks, static fn ($b) => $typeOf($b) === 'table'));

            return isset($tables[(int) $n]) ? ['indexes' => [$tables[(int) $n]]] : $no('selector_not_found');
        }

        if (array_key_exists('underHeading', $s)) {
            $nth = $s['nth'] ?? 0;
            if (! is_string($s['underHeading']) || ! Content::isInteger($nth) || $nth < 0) {
                return $no('selector_invalid');
            }
            $want = strtolower($s['underHeading']); // ASCII-only since PHP 8.2, as the spec asks
            $at = null;
            foreach ($blocks as $i => $b) {
                if ($typeOf($b) === 'heading' && strtolower(self::runText($b['runs'] ?? null)) === $want) {
                    $at = $i;

                    break;
                }
            }
            if ($at === null) {
                return $no('selector_not_found');
            }
            $level = self::level($blocks[$at]);
            $tables = [];
            for ($i = $at + 1, $n = count($blocks); $i < $n; $i++) {
                $type = $typeOf($blocks[$i]);
                if ($type === 'heading' && self::level($blocks[$i]) <= $level) {
                    break;
                }
                if ($type === 'table') {
                    $tables[] = $i;
                }
            }

            return isset($tables[(int) $nth]) ? ['indexes' => [$tables[(int) $nth]]] : $no('selector_not_found');
        }

        if (array_key_exists('blocks', $s)) {
            $r = $s['blocks'];
            if (! Content::isList($r) || count($r) !== 2
                || ! Content::isInteger($r[0]) || ! Content::isInteger($r[1])
                || $r[0] < 0 || $r[1] < 0 || $r[0] > $r[1]) {
                return $no('selector_invalid');
            }
            if ($r[1] >= count($blocks)) {
                return $no('selector_not_found');
            }

            return ['indexes' => range((int) $r[0], (int) $r[1])];
        }

        return $no('selector_invalid');
    }

    /** The concatenated text of a run list, unguarded (for matching only). */
    public static function runText(mixed $runs): string
    {
        $out = '';
        foreach (is_array($runs) ? $runs : [] as $run) {
            if (is_array($run) && is_scalar($run['text'] ?? null)) {
                $out .= (string) $run['text'];
            }
        }

        return $out;
    }

    private static function level(mixed $block): int
    {
        $level = is_array($block) ? ($block['level'] ?? 1) : 1;

        return is_numeric($level) ? (int) $level : 1;
    }

    /** @param array<string, mixed> $b */
    private function block(array $b, string $p): void
    {
        switch ($b['type'] ?? null) {
            case 'heading':
            case 'paragraph':
                $this->layoutIfAny($b, $p);
                $runs = $this->runs($b['runs'] ?? null, $p.'/runs');
                $this->items[] = ($b['type'] === 'heading')
                    ? ['type' => 'text', 'role' => 'heading', 'level' => max(1, min(6, self::level($b))), 'runs' => $runs]
                    : ['type' => 'text', 'role' => 'paragraph', 'runs' => $runs];

                return;
            case 'list':
                $items = [];
                foreach (is_array($b['items'] ?? null) ? array_values($b['items']) : [] as $k => $item) {
                    $items[] = $this->listItem(is_array($item) ? $item : [], $p.'/items/'.$k);
                }
                if ($items === []) {
                    $this->report->add('layout-not-preserved', $p); // an empty list holds nothing to carry

                    return;
                }
                $this->items[] = ['type' => 'list', 'ordered' => ($b['ordered'] ?? false) === true, 'items' => $items];

                return;
            case 'table':
                $table = $this->table($b, $p);
                if ($table !== null) {
                    $this->items[] = $table;
                }

                return;
            case 'code':
                $this->report->add('code-as-text', $p);
                $this->items[] = ['type' => 'text', 'role' => 'paragraph', 'runs' => [['text' => $this->guard->strip($b['text'] ?? ''), 'code' => true]]];

                return;
            case 'quote':
                $this->report->add('quote-flattened', $p);
                foreach (is_array($b['blocks'] ?? null) ? array_values($b['blocks']) : [] as $k => $child) {
                    if (is_array($child)) {
                        $this->block($child, $p.'/blocks/'.$k);
                    }
                }

                return;
            case 'image':
                $image = Content::imageFromDataUri($b['src'] ?? null);
                if ($image === null) {
                    $this->report->add('image', $p);

                    return;
                }
                $ref = 'img'.(count($this->images) + 1);
                $this->images[$ref] = $image;
                $item = ['type' => 'image', 'ref' => $ref];
                if (array_key_exists('alt', $b)) {
                    $item['alt'] = $this->guard->strip($b['alt']);
                }
                foreach (['widthPx', 'heightPx'] as $dim) {
                    if (array_key_exists($dim, $b)) {
                        $item[$dim] = $b[$dim];
                    }
                }
                $this->items[] = $item;

                return;
            case 'chart':
                $this->report->add('chart', $p);

                return;
            default: // pageBreak, hr: layout, not content
                $this->report->add('layout-not-preserved', $p);
        }
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function listItem(array $item, string $p): array
    {
        $this->layoutIfAny($item, $p);
        $out = ['runs' => $this->runs($item['runs'] ?? null, $p.'/runs')];
        if (is_array($item['children'] ?? null) && array_is_list($item['children'])) {
            $children = [];
            foreach ($item['children'] as $k => $child) {
                $children[] = $this->listItem(is_array($child) ? $child : [], $p.'/children/'.$k);
            }
            $out['children'] = $children;
        }

        return $out;
    }

    /** @param array<string, mixed> $b */
    private function layoutIfAny(array $b, string $p): void
    {
        foreach (self::PARA_PROPS as $key) {
            if (array_key_exists($key, $b)) {
                $this->report->add('layout-not-preserved', $p);

                return;
            }
        }
    }

    /** @return list<array<string, mixed>> */
    private function runs(mixed $runs, string $p): array
    {
        $out = [];
        foreach (is_array($runs) ? array_values($runs) : [] as $i => $r) {
            $r = is_array($r) ? $r : [];
            $run = ['text' => $this->guard->strip($r['text'] ?? '')];
            foreach (self::RUN_FLAGS as $flag) {
                if (($r[$flag] ?? null) === true) {
                    $run[$flag] = true;
                }
            }
            if (is_string($r['link'] ?? null)) {
                if (Content::linkAllowed($r['link'])) {
                    $run['link'] = $r['link'];
                } else {
                    $this->report->add('link', $p.'/'.$i);
                }
            }
            $styled = ($r['smallCaps'] ?? null) === true;
            foreach (self::RUN_STYLE as $key) {
                $styled = $styled || array_key_exists($key, $r);
            }
            if ($styled) {
                $this->report->add('run-style-dropped', $p.'/'.$i);
            }
            $out[] = $run;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $b
     * @return array<string, mixed>|null
     */
    private function table(array $b, string $p): ?array
    {
        foreach (self::TABLE_LAYOUT as $key) {
            if (array_key_exists($key, $b)) {
                $this->report->add('layout-not-preserved', $p);

                break;
            }
        }
        $headerRows = 0;
        $leading = true;
        $rows = [];
        foreach (is_array($b['rows'] ?? null) ? array_values($b['rows']) : [] as $ri => $row) {
            $row = is_array($row) ? $row : [];
            $rp = $p.'/rows/'.$ri;
            if (($row['header'] ?? null) === true) {
                if ($leading) {
                    $headerRows++;
                } else {
                    $this->report->add('header-rows-flattened', $rp);
                }
            } else {
                $leading = false;
            }
            $cells = [];
            foreach (is_array($row['cells'] ?? null) ? array_values($row['cells']) : [] as $ci => $cell) {
                $cells[] = $this->cell(is_array($cell) ? $cell : [], $rp.'/cells/'.$ci);
            }
            $rows[] = ['cells' => $cells];
        }
        if ($rows === []) {
            $this->report->add('layout-not-preserved', $p); // a table with no rows holds nothing to carry

            return null;
        }

        // Short rows are padded with empty cells: nothing is lost, so nothing is reported.
        $widths = self::rowWidths($rows);
        $grid = max($widths);
        foreach ($rows as $r => $row) {
            for ($k = $widths[$r]; $k < $grid; $k++) {
                $rows[$r]['cells'][] = ['t' => 'empty'];
            }
        }

        $table = ['type' => 'table', 'headerRows' => $headerRows, 'rows' => $rows];
        $w = $b['widths'] ?? null;
        if (is_array($w) && array_is_list($w) && $w !== []) {
            $numeric = array_filter($w, static fn ($x) => (is_int($x) || is_float($x)) && $x >= 0);
            $sum = array_sum($numeric);
            if (count($numeric) === count($w) && count($w) === $grid && $sum > 0 && ! in_array(0, $w, false)) {
                $table['columns'] = array_map(static fn ($x) => ['width' => $x / $sum], $w);
            } else {
                // Weights that do not describe this grid cannot be carried as columns.
                $this->report->add('layout-not-preserved', $p.'/widths');
            }
        }

        return $table;
    }

    /**
     * @param  array<string, mixed>  $c
     * @return array<string, mixed>
     */
    private function cell(array $c, string $cp): array
    {
        $styleDropped = false;
        foreach (self::CELL_STYLE as $key) {
            $styleDropped = $styleDropped || array_key_exists($key, $c);
        }
        $texts = [];
        $runSets = [];
        foreach (is_array($c['blocks'] ?? null) ? array_values($c['blocks']) : [] as $k => $blk) {
            $type = is_array($blk) ? ($blk['type'] ?? null) : null;
            if ($type === 'paragraph' || $type === 'heading') {
                foreach (self::PARA_PROPS as $key) {
                    $styleDropped = $styleDropped || array_key_exists($key, $blk);
                }
                $runs = $this->runs($blk['runs'] ?? null, $cp.'/blocks/'.$k.'/runs');
                $runSets[] = $runs;
                $texts[] = implode('', array_column($runs, 'text'));
            } else {
                $this->report->add('nested-block', $cp.'/blocks/'.$k);
            }
        }
        if ($styleDropped) {
            $this->report->add('cell-style-dropped', $cp);
        }

        $v = implode("\n", $texts);
        $cell = $v === '' ? ['t' => 'empty'] : ['t' => 'string', 'v' => $v];
        if ($v !== '') {
            $all = [];
            foreach ($runSets as $k => $runs) {
                if ($k > 0) {
                    $all[] = ['text' => "\n"];
                }
                array_push($all, ...$runs);
            }
            $styled = false;
            foreach ($all as $run) {
                $styled = $styled || count($run) > 1; // a flag or a link beside its text
            }
            $cut = Content::truncateCell($v, $this->cellBytes);
            if ($cut['truncated']) {
                // The one lossy limit (SPEC 9): runs and text go with the cut.
                $cell['v'] = $cut['text'];
                $this->report->add('cell-truncated', $cp);
            } elseif ($styled) {
                $cell['runs'] = $all;
            }
        }
        foreach (['colSpan', 'rowSpan'] as $span) {
            if (array_key_exists($span, $c)) {
                $cell[$span] = $c[$span];
            }
        }

        return $cell;
    }

    /**
     * Occupied slots per row, spans considered: the grid width each row reaches.
     *
     * @param  list<array{cells: list<array<string, mixed>>}>  $rows
     * @return list<int>
     */
    private static function rowWidths(array $rows): array
    {
        $covered = [];
        $out = [];
        foreach ($rows as $ri => $row) {
            $w = $covered[$ri] ?? 0;
            foreach ($row['cells'] as $c) {
                $cs = Content::isInteger($c['colSpan'] ?? 1) ? max(1, (int) ($c['colSpan'] ?? 1)) : 1;
                $rs = Content::isInteger($c['rowSpan'] ?? 1) ? max(1, (int) ($c['rowSpan'] ?? 1)) : 1;
                $w += $cs;
                for ($d = 1; $d < $rs && $ri + $d < count($rows); $d++) {
                    $covered[$ri + $d] = ($covered[$ri + $d] ?? 0) + $cs;
                }
            }
            $out[] = $w;
        }

        return $out;
    }

    /**
     * The effective limits from `{includeHidden?, headerRows?, limits?}`, or a
     * throw for an unknown or malformed option.
     *
     * @param  array<string, mixed>|stdClass  $options
     * @return array<string, int>
     *
     * @throws OptionsInvalid
     */
    public static function options(array|stdClass $options): array
    {
        $options = Content::props($options);
        foreach ($options as $key => $value) {
            if (! in_array($key, self::OPTION_KEYS, true)) {
                throw new OptionsInvalid('unknown option "'.$key.'"');
            }
        }
        if (array_key_exists('includeHidden', $options) && ! is_bool($options['includeHidden'])) {
            throw new OptionsInvalid('includeHidden must be a boolean');
        }
        if (array_key_exists('headerRows', $options) && (! Content::isInteger($options['headerRows']) || $options['headerRows'] < 0)) {
            throw new OptionsInvalid('headerRows must be a non-negative integer');
        }

        return Content::limits(array_key_exists('limits', $options) ? ['limits' => $options['limits']] : null);
    }
}
