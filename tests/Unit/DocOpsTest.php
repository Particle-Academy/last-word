<?php

declare(strict_types=1);

use LastWord\Agent;
use LastWord\Ops\DocDiff;
use LastWord\Ops\DocOpSchema;

/*
 * Agent::diff / Agent::reduce / Agent::opSchema (last-word#2).
 *
 * What a version history built on these needs, pinned:
 *
 * 1. Round trip: reduce($a, diff($a, $b)) equals $b, both ways.
 * 2. Small edits stay small: rewording one paragraph is one block-level op,
 *    at whatever depth it sits — asserted as the exact ops, with their paths.
 * 3. A save without a change records nothing: diff($d, read(toBytes($d))) === [].
 */

function lwOpsDoc(): array
{
    $p = static fn (string $text): array => ['type' => 'paragraph', 'runs' => [['text' => $text]]];

    return [
        'title' => 'Q3 review',
        'blocks' => [
            ['type' => 'heading', 'level' => 1, 'runs' => [['text' => 'Q3 review']]],
            $p('Revenue grew in every region.'),
            $p('Costs held flat.'),
            ['type' => 'list', 'items' => [
                ['runs' => [['text' => 'North']], 'children' => [['runs' => [['text' => 'Enterprise']]]]],
                ['runs' => [['text' => 'South']]],
            ]],
            ['type' => 'table', 'rows' => [
                ['header' => true, 'cells' => [['blocks' => [$p('Region')]], ['blocks' => [$p('Revenue')]]]],
                ['cells' => [['blocks' => [$p('North')]], ['blocks' => [$p('1,250,000')]]]],
            ]],
            ['type' => 'quote', 'blocks' => [$p('Best quarter yet.'), $p('— the CFO')]],
            ['type' => 'hr'],
            $p('Next steps follow.'),
        ],
    ];
}

function lwP(string $text): array
{
    return ['type' => 'paragraph', 'runs' => [['text' => $text]]];
}

dataset('doc edits', [
    'a paragraph reworded' => [function (array $d) {
        $d['blocks'][2] = lwP('Costs fell 3%.');

        return $d;
    }, [['op' => 'blocks.replace', 'path' => '/blocks', 'index' => 2]]],
    'a paragraph inserted' => [function (array $d) {
        array_splice($d['blocks'], 2, 0, [lwP('Margins widened.')]);

        return $d;
    }, [['op' => 'blocks.insert', 'path' => '/blocks', 'index' => 2]]],
    'a paragraph removed' => [function (array $d) {
        array_splice($d['blocks'], 1, 1);

        return $d;
    }, [['op' => 'blocks.remove', 'path' => '/blocks', 'index' => 1]]],
    'a paragraph moved' => [function (array $d) {
        [$moved] = array_splice($d['blocks'], 7, 1);
        array_splice($d['blocks'], 1, 0, [$moved]);

        return $d;
    }, [['op' => 'blocks.move', 'path' => '/blocks', 'from' => 7, 'to' => 1]]],
    'the title' => [function (array $d) {
        $d['title'] = 'Q3 review (final)';

        return $d;
    }, [['op' => 'doc.set', 'key' => 'title']]],
    'page settings added' => [function (array $d) {
        $d['page'] = ['size' => 'a4', 'orientation' => 'landscape'];

        return $d;
    }, [['op' => 'doc.set', 'key' => 'page']]],
    'a list item reworded' => [function (array $d) {
        $d['blocks'][3]['items'][1]['runs'] = [['text' => 'South and West']];

        return $d;
    }, [['op' => 'items.replace', 'path' => '/blocks/3/items', 'index' => 1]]],
    'a nested list item added' => [function (array $d) {
        $d['blocks'][3]['items'][0]['children'][] = ['runs' => [['text' => 'Mid-market']]];

        return $d;
    }, [['op' => 'items.insert', 'path' => '/blocks/3/items/0/children', 'index' => 1]]],
    'a table cell reworded' => [function (array $d) {
        $d['blocks'][4]['rows'][1]['cells'][1]['blocks'] = [lwP('1,300,000')];

        return $d;
    }, [['op' => 'blocks.replace', 'path' => '/blocks/4/rows/1/cells/1/blocks', 'index' => 0]]],
    'a table row added' => [function (array $d) {
        $d['blocks'][4]['rows'][] = ['cells' => [['blocks' => [lwP('South')]], ['blocks' => [lwP('980,400')]]]];

        return $d;
    }, [['op' => 'rows.insert', 'path' => '/blocks/4/rows', 'index' => 2]]],
    'a quoted paragraph reworded' => [function (array $d) {
        $d['blocks'][5]['blocks'][1] = lwP('— our CFO');

        return $d;
    }, [['op' => 'blocks.replace', 'path' => '/blocks/5/blocks', 'index' => 1]]],
    'a block changed type' => [function (array $d) {
        $d['blocks'][2] = ['type' => 'heading', 'level' => 2, 'runs' => [['text' => 'Costs held flat.']]];

        return $d;
    }, [['op' => 'blocks.replace', 'path' => '/blocks', 'index' => 2]]],
    'a table re-styled' => [function (array $d) {
        $d['blocks'][4]['width'] = 80;

        return $d;
    }, [['op' => 'blocks.replace', 'path' => '/blocks', 'index' => 4]]],
]);

