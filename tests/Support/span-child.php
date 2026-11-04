<?php

declare(strict_types=1);

/*
 * Runs one table-span operation in a child with its own memory_limit, so that a
 * regression fails one test instead of taking the suite (or the machine) with it.
 * Prints the outcome; exits non-zero on a fatal error, which is the red.
 */

require dirname(__DIR__, 2).'/vendor/autoload.php';

use LastWord\Agent;
use LastWord\Layout\TableGrid;

$kind = $argv[1] ?? '';
$cell = static fn (int $colSpan, int $rowSpan): array => [
    'blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'x']]]],
    'colSpan' => $colSpan,
    'rowSpan' => $rowSpan,
];

echo match ($kind) {
    // 30 cells, each 1000 wide and 300 tall, in about 6 KB of JSON.
    'grid' => (string) TableGrid::of([
        ['cells' => array_map(static fn () => $cell(1000, 300), range(1, 30))],
        ...array_fill(0, 299, ['cells' => []]),
    ])['colCount'],
    // A short row beside a very wide one: the exchange must refuse without padding it.
    'portable' => json_encode(Agent::toPortable(['blocks' => [['type' => 'table', 'rows' => [
        ['cells' => [$cell(1000000000, 1)]],
        ['cells' => [['blocks' => []]]],
    ]]]])),
    default => throw new InvalidArgumentException('unknown kind'),
};
