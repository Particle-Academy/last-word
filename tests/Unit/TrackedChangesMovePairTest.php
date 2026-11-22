<?php

declare(strict_types=1);

use LastWord\Agent;

/*
 * A move is ONE passage in two places: what is moved away (moveFrom) and what
 * arrives (moveTo) under the same name are the same text. A pair whose texts
 * differ is not a move; the model refuses it, and the reader reads it as a
 * deletion and an insertion, keeping both texts.
 */

function lwmBy(string $id, string $name): array
{
    return ['id' => $id, 'author' => 'Ann', 'name' => $name];
}

it('refuses a move whose source and destination texts differ', function () {
    $doc = ['blocks' => [
        ['type' => 'paragraph', 'runs' => [['text' => 'Pay ten pounds.', 'revision' => ['moveFrom' => lwmBy('r1', 'm')]]]],
        ['type' => 'paragraph', 'runs' => [['text' => 'Pay a million pounds.', 'revision' => ['moveTo' => lwmBy('r2', 'm')]]]],
    ]];
    $codes = array_column(Agent::validate($doc), 'code');

    expect($codes)->toBe(['revision_move_mismatch']);
});

it('accepts a move whose text is split into different runs on each side', function () {
    $doc = ['blocks' => [
        ['type' => 'paragraph', 'runs' => [
            ['text' => 'Pay ', 'revision' => ['moveFrom' => lwmBy('r1', 'm')]],
            ['text' => 'ten', 'bold' => true, 'revision' => ['moveFrom' => lwmBy('r1', 'm')]],
        ]],
        ['type' => 'paragraph', 'runs' => [['text' => 'Pay ten', 'revision' => ['moveTo' => lwmBy('r2', 'm')]]]],
    ]];

    expect(Agent::validate($doc))->toBe([]);
});

it('reads a mismatched pair as a deletion and an insertion, both texts kept', function () {
    $w = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"';
    $body = '<w:p><w:moveFromRangeStart w:id="1" w:name="m" w:author="Ann"/><w:moveFrom w:id="2" w:author="Ann"><w:r><w:t>ten</w:t></w:r></w:moveFrom><w:moveFromRangeEnd w:id="1"/></w:p>'
        . '<w:p><w:moveToRangeStart w:id="3" w:name="m" w:author="Ann"/><w:moveTo w:id="4" w:author="Ann"><w:r><w:t>a million</w:t></w:r></w:moveTo><w:moveToRangeEnd w:id="3"/></w:p>';
    $tmp = lwTemp('lw-move-pair', '.docx');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE | ZipArchive::CREATE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
    $zip->addFromString('word/document.xml', '<?xml version="1.0"?><w:document ' . $w . '><w:body>' . $body . '</w:body></w:document>');
    $zip->close();

    $read = Agent::read((string) file_get_contents($tmp), ['preserveTrackedChanges' => true]);

    expect(array_keys($read['blocks'][0]['runs'][0]['revision'] ?? []))->toBe(['delete'])
        ->and($read['blocks'][0]['runs'][0]['text'])->toBe('ten')
        ->and(array_keys($read['blocks'][1]['runs'][0]['revision'] ?? []))->toBe(['insert'])
        ->and($read['blocks'][1]['runs'][0]['text'])->toBe('a million')
        ->and(Agent::validate($read))->toBe([]);
});
