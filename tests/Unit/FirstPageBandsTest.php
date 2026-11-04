<?php

declare(strict_types=1);

use LastWord\Agent;
use LastWord\Exceptions\TemplateException;
use LastWord\Tests\Support\GeneratedLetterhead as Letterhead;
use LastWord\Tests\Support\Package;

/**
 * The letterhead on the first page only, and first / even bands of a
 * document's own (MOIC, 2026-11-01).
 *
 * - `templateFirstPageOnly: true` places the template's letterhead header and
 *   footer as Word's FIRST-page header and footer (`w:titlePg` on). Later pages
 *   get the document's own `page.header` / `page.footer`, or nothing.
 * - `page.firstHeader` / `page.firstFooter` / `page.evenHeader` /
 *   `page.evenFooter` are a document's own variants, each the same shape as
 *   `page.header`. `page.header` stays "every page": with a `firstHeader` it is
 *   every page but the first.
 * - read() returns the variants, so a first-page-only letterhead reads as
 *   `firstHeader` with no `header`.
 */
const FP_PARTS = ['headers', 'footers'];

function fpDoc(array $page = []): array
{
    $doc = ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'A letter of several pages.']]]]];
    if ($page !== []) {
        $doc['page'] = $page;
    }

    return $doc;
}

/** The section's header/footer references, in order, as "kind:type:rId". */
function fpRefs(array $parts): array
{
    preg_match_all('/<w:(header|footer)Reference w:type="([a-z]+)" r:id="([^"]+)"\/>/', $parts['word/document.xml'], $m, PREG_SET_ORDER);

    return array_map(static fn (array $r): string => "{$r[1]}:{$r[2]}:{$r[3]}", $m);
}

/** Relationship id => target, from word/_rels/document.xml.rels. */
function fpTargets(array $parts): array
{
    preg_match_all('/Id="([^"]+)" Type="[^"]+\/(?:header|footer)" Target="([^"]+)"/', $parts['word/_rels/document.xml.rels'], $m, PREG_SET_ORDER);
    $out = [];
    foreach ($m as $r) {
        $out[$r[1]] = $r[2];
    }

    return $out;
}

function fpBand(string $text): array
{
    return ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => $text]]]]];
}

// ─── templateFirstPageOnly ───────────────────────────────────────────────

it('places the template letterhead on the first page only', function (): void {
    $parts = Package::parts(Agent::toBytes(fpDoc(), [
        'template' => Letterhead::bytes(), 'templateParts' => FP_PARTS, 'templateFirstPageOnly' => true,
    ]));

    expect(fpRefs($parts))->toBe(['header:first:rIdTplHeader4', 'footer:first:rIdTplFooter4'])
        ->and($parts['word/document.xml'])->toContain('<w:titlePg/></w:sectPr>')
        ->and(fpTargets($parts))->toBe(['rIdTplHeader4' => 'header4.xml', 'rIdTplFooter4' => 'footer4.xml'])
        ->and($parts['word/header4.xml'])->toContain(Letterhead::HEADER_TEXT)
        ->and($parts['word/footer4.xml'])->toContain(Letterhead::FOOTER_TEXT)
        ->and($parts['word/media/template1.png'])->toBe(Letterhead::logoPng());
    expect($parts)->not->toHaveKey('word/header1.xml');
    Package::assertConsistent($parts);
});

it('puts the document\'s own bands on the later pages, and keeps the letterhead on the first', function (): void {
    $parts = Package::parts(Agent::toBytes(fpDoc([
        'header' => ['left' => 'Acme | continued'],
        'footer' => ['right' => 'Page {page} of {pages}'],
    ]), ['template' => Letterhead::bytes(), 'templateParts' => FP_PARTS, 'templateFirstPageOnly' => true]));

    expect(fpRefs($parts))->toBe([
        'header:default:rIdHeader1', 'header:first:rIdTplHeader4',
        'footer:default:rIdFooter1', 'footer:first:rIdTplFooter4',
    ])
        ->and($parts['word/header1.xml'])->toContain('Acme | continued')
        ->and($parts['word/header4.xml'])->toContain(Letterhead::HEADER_TEXT)
        ->and($parts['word/document.xml'])->toContain('<w:titlePg/>');
    Package::assertConsistent($parts);
});

it('uses the template\'s own first-page part when it has one, and never its even part', function (): void {
    $parts = Package::parts(Agent::toBytes(fpDoc(), [
        'template' => Letterhead::bytes(['firstPage' => true, 'evenPage' => true]),
        'templateParts' => FP_PARTS, 'templateFirstPageOnly' => true,
    ]));

    expect(fpRefs($parts))->toBe(['header:first:rIdTplHeader4', 'footer:first:rIdTplFooter4'])
        ->and($parts['word/header4.xml'])->toContain(Letterhead::FIRST_HEADER_TEXT)
        ->and(implode('', $parts))->not->toContain(Letterhead::EVEN_HEADER_TEXT);
    expect($parts)->not->toHaveKey('word/settings.xml');
    Package::assertConsistent($parts);
});

