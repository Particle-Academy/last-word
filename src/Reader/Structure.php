<?php

declare(strict_types=1);

namespace LastWord\Reader;

/**
 * The two shape decisions every reader has to make identically: how adjacent
 * runs merge, and how a flat sequence of list paragraphs becomes the nested
 * list model.
 *
 * `DocxReader` made both decisions first; the ODT, RTF and DOC readers call
 * these so an upload does not come back shaped differently because of its file
 * format. The list rule is `DocxReader::assembleList`'s, unchanged: a change of
 * orderedness at the top level starts a new list, a level deeper than the
 * previous item nests under it, and a jump of several levels is clamped.
 */
final class Structure
{
    /** Run keys that, when equal, let two neighbouring runs become one. */
    private const FORMAT_KEYS = ['bold', 'italic', 'underline', 'strike', 'code', 'link'];

    /**
     * @param  list<array<string, mixed>>  $runs  each with `text` and optional flags / `link`
     * @return list<array<string, mixed>>
     */
    public static function mergeRuns(array $runs): array
    {
        $out = [];
        foreach ($runs as $run) {
            if (($run['text'] ?? '') === '') {
                continue;
            }
            $run = self::normalise($run);
            $last = count($out) - 1;
            if ($last >= 0 && self::sameFormat($out[$last], $run)) {
                $out[$last]['text'] .= $run['text'];

                continue;
            }
            $out[] = $run;
        }

        return $out;
    }

    /** The text of a run list, joined. */
    public static function text(array $runs): string
    {
        return implode('', array_map(static fn (array $r): string => (string) ($r['text'] ?? ''), $runs));
    }

    /**
     * Group consecutive list entries into list blocks.
     *
     * @param  list<array{ilvl: int, ordered: bool, runs: list<array<string, mixed>>}>  $entries
     * @return list<array<string, mixed>>
     */
    public static function lists(array $entries): array
    {
        $blocks = [];
        $pending = [];
        foreach ($entries as $entry) {
            if ($pending !== [] && $entry['ilvl'] === 0 && $pending[0]['ordered'] !== $entry['ordered']) {
                $blocks[] = self::assembleList($pending);
                $pending = [];
            }
            $pending[] = $entry;
        }
        if ($pending !== []) {
            $blocks[] = self::assembleList($pending);
        }

        return $blocks;
    }

    /**
     * @param  list<array{ilvl: int, ordered: bool, runs: list<array<string, mixed>>}>  $entries
     * @return array<string, mixed>
     */
    private static function assembleList(array $entries): array
    {
        $block = ['type' => 'list'];
        if ($entries[0]['ordered']) {
            $block['ordered'] = true;
        }
        $block['items'] = [];

        $items = &$block['items'];
        $stack = [&$items];

        foreach ($entries as $entry) {
            $depth = min($entry['ilvl'], count($stack));
            while (count($stack) - 1 > $depth) {
                array_pop($stack);
            }
            $parent = &$stack[count($stack) - 1];
            if ($depth > count($stack) - 1) {
                if ($parent !== []) {
                    $lastIndex = count($parent) - 1;
                    if (! isset($parent[$lastIndex]['children'])) {
                        $parent[$lastIndex]['children'] = [];
                    }
                    $stack[] = &$parent[$lastIndex]['children'];
                    $parent = &$stack[count($stack) - 1];
                }
            }
            $parent[] = ['runs' => $entry['runs']];
            unset($parent);
        }

        return $block;
    }

    /** @param array<string, mixed> $run */
    private static function normalise(array $run): array
    {
        $out = ['text' => (string) $run['text']];
        foreach (self::FORMAT_KEYS as $key) {
            if ($key === 'link') {
                if (isset($run['link']) && is_string($run['link']) && $run['link'] !== '') {
                    $out['link'] = $run['link'];
                }
            } elseif (! empty($run[$key])) {
                $out[$key] = true;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private static function sameFormat(array $a, array $b): bool
    {
        unset($a['text'], $b['text']);

        return $a === $b;
    }
}
