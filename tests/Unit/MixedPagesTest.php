<?php

declare(strict_types=1);

use LastWord\Agent;
use LastWord\Schema\Schema;
use LastWord\Tests\Support\GeneratedLetterhead;
use LastWord\Tests\Support\Package;

/*
 * Mixed page orientation, stage 2 (.ai/plans/mixed-page-orientation.md): a
 * pageBreak with `page` starts a new Word section. Case for case with the Node
 * and Python packages, whose parts are held byte-identical to these.
 */

function mpPara(string $text = 'Body'): array
{
    return ['type' => 'paragraph', 'runs' => [['text' => $text]]];
}

/** Every w:sectPr of a written document, in document order, as XML. */
function mpSections(string $bytes): array
{
    return mpSectionsOf(Package::parts($bytes)['word/document.xml']);
}

function mpSectionsOf(string $xml): array
{
    preg_match_all('#<w:sectPr>.*?</w:sectPr>|<w:sectPr/>#s', $xml, $m);

    return $m[0];
}

/** One sectPr's header or footer references: type => target, through the document's relationships. */
function mpRefs(string $sectPr, array $parts, string $kind): array
{
    preg_match_all('#Id="([^"]+)"[^>]*Target="([^"]+)"#', $parts['word/_rels/document.xml.rels'], $rels, PREG_SET_ORDER);
    $targets = array_column($rels, 2, 1);
    preg_match_all('#<w:' . $kind . 'Reference w:type="(\w+)" r:id="([^"]+)"/>#', $sectPr, $m, PREG_SET_ORDER);
    $out = [];
    foreach ($m as $r) {
        $out[$r[1]] = $targets[$r[2]] ?? null;
    }

    return $out;
}

/** A document.xml body wrapped as a minimal package, with optional parts beside it. */
function mpDocx(string $body, array $extra = []): string
{
    $tmp = lwTemp('lw-mp-', '.docx');
    $zip = new ZipArchive();
    expect($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE))->toBeTrue();
    $overrides = '';
    foreach (array_keys($extra) as $name) {
        if (preg_match('#^word/(header|footer)\d+\.xml$#', $name, $m) === 1) {
            $overrides .= '<Override PartName="/' . $name . '" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.' . $m[1] . '+xml"/>';
        }
    }
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>' . $overrides . '</Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
    $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><w:body>' . $body . '</w:body></w:document>');
    foreach ($extra as $name => $xml) {
        $zip->addFromString($name, $xml);
    }
    $zip->close();

    return (string) file_get_contents($tmp);
}

const MP_P = '<w:p><w:r><w:t>Text</w:t></w:r></w:p>';
const MP_PORTRAIT = '<w:pgSz w:w="12240" w:h="15840"/>';
const MP_LANDSCAPE = '<w:pgSz w:w="15840" w:h="12240" w:orient="landscape"/>';

/** A paragraph ending a section with these sectPr contents. */
function mpEnds(string $inner): string
{
    return '<w:p><w:pPr><w:sectPr>' . $inner . '</w:sectPr></w:pPr></w:p>';
}

function mpMixed(): array
{
    return [
        'title' => 'Mixed',
        'page' => [
            'margins' => ['top' => 54],
            'header' => ['left' => 'Report', 'right' => 'Page {page} of {pages}'],
            'footer' => ['center' => 'Section {section}, page of {sectionpages}'],
        ],
        'blocks' => [
            mpPara('Portrait'),
            ['type' => 'pageBreak', 'page' => ['orientation' => 'landscape', 'margins' => ['left' => 28.8, 'right' => 28.8]]],
            ['type' => 'table', 'rows' => [['cells' => [['blocks' => [mpPara('A')]], ['blocks' => [mpPara('B')]]]]]],
            ['type' => 'pageBreak', 'page' => ['orientation' => 'portrait']],
            mpPara('Portrait again'),
        ],
    ];
}

/* ---------------- the vocabulary ---------------- */

it('accepts a pageBreak with page, including an empty one', function (array $page): void {
    expect(Agent::validate(['blocks' => [mpPara(), ['type' => 'pageBreak', 'page' => $page], mpPara()]]))->toBe([]);
})->with([
    'landscape' => [['orientation' => 'landscape', 'margins' => ['left' => 28.8, 'right' => 28.8]]],
    'empty' => [[]],
    'bands' => [['header' => ['left' => 'x'], 'firstFooter' => ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'y']]]]]]],
    'border' => [['border' => ['width' => 1]]],
]);

it('declares page on the pageBreak schema, without background', function (): void {
    $blocks = [];
    foreach (Schema::jsonSchema()['$defs']['block']['oneOf'] as $b) {
        $blocks[$b['properties']['type']['const']] = $b;
    }
    $page = $blocks['pageBreak']['properties']['page'];

    expect($page['type'])->toBe('object')
        ->and($page['additionalProperties'])->toBeFalse()
        ->and($page['properties'])->toHaveKeys(['size', 'orientation', 'margins', 'header', 'footer', 'firstHeader', 'firstFooter', 'evenHeader', 'evenFooter', 'border'])
        ->and($page['properties'])->not->toHaveKey('background');
});

it('refuses page.background on a break, with fine-print\'s words', function (): void {
    expect(Agent::validate(['blocks' => [['type' => 'pageBreak', 'page' => ['background' => '#FFFFFF']]]]))
        ->toBe([['path' => 'blocks[0].page.background', 'message' => 'a document has one page colour: set background on the top-level page, not on a break']]);
});

