<?php

declare(strict_types=1);

use LastWord\Agent;

/*
 * Tracked changes beside the other run features: a bookmark, an internal link,
 * a field and a review comment on a run that carries a revision, and a table of
 * contents over a heading with one. Each writes and reads back as the same model.
 */

function lwtBy(string $id): array
{
    return ['id' => $id, 'author' => 'Ann'];
}

/** @return array{bytes: string, xml: string} */
function lwtRoundTrip(array $doc): array
{
    expect(Agent::validate($doc))->toBe([]);
    $bytes = Agent::toBytes($doc);
    $read = Agent::read($bytes, ['preserveTrackedChanges' => true]);
    unset($read['trackedChanges']);
    expect($read)->toBe($doc)
        ->and(Agent::toBytes($read))->toBe($bytes);

    $tmp = lwTemp('lw-alongside', '.docx');
    file_put_contents($tmp, $bytes);
    $zip = new ZipArchive();
    $zip->open($tmp);
    $xml = (string) $zip->getFromName('word/document.xml');
    $zip->close();

    return ['bytes' => $bytes, 'xml' => $xml];
}

it('an inserted run keeps its bookmark, and an internal link to it stays internal', function () {
    $doc = ['blocks' => [
        ['type' => 'paragraph', 'runs' => [['text' => 'Results', 'bookmark' => 'results', 'revision' => ['insert' => lwtBy('r1')]]]],
        ['type' => 'paragraph', 'runs' => [['text' => 'see results', 'link' => '#results', 'revision' => ['insert' => lwtBy('r2')]]]],
    ]];
    $xml = lwtRoundTrip($doc)['xml'];

    expect($xml)->toContain('w:name="results"')
        ->and($xml)->toContain('<w:hyperlink w:anchor="results"');
});

it('an inserted field stays a field', function () {
    $doc = ['blocks' => [['type' => 'paragraph', 'runs' => [
        ['text' => 'Page '],
        ['text' => '3', 'field' => '{page}', 'revision' => ['insert' => lwtBy('r1')]],
    ]]]];
    $xml = lwtRoundTrip($doc)['xml'];

    expect($xml)->toMatch('#<w:ins w:id="\d+" w:author="Ann">.*PAGE.*</w:ins>#s');
});

it('refuses a deleted field: Word keeps a deleted field\'s code as w:delInstrText, which this package does not write', function () {
    $doc = ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => '3', 'field' => '{page}', 'revision' => ['delete' => lwtBy('r1')]]]]]];

    expect(array_column(Agent::validate($doc), 'code'))->toBe(['revision_combination_invalid']);
});

it('a comment on part of an insertion does not split the insertion', function () {
    $ins = ['insert' => lwtBy('r1')];
    $doc = [
        'blocks' => [['type' => 'paragraph', 'runs' => [
            ['text' => 'Inserted ', 'revision' => $ins],
            ['text' => 'and commented', 'comments' => ['c1'], 'revision' => $ins],
            ['text' => ' text.', 'revision' => $ins],
        ]]],
        'comments' => [['id' => 'c1', 'author' => 'Bo', 'text' => 'Why?']],
    ];
    $xml = lwtRoundTrip($doc)['xml'];

    expect(substr_count($xml, '<w:ins '))->toBe(1)
        ->and($xml)->toContain('<w:commentRangeStart');
});

it('a table of contents lists a heading as accepting its revisions shows it', function () {
    $doc = ['blocks' => [
        ['type' => 'toc', 'levels' => 1],
        ['type' => 'heading', 'level' => 1, 'runs' => [
            ['text' => 'Old', 'revision' => ['delete' => lwtBy('r1')]],
            ['text' => 'New', 'revision' => ['insert' => lwtBy('r2')]],
        ]],
    ]];
    $bytes = Agent::toBytes($doc);
    $tmp = lwTemp('lw-alongside-toc', '.docx');
    file_put_contents($tmp, $bytes);
    $zip = new ZipArchive();
    $zip->open($tmp);
    $xml = (string) $zip->getFromName('word/document.xml');
    $zip->close();

    expect($xml)->toMatch('#w:anchor="_Toc1"[^>]*><w:r><w:t xml:space="preserve">New</w:t>#')
        ->and($xml)->not->toContain('>OldNew<');
});
