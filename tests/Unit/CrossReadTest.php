<?php

declare(strict_types=1);

use LastWord\Agent;

/*
 * Cross-language parity (last-word-js#1): the frozen fixture
 * `node-canonical.docx` was written by the Node mirror
 * (@particle-academy/last-word) from `node-canonical.json` — this suite
 * asserts the PHP reader restores it, metadata slots included (title from
 * docProps/core.xml, code language from the `lastword:code:{lang}` sdt tag),
 * plus the legacy pre-0.2.0 slots for back-compat.
 */

function lwNodeCanonicalDocx(): string
{
    return file_get_contents(__DIR__ . '/../fixtures/node-canonical.docx');
}

function lwNodeCanonicalJson(): array
{
    return json_decode(
        file_get_contents(__DIR__ . '/../fixtures/node-canonical.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
}

it('restores the title of a Node-written docx from docProps/core.xml', function () {
    $doc = Agent::read(lwNodeCanonicalDocx());

    expect($doc['title'])->toBe(lwNodeCanonicalJson()['title'])
        ->and($doc['title'])->toBe('LastWord Canonical');
});

it('restores every code block language of a Node-written docx from the sdt tag', function () {
    $doc = Agent::read(lwNodeCanonicalDocx());

    $languages = array_values(array_map(
        static fn (array $b) => $b['language'] ?? null,
        array_filter($doc['blocks'], static fn (array $b) => $b['type'] === 'code'),
    ));
    $expected = array_values(array_map(
        static fn (array $b) => $b['language'] ?? null,
        array_filter(lwNodeCanonicalJson()['blocks'], static fn (array $b) => $b['type'] === 'code'),
    ));

    expect($languages)->toBe($expected)
        ->and($languages)->toBe(['typescript']);
});

it('restores the exact block-type sequence of a Node-written docx', function () {
    $doc = Agent::read(lwNodeCanonicalDocx());

    expect(array_column($doc['blocks'], 'type'))
        ->toBe(array_column(lwNodeCanonicalJson()['blocks'], 'type'));
});

it('recovers the full Node canonical doc semantically (deep-equal, normalized)', function () {
    $doc = Agent::read(lwNodeCanonicalDocx());

    expect(lwNormalizeDoc($doc))->toEqual(lwNormalizeDoc(lwNodeCanonicalJson()));
});

it('still reads the pre-0.2.0 legacy slots (Title paragraph + code bookmark)', function () {
    // Exactly the shape the PHP 0.1.0 writer emitted: a Title-styled body
    // paragraph for the title, and CodeBlock-styled paragraphs with an
    // invisible LastWordCode_{lang} bookmark on the first line — no
    // docProps/core.xml, no sdt.
    $documentXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
        . '<w:body>'
        . '<w:p><w:pPr><w:pStyle w:val="Title"/></w:pPr><w:r><w:t xml:space="preserve">Legacy Title</w:t></w:r></w:p>'
        . '<w:p><w:pPr><w:pStyle w:val="CodeBlock"/></w:pPr>'
        . '<w:bookmarkStart w:id="1" w:name="LastWordCode_php"/><w:bookmarkEnd w:id="1"/>'
        . '<w:r><w:t xml:space="preserve">echo "hi";</w:t></w:r></w:p>'
        . '<w:p><w:pPr><w:pStyle w:val="CodeBlock"/></w:pPr>'
        . '<w:r><w:t xml:space="preserve">exit(0);</w:t></w:r></w:p>'
        . '</w:body></w:document>';

    $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lw-legacy-' . bin2hex(random_bytes(6)) . '.docx';
    $zip = new ZipArchive();
    expect($zip->open($tmp, ZipArchive::OVERWRITE | ZipArchive::CREATE))->toBeTrue();
    $zip->addFromString('word/document.xml', $documentXml);
    $zip->close();
    $bytes = file_get_contents($tmp);
    @unlink($tmp);

    $doc = Agent::read($bytes);

    expect($doc['title'])->toBe('Legacy Title')
        ->and($doc['blocks'])->toEqual([
            ['type' => 'code', 'language' => 'php', 'text' => "echo \"hi\";\nexit(0);"],
        ]);
});
