<?php

declare(strict_types=1);

use LastWord\Agent;

/**
 * Running headers and footers, with live page numbers.
 *
 * Every multi-page document agents built for review was marked down for having
 * no page numbers or footer, and the schema had no way to ask for one. A band is
 * one paragraph with a left, centre and right slot; `{page}` and `{pages}` become
 * PAGE / NUMPAGES fields, so "Page 2 of 3" stays true when the document is edited.
 * Without `page.header` / `page.footer` nothing changes, byte for byte.
 */
function hfDoc(array $page = []): array
{
    return [
        'title' => 'Bands',
        'page' => $page,
        'blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'Body']]]],
    ];
}

/** @return array<string, string> */
function hfParts(array $doc): array
{
    $path = tempnam(sys_get_temp_dir(), 'hf').'.docx';
    file_put_contents($path, Agent::toBytes($doc));
    $zip = new ZipArchive();
    $zip->open($path);
    $parts = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $parts[(string) $zip->getNameIndex($i)] = (string) $zip->getFromIndex($i);
    }
    $zip->close();
    @unlink($path);

    return $parts;
}

it('writes a footer with live page fields and wires it into the section', function () {
    $parts = hfParts(hfDoc(['footer' => ['left' => 'Q3 Board Memo | Confidential', 'right' => 'Page {page} of {pages}']]));

    expect($parts)->toHaveKey('word/footer1.xml')
        ->and($parts['word/footer1.xml'])->toContain('Q3 Board Memo | Confidential')
        ->and($parts['word/footer1.xml'])->toContain('<w:instrText xml:space="preserve"> PAGE </w:instrText>')
        ->and($parts['word/footer1.xml'])->toContain('<w:instrText xml:space="preserve"> NUMPAGES </w:instrText>')
        ->and($parts['word/document.xml'])->toContain('<w:footerReference w:type="default" r:id="rIdFooter1"/>')
        ->and($parts['word/_rels/document.xml.rels'])->toContain('Target="footer1.xml"')
        ->and($parts['[Content_Types].xml'])->toContain('PartName="/word/footer1.xml"');
});

it('writes a header the same way', function () {
    $parts = hfParts(hfDoc(['header' => ['right' => 'Board of Directors']]));

    expect($parts)->toHaveKey('word/header1.xml')
        ->and($parts['word/document.xml'])->toContain('<w:headerReference w:type="default" r:id="rIdHeader1"/>');
});

it('adds nothing when no band is asked for', function () {
    $parts = hfParts(hfDoc(['margins' => ['top' => 54]]));

    expect($parts)->not->toHaveKey('word/footer1.xml')
        ->and($parts)->not->toHaveKey('word/header1.xml')
        ->and($parts['word/document.xml'])->not->toContain('Reference');
});

it('reads its own bands back as it wrote them', function () {
    $page = [
        'header' => ['right' => 'Board of Directors'],
        'footer' => ['left' => 'Q3 Board Memo', 'center' => 'Confidential', 'right' => 'Page {page} of {pages}'],
    ];

    expect(Agent::read(Agent::toBytes(hfDoc($page)))['page'])->toBe($page);
});

it('publishes the bands in the schema', function () {
    $schema = Agent::jsonSchema();
    $page = $schema['properties']['page']['properties'];

    expect($page)->toHaveKeys(['header', 'footer'])
        ->and($page['footer']['$ref'])->toBe('#/$defs/band')
        ->and(json_encode($schema['$defs']['band']))->toContain('{pages}');
});
