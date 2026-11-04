<?php

declare(strict_types=1);

use LastWord\Agent;
use LastWord\Exceptions\SchemaException;

/*
 * Mixed page orientation, stage 1 (.ai/plans/mixed-page-orientation.md): a .docx
 * has one page setup until stage 2, so everything asking for more is refused
 * out loud, and everything a reader flattens is reported. Case for case with
 * the Node and Python packages; the words are the same bytes in all three.
 */

const LW_PAGE_REFUSED = 'pageBreak.page is not implemented in last-word yet; remove page to keep one page setup, or write this document as a PDF with fine-print';
const LW_SECTIONS_REFUSED = 'there is no sections key; use pageBreak.page';

function lwMixedPara(string $text = 'Body'): array
{
    return ['type' => 'paragraph', 'runs' => [['text' => $text]]];
}

function lwMixedPage(): array
{
    return ['orientation' => 'landscape', 'margins' => ['left' => 28.8, 'right' => 28.8]];
}

/** @return array<string, string> path => message */
function lwMixedErrors(array $errors): array
{
    return array_column($errors, 'message', 'path');
}

function lwBody(string $xml): string
{
    return lwMinimalSectionDocx('<?xml version="1.0" encoding="UTF-8"?>'
        . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>' . $xml . '</w:body></w:document>');
}

function lwMinimalSectionDocx(string $documentXml): string
{
    $tmp = lwTemp('lw-sections-');
    $zip = new ZipArchive();
    expect($zip->open($tmp, ZipArchive::OVERWRITE))->toBeTrue();
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
        . '</Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
        . '</Relationships>');
    $zip->addFromString('word/document.xml', $documentXml);
    $zip->close();
    $bytes = (string) file_get_contents($tmp);
    @unlink($tmp);

    return $bytes;
}

const LW_BREAK = '<w:p><w:pPr><w:sectPr><w:pgSz w:w="12240" w:h="15840"/></w:sectPr></w:pPr></w:p>';
const LW_LAST = '<w:sectPr><w:pgSz w:w="15840" w:h="12240" w:orient="landscape"/></w:sectPr>';
const LW_TEXT = '<w:p><w:r><w:t>Text</w:t></w:r></w:p>';

/* ---------------- validate ---------------- */

it('refuses pageBreak.page, with a message that says it is not implemented', function (array $page): void {
    $errors = Agent::validate(['blocks' => [lwMixedPara(), ['type' => 'pageBreak', 'page' => $page], lwMixedPara()]]);

    expect($errors)->toBe([['path' => 'blocks[1].page', 'message' => LW_PAGE_REFUSED]]);
})->with([
    'a real page' => [['orientation' => 'landscape', 'margins' => ['left' => 28.8]]],
    'an empty page' => [[]],
]);

it('refuses pageBreak.page nested in a quote or a table cell too', function (): void {
    $break = ['type' => 'pageBreak', 'page' => lwMixedPage()];
    $errors = lwMixedErrors(Agent::validate(['blocks' => [
        ['type' => 'quote', 'blocks' => [$break]],
        ['type' => 'table', 'rows' => [['cells' => [['blocks' => [$break]]]]]],
    ]]));

    expect($errors)->toBe([
        'blocks[0].blocks[0].page' => LW_PAGE_REFUSED,
        'blocks[1].rows[0].cells[0].blocks[0].page' => LW_PAGE_REFUSED,
    ]);
});

it('refuses each page key written straight on the break, naming pageBreak.page', function (string $key): void {
    $errors = Agent::validate(['blocks' => [['type' => 'pageBreak', $key => 'x']]]);

    expect($errors)->toBe([[
        'path' => "blocks[0].{$key}",
        'message' => "a pageBreak has no {$key} key; use pageBreak.page",
    ]]);
})->with(['size', 'orientation', 'margins', 'header', 'footer', 'firstHeader', 'firstFooter', 'evenHeader', 'evenFooter', 'background', 'border']);

it('stays lenient about any other extra key on a break: agents decorate', function (): void {
    expect(Agent::validate(['blocks' => [['type' => 'pageBreak', 'note' => 'mine', 'id' => 'b1']]]))->toBe([]);
});

it('refuses a top-level sections key, naming the right form', function (mixed $sections): void {
    expect(Agent::validate(['sections' => $sections, 'blocks' => []]))
        ->toBe([['path' => 'sections', 'message' => LW_SECTIONS_REFUSED]]);
})->with([
    'a list' => [[['page' => ['orientation' => 'landscape']]]],
    'empty' => [[]],
]);

it('still says sections is wrong when blocks is missing as well', function (): void {
    expect(lwMixedErrors(Agent::validate(['sections' => []])))->toHaveKeys(['sections', 'blocks']);
});

/* ---------------- repair: the trap ---------------- */