it('reproduces the target exactly, with one op at the right path', function (callable $edit, array $expected) {
    $a = lwOpsDoc();
    $b = $edit(lwOpsDoc());

    $ops = Agent::diff($a, $b);

    // The op, path and position; the payload is checked by the round trip.
    $shape = array_map(
        fn (array $op) => array_intersect_key($op, array_flip(['op', 'path', 'index', 'from', 'to', 'key'])),
        $ops,
    );
    expect($shape)->toBe($expected);
    expect(DocDiff::same(Agent::reduce($a, $ops), $b))->toBeTrue();

    $reverse = Agent::diff($b, $a);
    expect(DocDiff::same(Agent::reduce($b, $reverse), $a))->toBeTrue();
})->with('doc edits');

it('records nothing for a save without a change', function () {
    $doc = lwOpsDoc();
    expect(Agent::diff($doc, Agent::read(Agent::toBytes($doc))))->toBe([]);

    // The canonical fixture has constructs the reader normalises.
    $canonical = lwCanonical();
    expect(Agent::diff($canonical, Agent::read(Agent::toBytes($canonical))))->toBe([]);

    // Runs the reader will merge, and a header row it will read back bold, are
    // not changes to the file.
    $normalised = ['blocks' => [
        ['type' => 'paragraph', 'runs' => [['text' => 'Split '], ['text' => 'run']]],
        ['type' => 'table', 'rows' => [['header' => true, 'cells' => [['blocks' => [lwP('Head')]]]]]],
    ]];
    $readBack = Agent::read(Agent::toBytes($normalised));
    expect(DocDiff::same($readBack, $normalised))->toBeFalse('the fixture must actually exercise a normalisation');
    expect(Agent::diff($normalised, $readBack))->toBe([]);
    expect(Agent::equivalent($normalised, $readBack))->toBeTrue();
});

it('keeps the round trip over a seeded run of random edits, without replacing the document', function () {
    mt_srand(20260915);
    $words = ['alpha', 'beta', 'gamma', 'delta', 'epsilon', 'zeta'];

    for ($run = 0; $run < 60; $run++) {
        $a = lwOpsDoc();
        $b = $a;

        for ($k = 0, $edits = mt_rand(1, 4); $k < $edits; $k++) {
            $blocks = &$b['blocks'];
            $count = count($blocks);

            match (mt_rand(0, 4)) {
                0 => array_splice($blocks, mt_rand(0, $count), 0, [lwP($words[mt_rand(0, 5)].' '.mt_rand(1, 99))]),
                1 => $count > 1 ? array_splice($blocks, mt_rand(0, $count - 1), 1) : null,
                2 => $blocks[mt_rand(0, $count - 1)] = lwP($words[mt_rand(0, 5)]),
                3 => (function () use (&$blocks, $count) {
                    [$moved] = array_splice($blocks, mt_rand(0, $count - 1), 1);
                    array_splice($blocks, mt_rand(0, $count - 1), 0, [$moved]);
                })(),
                default => $b['title'] = 'T'.mt_rand(1, 9),
            };
            unset($blocks);
        }

        $ops = Agent::diff($a, $b);

        expect(DocDiff::same(Agent::reduce($a, $ops), $b))->toBeTrue("run {$run}");
        expect(array_column($ops, 'op'))->not->toContain('doc.replace', "run {$run} fell back");
    }
});

