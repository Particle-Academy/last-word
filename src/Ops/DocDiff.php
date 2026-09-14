<?php

declare(strict_types=1);

namespace LastWord\Ops;

use LastWord\Agent;

/**
 * The op list that turns one Last Word document into another.
 *
 * ## Two guarantees, and where each applies
 *
 * 1. **Same file, no ops.** When `$a` and `$b` write the same document —
 *    compared as `read(toBytes(...))`, so runs the reader merges, a header row's
 *    bold, an empty paragraph the writer drops are not changes — the diff is
 *    `[]`. That is what makes `diff($d, read(toBytes($d))) === []`: saving
 *    without a change records nothing.
 * 2. **Otherwise, exact.** `reduce($a, diff($a, $b))` equals `$b`, key order
 *    aside. The ops are computed on the documents as given and VERIFIED by
 *    replaying them through {@see DocReducer}; if they do not reproduce `$b`,
 *    the diff is one `doc.replace`.
 *
 * ## Small edits stay small
 *
 * Every list — the top-level blocks, a quote's blocks, a list's items and their
 * children, a table's rows, a row's cells, a cell's blocks — is ALIGNED by
 * content (a longest common subsequence), so rewording one paragraph is one
 * `blocks.replace`, moving one is one `blocks.move`, and rewording a paragraph
 * inside a table cell is one `blocks.replace` at that cell's path rather than a
 * new table. A changed container whose own properties are unchanged is diffed
 * inside; one whose properties changed, or whose inner diff would be more than
 * half its length in ops, is replaced whole.
 *
 * ## Determinism
 *
 * The same inputs give the same ops, in the same order, in the PHP, Node and
 * Python ports: alignment ties break toward deleting first, and the alignment is
 * skipped past {@see self::ALIGN_LIMIT} cells of work.
 */
final class DocDiff
{
    public const ALIGN_LIMIT = 250_000;

    /** Block type => the list inside it that is diffed, and that list's kind. */
    private const CONTAINERS = [
        'quote' => ['blocks', 'blocks'],
        'list' => ['items', 'items'],
        'table' => ['rows', 'rows'],
    ];

    /**
     * @param  array<string,mixed>  $a
     * @param  array<string,mixed>  $b
     * @return list<array<string,mixed>>
     */
    public static function diff(array $a, array $b): array
    {
        if (self::same($a, $b) || self::equivalent($a, $b)) {
            return [];
        }

        $ops = [];
        $keys = array_values(array_unique(array_merge(array_keys($a), array_keys($b))));
        sort($keys, SORT_STRING);

        foreach ($keys as $key) {
            if ($key === 'blocks') {
                continue;
            }
            if (! self::same($a[$key] ?? null, $b[$key] ?? null)) {
                $ops[] = ['op' => 'doc.set', 'key' => $key, 'value' => $b[$key] ?? null];
            }
        }

        foreach (self::listOps('blocks', '/blocks', self::listOf($a, 'blocks'), self::listOf($b, 'blocks')) as $op) {
            $ops[] = $op;
        }

        if (self::same(DocReducer::applyAll($a, $ops), $b)) {
            return $ops;
        }

        return [['op' => 'doc.replace', 'doc' => $b]];
    }

    /**
     * Whether two documents write the same file: both are written and read back.
     *
     * @param  array<string,mixed>  $a
     * @param  array<string,mixed>  $b
     */
    public static function equivalent(array $a, array $b): bool
    {
        return self::same(Agent::read(Agent::toBytes($a)), Agent::read(Agent::toBytes($b)));
    }

    /** Structural equality with map key order ignored and list order kept. */
    public static function same(mixed $a, mixed $b): bool
    {
        return self::canon($a) === self::canon($b);
    }

    /**
     * Ops that turn list `$from` into list `$to`, the list at `$path`.
     *
     * 1. Pairs: identical items the alignment keeps; items changed in place
     *    (the first of each run of deletes paired with the first of the inserts
     *    beside it); and an item deleted in one place and inserted identical in
     *    another, which is a move.
     * 2. Changed-in-place items are edited first, at their old index.
     * 3. Unpaired old items are removed, last first.
     * 4. Walking the target in order, each position is filled by an insert or a
     *    move.
     *
     * @param  list<mixed>  $from
     * @param  list<mixed>  $to
     * @return list<array<string,mixed>>
     */
    private static function listOps(string $kind, string $path, array $from, array $to): array
    {
        $hashFrom = array_map(self::canon(...), $from);
        $hashTo = array_map(self::canon(...), $to);
        $n = count($from);
        $m = count($to);

        /** @var array<int,int> $source target index => source index */
        $source = [];
        $changed = [];
        $deleted = [];
        $inserted = [];

        $i = 0;
        $j = 0;

        foreach (self::hunks($hashFrom, $hashTo) as [$start, $dels, $ins, $targetStart]) {
            while ($i < $start) {
                $source[$j++] = $i++;
            }

            $paired = min($dels, $ins);

            for ($k = 0; $k < $paired; $k++) {
                $changed[] = [$i + $k, $targetStart + $k];
                $source[$targetStart + $k] = $i + $k;
            }
            for ($k = $paired; $k < $dels; $k++) {
                $deleted[] = $i + $k;
            }
            for ($k = $paired; $k < $ins; $k++) {
                $inserted[] = $targetStart + $k;
            }

            $i += $dels;
            $j = $targetStart + $ins;
        }

        while ($i < $n) {
            $source[$j++] = $i++;
        }

        // An item removed here and inserted identical there is a move.
        foreach ($inserted as $x => $target) {
            foreach ($deleted as $y => $old) {
                if ($hashFrom[$old] === $hashTo[$target]) {
                    $source[$target] = $old;
                    unset($inserted[$x], $deleted[$y]);

                    break;
                }
            }
        }

        [$valueKey] = DocReducer::KINDS[$kind];
        $ops = [];

        foreach ($changed as [$old, $target]) {
            foreach (self::itemOps($kind, $path, $old, $from[$old], $to[$target]) as $op) {
                $ops[] = $op;
            }
        }

        $removed = array_values($deleted);
        rsort($removed);

        foreach ($removed as $old) {
            $ops[] = ['op' => "{$kind}.remove", 'path' => $path, 'index' => $old];
        }

        // The working order: surviving source indices, in source order.
        $work = array_values(array_diff(range(0, max(0, $n - 1)), $removed));
        if ($n === 0) {
            $work = [];
        }

        for ($t = 0; $t < $m; $t++) {
            $want = $source[$t] ?? null;

            if ($want === null) {
                $ops[] = ['op' => "{$kind}.insert", 'path' => $path, 'index' => $t, $valueKey => $to[$t]];
                array_splice($work, $t, 0, [-1 - $t]);

                continue;
            }

            if (($work[$t] ?? null) === $want) {
                continue;
            }

            $at = array_search($want, $work, true);
            $ops[] = ['op' => "{$kind}.move", 'path' => $path, 'from' => $at, 'to' => $t];
            array_splice($work, (int) $at, 1);
            array_splice($work, $t, 0, [$want]);
        }

        return $ops;
    }