it('refuses page on a nested break, with fine-print\'s words', function (): void {
    $break = ['type' => 'pageBreak', 'page' => ['orientation' => 'landscape']];
    $message = 'page settings go on a top-level page break; a break nested in a quote or a table cell cannot change the page';

    expect(Agent::validate(['blocks' => [
        ['type' => 'quote', 'blocks' => [$break]],
        ['type' => 'table', 'rows' => [['cells' => [['blocks' => [$break]]]]]],
    ]]))->toBe([
        ['path' => 'blocks[0].blocks[0].page', 'message' => $message],
        ['path' => 'blocks[1].rows[0].cells[0].blocks[0].page', 'message' => $message],
    ]);
});

it('refuses a page that is not an object', function (mixed $page): void {
    expect(Agent::validate(['blocks' => [['type' => 'pageBreak', 'page' => $page]]]))
        ->toBe([['path' => 'blocks[0].page', 'message' => 'page on a break must be an object of page settings, {} for a new stretch with nothing changed']]);
})->with(['a string' => ['landscape'], 'a list' => [[1, 2]], 'a number' => [3]]);

it('judges a break\'s settings with the page rules', function (array $page, string $path, string $message): void {
    expect(Agent::validate(['blocks' => [['type' => 'pageBreak', 'page' => $page]]]))->toBe([['path' => $path, 'message' => $message]]);
})->with([
    'size' => [['size' => 'a3'], 'blocks[0].page.size', 'page size must be one of: letter, legal, a4'],
    'orientation' => [['orientation' => 'sideways'], 'blocks[0].page.orientation', 'page orientation must be one of: portrait, landscape'],
    'border colour' => [['border' => ['color' => 'red']], 'blocks[0].page.border.color', 'page border color must be a #RRGGBB hex string'],
]);

it('judges a break\'s border against the page it turns to', function (): void {
    // Fits a letter page and not an A4 one, whose size it inherits through the merge.
    $doc = ['blocks' => [
        ['type' => 'pageBreak', 'page' => ['size' => 'a4']],
        ['type' => 'pageBreak', 'page' => ['border' => ['inset' => 300]]],
    ]];

    expect(array_column(Agent::validate($doc), 'path'))->toBe(['blocks[1].page.border']);
});

it('keeps refusing near-miss keys and sections by name', function (): void {
    expect(Agent::validate(['sections' => [], 'blocks' => [['type' => 'pageBreak', 'orientation' => 'landscape']]]))->toBe([
        ['path' => 'sections', 'message' => 'there is no sections key; use pageBreak.page'],
        ['path' => 'blocks[0].orientation', 'message' => 'a pageBreak has no orientation key; use pageBreak.page'],
    ]);
});

it('repair moves a near-miss key into page, with a note, and merges it', function (): void {
    $r = Agent::validateAndRepair(['blocks' => [['type' => 'pageBreak', 'orientation' => 'landscape', 'page' => ['size' => 'a4']]]]);

    expect($r['ok'])->toBeTrue()
        ->and($r['schema']['blocks'][0])->toBe(['type' => 'pageBreak', 'page' => ['size' => 'a4', 'orientation' => 'landscape']])
        ->and($r['errors'])->toBe([['path' => 'blocks[0].orientation', 'message' => 'moved orientation into the break\'s "page": a break\'s page settings go there']]);
});

it('repair says so when page already holds the key', function (): void {
    $r = Agent::validateAndRepair(['blocks' => [['type' => 'pageBreak', 'orientation' => 'landscape', 'page' => ['orientation' => 'portrait']]]]);

    expect($r['schema']['blocks'][0])->toBe(['type' => 'pageBreak', 'page' => ['orientation' => 'portrait']])
        ->and($r['errors'][0]['message'])->toBe('dropped orientation from the break: its page already says what it is');
});

it('repair removes a background from a break, with a note', function (): void {
    foreach ([['type' => 'pageBreak', 'background' => '#FFFFFF'], ['type' => 'pageBreak', 'page' => ['background' => '#FFFFFF']]] as $break) {
        $r = Agent::validateAndRepair(['blocks' => [$break]]);

        expect($r['ok'])->toBeTrue()
            ->and($r['schema']['blocks'][0]['page'] ?? [])->not->toHaveKey('background');
    }
});

it('an unrelated repair cannot lose page', function (): void {
    $page = ['orientation' => 'landscape', 'margins' => ['left' => 28.8]];
    $r = Agent::validateAndRepair(['blocks' => ['needs repair', ['type' => 'pageBreak', 'page' => $page], mpPara()]]);

    expect($r['ok'])->toBeTrue()->and($r['schema']['blocks'][1])->toBe(['type' => 'pageBreak', 'page' => $page]);
});

it('repairs the blocks of a band on a break', function (): void {
    $r = Agent::validateAndRepair(['blocks' => [['type' => 'pageBreak', 'page' => ['header' => ['blocks' => ['loose text']]]]]]);

    expect($r['ok'])->toBeTrue()
        ->and($r['schema']['blocks'][0]['page']['header']['blocks'][0])->toBe(mpPara('loose text'));
});

it('still strips a decorated plain break', function (): void {
    $r = Agent::validateAndRepair(['blocks' => ['needs repair', ['type' => 'pageBreak', 'note' => 'mine']]]);

    expect($r['schema']['blocks'][1])->toBe(['type' => 'pageBreak']);
});

/* ---------------- stretches: the rulings ---------------- */

