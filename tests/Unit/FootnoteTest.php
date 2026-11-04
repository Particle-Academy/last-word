<?php

declare(strict_types=1);

use LastWord\Agent;
use LastWord\Tests\Support\Package;
use LastWord\Writer\DocxWriter;

/**
 * Footnotes (MOIC, 2026-11-01): writer agents write documents WITH footnotes,
 * beside the headers, footers and page numbers they already write.
 *
 * A footnote is a RUN: `{"text": "", "footnote": {"blocks": [...]}}`, placed
 * where the reference mark goes. Word numbers the marks; the model carries no
 * number, so an agent never has to keep a counter in step with the text. The
 * writer emits word/footnotes.xml with the separator and continuation
 * separator Word requires, a FootnoteReference-styled `w:footnoteReference`
 * run, the relationship, the content type and settings `w:footnotePr`; the
 * reader gives the same run back. Without a footnote nothing changes, byte for
 * byte.
 */
function fnNote(string $text): array
{
    return ['text' => '', 'footnote' => ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => $text]]]]]];
}

function fnDoc(): array
{
    return [
        'title' => 'Footnotes',
        'blocks' => [
            ['type' => 'paragraph', 'runs' => [['text' => 'Revenue grew 18%'], fnNote('Audited figures, FY2026.'), ['text' => ' on the prior year.']]],
        ],
    ];
}

/** @return array<string, string> */
function fnParts(array $doc): array
{
    return Package::parts(Agent::toBytes($doc));
}

// ─── Schema + validation ─────────────────────────────────────────────────

it('publishes a footnote on the run, holding blocks', function () {
    $schema = Agent::jsonSchema();
    $run = $schema['$defs']['run'];

    expect($run['properties']['footnote'])->toBe(['$ref' => '#/$defs/footnote'])
        ->and($schema['$defs']['footnote']['required'])->toBe(['blocks'])
        ->and($schema['$defs']['footnote']['properties']['blocks']['type'])->toBe('array');
});

it('accepts a document with footnotes, including rich ones and ones in tables and lists', function () {
    $doc = fnDoc();
    $doc['blocks'][] = ['type' => 'list', 'items' => [['runs' => [['text' => 'Item'], fnNote('On a list item.')]]]];
    $doc['blocks'][] = ['type' => 'table', 'rows' => [['cells' => [['blocks' => [['type' => 'paragraph', 'runs' => [['text' => '12.5'], fnNote('In a cell.')]]]]]]]];
    $doc['blocks'][] = ['type' => 'paragraph', 'runs' => [['text' => 'Rich'], ['text' => '', 'footnote' => ['blocks' => [
        ['type' => 'paragraph', 'runs' => [['text' => 'Sources:', 'bold' => true]]],
        ['type' => 'list', 'items' => [['runs' => [['text' => 'Ledger']]], ['runs' => [['text' => 'Board pack']]]]],
    ]]]]];

    expect(Agent::validate($doc))->toBe([]);
});

it('refuses a footnote that is malformed, misplaced or nested', function (array $doc, string $path) {
    $paths = array_column(Agent::validate($doc), 'path');

    expect($paths)->toContain($path);
})->with([
    'no blocks' => [['blocks' => [['type' => 'paragraph', 'runs' => [['text' => '', 'footnote' => []]]]]], 'blocks[0].runs[0].footnote.blocks'],
    'not an object' => [['blocks' => [['type' => 'paragraph', 'runs' => [['text' => '', 'footnote' => 'Source: X']]]]], 'blocks[0].runs[0].footnote'],
    'text on the mark' => [['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'x'] + fnNote('n')]]]], 'blocks[0].runs[0].text'],
    'a footnote in a footnote' => [['blocks' => [['type' => 'paragraph', 'runs' => [['text' => '', 'footnote' => ['blocks' => [['type' => 'paragraph', 'runs' => [fnNote('deeper')]]]]]]]]], 'blocks[0].runs[0].footnote.blocks[0].runs[0].footnote'],
    'a page break in a footnote' =>[['blocks' => [['type' => 'paragraph', 'runs' => [['text' => '', 'footnote' => ['blocks' => [['type' => 'pageBreak']]]]]]]], 'blocks[0].runs[0].footnote.blocks[0].type'],
    'a footnote in a footer' => [['page' => ['footer' => ['blocks' => [['type' => 'paragraph', 'runs' => [fnNote('n')]]]]], 'blocks' => []], 'page.footer.blocks[0].runs[0].footnote'],
]);

it('repairs the near misses an agent makes, and says so', function () {
    $doc = ['blocks' => [['type' => 'paragraph', 'runs' => [
        ['footnote' => ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'No text key.']]]]]],
        ['text' => 'Typed on the mark', 'footnote' => ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'Kept.']]]]]],
    ]]]];

    $out = Agent::validateAndRepair($doc);

    expect($out['ok'])->toBeTrue()
        ->and($out['schema']['blocks'][0]['runs'])->toBe([
            ['text' => '', 'footnote' => ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'No text key.']]]]]],
            ['text' => 'Typed on the mark'],
            ['text' => '', 'footnote' => ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'Kept.']]]]]],
        ])
        ->and($out['errors'])->not->toBe([]);
});

