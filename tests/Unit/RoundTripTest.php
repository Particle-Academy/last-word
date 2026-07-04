<?php

declare(strict_types=1);

use LastWord\Agent;

/*
 * Spec vector 1: the canonical doc (one of each block type, nested list 3
 * deep, table with header + styled runs, PNG image data URL) survives
 * toBytes → read as the same semantic model, allowing run-merge
 * normalization.
 */

it('round-trips the canonical document through DOCX bytes', function () {
    $doc = lwCanonical();

    $bytes = Agent::toBytes($doc);
    expect(substr($bytes, 0, 4))->toBe("PK\x03\x04");

    $readBack = Agent::read($bytes);

    expect(lwNormalizeDoc($readBack))->toEqual(lwNormalizeDoc($doc));
});

it('preserves every structural detail through the round-trip', function () {
    $readBack = Agent::read(Agent::toBytes(lwCanonical()));

    expect($readBack['title'])->toBe('Last Word Canonical');

    $types = array_column($readBack['blocks'], 'type');
    expect($types)->toBe([
        'heading', 'paragraph', 'heading', 'list', 'list', 'heading',
        'table', 'code', 'quote', 'image', 'pageBreak', 'hr', 'paragraph',
    ]);

    // 3-deep nested list
    $list = $readBack['blocks'][3];
    expect($list)->not->toHaveKey('ordered')
        ->and($list['items'][1]['children'][0]['children'][0]['runs'][1])->toEqual(['text' => 'deep', 'italic' => true]);

    // ordered list restarts as its own block
    expect($readBack['blocks'][4]['ordered'])->toBeTrue();

    // table header row survives with bold cells
    $table = $readBack['blocks'][6];
    expect($table['rows'][0]['header'])->toBeTrue()
        ->and($table['rows'][0]['cells'][0]['blocks'][0]['runs'][0]['bold'])->toBeTrue()
        ->and($table['rows'][2]['cells'][2]['blocks'][0]['runs'][1])->toEqual(['text' => 'styled', 'bold' => true]);

    // code block keeps its language and exact text
    expect($readBack['blocks'][7]['language'])->toBe('typescript')
        ->and($readBack['blocks'][7]['text'])->toBe("export function lastWord(doc: Doc): Uint8Array {\n  return toBytes(doc);\n}");

    // image round-trips the exact data URL + dimensions + alt
    $image = $readBack['blocks'][9];
    expect($image['src'])->toBe(lwRedPngDataUrl())
        ->and($image['widthPx'])->toBe(96)
        ->and($image['heightPx'])->toBe(96)
        ->and($image['alt'])->toBe('Red square');

    // styled runs on the intro paragraph
    $runs = $readBack['blocks'][1]['runs'];
    $byText = [];
    foreach ($runs as $run) {
        $byText[$run['text']] = $run;
    }
    expect($byText['agentic']['bold'])->toBeTrue()
        ->and($byText['italic']['italic'])->toBeTrue()
        ->and($byText['underlined']['underline'])->toBeTrue()
        ->and($byText['struck']['strike'])->toBeTrue()
        ->and($byText['inline code']['code'])->toBeTrue()
        ->and($byText['link']['link'])->toBe('https://particle.academy')
        ->and($byText['colored']['color'])->toBe('#C0392B')
        ->and($byText['highlighted']['highlight'])->toBe('#FFF3A0');

    // alignment on the closing paragraph
    expect($readBack['blocks'][12]['align'])->toBe('center');
});

it('writes a docx file to disk and reads it back by path', function () {
    $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'last-word-test-' . bin2hex(random_bytes(6)) . DIRECTORY_SEPARATOR . 'canonical.docx';

    try {
        $result = Agent::write(lwCanonical(), $path);

        expect($result['path'])->toBe($path)
            ->and($result['blocks'])->toBe(13)
            ->and($result['bytes'])->toBeGreaterThan(0)
            ->and(is_file($path))->toBeTrue()
            ->and(filesize($path))->toBe($result['bytes']);

        $readBack = Agent::read($path);
        expect(lwNormalizeDoc($readBack))->toEqual(lwNormalizeDoc(lwCanonical()));
    } finally {
        @unlink($path);
        @rmdir(dirname($path));
    }
});

it('exposes fromBytes as an alias of read', function () {
    $bytes = Agent::toBytes(lwCanonical());

    expect(Agent::fromBytes($bytes))->toEqual(Agent::read($bytes));
});