it('merges margins side by side and replaces every other key whole', function (): void {
    $stretches = Schema::stretches(['page' => [
        'margins' => ['top' => 10, 'left' => 20],
        'header' => ['left' => 'a', 'right' => 'b'],
        'border' => ['width' => 1, 'inset' => 3],
    ], 'blocks' => [
        ['type' => 'pageBreak', 'page' => ['margins' => ['left' => 30], 'header' => ['right' => 'c'], 'border' => ['width' => 2]]],
    ]]);

    expect($stretches[1]['doc']['page']['margins'])->toBe(['top' => 10, 'left' => 30])
        ->and($stretches[1]['doc']['page']['header'])->toBe(['right' => 'c'])
        ->and($stretches[1]['doc']['page']['border'])->toBe(['width' => 2]);
});

it('never inherits firstHeader or firstFooter', function (): void {
    $stretches = Schema::stretches(['page' => ['firstHeader' => ['left' => 'cover'], 'firstFooter' => ['left' => 'cf'], 'header' => ['left' => 'run'], 'evenHeader' => ['left' => 'even']], 'blocks' => [
        ['type' => 'pageBreak', 'page' => []],
        ['type' => 'pageBreak', 'page' => ['firstHeader' => ['left' => 'second cover']]],
        ['type' => 'pageBreak', 'page' => []],
    ]]);

    expect(array_keys($stretches[1]['doc']['page']))->toBe(['header', 'evenHeader'])
        ->and($stretches[2]['doc']['page']['firstHeader'])->toBe(['left' => 'second cover'])
        ->and($stretches[3]['doc']['page'])->not->toHaveKey('firstHeader');
});

it('keeps portrait gone until a break brings it back', function (): void {
    $stretches = Schema::stretches(['blocks' => [
        ['type' => 'pageBreak', 'page' => ['orientation' => 'landscape']],
        ['type' => 'pageBreak', 'page' => []],
    ]]);

    expect($stretches[2]['doc']['page']['orientation'])->toBe('landscape');
});

it('a plain break or a nested one starts no stretch', function (): void {
    $stretches = Schema::stretches(['blocks' => [mpPara(), ['type' => 'pageBreak'], ['type' => 'quote', 'blocks' => [['type' => 'pageBreak', 'page' => []]]]]]);

    expect($stretches)->toHaveCount(1)->and(array_values($stretches[0]['blocks']))->toHaveCount(3);
});

/* ---------------- the writer ---------------- */

it('puts each section\'s sectPr in a dedicated empty paragraph, in order', function (): void {
    $bytes = Agent::toBytes(mpMixed());
    $xml = Package::parts($bytes)['word/document.xml'];
    $sections = mpSections($bytes);

    expect($sections)->toHaveCount(3)
        ->and($sections[0])->toContain('<w:pgSz w:w="12240" w:h="15840"/><w:pgMar w:top="1080" w:right="1440" w:bottom="1440" w:left="1440"')
        ->and($sections[1])->toContain('<w:pgSz w:w="15840" w:h="12240" w:orient="landscape"/><w:pgMar w:top="1080" w:right="576" w:bottom="1440" w:left="576"')
        ->and($sections[2])->toContain('<w:pgSz w:w="12240" w:h="15840"/><w:pgMar w:top="1080" w:right="576" w:bottom="1440" w:left="576"')
        // The first two end a paragraph of their own, with no text and no page break.
        ->and($xml)->toContain('Portrait</w:t></w:r></w:p><w:p><w:pPr><w:spacing w:before="0" w:after="0" w:line="20" w:lineRule="exact"/><w:rPr><w:sz w:val="2"/></w:rPr>' . $sections[0] . '</w:pPr></w:p>')
        ->and($xml)->toContain('</w:tbl><w:p><w:pPr><w:spacing w:before="0" w:after="0" w:line="20" w:lineRule="exact"/><w:rPr><w:sz w:val="2"/></w:rPr>' . $sections[1] . '</w:pPr></w:p>')
        ->and($xml)->toContain('Portrait again</w:t></w:r></w:p>' . $sections[2] . '</w:body>')
        // A section break is the break: a w:br as well would add a blank page.
        ->and($xml)->not->toContain('w:type="page"')
        // No w:type: the default, nextPage.
        ->and(implode('', $sections))->not->toContain('<w:type');
});

it('gives each width its own one-line band part, tab stops at that width', function (): void {
    $parts = Package::parts(Agent::toBytes(mpMixed()));
    $sections = mpSections(Agent::toBytes(mpMixed()));

    expect(mpRefs($sections[0], $parts, 'header'))->toBe(['default' => 'header1.xml'])
        ->and(mpRefs($sections[1], $parts, 'header'))->toBe(['default' => 'header5.xml'])
        ->and(mpRefs($sections[2], $parts, 'header'))->toBe(['default' => 'header6.xml'])
        ->and(mpRefs($sections[0], $parts, 'footer'))->toBe(['default' => 'footer1.xml'])
        ->and(mpRefs($sections[1], $parts, 'footer'))->toBe(['default' => 'footer5.xml'])
        ->and($parts['word/header1.xml'])->toContain('<w:tab w:val="center" w:pos="4680"/><w:tab w:val="right" w:pos="9360"/>')
        ->and($parts['word/header5.xml'])->toContain('<w:tab w:val="center" w:pos="7344"/><w:tab w:val="right" w:pos="14688"/>')
        ->and($parts['word/header6.xml'])->toContain('<w:tab w:val="center" w:pos="5544"/><w:tab w:val="right" w:pos="11088"/>')
        ->and(array_keys($parts))->toContain('word/header1.xml', 'word/header5.xml', 'word/header6.xml', 'word/footer1.xml', 'word/footer5.xml', 'word/footer6.xml')
        ->and(array_keys($parts))->not->toContain('word/header2.xml', 'word/header3.xml');
    Package::assertConsistent($parts);
});

