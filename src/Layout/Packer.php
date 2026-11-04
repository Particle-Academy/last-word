<?php

declare(strict_types=1);

namespace LastWord\Layout;

/**
 * The page packer: a line-for-line port of last-word-ui's `paginate()`
 * (`src/paginate.ts`), so the same measured items pack to the same pages.
 * Lengths are CSS px. Keep the two in step: a rule changed on one side and not
 * the other is a page count that silently disagrees.
 *
 * Items:
 *   ['type' => 'block', 'height' => float, 'breakpoints' => list<float>, 'keepNext' => bool,
 *    'footnotes' => list<array{id: int, at: float, height: float}>]
 *   ['type' => 'table', 'rows' => list<array{height: float, joinPrevious?: bool, footnotes?: list<array{id: int, height: float}>}>, 'headerRows' => int]
 *   ['type' => 'break', 'stretch'?: array{pageHeight: float, firstPageHeight?: ?float, firstEvenPageHeight?: ?float, evenPageHeight?: ?float}]
 *
 * A break with `stretch` starts a run of pages of another shape, and those are its
 * heights: `firstPageHeight` its first page when that page is odd and
 * `firstEvenPageHeight` when even, whichever the pages before it make it.
 */
final class Packer
{
    private const EPS = 1e-6;

    /** @var list<array<string, mixed>> */
    private array $items;

    private float $pageHeight;

    private ?float $firstHeight;

    private ?float $evenHeight;

    private ?float $firstEvenHeight;

    /** The index of the first page of the stretch being filled. */
    private int $stretchStart = 0;

    /** Which stretch is being filled, 0-based. */
    private int $stretch = 0;

    private float $sep;

    /** @var list<array{index: int, fragments: list<array<string, mixed>>, footnotes: list<int>, used: float, capacity: float}> */
    private array $pages = [];

    private int $cur = -1;

    private float $cap = 0.0;

    private float $content = 0.0;

    private float $noteHeights = 0.0;

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array{pageHeight: float, firstPageHeight?: ?float, evenPageHeight?: ?float, footnoteSeparator?: float}  $options
     * @return list<array{index: int, fragments: list<array<string, mixed>>, footnotes: list<int>, used: float, capacity: float}>
     */
    public static function paginate(array $items, array $options): array
    {
        $p = new self();
        $p->items = $items;
        $p->pageHeight = (float) $options['pageHeight'];
        $p->firstHeight = isset($options['firstPageHeight']) ? (float) $options['firstPageHeight'] : null;
        $p->evenHeight = isset($options['evenPageHeight']) ? (float) $options['evenPageHeight'] : null;
        $p->firstEvenHeight = isset($options['firstEvenPageHeight']) ? (float) $options['firstEvenPageHeight'] : null;
        $p->sep = max(0.0, (float) ($options['footnoteSeparator'] ?? 0.0));

        return $p->run();
    }

    private static function positive(?float $v): bool
    {
        return $v !== null && is_finite($v) && $v > 0;
    }

    public function capacity(int $index): float
    {
        $base = max(1.0, self::positive($this->pageHeight) ? $this->pageHeight : 1.0);
        $even = $index % 2 === 1;
        if ($index === $this->stretchStart) {
            $first = $even ? $this->firstEvenHeight : $this->firstHeight;
            if (self::positive($first)) {
                return max(1.0, (float) $first);
            }
        }
        if ($even) {
            return self::positive($this->evenHeight) ? max(1.0, (float) $this->evenHeight) : $base;
        }

        return $base;
    }

    /** @return list<array{index: int, fragments: list<array<string, mixed>>, footnotes: list<int>, used: float, capacity: float}> */
    private function run(): array
    {
        $this->newPage();
        foreach ($this->items as $index => $item) {
            if ($item['type'] === 'break') {
                if (isset($item['stretch'])) {
                    $this->pageHeight = (float) $item['stretch']['pageHeight'];
                    $this->firstHeight = isset($item['stretch']['firstPageHeight']) ? (float) $item['stretch']['firstPageHeight'] : null;
                    $this->firstEvenHeight = isset($item['stretch']['firstEvenPageHeight']) ? (float) $item['stretch']['firstEvenPageHeight'] : null;
                    $this->evenHeight = isset($item['stretch']['evenPageHeight']) ? (float) $item['stretch']['evenPageHeight'] : null;
                    $this->stretchStart = count($this->pages);
                    $this->stretch++;
                }
                $this->newPage();

                continue;
            }
            if ($item['type'] === 'table') {
                $this->placeTable($item, $index);

                continue;
            }
            $this->placeBlock($item, $index);
        }

        return $this->pages;
    }