it('lets the document\'s own first-page band win over the template, kind by kind', function (): void {
    $parts = Package::parts(Agent::toBytes(fpDoc(['firstHeader' => ['left' => 'Our own first page']]), [
        'template' => Letterhead::bytes(), 'templateParts' => FP_PARTS, 'templateFirstPageOnly' => true,
    ]));

    expect(fpRefs($parts))->toBe(['header:first:rIdHeader2', 'footer:first:rIdTplFooter4']);
    expect($parts)->not->toHaveKey('word/header4.xml');
    expect($parts['word/header2.xml'])->toContain('Our own first page');
    Package::assertConsistent($parts);
});

it('changes nothing when the template carries no header or footer', function (): void {
    $options = ['template' => Letterhead::bytes(), 'templateParts' => ['styles', 'page']];

    expect(Agent::toBytes(fpDoc(), $options + ['templateFirstPageOnly' => true]))->toBe(Agent::toBytes(fpDoc(), $options));
});

it('refuses a templateFirstPageOnly that is not a boolean', function (mixed $value): void {
    expect(fn () => Agent::toBytes(fpDoc(), ['template' => Letterhead::bytes(), 'templateParts' => FP_PARTS, 'templateFirstPageOnly' => $value]))
        ->toThrow(TemplateException::class, 'templateFirstPageOnly');
})->with([['yes'], [1], [null]]);

it('reads a first-page-only letterhead back as firstHeader / firstFooter, with no header', function (): void {
    $read = Agent::read(Agent::toBytes(fpDoc(['footer' => ['right' => 'Page {page}']]), [
        'template' => Letterhead::bytes(), 'templateParts' => FP_PARTS, 'templateFirstPageOnly' => true,
    ]));

    expect(array_keys($read['page']))->toBe(['footer', 'firstHeader', 'firstFooter'])
        ->and($read['page']['footer'])->toBe(['right' => 'Page {page}'])
        ->and($read['page']['firstHeader']['blocks'][0]['type'])->toBe('image')
        ->and(json_encode($read['page']['firstHeader']))->toContain(Letterhead::HEADER_TEXT)
        ->and(json_encode($read['page']['firstFooter']))->toContain(Letterhead::FOOTER_TEXT);
});

// ─── A document's own first and even bands ───────────────────────────────

it('writes a first-page band as Word\'s first header, with w:titlePg', function (): void {
    $parts = Package::parts(Agent::toBytes(fpDoc([
        'header' => ['left' => 'Every later page'],
        'firstHeader' => ['left' => 'The first page'],
        'footer' => ['center' => '{page}'],
    ])));

    expect(fpRefs($parts))->toBe([
        'header:default:rIdHeader1', 'header:first:rIdHeader2',
        // No firstFooter: the footer is on the first page too.
        'footer:default:rIdFooter1', 'footer:first:rIdFooter1',
    ])
        ->and(fpTargets($parts))->toBe(['rIdHeader1' => 'header1.xml', 'rIdHeader2' => 'header2.xml', 'rIdFooter1' => 'footer1.xml'])
        ->and($parts['word/header2.xml'])->toContain('The first page')
        ->and($parts['word/document.xml'])->toContain('<w:titlePg/></w:sectPr>');
    Package::assertConsistent($parts);
});

it('writes an even-page band with the even/odd setting', function (): void {
    $parts = Package::parts(Agent::toBytes(fpDoc([
        'header' => ['right' => 'Odd'],
        'evenHeader' => ['left' => 'Even'],
        'footer' => ['center' => '{page}'],
    ])));

    expect(fpRefs($parts))->toBe([
        'header:default:rIdHeader1', 'header:even:rIdHeader3',
        'footer:default:rIdFooter1', 'footer:even:rIdFooter1',
    ])
        ->and($parts['word/header3.xml'])->toContain('Even')
        ->and($parts['word/settings.xml'])->toContain('<w:evenAndOddHeaders/>')
        ->and($parts['word/document.xml'])->not->toContain('titlePg');
    Package::assertConsistent($parts);
});