    /**
     * Ops for one item changed in place: an edit inside it when it is a container
     * whose own properties did not change, otherwise a replace.
     *
     * @param  mixed  $old
     * @param  mixed  $new
     * @return list<array<string,mixed>>
     */
    private static function itemOps(string $kind, string $path, int $index, mixed $old, mixed $new): array
    {
        [$valueKey] = DocReducer::KINDS[$kind];
        $replace = [['op' => "{$kind}.replace", 'path' => $path, 'index' => $index, $valueKey => $new]];

        if (! is_array($old) || ! is_array($new)) {
            return $replace;
        }

        $child = match ($kind) {
            'blocks' => (($old['type'] ?? null) === ($new['type'] ?? null)) ? (self::CONTAINERS[$new['type'] ?? ''] ?? null) : null,
            'items' => ['children', 'items'],
            'rows' => ['cells', 'cells'],
            'cells' => ['blocks', 'blocks'],
            default => null,
        };

        if ($child === null) {
            return $replace;
        }

        [$key, $childKind] = $child;

        if (! is_array($old[$key] ?? null) || ! is_array($new[$key] ?? null)) {
            return $replace;
        }

        $restOld = $old;
        $restNew = $new;
        unset($restOld[$key], $restNew[$key]);

        if (! self::same($restOld, $restNew)) {
            return $replace;
        }

        $inner = self::listOps($childKind, "{$path}/{$index}/{$key}", array_values($old[$key]), array_values($new[$key]));

        return count($inner) <= max(1, intdiv(count($new[$key]), 2)) ? $inner : $replace;
    }

    /**
     * Hunks of a longest-common-subsequence alignment:
     * [start in $a, deleted, inserted, start in $b].
     *
     * @param  list<string>  $a
     * @param  list<string>  $b
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    public static function hunks(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);
        $prefix = 0;

        while ($prefix < $n && $prefix < $m && $a[$prefix] === $b[$prefix]) {
            $prefix++;
        }

        $suffix = 0;

        while ($suffix < $n - $prefix && $suffix < $m - $prefix && $a[$n - 1 - $suffix] === $b[$m - 1 - $suffix]) {
            $suffix++;
        }

        $midA = array_slice($a, $prefix, $n - $prefix - $suffix);
        $midB = array_slice($b, $prefix, $m - $prefix - $suffix);
        $rows = count($midA);
        $cols = count($midB);

        if ($rows === 0 && $cols === 0) {
            return [];
        }

        if ($rows * $cols > self::ALIGN_LIMIT) {
            return [[$prefix, $rows, $cols, $prefix]];
        }

        $lengths = array_fill(0, $rows + 1, array_fill(0, $cols + 1, 0));

        for ($i = $rows - 1; $i >= 0; $i--) {
            for ($j = $cols - 1; $j >= 0; $j--) {
                $lengths[$i][$j] = $midA[$i] === $midB[$j]
                    ? $lengths[$i + 1][$j + 1] + 1
                    : max($lengths[$i + 1][$j], $lengths[$i][$j + 1]);
            }
        }

        $hunks = [];
        $open = null;
        $i = 0;
        $j = 0;

        while ($i < $rows || $j < $cols) {
            if ($i < $rows && $j < $cols && $midA[$i] === $midB[$j]) {
                if ($open !== null) {
                    $hunks[] = $open;
                    $open = null;
                }
                $i++;
                $j++;

                continue;
            }

            $open ??= [$prefix + $i, 0, 0, $prefix + $j];

            if ($j >= $cols || ($i < $rows && $lengths[$i + 1][$j] >= $lengths[$i][$j + 1])) {
                $open[1]++;
                $i++;
            } else {
                $open[2]++;
                $j++;
            }
        }

        if ($open !== null) {
            $hunks[] = $open;
        }

        return $hunks;
    }

    /**
     * @param  array<string,mixed>  $node
     * @return list<mixed>
     */
    private static function listOf(array $node, string $key): array
    {
        return is_array($node[$key] ?? null) ? array_values($node[$key]) : [];
    }

    private static function canon(mixed $value): string
    {
        return (string) json_encode(self::sortKeys($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    private static function sortKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(self::sortKeys(...), $value);
    }
}