it('repairs away a footnote where Word cannot hold one', function () {
    $doc = [
        'page' => ['header' => ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'Head'], fnNote('n')]]]]],
        'blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'Body'], ['text' => '', 'footnote' => ['blocks' => [
            ['type' => 'paragraph', 'runs' => [['text' => 'Outer'], fnNote('inner')]],
        ]]]]]],
    ];

    $out = Agent::validateAndRepair($doc);

    expect($out['ok'])->toBeTrue()
        ->and($out['schema']['page']['header']['blocks'][0]['runs'])->toBe([['text' => 'Head']])
        ->and($out['schema']['blocks'][0]['runs'][1]['footnote']['blocks'][0]['runs'])->toBe([['text' => 'Outer']]);
});

// ─── Writer ──────────────────────────────────────────────────────────────

it('writes footnotes.xml with the separators Word requires, wired in everywhere Word looks', function () {
    $parts = fnParts(fnDoc());

    expect($parts)->toHaveKey('word/footnotes.xml');
    $fn = $parts['word/footnotes.xml'];

    expect($fn)->toContain('<w:footnote w:type="separator" w:id="-1">')
        ->and($fn)->toContain('<w:separator/>')
        ->and($fn)->toContain('<w:footnote w:type="continuationSeparator" w:id="0">')
        ->and($fn)->toContain('<w:continuationSeparator/>')
        ->and($fn)->toContain('<w:footnote w:id="1">')
        ->and($fn)->toContain('<w:pStyle w:val="FootnoteText"/>')
        ->and($fn)->toContain('<w:r><w:rPr><w:rStyle w:val="FootnoteReference"/></w:rPr><w:footnoteRef/></w:r>')
        ->and($fn)->toContain('Audited figures, FY2026.')
        ->and($parts['word/document.xml'])->toContain('<w:r><w:rPr><w:rStyle w:val="FootnoteReference"/></w:rPr><w:footnoteReference w:id="1"/></w:r>')
        ->and($parts['word/_rels/document.xml.rels'])->toContain('Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footnotes" Target="footnotes.xml"')
        ->and($parts['[Content_Types].xml'])->toContain('<Override PartName="/word/footnotes.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footnotes+xml"/>')
        ->and($parts['word/settings.xml'])->toContain('<w:footnotePr><w:footnote w:id="-1"/><w:footnote w:id="0"/></w:footnotePr>')
        ->and($parts['word/styles.xml'])->toContain('w:styleId="FootnoteText"')
        ->and($parts['word/styles.xml'])->toContain('w:styleId="FootnoteReference"')
        ->and($parts['word/styles.xml'])->toContain('<w:vertAlign w:val="superscript"/>');

    Package::assertConsistent($parts);
});

it('numbers footnotes in document order, wherever they sit', function () {
    $doc = fnDoc();
    $doc['blocks'][] = ['type' => 'table', 'rows' => [['cells' => [['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'c'], fnNote('Second.')]]]]]]]];
    $doc['blocks'][] = ['type' => 'heading', 'level' => 2, 'runs' => [['text' => 'H'], fnNote('Third.')]];

    $parts = fnParts($doc);
    preg_match_all('/<w:footnoteReference w:id="(\d+)"\/>/', $parts['word/document.xml'], $refs);
    preg_match_all('/<w:footnote w:id="(\d+)">.*?<\/w:footnote>/s', $parts['word/footnotes.xml'], $notes);

    expect($refs[1])->toBe(['1', '2', '3'])
        ->and($notes[1])->toBe(['1', '2', '3'])
        ->and($notes[0][1])->toContain('Second.')
        ->and($notes[0][2])->toContain('Third.');
});

it('gives a footnote its own relationships for its links and images', function () {
    $doc = ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'See'], ['text' => '', 'footnote' => ['blocks' => [
        ['type' => 'paragraph', 'runs' => [['text' => 'the filing', 'link' => 'https://example.com/10-k']]],
        ['type' => 'image', 'src' => lwRedPngDataUrl(), 'alt' => 'seal'],
    ]]], ['text' => ' and '], ['text' => 'this link', 'link' => 'https://example.org/']]]]];

    $parts = fnParts($doc);

    expect($parts)->toHaveKey('word/_rels/footnotes.xml.rels')
        ->and($parts['word/_rels/footnotes.xml.rels'])->toContain('Target="https://example.com/10-k"')
        ->and($parts['word/_rels/footnotes.xml.rels'])->toContain('Target="media/image1.png"')
        ->and($parts['word/_rels/document.xml.rels'])->not->toContain('example.com/10-k')
        ->and($parts['word/_rels/document.xml.rels'])->toContain('Target="https://example.org/"');

    Package::assertConsistent($parts);
});