it('round-trips first and even bands of either form', function (array $page): void {
    expect(Agent::read(Agent::toBytes(fpDoc($page)))['page'])->toBe($page);
})->with([
    'slots' => [['header' => ['left' => 'Rest'], 'footer' => ['right' => 'Page {page}'], 'firstHeader' => ['left' => 'First', 'right' => 'Letterhead']]],
    'blocks' => [['header' => fpBand('Rest'), 'firstHeader' => fpBand('First'), 'firstFooter' => fpBand('First footer')]],
    'first only' => [['firstHeader' => fpBand('Only on page one')]],
    'even' => [['header' => ['left' => 'Odd'], 'evenHeader' => ['left' => 'Even'], 'evenFooter' => fpBand('Even footer')]],
    'all three' => [['header' => ['left' => 'Odd'], 'footer' => ['center' => '{page}'], 'firstHeader' => fpBand('First'), 'firstFooter' => ['center' => 'Cover'], 'evenHeader' => ['left' => 'Even'], 'evenFooter' => ['center' => 'Even {page}']]],
]);

it('writes an empty first-page band as a blank first page, and reads it back so', function (): void {
    $page = ['header' => ['left' => 'Not on the cover'], 'firstHeader' => ['blocks' => []]];
    $parts = Package::parts(Agent::toBytes(fpDoc($page)));

    expect(fpRefs($parts))->toBe(['header:default:rIdHeader1', 'header:first:rIdHeader2'])
        ->and($parts['word/header2.xml'])->not->toContain('<w:t');
    expect(Agent::read(Agent::toBytes(fpDoc($page)))['page'])->toBe($page);
    Package::assertConsistent($parts);
});

it('treats an empty first-page band with nothing else as asking for nothing', function (): void {
    expect(Agent::toBytes(fpDoc(['firstHeader' => ['blocks' => []], 'firstFooter' => []])))
        ->toBe(Agent::toBytes(fpDoc()));
});

it('reads another producer\'s different-first-page header', function (): void {
    // Word's "Different first page" with its own first-page part.
    $read = Agent::read(Letterhead::bytes(['firstPage' => true]));

    expect(array_keys($read['page']))->toContain('header', 'firstHeader')
        ->and(json_encode($read['page']['firstHeader']))->toContain(Letterhead::FIRST_HEADER_TEXT)
        ->and(json_encode($read['page']['header']))->toContain(Letterhead::HEADER_TEXT);
    // titlePg on, but the footer has no first part of its own: page one has
    // no footer in Word, and the model says so rather than repeating it.
    expect($read['page']['firstFooter'])->toBe(['blocks' => []]);
});

it('reads another producer\'s even-page header', function (): void {
    $read = Agent::read(Letterhead::bytes(['evenPage' => true]));

    expect(json_encode($read['page']['evenHeader']))->toContain(Letterhead::EVEN_HEADER_TEXT)
        ->and($read['page'])->not->toHaveKey('firstHeader');
});

it('ignores a first-page part while w:titlePg is off, as Word does', function (): void {
    // Our own writer never produces this: a first reference with titlePg off.
    $bytes = Agent::toBytes(fpDoc(['header' => ['left' => 'Rest'], 'firstHeader' => ['left' => 'First']]));
    $parts = Package::parts($bytes);
    $parts['word/document.xml'] = str_replace('<w:titlePg/>', '', $parts['word/document.xml']);

    expect(Agent::read(fpZip($parts))['page'])->toBe(['header' => ['left' => 'Rest']]);
});

function fpZip(array $parts): string
{
    $path = lwTemp('lw-fp', '.docx');
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($parts as $name => $xml) {
        $zip->addFromString($name, $xml);
    }
    $zip->close();
    $bytes = (string) file_get_contents($path);
    @unlink($path);

    return $bytes;
}

// ─── Model rules ─────────────────────────────────────────────────────────

it('publishes the first and even bands in the schema, each the shape of page.header', function (): void {
    $page = Agent::jsonSchema()['properties']['page']['properties'];

    foreach (['firstHeader', 'firstFooter', 'evenHeader', 'evenFooter'] as $key) {
        expect($page)->toHaveKey($key)
            ->and($page[$key]['oneOf'])->toBe($page['header']['oneOf']);
    }
});

it('validates a first or even band as it validates page.header', function (): void {
    $errors = Agent::validate(fpDoc(['firstFooter' => ['left' => 5], 'evenHeader' => 'text']));

    expect(array_column($errors, 'path'))->toContain('page.firstFooter.left', 'page.evenHeader');
});

it('refuses a footnote in a first or even band, as in every band', function (string $key): void {
    $doc = fpDoc([$key => ['blocks' => [['type' => 'paragraph', 'runs' => [
        ['text' => 'Head'], ['text' => '', 'footnote' => ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'n']]]]]],
    ]]]]]);

    expect(Agent::validate($doc))->toContain(['path' => "page.{$key}.blocks[0].runs[1].footnote", 'message' => 'a header or footer cannot hold a footnote']);
    $repaired = Agent::validateAndRepair($doc);
    expect($repaired['schema']['page'][$key]['blocks'][0]['runs'])->toBe([['text' => 'Head']]);
})->with(['firstHeader', 'firstFooter', 'evenHeader', 'evenFooter']);
