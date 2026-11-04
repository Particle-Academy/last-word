<?php

declare(strict_types=1);

use LastWord\Agent;
use LastWord\Exceptions\SchemaException;

/*
 * Mixed page orientation (.ai/plans/mixed-page-orientation.md): what is still
 * refused now that `pageBreak.page` is honoured (see MixedPagesTest). Page keys
 * written straight on a break, and a top-level `sections`. Case for case with the
 * Node and Python packages; the words are the same bytes in all three.
 */

const LW_SECTIONS_REFUSED = 'there is no sections key; use pageBreak.page';

function lwMixedPara(string $text = 'Body'): array
{
    return ['type' => 'paragraph', 'runs' => [['text' => $text]]];
}

/** @return array<string, string> path => message */
function lwMixedErrors(array $errors): array
{
    return array_column($errors, 'message', 'path');
}

/* ---------------- validate ---------------- */

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

/* ---------------- repair ---------------- */

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
})->with([
    'near miss' => [['blocks' => [['type' => 'pageBreak', 'orientation' => 'landscape']]], 'blocks[0].orientation', 'a pageBreak has no orientation key; use pageBreak.page'],
    'sections' => [['sections' => [], 'blocks' => []], 'sections', LW_SECTIONS_REFUSED],
]);

it('layout() refuses it when a template routes through toBytes too', function (): void {
    $template = Agent::toBytes(['blocks' => [lwMixedPara()]]);

    expect(fn () => Agent::layout(['blocks' => [['type' => 'pageBreak', 'orientation' => 'landscape']]], ['template' => $template]))
        ->toThrow(SchemaException::class);
});

it('writes a plain break exactly as before', function (): void {
    $doc = ['blocks' => [lwMixedPara('one'), ['type' => 'pageBreak'], lwMixedPara('two')]];

    expect(Agent::validate($doc))->toBe([])
        ->and(Agent::read(Agent::toBytes($doc))['blocks'])->toBe($doc['blocks']);
});

/* ---------------- reading ---------------- */

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