it('puts the mark first even when the footnote opens with a table', function () {
    $doc = ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'x'], ['text' => '', 'footnote' => ['blocks' => [
        ['type' => 'table', 'rows' => [['cells' => [['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'cell']]]]]]]]],
    ]]]]]]];

    $fn = fnParts($doc)['word/footnotes.xml'];
    preg_match('/<w:footnote w:id="1">(.*?)<\/w:footnote>/s', $fn, $m);

    // The mark paragraph comes before the table; a footnote must also END in a paragraph.
    expect($m[1])->toStartWith('<w:p><w:pPr><w:pStyle w:val="FootnoteText"/></w:pPr><w:r><w:rPr><w:rStyle w:val="FootnoteReference"/></w:rPr><w:footnoteRef/></w:r>')
        ->and($m[1])->toEndWith('</w:p>');
});

it('changes nothing about a document without footnotes', function () {
    $parts = fnParts(['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'Plain']]]]]);

    expect($parts)->not->toHaveKey('word/footnotes.xml')
        ->and($parts)->not->toHaveKey('word/settings.xml')
        ->and($parts['word/styles.xml'])->not->toContain('Footnote')
        ->and($parts['[Content_Types].xml'])->not->toContain('footnotes')
        ->and($parts['word/_rels/document.xml.rels'])->not->toContain('footnotes');
});

it('writes nothing for a footnote in a header, which Word cannot hold', function () {
    // Validation refuses this document, so the writer is driven directly: what
    // it does with unvalidated input is the claim.
    $doc = ['page' => ['header' => ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'Head'], fnNote('n')]]]]], 'blocks' => []];

    $parts = Package::parts((new DocxWriter())->toBytes($doc));

    expect($parts)->not->toHaveKey('word/footnotes.xml')
        ->and($parts['word/header1.xml'])->not->toContain('footnote');
});

it('keeps a footnote with the page colour in one settings part', function () {
    $doc = fnDoc() + ['page' => ['background' => '#FDFAF3']];

    $settings = fnParts($doc)['word/settings.xml'];

    // CT_Settings is a sequence: displayBackgroundShape before footnotePr.
    expect($settings)->toContain('<w:displayBackgroundShape/><w:footnotePr>');
});

// ─── Reader ──────────────────────────────────────────────────────────────

it('reads its own footnotes back exactly, wherever they sit', function () {
    $doc = [
        'title' => 'Footnotes',
        'blocks' => [
            ['type' => 'heading', 'level' => 1, 'runs' => [['text' => 'Results'], fnNote('Unaudited.')]],
            ['type' => 'paragraph', 'runs' => [['text' => 'Revenue grew '], ['text' => '18%', 'bold' => true], fnNote('Against FY2025.'), ['text' => ' on the prior year.']]],
            ['type' => 'list', 'items' => [['runs' => [['text' => 'EMEA'], fnNote('Includes Turkey.')]]]],
            ['type' => 'table', 'rows' => [['cells' => [
                ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'Q3'], fnNote('Thirteen weeks.')]]]],
                ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => '1,250']]]]],
            ]]]],
            ['type' => 'quote', 'blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'Quoted'], fnNote('Interview, 3 October.')]]]],
            ['type' => 'paragraph', 'runs' => [['text' => 'Two in a row'], fnNote('First.'), fnNote('Second.')]],
            ['type' => 'paragraph', 'runs' => [['text' => 'Rich'], ['text' => '', 'footnote' => ['blocks' => [
                ['type' => 'paragraph', 'runs' => [['text' => 'Sources: ', 'bold' => true], ['text' => 'the ledger', 'link' => 'https://example.com/ledger']]],
                ['type' => 'list', 'ordered' => true, 'items' => [['runs' => [['text' => 'Ledger']]], ['runs' => [['text' => 'Board pack']]]]],
                ['type' => 'paragraph', 'runs' => [['text' => ' leading space kept']]],
            ]]]]],
        ],
    ];

    expect(Agent::read(Agent::toBytes($doc)))->toBe($doc);
});

