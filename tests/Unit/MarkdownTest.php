<?php

declare(strict_types=1);

use LastWord\Agent;

/*
 * Spec vectors 2 + 3: fromMarkdown(toMarkdown(doc)) is a semantic fixpoint
 * on the canonical doc, and toMarkdown(fromMarkdown(md)) reproduces a
 * canonical GFM document exactly.
 */

it('reaches a semantic fixpoint on fromMarkdown(toMarkdown(canonical))', function () {
    $doc = lwCanonical();

    $d1 = Agent::fromMarkdown(Agent::toMarkdown($doc));
    $d2 = Agent::fromMarkdown(Agent::toMarkdown($d1));

    expect(lwNormalizeDoc($d2))->toEqual(lwNormalizeDoc($d1));
});

it('reproduces a canonical GFM document byte-for-byte through the bridge', function () {
    $md = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../fixtures/canonical.md'));

    expect(Agent::toMarkdown(Agent::fromMarkdown($md)))->toBe($md);
});

it('parses the canonical GFM document into the expected model', function () {
    $md = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../fixtures/canonical.md'));
    $doc = Agent::fromMarkdown($md);
    $blocks = $doc['blocks'];

    expect(array_column($blocks, 'type'))->toBe([
        'heading', 'paragraph', 'heading', 'list', 'list', 'heading',
        'table', 'code', 'quote', 'image', 'pageBreak', 'hr', 'paragraph',
    ]);

    // Styled inline runs
    $runs = $blocks[1]['runs'];
    $byText = [];
    foreach ($runs as $run) {
        $byText[$run['text']] = $run;
    }
    expect($byText['bold']['bold'])->toBeTrue()
        ->and($byText['italic']['italic'])->toBeTrue()
        ->and($byText['struck']['strike'])->toBeTrue()
        ->and($byText['code']['code'])->toBeTrue()
        ->and($byText['link']['link'])->toBe('https://example.com');

    // Nested list 3 deep
    $list = $blocks[3];
    expect($list)->not->toHaveKey('ordered')
        ->and($list['items'][1]['children'][0]['children'][0]['runs'][0]['text'])->toBe('Two point one point one');

    // Ordered list is its own block
    expect($blocks[4]['ordered'])->toBeTrue()
        ->and($blocks[4]['items'])->toHaveCount(2);

    // Table with header
    expect($blocks[6]['rows'][0]['header'])->toBeTrue()
        ->and($blocks[6]['rows'][1]['cells'][0]['blocks'][0]['runs'][0]['text'])->toBe('alpha');

    // Fenced code with language
    expect($blocks[7]['language'])->toBe('ts')
        ->and($blocks[7]['text'])->toBe('const x: number = 1;');

    // Blockquote wraps a paragraph
    expect($blocks[8]['blocks'][0]['type'])->toBe('paragraph');

    // Image with alt + data URL src
    expect($blocks[9]['alt'])->toBe('Alt text')
        ->and($blocks[9]['src'])->toBe(lwRedPngDataUrl());
});

it('emits the doc title as a leading heading in markdown', function () {
    $md = Agent::toMarkdown(['title' => 'My Title', 'blocks' => [
        ['type' => 'paragraph', 'runs' => [['text' => 'Body.']]],
    ]]);

    expect($md)->toBe("# My Title\n\nBody.\n");
});

it('escapes markdown punctuation in plain text symmetrically', function () {
    $doc = ['blocks' => [
        ['type' => 'paragraph', 'runs' => [['text' => 'stars *not bold*, ticks `x`, brackets [y]']]],
    ]];

    $md = Agent::toMarkdown($doc);
    $back = Agent::fromMarkdown($md);

    expect($back['blocks'][0]['runs'])->toEqual([['text' => 'stars *not bold*, ticks `x`, brackets [y]']]);
});
