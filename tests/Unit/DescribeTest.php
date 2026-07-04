<?php

declare(strict_types=1);

use LastWord\Agent;

/*
 * Spec vector 6: describe() includes the title, per-type block counts and
 * a word count.
 */

it('describes the canonical document', function () {
    $summary = Agent::describe(lwCanonical());

    expect($summary)->toContain('Last Word Canonical')
        ->and($summary)->toContain('Blocks: 13')
        ->and($summary)->toContain('3 heading')
        ->and($summary)->toContain('2 paragraph')
        ->and($summary)->toContain('2 list')
        ->and($summary)->toContain('1 table')
        ->and($summary)->toContain('1 code')
        ->and($summary)->toContain('1 quote')
        ->and($summary)->toContain('1 image')
        ->and($summary)->toContain('1 pageBreak')
        ->and($summary)->toContain('1 hr')
        ->and($summary)->toMatch('/Words: \d+/');
});

it('counts words across runs, list items, table cells and code', function () {
    $summary = Agent::describe([
        'title' => 'Two words',
        'blocks' => [
            ['type' => 'paragraph', 'runs' => [['text' => 'three little words']]],
            ['type' => 'list', 'items' => [['runs' => [['text' => 'four']], 'children' => [['runs' => [['text' => 'five']]]]]]],
            ['type' => 'code', 'text' => 'six seven'],
        ],
    ]);

    // 2 (title) + 3 + 2 (list) + 2 (code) = 9
    expect($summary)->toContain('Words: 9');
});

it('describes an untitled empty document gracefully', function () {
    $summary = Agent::describe(['blocks' => []]);

    expect($summary)->toContain('Untitled')
        ->and($summary)->toContain('Blocks: 0')
        ->and($summary)->toContain('Words: 0');
});