it('skips an op whose path or index does not resolve, and never modifies its input', function () {
    $d = lwOpsDoc();

    expect(Agent::reduce($d, ['op' => 'blocks.remove', 'path' => '/blocks', 'index' => 99]))->toBe($d);
    expect(Agent::reduce($d, ['op' => 'blocks.replace', 'path' => '/nowhere/blocks', 'index' => 0, 'block' => lwP('x')]))->toBe($d);
    // A path must end in the kind of list the op edits.
    expect(Agent::reduce($d, ['op' => 'rows.remove', 'path' => '/blocks', 'index' => 0]))->toBe($d);
    expect(Agent::reduce($d, ['op' => 'no.such', 'path' => '/blocks']))->toBe($d);
    expect($d)->toBe(lwOpsDoc());

    // Inserting into a children list that does not exist yet creates it.
    $withChild = Agent::reduce($d, ['op' => 'items.insert', 'path' => '/blocks/3/items/1/children', 'index' => 0, 'item' => ['runs' => [['text' => 'Retail']]]]);
    expect($withChild['blocks'][3]['items'][1]['children'])->toBe([['runs' => [['text' => 'Retail']]]]);

    // One op or a list.
    $one = Agent::reduce($d, ['op' => 'doc.set', 'key' => 'title', 'value' => null]);
    expect($one)->not->toHaveKey('title');
    expect(Agent::reduce($d, [['op' => 'doc.set', 'key' => 'title', 'value' => 'X']])['title'])->toBe('X');
});

it('publishes one schema variant per op, and diff only emits those', function () {
    $schema = Agent::opSchema();

    expect($schema)->toBe(DocOpSchema::jsonSchema());
    expect(array_map(fn (array $v) => $v['properties']['op']['const'], $schema['oneOf']))->toBe(DocOpSchema::TYPES);
});

it('aligns lists by content, breaking ties toward deleting first', function () {
    expect(DocDiff::hunks(['a', 'b', 'c'], ['a', 'x', 'b', 'c']))->toBe([[1, 0, 1, 1]]);
    expect(DocDiff::hunks(['a', 'b', 'c'], ['a', 'c']))->toBe([[1, 1, 0, 1]]);
    expect(DocDiff::hunks(['a', 'b'], ['a', 'z']))->toBe([[1, 1, 1, 1]]);
});

it('refuses to compare values JSON cannot hold, instead of calling them the same', function () {
    // Found in holy-sheet's identical helper by its Python port: both values
    // encoded to "" and compared equal.
    expect(fn () => DocDiff::same(chr(0xB1), chr(0xB2)))->toThrow(JsonException::class);
});

it('skips an op whose position or key is not one, instead of casting it to 0 or "1"', function () {
    $d = lwOpsDoc();

    // `(int) "abc"` is 0: these edited the FIRST block.
    expect(Agent::reduce($d, ['op' => 'blocks.remove', 'path' => '/blocks', 'index' => 'abc']))->toBe($d);
    expect(Agent::reduce($d, ['op' => 'blocks.replace', 'path' => '/blocks', 'index' => 'x', 'block' => lwP('x')]))->toBe($d);
    expect(Agent::reduce($d, ['op' => 'blocks.move', 'path' => '/blocks', 'from' => 'first', 'to' => 3]))->toBe($d);
    expect(Agent::reduce($d, ['op' => 'blocks.insert', 'path' => '/blocks', 'index' => true, 'block' => lwP('x')]))->toBe($d);

    // `(string) true` is "1": this set a top-level key "1".
    expect(Agent::reduce($d, ['op' => 'doc.set', 'key' => true, 'value' => 'x']))->toBe($d);

    // An op name or path that is not a string is no op, not "Array".
    expect(Agent::reduce($d, ['op' => ['blocks.remove'], 'path' => '/blocks', 'index' => 0]))->toBe($d);
    expect(Agent::reduce($d, ['op' => 'blocks.remove', 'path' => ['/blocks'], 'index' => 0]))->toBe($d);

    // Digit strings and ints still work.
    expect(count(Agent::reduce($d, ['op' => 'blocks.remove', 'path' => '/blocks', 'index' => '1'])['blocks']))->toBe(count($d['blocks']) - 1);
});