it('a stretch at the same width reuses the part rather than repeating it', function (): void {
    $doc = ['page' => ['header' => ['left' => 'x']], 'blocks' => [
        mpPara(),
        ['type' => 'pageBreak', 'page' => []],
        mpPara(),
        ['type' => 'pageBreak', 'page' => ['orientation' => 'landscape']],
        mpPara(),
        ['type' => 'pageBreak', 'page' => ['orientation' => 'portrait']],
        mpPara(),
    ]];
    $bytes = Agent::toBytes($doc);
    $parts = Package::parts($bytes);
    $sections = mpSections($bytes);

    expect(array_map(fn ($s) => mpRefs($s, $parts, 'header')['default'], $sections))
        ->toBe(['header1.xml', 'header1.xml', 'header5.xml', 'header1.xml']);
});

it('the first stretch keeps header1 to header3 by slot', function (): void {
    $doc = ['page' => [
        'header' => ['left' => 'default'], 'firstHeader' => ['left' => 'first'], 'evenHeader' => ['left' => 'even'],
    ], 'blocks' => [mpPara(), ['type' => 'pageBreak', 'page' => ['orientation' => 'landscape']], mpPara()]];
    $bytes = Agent::toBytes($doc);
    $parts = Package::parts($bytes);
    $sections = mpSections($bytes);

    expect(mpRefs($sections[0], $parts, 'header'))->toBe(['default' => 'header1.xml', 'first' => 'header2.xml', 'even' => 'header3.xml'])
        ->and($parts['word/header1.xml'])->toContain('>default<')
        ->and($parts['word/header2.xml'])->toContain('>first<')
        ->and($parts['word/header3.xml'])->toContain('>even<')
        ->and(mpRefs($sections[1], $parts, 'header'))->toBe(['default' => 'header5.xml', 'even' => 'header6.xml']);
});

it('the first stretch\'s parts are the bytes a one-stretch document writes', function (): void {
    $page = ['header' => ['left' => 'L', 'right' => 'Page {page}'], 'firstFooter' => ['center' => 'F'], 'evenHeader' => ['left' => 'E']];
    $alone = Package::parts(Agent::toBytes(['page' => $page, 'blocks' => [mpPara()]]));
    $mixed = Package::parts(Agent::toBytes(['page' => $page, 'blocks' => [mpPara(), ['type' => 'pageBreak', 'page' => ['orientation' => 'landscape']], mpPara()]]));

    foreach (['word/header1.xml', 'word/header3.xml', 'word/footer2.xml'] as $name) {
        expect($mixed[$name])->toBe($alone[$name]);
    }
});

it('turns titlePg on only for the section that names a first band', function (): void {
    $doc = ['page' => ['header' => ['left' => 'run'], 'firstHeader' => ['left' => 'cover']], 'blocks' => [
        mpPara(),
        ['type' => 'pageBreak', 'page' => ['orientation' => 'landscape']],
        mpPara(),
        ['type' => 'pageBreak', 'page' => ['firstFooter' => ['left' => 'second first']]],
        mpPara(),
    ]];
    $bytes = Agent::toBytes($doc);
    $parts = Package::parts($bytes);
    $sections = mpSections($bytes);

    expect($sections[0])->toContain('<w:titlePg/>')
        ->and($sections[1])->not->toContain('<w:titlePg/>')
        ->and($sections[1])->not->toContain('w:type="first"')
        ->and($sections[2])->toContain('<w:titlePg/>')
        // The other kind shows its default on that first page, as at the top level.
        ->and(mpRefs($sections[2], $parts, 'header')['first'])->toBe(mpRefs($sections[2], $parts, 'header')['default'])
        ->and(mpRefs($sections[2], $parts, 'footer'))->toHaveKey('first');
});

it('turns even and odd on for the whole document, and no stretch borrows another\'s even band', function (): void {
    $doc = ['page' => ['header' => ['left' => 'run']], 'blocks' => [
        mpPara(),
        ['type' => 'pageBreak', 'page' => ['evenHeader' => ['left' => 'even']]],
        mpPara(),
    ]];
    $bytes = Agent::toBytes($doc);
    $parts = Package::parts($bytes);
    $sections = mpSections($bytes);

    expect($parts['word/settings.xml'])->toContain('<w:evenAndOddHeaders/>')
        ->and(mpRefs($sections[0], $parts, 'header'))->toBe(['default' => 'header1.xml', 'even' => 'header1.xml'])
        ->and(mpRefs($sections[1], $parts, 'header')['even'])->toBe('header5.xml');
});

it('blanks a band a break empties, because Word would inherit the last one', function (array $page): void {
    $doc = ['page' => ['header' => ['left' => 'run']], 'blocks' => [mpPara(), ['type' => 'pageBreak', 'page' => $page], mpPara()]];
    $bytes = Agent::toBytes($doc);
    $parts = Package::parts($bytes);
    $target = mpRefs(mpSections($bytes)[1], $parts, 'header')['default'];

    expect($target)->not->toBe('header1.xml')
        ->and($parts['word/' . $target])->toContain('<w:p/>')
        ->and($parts['word/' . $target])->not->toContain('run');
})->with([
    'blocks: []' => [['header' => ['blocks' => []]]],
    'no text' => [['header' => ['left' => '']]],
]);

