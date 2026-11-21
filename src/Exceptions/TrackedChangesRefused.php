<?php

declare(strict_types=1);

namespace LastWord\Exceptions;

use RuntimeException;

/**
 * A .docx read with `refuseTrackedChanges` holds revision markup.
 *
 * The reader flattens revisions: an insertion's text is kept, a deletion's is
 * dropped, a formatting change keeps the new formatting. Writing that result
 * back erases the pending changes, so a caller that must not do that asks for
 * this refusal instead. Branch on `reason` (always `tracked_changes_present`)
 * and the counts; the message names the counts and never document text.
 */
final class TrackedChangesRefused extends RuntimeException
{
    public readonly string $reason;

    public function __construct(
        public readonly int $insertions,
        public readonly int $deletions,
        public readonly int $moves,
        public readonly int $formatChanges,
    ) {
        $this->reason = 'tracked_changes_present';
        parent::__construct(
            'Document has tracked changes ('
            .self::count($insertions, 'insertion', 'insertions').', '
            .self::count($deletions, 'deletion', 'deletions').', '
            .self::count($moves, 'move', 'moves').', '
            .self::count($formatChanges, 'formatting change', 'formatting changes')
            .'); refused because refuseTrackedChanges is set. Reading would accept them '
            .'and writing the result back would erase them.'
        );
    }

    private static function count(int $n, string $one, string $many): string
    {
        return $n.' '.($n === 1 ? $one : $many);
    }
}
