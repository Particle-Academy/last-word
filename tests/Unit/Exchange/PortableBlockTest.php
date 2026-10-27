<?php

declare(strict_types=1);

use LastWord\Agent;
use LastWord\Exceptions\SchemaException;

/*
 * The inline `{"type": "portable", "content": ...}` block (plan section 4.1,
 * owner decision 9): validated closed and against the exchange limits,
 * expanded deterministically by validateAndRepair() and by the write path,
 * wherever a block may go (the document, a quote, a table cell).
 */

function lwpbContent(): array
{
    return [
        'fancyContent' => 1,
        'source' => ['package' => 'holy-sheet'],
        'items' => [[
            'type' => 'table', 'caption' => 'Q4 revenue', 'headerRows' => 1,
            'rows' => [
                ['cells' => [['t' => 'string', 'v' => 'Region'], ['t' => 'string', 'v' => 'Revenue']]],
                ['cells' => [['t' => 'string', 'v' => 'NA'], ['t' => 'number', 'v' => 4820000, 'text' => '$4,820,000.00', 'formula' => 'SUM(B2:B4)']]],
            ],
        ]],
    ];
}

function lwpbDoc(array $block): array
{
    return ['title' => 'Q4 review', 'blocks' => [
        ['type' => 'heading', 'level' => 1, 'runs' => [['text' => 'Q4 review']]],
        $block,
    ]];
}

it('validates a document holding a portable block', function (): void {
    expect(Agent::validate(lwpbDoc(['type' => 'portable', 'content' => lwpbContent()])))->toBe([]);
});

it('expands the block in validateAndRepair, with a note and a report located in the document', function (): void {
    $r = Agent::validateAndRepair(lwpbDoc(['type' => 'portable', 'content' => lwpbContent()]));

    expect($r['ok'])->toBeTrue()
        ->and(array_column($r['schema']['blocks'], 'type'))->toBe(['heading', 'paragraph', 'table'])
        ->and($r['schema']['blocks'][2]['rows'][0]['header'])->toBeTrue()
        ->and($r['schema']['blocks'][2]['rows'][1]['cells'][1]['blocks'][0])
        ->toBe(['type' => 'paragraph', 'runs' => [['text' => '$4,820,000.00']], 'align' => 'right'])
        ->and($r['errors'][0]['path'])->toBe('blocks[1]')
        ->and($r['report']['approximated'])->toBe([
            ['kind' => 'formula-as-value', 'count' => 1, 'where' => ['/blocks/1/content/items/0/rows/1/cells/1']],
            ['kind' => 'typed-as-text', 'count' => 1, 'where' => ['/blocks/1/content/items/0/rows/1/cells/1']],
        ]);
});

it('expands inside a quote and inside a table cell', function (): void {
    $doc = ['blocks' => [
        ['type' => 'quote', 'blocks' => [['type' => 'portable', 'content' => lwpbContent()]]],
        ['type' => 'table', 'rows' => [['cells' => [['blocks' => [['type' => 'portable', 'content' => lwpbContent()]]]]]]],
    ]];
    $r = Agent::validateAndRepair($doc);

    expect($r['ok'])->toBeTrue()
        ->and(array_column($r['schema']['blocks'][0]['blocks'], 'type'))->toBe(['paragraph', 'table'])
        ->and(array_column($r['schema']['blocks'][1]['rows'][0]['cells'][0]['blocks'], 'type'))->toBe(['paragraph', 'table']);
});

it('writes the same bytes whether the block is expanded first or by the writer', function (): void {
    $doc = lwpbDoc(['type' => 'portable', 'content' => lwpbContent()]);

    expect(Agent::toBytes($doc))->toBe(Agent::toBytes(Agent::validateAndRepair($doc)['schema']))
        ->and(Agent::read(Agent::toBytes($doc))['blocks'][2]['type'])->toBe('table');
});

it('refuses invalid content: validate names it, repair leaves it, the writer throws', function (): void {
    $bad = lwpbContent();
    $bad['items'][0]['rows'][1]['cells'][0]['runs'] = [['text' => 'NA', 'link' => 'javascript:alert(1)']];
    $doc = lwpbDoc(['type' => 'portable', 'content' => $bad]);

    $errors = Agent::validate($doc);
    expect($errors)->toHaveCount(1)
        ->and($errors[0]['path'])->toBe('blocks[1].content')
        ->and($errors[0]['message'])->toContain('content_invalid')
        ->and($errors[0]['message'])->not->toContain('javascript');

    $r = Agent::validateAndRepair($doc);
    expect($r['ok'])->toBeFalse()
        ->and($r['schema']['blocks'][1]['type'])->toBe('portable');

    expect(fn () => Agent::toBytes($doc))->toThrow(SchemaException::class);
});

it('enforces the exchange limits on an inline block', function (): void {
    $big = ['fancyContent' => 1, 'items' => array_fill(0, 5001, ['type' => 'text', 'role' => 'paragraph', 'runs' => []])];

    expect(Agent::validate(lwpbDoc(['type' => 'portable', 'content' => $big]))[0]['message'])->toContain('content_too_many_items');
});

it('keeps portable beside the model in the JSON schema, never inside it', function (): void {
    $s = Agent::jsonSchema();
    $types = array_map(fn (array $b) => $b['properties']['type']['const'], $s['$defs']['block']['oneOf']);

    expect($types)->not->toContain('portable')
        ->and($s['$defs'])->toHaveKey('portable')
        ->and($s['properties']['blocks']['items'])->toBe(['$ref' => '#/$defs/blockOrPortable'])
        ->and($s['$defs']['portable']['properties']['type'])->toBe(['const' => 'portable']);
});

it('converts explicitly with fromPortable, to edit before placing', function (): void {
    $r = Agent::fromPortable(lwpbContent());

    expect($r['ok'])->toBeTrue()
        ->and(array_column($r['blocks'], 'type'))->toBe(['paragraph', 'table'])
        ->and(Agent::validate(['blocks' => $r['blocks']]))->toBe([]);
});