it('repair keeps page, so an unrelated repair cannot launder it into ok: true', function (): void {
    // The bare string needs a repair; the break needs nothing but refusing.
    $result = Agent::validateAndRepair(['blocks' => ['needs repair', ['type' => 'pageBreak', 'page' => lwMixedPage()], lwMixedPara()]]);

    expect($result['ok'])->toBeFalse()
        ->and($result['schema']['blocks'][0])->toBe(lwMixedPara('needs repair'))
        ->and($result['schema']['blocks'][1])->toBe(['type' => 'pageBreak', 'page' => lwMixedPage()])
        ->and(lwMixedErrors($result['errors']))->toHaveKey('blocks[1].page', LW_PAGE_REFUSED);
});

it('repair keeps an empty page as well: {} is still a request', function (): void {
    $result = Agent::validateAndRepair(['blocks' => ['needs repair', ['type' => 'pageBreak', 'page' => []]]]);

    expect($result['ok'])->toBeFalse()
        ->and($result['schema']['blocks'][1])->toBe(['type' => 'pageBreak', 'page' => []]);
});

it('repair keeps page nested in a quote and a cell', function (): void {
    $break = ['type' => 'pageBreak', 'page' => lwMixedPage()];
    $result = Agent::validateAndRepair(['blocks' => [
        'needs repair',
        ['type' => 'quote', 'blocks' => [$break]],
        ['type' => 'table', 'rows' => [['cells' => [['blocks' => [$break]]]]]],
    ]]);

    expect($result['ok'])->toBeFalse()
        ->and($result['schema']['blocks'][1]['blocks'][0])->toBe($break)
        ->and($result['schema']['blocks'][2]['rows'][0]['cells'][0]['blocks'][0])->toBe($break);
});

it('repair neither strips nor moves a near-miss key: it stays refused until stage 2', function (): void {
    $result = Agent::validateAndRepair(['blocks' => ['needs repair', ['type' => 'pageBreak', 'orientation' => 'landscape', 'margins' => ['left' => 10]]]]);

    expect($result['ok'])->toBeFalse()
        ->and($result['schema']['blocks'][1])->toBe(['type' => 'pageBreak', 'orientation' => 'landscape', 'margins' => ['left' => 10]])
        ->and(lwMixedErrors($result['errors']))
        ->toHaveKey('blocks[1].orientation', 'a pageBreak has no orientation key; use pageBreak.page')
        ->toHaveKey('blocks[1].margins', 'a pageBreak has no margins key; use pageBreak.page');
});

it('repair keeps a top-level sections key and fails', function (): void {
    $result = Agent::validateAndRepair(['sections' => [['x' => 1]], 'blocks' => ['needs repair']]);

    expect($result['ok'])->toBeFalse()
        ->and($result['schema']['sections'])->toBe([['x' => 1]])
        ->and(lwMixedErrors($result['errors']))->toHaveKey('sections', LW_SECTIONS_REFUSED);
});

it('repair still strips a decorated plain break down to the break, as it always did', function (): void {
    $result = Agent::validateAndRepair(['blocks' => ['needs repair', ['type' => 'pageBreak', 'note' => 'mine']]]);

    expect($result['ok'])->toBeTrue()
        ->and($result['schema']['blocks'][1])->toBe(['type' => 'pageBreak']);
});

/* ---------------- what goes through writable() ---------------- */

it('toBytes, write and layout all refuse it', function (array $doc, string $path, string $message): void {
    $tmp = lwTemp('lw-mixed-');
    foreach ([
        static fn () => Agent::toBytes($doc),
        static fn () => Agent::write($doc, $tmp),
        static fn () => Agent::layout($doc),
    ] as $call) {
        try {
            $call();
            $this->fail('expected a SchemaException');
        } catch (SchemaException $e) {
            expect(lwMixedErrors($e->errors))->toBe([$path => $message]);
        }
    }
    @unlink($tmp);
})->with([
    'page' => [['blocks' => [['type' => 'pageBreak', 'page' => ['orientation' => 'landscape']]]], 'blocks[0].page', LW_PAGE_REFUSED],
    'near miss' => [['blocks' => [['type' => 'pageBreak', 'orientation' => 'landscape']]], 'blocks[0].orientation', 'a pageBreak has no orientation key; use pageBreak.page'],
    'sections' => [['sections' => [], 'blocks' => []], 'sections', LW_SECTIONS_REFUSED],
]);

it('layout() refuses it when a template routes through toBytes too', function (): void {
    $template = Agent::toBytes(['blocks' => [lwMixedPara()]]);

    expect(fn () => Agent::layout(['blocks' => [['type' => 'pageBreak', 'page' => []]]], ['template' => $template]))
        ->toThrow(SchemaException::class);
});

it('writes a plain break exactly as before', function (): void {
    $doc = ['blocks' => [lwMixedPara('one'), ['type' => 'pageBreak'], lwMixedPara('two')]];

    expect(Agent::validate($doc))->toBe([])
        ->and(Agent::read(Agent::toBytes($doc))['blocks'])->toBe($doc['blocks']);
});