it('caches a section number in its own part, so a viewer that does not compute SECTION is right', function (): void {
    $doc = ['page' => ['footer' => ['left' => '{section}']], 'blocks' => [mpPara(), ['type' => 'pageBreak', 'page' => []], mpPara()]];
    $bytes = Agent::toBytes($doc);
    $parts = Package::parts($bytes);
    $sections = mpSections($bytes);
    $second = mpRefs($sections[1], $parts, 'footer')['default'];

    expect(mpRefs($sections[0], $parts, 'footer')['default'])->toBe('footer1.xml')
        ->and($second)->toBe('footer5.xml')
        ->and($parts['word/footer1.xml'])->toContain('<w:t>1</w:t>')
        ->and($parts['word/' . $second])->toContain('<w:t>2</w:t>')
        // {sectionpages} is not {section}.
        ->and(Package::parts(Agent::toBytes(['page' => ['footer' => ['left' => '{sectionpages}']], 'blocks' => [['type' => 'pageBreak', 'page' => []]]])))->not->toHaveKey('word/footer5.xml')
        ->and(Agent::read($bytes)['page']['footer'])->toBe(['left' => '{section}']);
});

it('renders a blocks band at the width of its stretch, with its own relationships', function (): void {
    $table = ['type' => 'table', 'rows' => [['cells' => [['blocks' => [mpPara('logo')]]]]]];
    $image = ['type' => 'image', 'src' => lwRedPngDataUrl()];
    $doc = ['page' => ['header' => ['blocks' => [$table, $image]]], 'blocks' => [mpPara(), ['type' => 'pageBreak', 'page' => ['orientation' => 'landscape']], mpPara()]];
    $parts = Package::parts(Agent::toBytes($doc));

    expect($parts['word/header1.xml'])->toContain('<w:gridCol w:w="9360"/>')
        ->and($parts['word/header5.xml'])->toContain('<w:gridCol w:w="12960"/>')
        ->and($parts)->toHaveKeys(['word/_rels/header1.xml.rels', 'word/_rels/header5.xml.rels']);
    Package::assertConsistent($parts);
});

it('lays tables out against the width of their own stretch', function (): void {
    $bytes = Agent::toBytes(mpMixed());

    expect(Package::parts($bytes)['word/document.xml'])->toContain('<w:gridCol w:w="7344"/><w:gridCol w:w="7344"/>');
});

it('a chart spans the column of its own stretch and reads back without a size', function (): void {
    $chart = ['type' => 'chart', 'option' => ['xAxis' => ['type' => 'category', 'data' => ['a', 'b']], 'yAxis' => ['type' => 'value'], 'series' => [['type' => 'bar', 'data' => [1, 2]]]]];
    $doc = ['blocks' => [$chart, ['type' => 'pageBreak', 'page' => ['orientation' => 'landscape']], $chart]];
    $read = Agent::read(Agent::toBytes($doc));

    expect(array_column($read['blocks'], 'type'))->toBe(['chart', 'pageBreak', 'chart'])
        ->and($read['blocks'][0])->not->toHaveKey('widthPx')
        ->and($read['blocks'][2])->not->toHaveKey('widthPx');
});

it('writes a border per stretch and drops it on style none', function (): void {
    $bytes = Agent::toBytes(['page' => ['border' => ['width' => 1]], 'blocks' => [
        ['type' => 'pageBreak', 'page' => ['border' => ['width' => 2]]],
        ['type' => 'pageBreak', 'page' => ['border' => ['style' => 'none']]],
    ]]);
    $sections = mpSections($bytes);

    expect($sections[0])->toContain('w:sz="8"')
        ->and($sections[1])->toContain('w:sz="16"')
        ->and($sections[2])->not->toContain('pgBorders');
});

it('an empty last stretch still has a paragraph, and an empty first one its section', function (): void {
    $xml = Package::parts(Agent::toBytes(['blocks' => [['type' => 'pageBreak', 'page' => []], mpPara(), ['type' => 'pageBreak', 'page' => ['orientation' => 'landscape']]]]))['word/document.xml'];

    expect($xml)->toStartWith((string) strstr($xml, '<?xml'))
        ->and($xml)->toMatch('#<w:body><w:p><w:pPr><w:spacing[^>]*/><w:rPr><w:sz w:val="2"/></w:rPr><w:sectPr>#')
        ->and($xml)->toMatch('#</w:pPr></w:p><w:p/><w:sectPr>(?:(?!</w:sectPr>).)*</w:sectPr></w:body>#s');
});

it('carries a template\'s band through every stretch until a break names its own', function (): void {
    $template = GeneratedLetterhead::bytes();
    $bytes = Agent::toBytes(['blocks' => [
        mpPara(),
        ['type' => 'pageBreak', 'page' => ['orientation' => 'landscape']],
        mpPara(),
        ['type' => 'pageBreak', 'page' => ['header' => ['left' => 'mine']]],
        mpPara(),
    ]], ['template' => $template, 'templateParts' => ['headers', 'footers']]);
    $parts = Package::parts($bytes);
    $sections = mpSections($bytes);

    expect(mpRefs($sections[0], $parts, 'header')['default'])->toBe('header1.xml')
        ->and(mpRefs($sections[1], $parts, 'header')['default'])->toBe('header1.xml')
        ->and(mpRefs($sections[2], $parts, 'header')['default'])->toBe('header5.xml')
        ->and($parts['word/header5.xml'])->toContain('mine');
    Package::assertConsistent($parts);
});

it('a template\'s first-page-only part stays on page one', function (): void {
    $bytes = Agent::toBytes(['blocks' => [mpPara(), ['type' => 'pageBreak', 'page' => ['orientation' => 'landscape']], mpPara()]], [
        'template' => GeneratedLetterhead::bytes(), 'templateParts' => ['headers'], 'templateFirstPageOnly' => true,
    ]);
    $sections = mpSections($bytes);

    expect($sections[0])->toContain('<w:titlePg/>')->toContain('w:type="first"')
        ->and($sections[1])->not->toContain('<w:titlePg/>')
        ->and($sections[1])->not->toContain('w:type="first"');
});

