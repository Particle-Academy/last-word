<?php

declare(strict_types=1);

namespace LastWord\Exchange;

/**
 * Expands inline `{"type": "portable", "content": ...}` blocks into ordinary
 * blocks through {@see FromPortable}, wherever a block list may hold one: the
 * document's `blocks`, a quote's `blocks`, a table cell's `blocks`.
 *
 * Used by validateAndRepair(), the write path and lint, so all three see the
 * same expansion. Content that does not validate is LEFT IN PLACE: it is
 * refused (the validator reports it), never guessed at or dropped.
 * Deterministic: the same document always expands to the same blocks.
 */
final class Portable
{
    public const TYPE = 'portable';

    /** Quotes and table cells nested deeper than this are not searched for portable blocks. */
    public const MAX_DEPTH = 64;

    /** @var list<array{path: string, message: string}> */
    private array $notes = [];

    /** @var array<string, mixed> */
    private array $report;

    private bool $expanded = false;

    private function __construct()
    {
        $this->report = Report::empty();
    }

    /**
     * @param  array<string, mixed>  $doc
     * @return array{doc: array<string, mixed>, notes: list<array{path: string, message: string}>, report: array<string, mixed>, expanded: bool}
     */
    public static function expand(array $doc): array
    {
        $x = new self;
        if (is_array($doc['blocks'] ?? null) && array_is_list($doc['blocks'])) {
            $doc['blocks'] = $x->blocks($doc['blocks'], 'blocks', '/blocks', 0);
        }

        return ['doc' => $doc, 'notes' => $x->notes, 'report' => $x->report, 'expanded' => $x->expanded];
    }

    /**
     * @param  list<mixed>  $blocks
     * @return list<mixed>
     */
    private function blocks(array $blocks, string $path, string $pointer, int $depth): array
    {
        if ($depth > self::MAX_DEPTH) {
            return $blocks; // a recursion bound: nothing this deep is expanded
        }
        $out = [];
        foreach ($blocks as $i => $block) {
            $at = "{$path}[{$i}]";
            $ptr = "{$pointer}/{$i}";
            if (! is_array($block)) {
                $out[] = $block;

                continue;
            }
            $type = $block['type'] ?? null;
            if ($type === self::TYPE) {
                $r = FromPortable::convert($block['content'] ?? null);
                if (! $r['ok']) {
                    $out[] = $block;

                    continue;
                }
                array_push($out, ...$r['blocks']);
                $this->expanded = true;
                $this->notes[] = ['path' => $at, 'message' => 'expanded a portable block into '.count($r['blocks']).' block(s)'];
                $this->report = Report::merge($this->report, Report::rebase($r['report'], $ptr.'/content'));

                continue;
            }
            if ($type === 'quote' && is_array($block['blocks'] ?? null) && array_is_list($block['blocks'])) {
                $block['blocks'] = $this->blocks($block['blocks'], "{$at}.blocks", "{$ptr}/blocks", $depth + 1);
            }
            if ($type === 'table' && is_array($block['rows'] ?? null) && array_is_list($block['rows'])) {
                foreach ($block['rows'] as $r => $row) {
                    if (! is_array($row) || ! is_array($row['cells'] ?? null) || ! array_is_list($row['cells'])) {
                        continue;
                    }
                    foreach ($row['cells'] as $c => $cell) {
                        if (is_array($cell) && is_array($cell['blocks'] ?? null) && array_is_list($cell['blocks'])) {
                            $block['rows'][$r]['cells'][$c]['blocks'] = $this->blocks(
                                $cell['blocks'],
                                "{$at}.rows[{$r}].cells[{$c}].blocks",
                                "{$ptr}/rows/{$r}/cells/{$c}/blocks",
                                $depth + 1,
                            );
                        }
                    }
                }
            }
            $out[] = $block;
        }

        return $out;
    }
}