/* ---------------- reading ---------------- */

it('reports a real two-section file, and otherwise reads what it read before', function (): void {
    $bytes = (string) file_get_contents(__DIR__ . '/../fixtures/formats/two-sections.docx');
    $doc = Agent::read($bytes);

    expect($doc['unrepresented'])->toBe([['where' => '/page', 'kind' => 'layout-not-preserved', 'count' => 1]])
        ->and($doc['page']['orientation'])->toBe('landscape')
        ->and(array_column($doc['blocks'], 'type'))->toBe(['heading', 'paragraph', 'heading', 'table'])
        ->and(json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
        ->toBe(trim((string) file_get_contents(__DIR__ . '/../fixtures/formats/two-sections.read.json')));
});

it('the two-section fixture really is two sections, and the second really is landscape', function (): void {
    $zip = new ZipArchive();
    $tmp = lwTemp('lw-two-');
    file_put_contents($tmp, (string) file_get_contents(__DIR__ . '/../fixtures/formats/two-sections.docx'));
    expect($zip->open($tmp))->toBeTrue();
    $xml = (string) $zip->getFromName('word/document.xml');
    $zip->close();
    @unlink($tmp);

    expect(substr_count($xml, '<w:sectPr'))->toBe(2)
        ->and($xml)->toMatch('#<w:pPr>(?:(?!</w:pPr>).)*<w:sectPr>(?:(?!</w:sectPr>).)*w:w="12240" w:h="15840"#s')
        ->and($xml)->toMatch('#<w:sectPr>(?:(?!</w:sectPr>).)*w:orient="landscape"(?:(?!</w:sectPr>).)*</w:sectPr></w:body>#s');
});

it('counts the section breaks the read flattened', function (string $body, ?int $count): void {
    $doc = Agent::read(lwBody($body));

    if ($count === null) {
        expect($doc)->not->toHaveKey('unrepresented');
    } else {
        expect($doc['unrepresented'])->toBe([['where' => '/page', 'kind' => 'layout-not-preserved', 'count' => $count]]);
    }
})->with([
    'one section' => [LW_TEXT . LW_LAST, null],
    'no sectPr at all' => [LW_TEXT, null],
    'two sections' => [LW_TEXT . LW_BREAK . LW_TEXT . LW_LAST, 1],
    'three sections' => [LW_TEXT . LW_BREAK . LW_TEXT . LW_BREAK . LW_TEXT . LW_LAST, 2],
    'a break and no final sectPr' => [LW_TEXT . LW_BREAK . LW_TEXT, 1],
    'the break on a paragraph with text' => ['<w:p><w:pPr><w:sectPr/></w:pPr><w:r><w:t>Text</w:t></w:r></w:p>' . LW_TEXT . LW_LAST, 1],
    'inside an sdt' => ['<w:sdt><w:sdtContent>' . LW_BREAK . '</w:sdtContent></w:sdt>' . LW_TEXT . LW_LAST, 1],
    'inside customXml' => ['<w:customXml>' . LW_BREAK . '</w:customXml>' . LW_TEXT . LW_LAST, 1],
    'a tracked earlier sectPr is not a section' => [LW_TEXT . '<w:sectPr><w:sectPrChange><w:sectPr/></w:sectPrChange></w:sectPr>', null],
]);

it('reads the same blocks with or without the section break', function (): void {
    $with = Agent::read(lwBody(LW_TEXT . LW_BREAK . LW_TEXT . LW_LAST));
    $without = Agent::read(lwBody(LW_TEXT . LW_TEXT . LW_LAST));
    unset($with['unrepresented']);

    expect($with)->toBe($without);
});

it('never reports a document the writer wrote', function (): void {
    $doc = Agent::read(Agent::toBytes([
        'page' => ['orientation' => 'landscape', 'header' => ['left' => 'H']],
        'blocks' => [lwMixedPara(), ['type' => 'pageBreak'], lwMixedPara()],
    ]));

    expect($doc)->not->toHaveKey('unrepresented');
});

it('keeps a band note beside the layout note', function (): void {
    $notes = [
        ['where' => '/page/header', 'kind' => 'image', 'count' => 1],
        ['where' => '/page', 'kind' => 'layout-not-preserved', 'count' => 2],
    ];

    $lines = explode("\n", Agent::describe(['blocks' => [], 'unrepresented' => $notes]));

    expect($lines)->toContain('Not represented (kept only by writing onto the original as a template): /page/header: 1 image')
        ->and($lines)->toContain("Layout not preserved: 2 section breaks flattened into the last section's page setup");
});

it('describe names one section break in the singular', function (): void {
    $text = Agent::describe(['blocks' => [], 'unrepresented' => [['where' => '/page', 'kind' => 'layout-not-preserved', 'count' => 1]]]);

    expect($text)->toContain("Layout not preserved: 1 section break flattened into the last section's page setup")
        ->and($text)->not->toContain('Not represented');
});