it('writes the same bytes twice', function (): void {
    expect(Package::parts(Agent::toBytes(mpMixed())))->toBe(Package::parts(Agent::toBytes(mpMixed())));
});

/* ---------------- the reader ---------------- */

it('top-level page is the first section, and each next-page section a break holding only what differs', function (): void {
    $bytes = mpDocx(MP_P . mpEnds(MP_PORTRAIT . '<w:pgMar w:top="1440" w:right="720" w:bottom="1440" w:left="1440" w:header="720" w:footer="720" w:gutter="0"/>')
        . MP_P . mpEnds('<w:type w:val="nextPage"/>' . MP_LANDSCAPE . '<w:pgMar w:top="1440" w:right="720" w:bottom="1440" w:left="1440" w:header="720" w:footer="720" w:gutter="0"/>')
        . MP_P . '<w:sectPr>' . MP_PORTRAIT . '<w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440" w:header="720" w:footer="720" w:gutter="0"/></w:sectPr>');
    $doc = Agent::read($bytes);

    expect($doc['page'])->toBe(['margins' => ['right' => 36]])
        ->and($doc['blocks'][1])->toBe(['type' => 'pageBreak', 'page' => ['orientation' => 'landscape']])
        ->and($doc['blocks'][3])->toBe(['type' => 'pageBreak', 'page' => ['orientation' => 'portrait', 'margins' => ['right' => 72]]])
        ->and($doc)->not->toHaveKey('unrepresented');
});

it('reads w:type as how THIS section starts, so the last section\'s type belongs to the last break', function (): void {
    $doc = Agent::read(mpDocx(MP_P . mpEnds('<w:type w:val="continuous"/>' . MP_PORTRAIT) . MP_P . '<w:sectPr>' . MP_LANDSCAPE . '</w:sectPr>'));

    // A continuous FIRST section means nothing: the break is the second section's, nextPage by default.
    expect($doc['blocks'][1])->toBe(['type' => 'pageBreak', 'page' => ['orientation' => 'landscape']])
        ->and($doc)->not->toHaveKey('unrepresented');
});

it('maps each section start as the plan\'s table says', function (string $type, string $next, array $break, array $unrepresented): void {
    $doc = Agent::read(mpDocx(MP_P . mpEnds(MP_PORTRAIT) . MP_P . '<w:sectPr><w:type w:val="' . $type . '"/>' . $next . '</w:sectPr>'));
    $found = array_values(array_filter($doc['blocks'], static fn (array $b): bool => $b['type'] === 'pageBreak'));

    expect($found)->toBe($break === [] ? [] : [$break])
        ->and($doc['unrepresented'] ?? [])->toBe($unrepresented);
})->with([
    'nextPage' => ['nextPage', MP_LANDSCAPE, ['type' => 'pageBreak', 'page' => ['orientation' => 'landscape']], []],
    'nextPage, nothing different' => ['nextPage', MP_PORTRAIT, ['type' => 'pageBreak', 'page' => []], []],
    'oddPage' => ['oddPage', MP_PORTRAIT, ['type' => 'pageBreak', 'page' => []], [['where' => '/blocks/1', 'kind' => 'layout-not-preserved', 'count' => 1]]],
    'evenPage' => ['evenPage', MP_LANDSCAPE, ['type' => 'pageBreak', 'page' => ['orientation' => 'landscape']], [['where' => '/blocks/1', 'kind' => 'layout-not-preserved', 'count' => 1]]],
    'continuous, same page' => ['continuous', MP_PORTRAIT, [], [['where' => '/blocks/1', 'kind' => 'layout-not-preserved', 'count' => 1]]],
    'continuous, other orientation' => ['continuous', MP_LANDSCAPE, ['type' => 'pageBreak', 'page' => ['orientation' => 'landscape']], []],
    'continuous, other size' => ['continuous', '<w:pgSz w:w="11906" w:h="16838"/>', ['type' => 'pageBreak', 'page' => ['size' => 'a4']], []],
    'continuous, other margins' => ['continuous', MP_PORTRAIT . '<w:pgMar w:top="720" w:right="1440" w:bottom="1440" w:left="1440" w:header="720" w:footer="720" w:gutter="0"/>', [], [['where' => '/blocks/1', 'kind' => 'layout-not-preserved', 'count' => 1]]],
    'continuous, columns' => ['continuous', MP_PORTRAIT . '<w:cols w:num="2"/>', [], [['where' => '/blocks/1', 'kind' => 'layout-not-preserved', 'count' => 1]]],
]);

it('a continuous stretch leaves the page where it was for the next section', function (): void {
    $margins = '<w:pgMar w:top="720" w:right="1440" w:bottom="1440" w:left="1440" w:header="720" w:footer="720" w:gutter="0"/>';
    $doc = Agent::read(mpDocx(MP_P . mpEnds(MP_PORTRAIT) . MP_P . mpEnds('<w:type w:val="continuous"/>' . MP_PORTRAIT . $margins) . MP_P . '<w:sectPr>' . MP_PORTRAIT . '</w:sectPr>'));

    expect(array_column($doc['blocks'], 'type'))->toBe(['paragraph', 'paragraph', 'pageBreak', 'paragraph'])
        ->and($doc['blocks'][2])->toBe(['type' => 'pageBreak', 'page' => []]);
});