it('keeps two identical footnotes in a row as two', function () {
    // Two different footnotes never merge -- their `footnote` values differ --
    // so only identical ones test that a reader never merges footnote runs.
    $doc = ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'Twice'], fnNote('Ibid.'), fnNote('Ibid.')]]]];

    expect(Agent::read(Agent::toBytes($doc)))->toBe($doc);
});

it('reads a footnote that is the whole paragraph', function () {
    $doc = ['blocks' => [['type' => 'paragraph', 'runs' => [fnNote('Only a mark.')]]]];

    expect(Agent::read(Agent::toBytes($doc)))->toBe($doc);
});

it('reads Word\'s footnotes: any ids, the mark and its space, the separators skipped', function () {
    $bytes = Agent::toBytes(['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'Body'], fnNote('placeholder'), ['text' => ' more'], fnNote('placeholder 2')]]]]);

    // Word 2003-era numbering: separators at 0 and 1, notes from 2, and a
    // reference written out of order with its definition.
    $word = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<w:footnotes xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
        .'<w:footnote w:type="separator" w:id="0"><w:p><w:r><w:separator/></w:r></w:p></w:footnote>'
        .'<w:footnote w:type="continuationSeparator" w:id="1"><w:p><w:r><w:continuationSeparator/></w:r></w:p></w:footnote>'
        .'<w:footnote w:id="7"><w:p><w:pPr><w:pStyle w:val="FootnoteText"/></w:pPr><w:r><w:rPr><w:rStyle w:val="FootnoteReference"/></w:rPr><w:footnoteRef/></w:r><w:r><w:t xml:space="preserve"> Seventh, </w:t></w:r><w:r><w:rPr><w:i/></w:rPr><w:t>italic</w:t></w:r></w:p></w:footnote>'
        .'<w:footnote w:id="2"><w:p><w:pPr><w:pStyle w:val="FootnoteText"/></w:pPr><w:r><w:rPr><w:rStyle w:val="FootnoteReference"/></w:rPr><w:footnoteRef/></w:r><w:r><w:t xml:space="preserve"> Second.</w:t></w:r></w:p></w:footnote>'
        .'</w:footnotes>';
    $bytes = fnReplacePart($bytes, 'word/footnotes.xml', $word);
    // strtr, not str_replace: one pass, so 1 -> 2 is not then rewritten 2 -> 7.
    $bytes = fnReplacePart($bytes, 'word/document.xml', strtr(fnPart($bytes, 'word/document.xml'), [
        '<w:footnoteReference w:id="1"/>' => '<w:footnoteReference w:id="2"/>',
        '<w:footnoteReference w:id="2"/>' => '<w:footnoteReference w:id="7"/>',
    ]));

    expect(Agent::read($bytes)['blocks'])->toBe([['type' => 'paragraph', 'runs' => [
        ['text' => 'Body'],
        fnNote('Second.'),
        ['text' => ' more'],
        ['text' => '', 'footnote' => ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'Seventh, '], ['text' => 'italic', 'italic' => true]]]]]],
    ]]]);
});

it('reads a reference whose footnote is missing as nothing, and never invents one', function () {
    $bytes = Agent::toBytes(fnDoc());
    $bytes = fnReplacePart($bytes, 'word/document.xml', str_replace('w:id="1"', 'w:id="99"', fnPart($bytes, 'word/document.xml')));

    expect(Agent::read($bytes)['blocks'][0]['runs'])->toBe([['text' => 'Revenue grew 18% on the prior year.']]);
});

it('reads a run that holds text and a reference as two runs', function () {
    $bytes = Agent::toBytes(fnDoc());
    // Another producer's spelling: text and the reference in ONE w:r.
    $xml = str_replace(
        '<w:r><w:t xml:space="preserve">Revenue grew 18%</w:t></w:r><w:r><w:rPr><w:rStyle w:val="FootnoteReference"/></w:rPr><w:footnoteReference w:id="1"/></w:r>',
        '<w:r><w:t xml:space="preserve">Revenue grew 18%</w:t><w:footnoteReference w:id="1"/></w:r>',
        fnPart($bytes, 'word/document.xml'),
    );
    expect($xml)->toContain('18%</w:t><w:footnoteReference');

    expect(Agent::read(fnReplacePart($bytes, 'word/document.xml', $xml))['blocks'])->toBe(fnDoc()['blocks']);
});

function fnPart(string $bytes, string $name): string
{
    return Package::parts($bytes)[$name];
}

function fnReplacePart(string $bytes, string $name, string $xml): string
{
    $path = lwTemp('lw-fn', '.docx');
    file_put_contents($path, $bytes);
    $zip = new ZipArchive();
    $zip->open($path);
    $zip->addFromString($name, $xml);
    $zip->close();
    $out = (string) file_get_contents($path);
    @unlink($path);

    return $out;
}
