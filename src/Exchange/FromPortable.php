<?php

declare(strict_types=1);

namespace LastWord\Exchange;

/**
 * Portable content to last-word blocks (exchange SPEC section 11.1, pinned
 * by `exchange/to-last-word`; identical by design to fine-print's
 * `exchange/to-fine-print`, and this class is fine-print 0.5.0's, kept in step).
 *
 * The content is validated first, closed and against the limits; invalid
 * content is refused with the validator's code and pointer and nothing is
 * produced. A deterministic, pure function of its input.
 */
final class FromPortable
{
    /**
     * @param  array<string, mixed>  $options  `{limits?: {...}}`
     * @return array{ok: true, blocks: list<array<string, mixed>>, report: array<string, mixed>}|array{ok: false, refused: array{reason: string, path: string}}
     */
    public static function convert(mixed $content, array $options = []): array
    {
        $check = Content::validate($content, $options === [] ? null : $options);
        if (! $check['ok']) {
            return ['ok' => false, 'refused' => ['reason' => $check['code'], 'path' => $check['path']]];
        }
        $c = Content::toArray($content);
        $images = is_array($c['images'] ?? null) ? $c['images'] : [];
        $report = new Report;
        $blocks = [];
        foreach ($c['items'] as $i => $item) {
            $path = '/items/'.$i;
            switch ($item['type']) {
                case 'text':
                    $blocks[] = $item['role'] === 'heading'
                        ? ['type' => 'heading', 'level' => (int) $item['level'], 'runs' => $item['runs']]
                        : ['type' => 'paragraph', 'runs' => $item['runs']];

                    break;
                case 'list':
                    $blocks[] = ['type' => 'list', 'ordered' => (bool) ($item['ordered'] ?? false), 'items' => self::listItems($item['items'])];

                    break;
                case 'image':
                    $img = $images[$item['ref']];
                    $block = ['type' => 'image', 'src' => 'data:'.$img['mime'].';base64,'.$img['data']];
                    foreach (['alt', 'widthPx', 'heightPx'] as $key) {
                        if (array_key_exists($key, $item)) {
                            $block[$key] = $item[$key];
                        }
                    }
                    $blocks[] = $block;

                    break;
                case 'table':
                    if (array_key_exists('caption', $item)) {
                        $blocks[] = ['type' => 'paragraph', 'runs' => [['text' => $item['caption']]]];
                    }
                    $blocks[] = self::table($item, $path, $report);

                    break;
            }
        }

        return ['ok' => true, 'blocks' => $blocks, 'report' => $report->toArray()];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private static function listItems(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            $entry = ['runs' => $item['runs']];
            if (array_key_exists('children', $item)) {
                $entry['children'] = self::listItems($item['children']);
            }
            $out[] = $entry;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $table
     * @return array<string, mixed>
     */
    private static function table(array $table, string $path, Report $report): array
    {
        $headerRows = (int) ($table['headerRows'] ?? 0);
        $anchors = Content::anchors($table['rows']);

        // A column is numeric when every non-empty BODY cell anchored in it is
        // a number, and it has at least one (fine-print's Tabulator rule).
        $hasNumber = [];
        $hasOther = [];
        foreach ($table['rows'] as $r => $row) {
            if ($r < $headerRows) {
                continue;
            }
            foreach ($row['cells'] as $k => $cell) {
                if ($cell['t'] === 'empty') {
                    continue;
                }
                $col = $anchors[$r][$k];
                if ($cell['t'] === 'number') {
                    $hasNumber[$col] = true;
                } else {
                    $hasOther[$col] = true;
                }
            }
        }

        $rows = [];
        foreach ($table['rows'] as $r => $row) {
            $cells = [];
            foreach ($row['cells'] as $k => $cell) {
                $at = $path.'/rows/'.$r.'/cells/'.$k;
                if ($cell['t'] !== 'empty' && $cell['t'] !== 'string') {
                    $report->add('typed-as-text', $at);
                }
                if (array_key_exists('formula', $cell)) {
                    $report->add('formula-as-value', $at);
                }
                $display = $cell['text'] ?? Content::canonical($cell['t'], $cell['v'] ?? null);
                $runs = $cell['runs'] ?? ($display === '' ? [] : [['text' => $display]]);
                $paragraph = ['type' => 'paragraph', 'runs' => $runs];
                $col = $anchors[$r][$k];
                $align = $cell['align'] ?? ((isset($hasNumber[$col]) && ! isset($hasOther[$col])) ? 'right' : null);
                if ($align !== null) {
                    $paragraph['align'] = $align;
                }
                $out = ['blocks' => [$paragraph]];
                foreach (['colSpan', 'rowSpan'] as $span) {
                    if (array_key_exists($span, $cell)) {
                        $out[$span] = (int) $cell[$span];
                    }
                }
                $cells[] = $out;
            }
            $rows[] = $r < $headerRows ? ['header' => true, 'cells' => $cells] : ['cells' => $cells];
        }

        $block = ['type' => 'table'];
        $columns = $table['columns'] ?? null;
        if (is_array($columns) && $columns !== [] && count(array_filter($columns, fn ($c) => array_key_exists('width', $c))) === count($columns)) {
            $block['widths'] = array_map(fn ($c) => $c['width'], $columns);
        }
        $block['rows'] = $rows;

        return $block;
    }
}