it('reports columns, a page-number restart and an unknown paper size, and drops them', function (string $inner, ?array $page, string $where): void {
    $doc = Agent::read(mpDocx(MP_P . '<w:sectPr>' . $inner . '</w:sectPr>'));

    expect($doc['page'] ?? null)->toBe($page)
        ->and($doc['unrepresented'])->toBe([['where' => $where, 'kind' => 'layout-not-preserved', 'count' => 1]]);
})->with([
    'columns' => [MP_PORTRAIT . '<w:cols w:num="3"/>', null, '/page'],
    'restart' => [MP_PORTRAIT . '<w:pgNumType w:start="5"/>', null, '/page'],
    'paper' => ['<w:pgSz w:w="10000" w:h="14000"/>', null, '/page'],
]);

it('says nothing of one column, a page number that does not restart, or a first page numbered 1', function (string $inner): void {
    expect(Agent::read(mpDocx(MP_P . '<w:sectPr>' . MP_PORTRAIT . $inner . '</w:sectPr>')))->not->toHaveKey('unrepresented');
})->with(['one column' => ['<w:cols w:num="1"/>'], 'a format only' => ['<w:pgNumType w:fmt="decimal"/>'], 'starts at 1' => ['<w:pgNumType w:start="1"/>']]);

it('reports a later section\'s dropped settings at its break, and a restart there even from 1', function (): void {
    $doc = Agent::read(mpDocx(MP_P . mpEnds(MP_PORTRAIT) . MP_P . '<w:sectPr>' . MP_PORTRAIT . '<w:pgNumType w:start="1"/></w:sectPr>'));

    expect($doc['blocks'][1])->toBe(['type' => 'pageBreak', 'page' => []])
        ->and($doc['unrepresented'])->toBe([['where' => '/blocks/1', 'kind' => 'layout-not-preserved', 'count' => 1]]);
});

it('a section break inside a code or quote block is reported, not read', function (): void {
    $sdt = '<w:sdt><w:sdtPr><w:tag w:val="lastword:quote"/></w:sdtPr><w:sdtContent><w:p><w:pPr><w:sectPr>' . MP_LANDSCAPE . '</w:sectPr></w:pPr><w:r><w:t>Q</w:t></w:r></w:p></w:sdtContent></w:sdt>';
    $doc = Agent::read(mpDocx($sdt . MP_P . '<w:sectPr>' . MP_PORTRAIT . '</w:sectPr>'));

    expect(array_column($doc['blocks'], 'type'))->toBe(['quote', 'paragraph'])
        ->and($doc)->not->toHaveKey('page')
        ->and($doc['unrepresented'])->toBe([['where' => '/page', 'kind' => 'layout-not-preserved', 'count' => 1]]);
});

it('a section break on a paragraph with text, or a list item, falls after it', function (): void {
    $doc = Agent::read(mpDocx('<w:p><w:pPr><w:sectPr>' . MP_PORTRAIT . '</w:sectPr></w:pPr><w:r><w:t>Text</w:t></w:r></w:p>' . MP_P . '<w:sectPr>' . MP_LANDSCAPE . '</w:sectPr>'));

    expect(array_column($doc['blocks'], 'type'))->toBe(['paragraph', 'pageBreak', 'paragraph'])
        ->and($doc['blocks'][1]['page'])->toBe(['orientation' => 'landscape']);
});

it('finishes a table before the section break, in a sdt wrapper too', function (): void {
    $doc = Agent::read(mpDocx('<w:sdt><w:sdtContent>' . MP_P . mpEnds(MP_PORTRAIT) . '</w:sdtContent></w:sdt>' . MP_P . '<w:sectPr>' . MP_LANDSCAPE . '</w:sectPr>'));

    expect(array_column($doc['blocks'], 'type'))->toBe(['paragraph', 'pageBreak', 'paragraph']);
});

it('Word\'s band inheritance reads as no change, and a new part as the change', function (): void {
    $hdr = fn (string $t): string => '<?xml version="1.0" encoding="UTF-8"?><w:hdr xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:p><w:r><w:t>' . $t . '</w:t></w:r></w:p></w:hdr>';
    $rels = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rH1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/header" Target="header1.xml"/>'
        . '<Relationship Id="rH2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/header" Target="header2.xml"/>'
        . '</Relationships>';
    $doc = Agent::read(mpDocx(
        MP_P . mpEnds('<w:headerReference w:type="default" r:id="rH1"/>' . MP_PORTRAIT)
            . MP_P . mpEnds(MP_LANDSCAPE)
            . MP_P . '<w:sectPr><w:headerReference w:type="default" r:id="rH2"/>' . MP_PORTRAIT . '</w:sectPr>',
        ['word/header1.xml' => $hdr('One'), 'word/header2.xml' => $hdr('Two'), 'word/_rels/document.xml.rels' => $rels],
    ));

    expect($doc['page']['header'])->toBe(['left' => 'One'])
        ->and($doc['blocks'][1])->toBe(['type' => 'pageBreak', 'page' => ['orientation' => 'landscape']])
        ->and($doc['blocks'][3])->toBe(['type' => 'pageBreak', 'page' => ['orientation' => 'portrait', 'header' => ['left' => 'Two']]]);
});

