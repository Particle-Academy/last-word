<?php

declare(strict_types=1);

namespace LastWord\Ops;

/**
 * Apply {@see DocOpSchema} ops to a Last Word document, returning a new document.
 *
 * Pure: the input is never modified. An op whose `path` does not reach a list of
 * the kind it edits, or whose index is out of range, is skipped, so a replayed
 * history degrades rather than throws.
 *
 * ## Paths
 *
 * A list op names the LIST it edits with a JSON Pointer, and the item by index
 * in it:
 *
 * | op | path ends in | e.g. |
 * |---|---|---|
 * | `blocks.*` | `blocks` | `/blocks`, `/blocks/4/blocks` (a quote), `/blocks/2/rows/1/cells/0/blocks` |
 * | `items.*` | `items` or `children` | `/blocks/3/items`, `/blocks/3/items/0/children` |
 * | `rows.*` | `rows` | `/blocks/2/rows` |
 * | `cells.*` | `cells` | `/blocks/2/rows/1/cells` |
 *
 * Each kind has `insert {path, index, <value>}`, `remove {path, index}`,
 * `move {path, from, to}` and `replace {path, index, <value>}`, where the value
 * key is `block`, `item`, `row` or `cell`. Inserting into a `children` list that
 * is not there yet creates it.
 */
final class DocReducer
{
    /** List kind => [the value key its ops carry, the path tokens a list of that kind ends in]. */
    public const KINDS = [
        'blocks' => ['block', ['blocks']],
        'items' => ['item', ['items', 'children']],
        'rows' => ['row', ['rows']],
        'cells' => ['cell', ['cells']],
    ];

    /**
     * @param  array<string,mixed>  $doc
     * @param  list<array<string,mixed>>  $ops
     * @return array<string,mixed>
     */
    public static function applyAll(array $doc, array $ops): array
    {
        foreach ($ops as $op) {
            $doc = self::apply($doc, $op);
        }

        return $doc;
    }

    /**
     * @param  array<string,mixed>  $doc
     * @param  array<string,mixed>  $op
     * @return array<string,mixed>
     */
    public static function apply(array $doc, array $op): array
    {
        $name = (string) ($op['op'] ?? '');

        if ($name === 'doc.replace') {
            return is_array($op['doc'] ?? null) ? $op['doc'] : $doc;
        }

        if ($name === 'doc.set') {
            $key = (string) ($op['key'] ?? '');

            if ($key === '' || $key === 'blocks') {
                return $doc;
            }

            if (($op['value'] ?? null) === null) {
                unset($doc[$key]);
            } else {
                $doc[$key] = $op['value'];
            }

            return $doc;
        }

        [$kind, $action] = array_pad(explode('.', $name, 2), 2, '');

        if (! isset(self::KINDS[$kind]) || ! in_array($action, ['insert', 'remove', 'move', 'replace'], true)) {
            return $doc;
        }

        $tokens = self::tokens((string) ($op['path'] ?? ''));

        if ($tokens === null || $tokens === [] || ! in_array(end($tokens), self::KINDS[$kind][1], true)) {
            return $doc;
        }

        return self::edit($doc, $tokens, static fn (?array $list): ?array => self::editList($list, $action, $op, self::KINDS[$kind][0]));
    }

    /**
     * Walk to the list at `$tokens` and replace it with `$change($list)`. A null
     * result (or an unreachable path) leaves the document as it was.
     *
     * @param  array<mixed>  $node
     * @param  list<string>  $tokens
     * @param  callable(?array): ?array  $change
     * @return array<mixed>
     */
    private static function edit(array $node, array $tokens, callable $change): array
    {
        $token = array_shift($tokens);
        $key = array_is_list($node) && ctype_digit($token) ? (int) $token : $token;

        if ($tokens === []) {
            $current = $node[$key] ?? null;

            if ($current !== null && (! is_array($current) || ! array_is_list($current))) {
                return $node;
            }

            $changed = $change($current);

            if ($changed !== null) {
                $node[$key] = $changed;
            }

            return $node;
        }

        if (! is_array($node[$key] ?? null)) {
            return $node;
        }

        $node[$key] = self::edit($node[$key], $tokens, $change);

        return $node;
    }

    /**
     * @param  list<mixed>|null  $list
     * @param  array<string,mixed>  $op
     * @return list<mixed>|null
     */
    private static function editList(?array $list, string $action, array $op, string $valueKey): ?array
    {
        if ($list === null && $action !== 'insert') {
            return null;
        }

        $list ??= [];
        $count = count($list);

        switch ($action) {
            case 'insert':
                if (! array_key_exists($valueKey, $op)) {
                    return null;
                }
                $index = max(0, min($count, (int) ($op['index'] ?? $count)));
                array_splice($list, $index, 0, [$op[$valueKey]]);

                return $list;

            case 'remove':
                $index = (int) ($op['index'] ?? -1);
                if ($index < 0 || $index >= $count) {
                    return null;
                }
                array_splice($list, $index, 1);

                return $list;

            case 'replace':
                $index = (int) ($op['index'] ?? -1);
                if ($index < 0 || $index >= $count || ! array_key_exists($valueKey, $op)) {
                    return null;
                }
                $list[$index] = $op[$valueKey];

                return $list;

            case 'move':
                $from = (int) ($op['from'] ?? -1);
                if ($from < 0 || $from >= $count) {
                    return null;
                }
                [$moved] = array_splice($list, $from, 1);
                $to = max(0, min(count($list), (int) ($op['to'] ?? $from)));
                array_splice($list, $to, 0, [$moved]);

                return $list;
        }

        return null;
    }

    /**
     * RFC 6901 JSON Pointer tokens, or null for a pointer that is not one.
     *
     * @return list<string>|null
     */
    public static function tokens(string $pointer): ?array
    {
        if ($pointer === '' || $pointer[0] !== '/') {
            return null;
        }

        return array_map(
            static fn (string $t): string => str_replace(['~1', '~0'], ['/', '~'], $t),
            explode('/', substr($pointer, 1)),
        );
    }
}