    private function newPage(): void
    {
        $this->cap = $this->capacity(count($this->pages));
        $this->pages[] = ['index' => count($this->pages), 'fragments' => [], 'footnotes' => [], 'used' => 0.0, 'capacity' => $this->cap, 'stretch' => $this->stretch];
        $this->cur = count($this->pages) - 1;
        $this->content = 0.0;
        $this->noteHeights = 0.0;
    }

    private function noteCost(int $extraCount, float $extraHeight): float
    {
        $count = count($this->pages[$this->cur]['footnotes']) + $extraCount;

        return $count > 0 ? $this->noteHeights + $extraHeight + $this->sep : 0.0;
    }

    /** @param list<array{height: float}> $notes */
    private function fits(float $h, array $notes): bool
    {
        return $this->content + $h + $this->noteCost(count($notes), self::sum($notes)) <= $this->cap + self::EPS;
    }

    /** @param list<array{id: int, height: float}> $notes */
    private function commit(float $h, array $notes): void
    {
        $this->content += $h;
        foreach ($notes as $n) {
            $this->pages[$this->cur]['footnotes'][] = $n['id'];
            $this->noteHeights += $n['height'];
        }
        $this->pages[$this->cur]['used'] = $this->content + $this->noteCost(0, 0.0);
    }

    private function isEmpty(): bool
    {
        return $this->pages[$this->cur]['fragments'] === [];
    }