it('reads what the writer wrote, to a fixpoint', function (array $doc): void {
    $once = Agent::read(Agent::toBytes($doc));
    $twice = Agent::read(Agent::toBytes($once));

    expect($twice)->toBe($once)
        ->and(Agent::validate($once))->toBe([])
        ->and($once)->not->toHaveKey('unrepresented')
        ->and(array_values(array_filter(array_column($once['blocks'], 'type'), fn ($t) => $t === 'pageBreak')))
        ->toHaveCount(count(array_filter($doc['blocks'], fn ($b) => $b['type'] === 'pageBreak')));
})->with([
    'mixed' => [mpMixed()],
    'empty break' => [['blocks' => [mpPara(), ['type' => 'pageBreak', 'page' => []], mpPara()]]],
    'bands' => [['page' => ['header' => ['left' => 'run'], 'firstHeader' => ['left' => 'cover'], 'evenFooter' => ['right' => '{page}'], 'footer' => ['left' => 'f']], 'blocks' => [
        mpPara(),
        ['type' => 'pageBreak', 'page' => ['orientation' => 'landscape', 'firstFooter' => ['left' => 'second']]],
        mpPara(),
        ['type' => 'pageBreak', 'page' => ['header' => ['blocks' => []], 'evenFooter' => ['blocks' => []]]],
        mpPara(),
    ]]],
    'sizes' => [['page' => ['size' => 'a4'], 'blocks' => [mpPara(), ['type' => 'pageBreak', 'page' => ['size' => 'letter', 'margins' => ['left' => 36, 'header' => 18]]], mpPara(), ['type' => 'pageBreak', 'page' => ['margins' => ['left' => 72]]], mpPara()]]],
    'border' => [['page' => ['border' => ['width' => 1]], 'blocks' => [mpPara(), ['type' => 'pageBreak', 'page' => ['border' => ['width' => 2]]], mpPara(), ['type' => 'pageBreak', 'page' => ['border' => ['style' => 'none']]], mpPara()]]],
]);

it('reads back the model it was given, key for key', function (): void {
    $doc = mpMixed();
    $read = Agent::read(Agent::toBytes($doc));

    expect($read['page'])->toBe($doc['page'])
        ->and($read['blocks'][1])->toBe($doc['blocks'][1])
        ->and($read['blocks'][3])->toBe($doc['blocks'][3]);
});

it('reads the real two-section file as two stretches', function (): void {
    $doc = Agent::read((string) file_get_contents(__DIR__ . '/../fixtures/formats/two-sections.docx'));

    expect($doc)->not->toHaveKey('page')
        ->and($doc)->not->toHaveKey('unrepresented')
        ->and(array_column($doc['blocks'], 'type'))->toBe(['heading', 'paragraph', 'pageBreak', 'heading', 'table'])
        ->and($doc['blocks'][2])->toBe(['type' => 'pageBreak', 'page' => ['orientation' => 'landscape']])
        ->and(json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
        ->toBe(trim((string) file_get_contents(__DIR__ . '/../fixtures/formats/two-sections.read.json')));
});

/* ---------------- what else reads the model ---------------- */

it('describe() counts the stretches, as fine-print\'s does', function (): void {
    expect(Agent::describe(mpMixed()))->toContain('Stretches: 3 (portrait, landscape, portrait)')
        ->and(Agent::describe(['blocks' => [mpPara()]]))->not->toContain('Stretches');
});

it('describe() says what a read dropped, not that sections were flattened', function (): void {
    $doc = Agent::read(mpDocx(MP_P . '<w:sectPr>' . MP_PORTRAIT . '<w:cols w:num="2"/></w:sectPr>'));

    expect(Agent::describe($doc))->toContain('Layout not preserved: 1 section setting dropped (columns, odd or even starts, continuous breaks, page-number restarts, unknown paper)');
});

it('markdown marks the break and reports the page it could not carry', function (): void {
    $r = Agent::toMarkdownWithReport(['blocks' => [mpPara(), ['type' => 'pageBreak', 'page' => ['orientation' => 'landscape']], mpPara()]]);

    expect($r['markdown'])->toContain('<!-- pagebreak -->')
        ->and(json_encode($r['report']))->toContain('layout-not-preserved');
});

it('layout() takes each stretch at its own page', function (): void {
    $layout = Agent::layout(['blocks' => [mpPara(), ['type' => 'pageBreak', 'page' => ['orientation' => 'landscape']], mpPara()]]);

    expect($layout['pages'])->toBe(2)
        ->and($layout['pageStarts'])->toBe([0, 2])
        ->and($layout['page'])->toBe(['size' => 'letter', 'width' => 612.0, 'height' => 792.0])
        ->and($layout['pageList'][0]['width'])->toBe(612.0)
        ->and($layout['pageList'][0]['section'])->toBe(1)
        ->and($layout['pageList'][1]['width'])->toBe(792.0)
        ->and($layout['pageList'][1]['height'])->toBe(612.0)
        ->and($layout['pageList'][1]['section'])->toBe(2)
        ->and($layout['pageList'][1]['capacity'])->toBe(468.0);
});

it('layout() measures text at the width of its stretch', function (): void {
    $long = mpPara(str_repeat('word ', 400));
    $portrait = Agent::layout(['blocks' => [$long]]);
    $landscape = Agent::layout(['blocks' => [['type' => 'pageBreak', 'page' => ['orientation' => 'landscape', 'margins' => ['top' => 72]]], $long]]);

    expect($landscape['pageList'][1]['used'])->toBeLessThan($portrait['pageList'][0]['used']);
});

it('layout() gives a stretch\'s first page its own band and an even page the even one', function (): void {
    $tall = ['blocks' => array_fill(0, 12, mpPara('line'))];
    $doc = ['page' => ['evenHeader' => $tall], 'blocks' => [
        mpPara(),
        ['type' => 'pageBreak', 'page' => ['firstHeader' => $tall]],
        mpPara(),
    ]];
    $layout = Agent::layout($doc);
    $plain = Agent::layout(['blocks' => [mpPara()]])['pageList'][0]['capacity'];

    expect($layout['pageList'][0]['capacity'])->toBe($plain)
        ->and($layout['pageList'][1]['capacity'])->toBeLessThan($plain);
});
