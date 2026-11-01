<?php

declare(strict_types=1);

namespace LastWord\Layout;

/**
 * What in a document `layout()` can only estimate, so a caller knows how far to
 * trust the page count: text in a font other than Calibri (the viewer draws it
 * in whatever that font is on the reader's machine), code (Consolas is not
 * bundled), characters Carlito does not have, and date or time fields in a
 * band. An empty list means every line was measured exactly.
 */
final class Approximations
{
    /**
     * @param  array<string, mixed>  $doc
     * @return list<string>
     */
    public static function of(array $doc, Metrics $m): array
    {
        $found = [];
        $docFont = is_string($doc['defaultFont'] ?? null) ? trim($doc['defaultFont']) : '';
        if ($docFont !== '' && strtolower($docFont) !== 'calibri') {
            $found['font:' . $docFont] = true;
        }
        $runs = static function (mixed $list) use (&$found, $m): void {
            foreach (is_array($list) ? $list : [] as $run) {
                if (! is_array($run)) {
                    continue;
                }
                $font = is_string($run['font'] ?? null) ? trim($run['font']) : '';
                if ($font !== '' && strtolower($font) !== 'calibri') {
                    $found['font:' . $font] = true;
                }
                if (! empty($run['code'])) {
                    $found['code'] = true;
                }
                foreach (Metrics::codepoints(is_string($run['text'] ?? null) ? $run['text'] : '') as $cp) {
                    if ($cp !== 0x0A && $cp !== 0x09 && $cp !== 0x0D && ! $m->covers($cp)) {
                        $found['characters outside Carlito'] = true;
                        break;
                    }
                }
                if (is_string($run['text'] ?? null) && preg_match('/\{(date|time)(:[^{}]*)?\}/', $run['text']) === 1) {
                    $found['date or time field'] = true;
                }
            }
        };
        $walk = static function (mixed $blocks) use (&$walk, $runs, &$found): void {
            foreach (is_array($blocks) ? $blocks : [] as $b) {
                if (! is_array($b)) {
                    continue;
                }
                $runs($b['runs'] ?? null);
                foreach (is_array($b['runs'] ?? null) ? $b['runs'] : [] as $run) {
                    if (is_array($run) && is_array($run['footnote'] ?? null)) {
                        $walk($run['footnote']['blocks'] ?? null);
                    }
                }
                match ($b['type'] ?? null) {
                    'code' => $found['code'] = true,
                    'quote' => $walk($b['blocks'] ?? null),
                    'list' => self::items($b['items'] ?? null, $runs),
                    'table' => array_map(
                        static fn ($row) => array_map(static fn ($c) => $walk(is_array($c) ? ($c['blocks'] ?? null) : null), is_array($row) && is_array($row['cells'] ?? null) ? $row['cells'] : []),
                        is_array($b['rows'] ?? null) ? $b['rows'] : [],
                    ),
                    default => null,
                };
            }
        };
        $walk($doc['blocks'] ?? null);
        $page = is_array($doc['page'] ?? null) ? $doc['page'] : [];
        foreach (['header', 'footer', 'firstHeader', 'firstFooter', 'evenHeader', 'evenFooter'] as $key) {
            $band = $page[$key] ?? null;
            if (! is_array($band)) {
                continue;
            }
            $walk($band['blocks'] ?? null);
            foreach (['left', 'center', 'right'] as $s) {
                if (is_string($band[$s] ?? null) && preg_match('/\{(date|time)(:[^{}]*)?\}/', $band[$s]) === 1) {
                    $found['date or time field'] = true;
                }
            }
        }
        $out = array_keys($found);
        sort($out);

        return $out;
    }

    private static function items(mixed $items, callable $runs): void
    {
        foreach (is_array($items) ? $items : [] as $item) {
            if (is_array($item)) {
                $runs($item['runs'] ?? null);
                self::items($item['children'] ?? null, $runs);
            }
        }
    }
}