    /** @param list<array{height: float}> $notes */
    private static function sum(array $notes): float
    {
        $s = 0.0;
        foreach ($notes as $n) {
            $s += $n['height'];
        }

        return $s;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return list<array{id: int, at: float, height: float}>
     */
    private function notesIn(array $item, float $start, float $end, bool $last): array
    {
        $h = max(0.0, (float) $item['height']);
        $out = [];
        foreach ($item['footnotes'] ?? [] as $n) {
            $at = min($h, max(0.0, (float) $n['at']));
            if ($at >= $start - self::EPS && ($last ? $at <= $end + self::EPS : $at < $end - self::EPS)) {
                $out[] = $n;
            }
        }

        return $out;
    }

    /** @param array<string, mixed>|null $item */
    private function firstPiece(?array $item): float
    {
        if ($item === null || $item['type'] === 'break') {
            return 0.0;
        }
        if ($item['type'] === 'block') {
            foreach ($item['breakpoints'] ?? [] as $b) {
                if ($b > self::EPS && $b < $item['height'] - self::EPS) {
                    return (float) $b;
                }
            }

            return (float) $item['height'];
        }
        $header = 0.0;
        foreach (array_slice($item['rows'], 0, $this->headerCount($item)) as $r) {
            $header += $r['height'];
        }
        $units = $this->tableUnits($item);

        return $header + ($units[0]['height'] ?? 0.0);
    }

    private function keepChainHeight(int $from): float
    {
        $total = 0.0;
        $i = $from;
        while ($i < count($this->items)) {
            $it = $this->items[$i];
            if ($it['type'] === 'block' && ! empty($it['keepNext'])) {
                $total += $it['height'];
                $i++;

                continue;
            }
            $total += $this->firstPiece($it);
            break;
        }

        return $total;
    }

    /** @param array<string, mixed> $item */
    private function placeBlock(array $item, int $index): void
    {
        $height = max(0.0, (float) $item['height']);

        if (! empty($item['keepNext']) && ! $this->isEmpty()) {
            $chain = $this->keepChainHeight($index);
            $next = $this->items[$index + 1] ?? null;
            $meaningful = $next !== null && $next['type'] !== 'break';
            $nextCap = $this->capacity($this->pages[$this->cur]['index'] + 1);
            if ($meaningful && $chain <= $nextCap + self::EPS && ! $this->fits($chain, $this->notesIn($item, 0.0, $height, true))) {
                $this->newPage();
            }
        }

        $start = 0.0;
        for ($guard = 0; $guard < 100000; $guard++) {
            $restNotes = $this->notesIn($item, $start, $height, true);
            if ($this->fits($height - $start, $restNotes)) {
                $this->pages[$this->cur]['fragments'][] = ['type' => 'block', 'item' => $index, 'start' => $start, 'end' => $height];
                $this->commit($height - $start, $restNotes);

                return;
            }

            $bps = array_values(array_filter($item['breakpoints'] ?? [], fn ($b) => $b > $start + self::EPS && $b < $height - self::EPS));
            rsort($bps);
            $cut = null;
            foreach ($bps as $bp) {
                if ($this->fits($bp - $start, $this->notesIn($item, $start, $bp, false))) {
                    $cut = (float) $bp;
                    break;
                }
            }
            if ($cut !== null) {
                $this->pages[$this->cur]['fragments'][] = ['type' => 'block', 'item' => $index, 'start' => $start, 'end' => $cut];
                $this->commit($cut - $start, $this->notesIn($item, $start, $cut, false));
                $this->newPage();
                $start = $cut;

                continue;
            }

            if (! $this->isEmpty()) {
                $this->newPage();

                continue;
            }

            $room = $this->cap;
            $end = min($height, $start + $room);
            $notes = $this->notesIn($item, $start, $end, false);
            $room = $this->cap - $this->noteCost(count($notes), self::sum($notes));
            if ($room <= self::EPS) {
                $room = $this->cap;
            }
            $end = min($height, $start + $room);
            $notes = $this->notesIn($item, $start, $end, $end >= $height - self::EPS);
            $forced = $end < $height - self::EPS;
            $this->pages[$this->cur]['fragments'][] = ['type' => 'block', 'item' => $index, 'start' => $start, 'end' => $end, 'forced' => $forced];
            $this->commit($end - $start, $notes);
            if (! $forced) {
                return;
            }
            $this->newPage();
            $start = $end;
        }
    }

    /** @param array<string, mixed> $item */
    private function headerCount(array $item): int
    {
        return max(0, min((int) ($item['headerRows'] ?? 0), count($item['rows'])));
    }

    /**
     * @param  array<string, mixed>  $item
     * @return list<array{rows: list<int>, height: float, notes: list<array{id: int, height: float}>}>
     */
    private function tableUnits(array $item): array
    {
        $units = [];
        $rows = $item['rows'];
        for ($r = $this->headerCount($item); $r < count($rows); $r++) {
            $row = $rows[$r];
            if (! empty($row['joinPrevious']) && $units !== []) {
                $last = count($units) - 1;
                $units[$last]['rows'][] = $r;
                $units[$last]['height'] += $row['height'];
                array_push($units[$last]['notes'], ...($row['footnotes'] ?? []));
            } else {
                $units[] = ['rows' => [$r], 'height' => (float) $row['height'], 'notes' => $row['footnotes'] ?? []];
            }
        }

        return $units;
    }

    /** @param array<string, mixed> $item */
    private function placeTable(array $item, int $index): void
    {
        $h = $this->headerCount($item);
        $headerIdx = range(0, $h - 1);
        if ($h === 0) {
            $headerIdx = [];
        }
        $headerHeight = 0.0;
        $headerNotes = [];
        foreach (array_slice($item['rows'], 0, $h) as $r) {
            $headerHeight += $r['height'];
            array_push($headerNotes, ...($r['footnotes'] ?? []));
        }
        $units = $this->tableUnits($item);
        if ($units === [] && $h === 0) {
            return;
        }
        if ($units === []) {
            $units[] = ['rows' => [], 'height' => 0.0, 'notes' => []];
        }

        $frag = null;
        $first = true;
        for ($u = 0; $u < count($units);) {
            $unit = $units[$u];
            if ($frag === null) {
                $notes = $first ? array_merge($headerNotes, $unit['notes']) : $unit['notes'];
                $need = $headerHeight + $unit['height'];
                if (! $this->fits($need, $notes) && ! $this->isEmpty()) {
                    $this->newPage();

                    continue;
                }
                $overflow = ! $this->fits($need, $notes);
                $this->pages[$this->cur]['fragments'][] = [
                    'type' => 'table', 'item' => $index, 'rows' => array_merge($headerIdx, $unit['rows']),
                    'repeatedHeader' => ! $first && $h > 0, 'overflow' => $overflow,
                ];
                $frag = count($this->pages[$this->cur]['fragments']) - 1;
                $this->commit($need, $notes);
                $first = false;
                $u++;

                continue;
            }
            if ($this->fits($unit['height'], $unit['notes'])) {
                array_push($this->pages[$this->cur]['fragments'][$frag]['rows'], ...$unit['rows']);
                $this->commit($unit['height'], $unit['notes']);
                $u++;

                continue;
            }
            $this->newPage();
            $frag = null;
        }
    }
}
