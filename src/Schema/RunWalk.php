<?php

declare(strict_types=1);

namespace LastWord\Schema;

use LastWord\Exchange\Portable;

/**
 * Every run of a document, wherever it sits, with the path the validator
 * names it by and where it is: `body`, `footnote` or `band` (a header or
 * footer). What bookmarks and internal links need: a link in a footnote or a
 * header jumps to a bookmark in the body, so neither can be judged one run at
 * a time.
 *
 * Order: the body's blocks in order (a footnote's runs where its mark is, a
 * break's bands where the break is), then the page's bands. Nesting deeper than
 * {@see Portable::MAX_DEPTH} is not walked, as the band and footnote checks.
 */
final class RunWalk
{
    /**
     * The document with every run replaced by what `$fn` returns for it.
     *
     * @param  array<string, mixed>  $doc
     * @param  callable(array<string, mixed>, string, string): array<string, mixed>  $fn  (run, path, where) => run
     * @return array<string, mixed>
     */
    public static function map(array $doc, callable $fn): array
    {
        if (is_array($doc['blocks'] ?? null) && array_is_list($doc['blocks'])) {
            $doc['blocks'] = self::blocks($doc['blocks'], 'blocks', 'body', $fn, 0);
        }
        if (is_array($doc['page'] ?? null)) {
            $doc['page'] = self::page($doc['page'], 'page', $fn);
        }

        return $doc;
    }

    /**
     * Every run, in walk order.
     *
     * @param  array<string, mixed>  $doc
     * @return list<array{run: array<string, mixed>, path: string, where: string}>
     */
    public static function all(array $doc): array
    {
        $out = [];
        self::map($doc, static function (array $run, string $path, string $where) use (&$out): array {
            $out[] = ['run' => $run, 'path' => $path, 'where' => $where];

            return $run;
        });

        return $out;
    }

    /**
     * @param  array<string, mixed>  $page
     * @return array<string, mixed>
     */
    private static function page(array $page, string $at, callable $fn): array
    {
        foreach (Schema::PAGE_BANDS as $kind) {
            $band = $page[$kind] ?? null;
            if (is_array($band) && is_array($band['blocks'] ?? null) && array_is_list($band['blocks'])) {
                $page[$kind]['blocks'] = self::blocks($band['blocks'], "{$at}.{$kind}.blocks", 'band', $fn, 0);
            }
        }

        return $page;
    }

    /**
     * @param  list<mixed>  $blocks
     * @return list<mixed>
     */
    private static function blocks(array $blocks, string $path, string $where, callable $fn, int $depth): array
    {
        if ($depth > Portable::MAX_DEPTH) {
            return $blocks;
        }
        foreach ($blocks as $i => $block) {
            if (!is_array($block)) {
                continue;
            }
            $at = "{$path}[{$i}]";
            switch ($block['type'] ?? null) {
                case 'heading':
                case 'paragraph':
                    $block = self::owner($block, $at, $where, $fn, $depth);

                    break;
                case 'list':
                    if (is_array($block['items'] ?? null) && array_is_list($block['items'])) {
                        $block['items'] = self::items($block['items'], "{$at}.items", $where, $fn, $depth);
                    }

                    break;
                case 'quote':
                    if (is_array($block['blocks'] ?? null) && array_is_list($block['blocks'])) {
                        $block['blocks'] = self::blocks($block['blocks'], "{$at}.blocks", $where, $fn, $depth + 1);
                    }

                    break;
                case 'table':
                    foreach (is_array($block['rows'] ?? null) ? $block['rows'] : [] as $r => $row) {
                        foreach (is_array($row) && is_array($row['cells'] ?? null) ? $row['cells'] : [] as $c => $cell) {
                            if (is_array($cell) && is_array($cell['blocks'] ?? null) && array_is_list($cell['blocks'])) {
                                $block['rows'][$r]['cells'][$c]['blocks'] = self::blocks($cell['blocks'], "{$at}.rows[{$r}].cells[{$c}].blocks", $where, $fn, $depth + 1);
                            }
                        }
                    }

                    break;
                case 'pageBreak':
                    if (is_array($block['page'] ?? null)) {
                        $block['page'] = self::page($block['page'], "{$at}.page", $fn);
                    }

                    break;
                default:
                    break;
            }
            $blocks[$i] = $block;
        }

        return $blocks;
    }

    /**
     * @param  list<mixed>  $items
     * @return list<mixed>
     */
    private static function items(array $items, string $path, string $where, callable $fn, int $depth): array
    {
        if ($depth > Portable::MAX_DEPTH) {
            return $items;
        }
        foreach ($items as $i => $item) {
            if (!is_array($item)) {
                continue;
            }
            $at = "{$path}[{$i}]";
            $item = self::owner($item, $at, $where, $fn, $depth);
            if (is_array($item['children'] ?? null) && array_is_list($item['children'])) {
                $item['children'] = self::items($item['children'], "{$at}.children", $where, $fn, $depth + 1);
            }
            $items[$i] = $item;
        }

        return $items;
    }

    /**
     * A run-bearing block or list item.
     *
     * @param  array<string, mixed>  $owner
     * @return array<string, mixed>
     */
    private static function owner(array $owner, string $at, string $where, callable $fn, int $depth): array
    {
        if (!is_array($owner['runs'] ?? null) || !array_is_list($owner['runs'])) {
            return $owner;
        }
        foreach ($owner['runs'] as $j => $run) {
            if (!is_array($run)) {
                continue;
            }
            $runPath = "{$at}.runs[{$j}]";
            $run = $fn($run, $runPath, $where);
            if (is_array($run['footnote'] ?? null) && is_array($run['footnote']['blocks'] ?? null) && array_is_list($run['footnote']['blocks'])) {
                $run['footnote']['blocks'] = self::blocks($run['footnote']['blocks'], "{$runPath}.footnote.blocks", $where === 'body' ? 'footnote' : $where, $fn, $depth + 1);
            }
            $owner['runs'][$j] = $run;
        }

        return $owner;
    }
}
