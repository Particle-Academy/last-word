<?php

declare(strict_types=1);

namespace LastWord\Exchange;

/**
 * The conversion report (exchange SPEC section 5): what was dropped or
 * approximated, and what the guard found. Locations and counts only, NEVER
 * text taken from the document: every `where` is a JSON Pointer built here
 * from indexes and fixed key names.
 */
final class Report
{
    /** The closed list of kinds, and the list each belongs in (SPEC 5.1). */
    public const KINDS = [
        'cell-style-dropped' => 'dropped',
        'chart' => 'dropped',
        'comment' => 'dropped',
        'composite-element' => 'dropped',
        'embedded-object' => 'dropped',
        'hidden-column' => 'dropped',
        'hidden-element' => 'dropped',
        'hidden-row' => 'dropped',
        'hidden-sheet' => 'dropped',
        'hidden-slide' => 'dropped',
        'image' => 'dropped',
        'link' => 'dropped',
        'nested-block' => 'dropped',
        'non-table-item' => 'dropped',
        'run-style-dropped' => 'dropped',
        'shape' => 'dropped',
        'table-clipped' => 'dropped',
        'cell-truncated' => 'approximated',
        'chart-as-table' => 'approximated',
        'code-as-text' => 'approximated',
        'csv-escaped' => 'approximated',
        'format-approximated' => 'approximated',
        'formula-as-value' => 'approximated',
        'formula-uncomputed' => 'approximated',
        'header-rows-flattened' => 'approximated',
        'layout-not-preserved' => 'approximated',
        'markup-as-text' => 'approximated',
        'merge-flattened' => 'approximated',
        'merge-unread' => 'approximated',
        'quote-flattened' => 'approximated',
        'structure-not-recovered' => 'approximated',
        'typed-as-text' => 'approximated',
    ];

    /** At most this many locations per entry. */
    public const MAX_WHERE = 16;

    /** @var array<string, array{count: int, where: list<string>}> */
    private array $entries = [];

    /** @var array<string, int> */
    private array $findings = [];

    /** `dropped`, `approximated`, or null for anything not on the list. */
    public static function classifyKind(mixed $kind): ?string
    {
        return is_string($kind) ? (self::KINDS[$kind] ?? null) : null;
    }

    /** The empty report: all three keys, always. */
    public static function empty(): array
    {
        return ['dropped' => [], 'approximated' => [], 'guard' => ['findings' => []]];
    }

    /** Record one occurrence of a kind, located by a JSON Pointer. */
    public function add(string $kind, ?string $where = null, int $count = 1): void
    {
        if (! isset(self::KINDS[$kind])) {
            throw new \LogicException('Unknown report kind '.$kind);
        }
        $e = $this->entries[$kind] ?? ['count' => 0, 'where' => []];
        $e['count'] += $count;
        if ($where !== null && count($e['where']) < self::MAX_WHERE) {
            $e['where'][] = $where;
        }
        $this->entries[$kind] = $e;
    }

    public function finding(string $rule, int $count = 1): void
    {
        $this->findings[$rule] = ($this->findings[$rule] ?? 0) + $count;
    }

    /** @return array{dropped: list<array<string, mixed>>, approximated: list<array<string, mixed>>, guard: array{findings: list<array{rule: string, count: int}>}} */
    public function toArray(): array
    {
        $out = self::empty();
        $kinds = array_keys($this->entries);
        usort($kinds, Content::compareKeys(...));
        foreach ($kinds as $kind) {
            $e = $this->entries[$kind];
            $entry = ['kind' => $kind, 'count' => $e['count']];
            if ($e['where'] !== []) {
                $entry['where'] = $e['where'];
            }
            $out[self::KINDS[$kind]][] = $entry;
        }
        $rules = array_keys($this->findings);
        usort($rules, fn ($a, $b) => Content::compareKeys((string) $a, (string) $b));
        foreach ($rules as $rule) {
            $out['guard']['findings'][] = ['rule' => (string) $rule, 'count' => $this->findings[$rule]];
        }

        return $out;
    }

    /**
     * Merge two reports: counts add, `where` lists concatenate (a then b,
     * capped), kinds and rules stay sorted. Entries whose kind is not on the
     * closed list, and guard entries without a string rule, are ignored.
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     * @return array<string, mixed>
     */
    public static function merge(array $a, array $b): array
    {
        $r = new self;
        foreach ([$a, $b] as $report) {
            foreach (['dropped', 'approximated'] as $list) {
                foreach (is_array($report[$list] ?? null) ? $report[$list] : [] as $entry) {
                    if (! is_array($entry) || self::classifyKind($entry['kind'] ?? null) === null) {
                        continue;
                    }
                    $count = is_int($entry['count'] ?? null) ? $entry['count'] : 0;
                    $where = is_array($entry['where'] ?? null) ? array_values(array_filter($entry['where'], 'is_string')) : [];
                    $r->add($entry['kind'], null, $count);
                    foreach ($where as $w) {
                        $r->add($entry['kind'], $w, 0);
                    }
                }
            }
            foreach (is_array($report['guard']['findings'] ?? null) ? $report['guard']['findings'] : [] as $f) {
                if (is_array($f) && is_string($f['rule'] ?? null) && is_int($f['count'] ?? null)) {
                    $r->finding($f['rule'], $f['count']);
                }
            }
        }

        return $r->toArray();
    }

    /**
     * The same report with every `where` moved under a prefix pointer, so a
     * report about portable content locates into the document holding it.
     *
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    public static function rebase(array $report, string $prefix): array
    {
        foreach (['dropped', 'approximated'] as $list) {
            foreach ($report[$list] as $i => $entry) {
                if (isset($entry['where'])) {
                    $report[$list][$i]['where'] = array_map(fn (string $w) => $prefix.$w, $entry['where']);
                }
            }
        }

        return $report;
    }
}
