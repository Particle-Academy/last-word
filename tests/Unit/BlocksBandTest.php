<?php

declare(strict_types=1);

use LastWord\Agent;
use LastWord\Exceptions\SchemaException;
use LastWord\Exchange\ExportPolicy;
use LastWord\Tests\Support\GeneratedLetterhead as Letterhead;
use LastWord\Tests\Support\Package;
use LastWord\Writer\TemplateSection;

/**
 * A header or footer that holds BLOCKS (last-word#6).
 *
 * `page.header` / `page.footer` is either the one-line band it always was
 * (`{left, center, right, size, color}`) or `{blocks: [...]}`, in the body's own
 * block vocabulary less `chart` and `pageBreak`. read() returns the blocks form
 * for a header the band could not hold -- a logo, several paragraphs, a table --
 * so a letterhead previews with its logo, and toBytes() writes it back.
 */
function bbLogoSrc(): string
{
    return 'data:image/png;base64,' . base64_encode(Letterhead::logoPng());
}

function bbDoc(array $page, array $blocks = []): array
{
    return [
        'title' => 'Blocks band',
        'page' => $page,
        'blocks' => $blocks !== [] ? $blocks : [['type' => 'paragraph', 'runs' => [['text' => 'Body text.']]]],
    ];
}

function bbPara(string $text, array $extra = []): array
{
    return ['type' => 'paragraph', 'runs' => [['text' => $text]]] + $extra;
}

/** Every block of a list, depth first, through table cells and quotes. */
function bbWalk(array $blocks): array
{
    $out = [];
    foreach ($blocks as $b) {
        $out[] = $b;
        foreach ($b['rows'] ?? [] as $row) {
            foreach ($row['cells'] ?? [] as $cell) {
                array_push($out, ...bbWalk($cell['blocks'] ?? []));
            }
        }
        array_push($out, ...bbWalk($b['blocks'] ?? []));
    }

    return $out;
}

// ─── Reading ────────────────────────────────────────────────────────────

it('reads a letterhead header with a logo as blocks: the logo, then the text', function (): void {
    $read = Agent::read(Letterhead::bytes());

    expect($read['page']['header'])->toBe(['blocks' => [
        ['type' => 'image', 'src' => bbLogoSrc(), 'widthPx' => 144, 'heightPx' => 48],
        bbPara(' ' . Letterhead::HEADER_TEXT),
    ]]);
    // The logo is represented now, so nothing is reported about it.
    expect($read)->not->toHaveKey('unrepresented');
});

it('reads a letterhead whose address sits in paragraphs under the logo', function (): void {
    $read = Agent::read(Letterhead::bytes(['addressBlock' => true]));

    expect($read['page']['header']['blocks'])->toBe([
        ['type' => 'image', 'src' => bbLogoSrc(), 'widthPx' => 144, 'heightPx' => 48],
        ['type' => 'paragraph', 'runs' => [['text' => Letterhead::ADDRESS_LINES[0], 'size' => 9.0]], 'align' => 'right'],
        ['type' => 'paragraph', 'runs' => [['text' => Letterhead::ADDRESS_LINES[1], 'size' => 9.0]], 'align' => 'right'],
    ]);
});

it('keeps a one-paragraph header or footer in the left/centre/right form, exactly as before', function (): void {
    // The letterhead's footer is one paragraph of text and a link: a band.
    $read = Agent::read(Letterhead::bytes());
    expect($read['page']['footer'])->toBe(['center' => Letterhead::FOOTER_TEXT . ' example.com']);

    // A band this package wrote, with tabs and live fields, reads as it was written.
    $page = ['header' => ['right' => 'Board of Directors'], 'footer' => ['left' => 'Memo', 'center' => 'Confidential', 'right' => 'Page {page} of {pages}', 'size' => 8, 'color' => '#111111']];
    expect(Agent::read(Agent::toBytes(bbDoc($page)))['page'])->toBe($page);
});

it('reads Word\'s page-number footer, wrapped in a content control, as blocks with live fields', function (): void {
    $read = Agent::read(Letterhead::bytes(['pageFooter' => true]));

    expect($read['page']['footer'])->toBe(['blocks' => [
        ['type' => 'paragraph', 'runs' => [['text' => 'Page {page} of {pages}']], 'align' => 'center'],
    ]]);
});

it('reports only what even blocks cannot hold', function (array $variant, array $blocks, array $unrepresented): void {
    $read = Agent::read(Letterhead::bytes($variant));

    expect($read['page']['header']['blocks'] ?? null)->toBe($blocks);
    expect($read['unrepresented'] ?? [])->toBe($unrepresented);
})->with([
    'a GIF logo (outside the image block)' => [['gifLogo' => true], [bbPara(' ' . Letterhead::HEADER_TEXT)], [['where' => '/page/header', 'kind' => 'image', 'count' => 1]]],
    'an SVG named .png (fails the magic bytes)' => [['pngNamedSvg' => true], [bbPara(' ' . Letterhead::HEADER_TEXT)], [['where' => '/page/header', 'kind' => 'image', 'count' => 1]]],
    'an externally linked logo (never fetched)' => [['externalImage' => true], [bbPara(' ' . Letterhead::HEADER_TEXT)], [['where' => '/page/header', 'kind' => 'image', 'count' => 1]]],
    'a VML picture' => [['vmlSrc' => true], [
        ['type' => 'image', 'src' => 'data:image/png;base64,' . base64_encode(Letterhead::logoPng()), 'widthPx' => 144, 'heightPx' => 48],
        bbPara(' ' . Letterhead::HEADER_TEXT),
    ], [['where' => '/page/header', 'kind' => 'image', 'count' => 1]]],
]);

it('does not read a header image over the media cap', function (): void {
    ini_set('memory_limit', '512M');
    $read = Agent::read(Letterhead::bytes(['oversizedLogo' => true]));

    expect($read['page']['header']['blocks'])->toBe([bbPara(' ' . Letterhead::HEADER_TEXT)]);
    expect($read['unrepresented'])->toBe([['where' => '/page/header', 'kind' => 'image', 'count' => 1]]);
});

it('never reads a header part with a DOCTYPE, or over the part cap, as blocks', function (array $variant): void {
    $read = Agent::read(Letterhead::bytes($variant));

    // The band reading and the report, exactly as before last-word#6.
    expect($read['page']['header'])->toBe(['left' => ' ' . Letterhead::HEADER_TEXT])
        ->and($read['unrepresented'])->toBe([['where' => '/page/header', 'kind' => 'image', 'count' => 1]]);
})->with([
    'a DOCTYPE' => [['doctypeHeader' => true]],
    'over 2 MiB' => [['oversizedHeader' => true]],
]);

it('does not change how the BODY reads an image beside text', function (): void {
    // Leading images go first only inside a header or footer; the body keeps
    // its order (text, then the image), so no existing body read changes.
    $doc = bbDoc([], [bbPara('Hello'), ['type' => 'image', 'src' => bbLogoSrc()]]);
    $read = Agent::read(Agent::toBytes($doc));

    expect(array_column($read['blocks'], 'type'))->toBe(['paragraph', 'image']);
});

// ─── Writing ────────────────────────────────────────────────────────────

it('writes a blocks header into header1.xml with its own rels and media', function (): void {
    $doc = bbDoc(['header' => ['blocks' => [
        ['type' => 'image', 'src' => bbLogoSrc(), 'widthPx' => 120, 'heightPx' => 40, 'alt' => 'Acme logo'],
        bbPara('1 Example Street', ['align' => 'right']),
    ]]]);
    $parts = Package::parts(Agent::toBytes($doc));

    expect($parts['word/header1.xml'])->toContain('<w:drawing>')
        ->and($parts['word/header1.xml'])->toContain('1 Example Street')
        ->and($parts['word/header1.xml'])->toContain('r:embed="rId1"')
        ->and($parts['word/_rels/header1.xml.rels'])->toContain('Target="media/image1.png"')
        ->and($parts['word/media/image1.png'])->toBe(Letterhead::logoPng())
        ->and($parts['word/_rels/document.xml.rels'])->not->toContain('media/image1.png')
        ->and($parts['word/document.xml'])->toContain('<w:headerReference w:type="default" r:id="rIdHeader1"/>');
    Package::assertConsistent($parts);
});

it('never collides with the body\'s images, rel ids or drawing ids', function (): void {
    $doc = bbDoc(
        ['header' => ['blocks' => [['type' => 'image', 'src' => bbLogoSrc()], ['type' => 'paragraph', 'runs' => [['text' => 'acme', 'link' => 'https://example.com/']]]]],
            'footer' => ['blocks' => [['type' => 'image', 'src' => lwRedPngDataUrl()]]]],
        [['type' => 'image', 'src' => lwRedPngDataUrl()], ['type' => 'paragraph', 'runs' => [['text' => 'x', 'link' => 'https://example.org/']]]],
    );
    $parts = Package::parts(Agent::toBytes($doc));

    // Body first: image1 is the body's; the header's and footer's come after it.
    expect(array_keys(array_filter($parts, fn ($k) => str_starts_with($k, 'word/media/'), ARRAY_FILTER_USE_KEY)))
        ->toBe(['word/media/image1.png', 'word/media/image2.png', 'word/media/image3.png']);
    expect($parts['word/_rels/header1.xml.rels'])->toContain('https://example.com/')
        ->and($parts['word/_rels/document.xml.rels'])->not->toContain('https://example.com/')
        ->and($parts['word/_rels/footer1.xml.rels'])->toContain('media/image3.png');
    Package::assertConsistent($parts);
});

it('never collides with a letterhead carried by templateParts, and the document\'s band still wins', function (): void {
    $doc = bbDoc(
        ['header' => ['blocks' => [['type' => 'image', 'src' => lwRedPngDataUrl()], bbPara('Our own header')]]],
        [['type' => 'image', 'src' => bbLogoSrc()]],
    );
    $parts = Package::parts(Agent::toBytes($doc, ['template' => Letterhead::bytes(), 'templateParts' => ['styles', 'headers', 'footers', 'page']]));

    // Our header replaced the template's; the template's footer came through.
    expect($parts['word/header1.xml'])->toContain('Our own header')
        ->and($parts['word/header1.xml'])->not->toContain(Letterhead::HEADER_TEXT)
        ->and($parts['word/footer1.xml'])->toContain(Letterhead::FOOTER_TEXT)
        ->and($parts)->not->toHaveKey('word/media/template1.png');
    // Our drawing ids sit above the letterhead's largest.
    preg_match_all('/<wp:docPr id="(\d+)"/', $parts['word/header1.xml'] . $parts['word/document.xml'], $ids);
    expect(min(array_map('intval', $ids[1])))->toBeGreaterThan(Letterhead::LOGO_DRAWING_ID);
    Package::assertConsistent($parts);
});

it('carries a blocks header beside a template\'s header on first and even pages', function (): void {
    $doc = bbDoc(['footer' => ['blocks' => [bbPara('Footer {page}')]]]);
    $parts = Package::parts(Agent::toBytes($doc, ['template' => Letterhead::bytes(['firstPage' => true, 'evenPage' => true]), 'templateParts' => ['headers', 'footers']]));

    expect($parts['word/document.xml'])->toContain('<w:footerReference w:type="first" r:id="rIdFooter1"/>')
        ->and($parts['word/document.xml'])->toContain('<w:footerReference w:type="even" r:id="rIdFooter1"/>');
    Package::assertConsistent($parts);
});

it('writes {page} and {pages} in a blocks band as live fields, in the run\'s own formatting', function (): void {
    $doc = bbDoc(['footer' => ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'Page {page} of {pages}', 'bold' => true]]]]]]);
    $parts = Package::parts(Agent::toBytes($doc));

    expect($parts['word/footer1.xml'])->toContain('<w:instrText xml:space="preserve"> PAGE </w:instrText>')
        ->and($parts['word/footer1.xml'])->toContain('<w:instrText xml:space="preserve"> NUMPAGES </w:instrText>')
        ->and($parts['word/footer1.xml'])->not->toContain('{page}')
        ->and(substr_count($parts['word/footer1.xml'], '<w:b/>'))->toBe(12);
    expect(Agent::read(Agent::toBytes($doc))['page']['footer'])->toBe(['blocks' => [
        ['type' => 'paragraph', 'runs' => [['text' => 'Page {page} of {pages}', 'bold' => true]]],
    ]]);
});

it('marks its own blocks band, so one plain paragraph still reads back as blocks', function (): void {
    $doc = bbDoc(['header' => ['blocks' => [bbPara('Acme Ltd', ['align' => 'center'])]]]);
    $parts = Package::parts(Agent::toBytes($doc));

    expect($parts['word/header1.xml'])->toContain('<w:sdt><w:sdtPr><w:tag w:val="lastword:band"/></w:sdtPr><w:sdtContent>');
    expect(Agent::read(Agent::toBytes($doc))['page']['header'])->toBe($doc['page']['header']);
});

it('keeps {page} literal in the BODY', function (): void {
    $parts = Package::parts(Agent::toBytes(bbDoc([], [bbPara('see {page}')])));

    expect($parts['word/document.xml'])->toContain('see {page}')->not->toContain('instrText');
});

it('writes a table in a band with a paragraph after it, as Word requires', function (): void {
    $doc = bbDoc(['header' => ['blocks' => [['type' => 'table', 'rows' => [['cells' => [
        ['blocks' => [['type' => 'image', 'src' => bbLogoSrc()]]],
        ['blocks' => [bbPara('Acme Ltd')]],
    ]]]]]]]);
    $parts = Package::parts(Agent::toBytes($doc));

    expect($parts['word/header1.xml'])->toEndWith('</w:tbl><w:p/></w:sdtContent></w:sdt></w:hdr>');
    Package::assertConsistent($parts);
    expect(Agent::read(Agent::toBytes($doc))['page']['header']['blocks'][0]['type'])->toBe('table');
});

it('is deterministic with a blocks band', function (): void {
    $doc = bbDoc(['header' => ['blocks' => [['type' => 'image', 'src' => bbLogoSrc()], bbPara('A')]]]);

    expect(Agent::toBytes($doc))->toBe(Agent::toBytes($doc));
});

it('treats an empty blocks band as asking for nothing, like an empty band', function (): void {
    $parts = Package::parts(Agent::toBytes(bbDoc(['header' => ['blocks' => []]])));

    expect($parts)->not->toHaveKey('word/header1.xml');
});

// ─── Round trip ─────────────────────────────────────────────────────────

it('keeps a letterhead\'s logo, address and footer through read and write, with no template', function (): void {
    $doc = Agent::read(Letterhead::bytes(['addressBlock' => true]));
    unset($doc['unrepresented']);
    $parts = Package::parts(Agent::toBytes($doc));

    $media = array_filter($parts, fn ($k) => str_starts_with($k, 'word/media/'), ARRAY_FILTER_USE_KEY);
    expect($parts['word/header1.xml'])->toContain('<w:drawing>')
        ->and($parts['word/header1.xml'])->toContain(Letterhead::ADDRESS_LINES[0])
        ->and($parts['word/header1.xml'])->toContain(Letterhead::ADDRESS_LINES[1])
        ->and($parts['word/footer1.xml'])->toContain(Letterhead::FOOTER_TEXT)
        ->and(array_values($media))->toContain(Letterhead::logoPng());
    // The template's sample letter never came along: only its body was read.
    Package::assertConsistent($parts);

    // And it is stable: a second trip reads the same header.
    $again = Agent::read(Agent::toBytes($doc));
    expect($again['page']['header'])->toBe($doc['page']['header'])
        ->and($again['page']['footer'])->toBe($doc['page']['footer']);
});

it('records nothing for a save without a change: diff(d, read(toBytes(d))) is empty', function (): void {
    $doc = bbDoc(['header' => ['blocks' => [['type' => 'image', 'src' => bbLogoSrc(), 'alt' => 'Logo'], bbPara('Acme')]], 'footer' => ['center' => 'Page {page}']]);

    expect(Agent::diff($doc, Agent::read(Agent::toBytes($doc))))->toBe([]);
});

it('diffs and reduces a changed blocks band as one doc.set of page', function (): void {
    $a = bbDoc(['header' => ['blocks' => [bbPara('Old')]]]);
    $b = bbDoc(['header' => ['blocks' => [['type' => 'image', 'src' => bbLogoSrc()], bbPara('New')]]]);
    $ops = Agent::diff($a, $b);

    expect($ops)->toBe([['op' => 'doc.set', 'key' => 'page', 'value' => $b['page']]])
        ->and(Agent::reduce($a, $ops))->toBe($b);
});

// ─── Validation, repair, hardening ──────────────────────────────────────

it('refuses a band that mixes the slot form with blocks', function (): void {
    $errors = Agent::validate(bbDoc(['header' => ['left' => 'A', 'blocks' => [bbPara('B')]]]));

    expect($errors)->toContain(['path' => 'page.header', 'message' => 'page header is either {left, center, right, size, color} or {blocks}, never both (found blocks with left)']);
});

it('refuses what a band cannot hold, at any depth', function (array $block, string $path, string $message): void {
    $errors = Agent::validate(bbDoc(['footer' => ['blocks' => [$block]]]));

    expect($errors)->toContain(['path' => $path, 'message' => $message]);
})->with([
    'a chart' => [['type' => 'chart', 'option' => ['series' => [['type' => 'pie', 'data' => [1]]]]], 'page.footer.blocks[0].type', 'a header or footer cannot hold a chart block'],
    'a page break' => [['type' => 'pageBreak'], 'page.footer.blocks[0].type', 'a header or footer cannot hold a pageBreak block'],
    'portable content' => [['type' => 'portable', 'content' => ['fancyContent' => 1, 'items' => []]], 'page.footer.blocks[0].type', 'a header or footer cannot hold a portable block'],
    'a page break in a table cell' => [['type' => 'table', 'rows' => [['cells' => [['blocks' => [['type' => 'pageBreak']]]]]]], 'page.footer.blocks[0].rows[0].cells[0].blocks[0].type', 'a header or footer cannot hold a pageBreak block'],
    'a paragraph without runs' => [['type' => 'paragraph'], 'page.footer.blocks[0].runs', 'missing "runs" (array of {text, …} run objects)'],
]);

it('refuses a blocks value that is not a list', function (): void {
    expect(Agent::validate(bbDoc(['header' => ['blocks' => 'logo']])))
        ->toContain(['path' => 'page.header.blocks', 'message' => 'page header blocks must be a list of block objects']);
});

it('checks a band image\'s bytes, not only its data URL', function (string $src, string $message): void {
    $errors = Agent::validate(bbDoc(['header' => ['blocks' => [['type' => 'image', 'src' => $src]]]]));

    expect($errors)->toContain(['path' => 'page.header.blocks[0].src', 'message' => $message]);
})->with([
    'an SVG declared as PNG' => ['data:image/png;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg"/>'), 'a header or footer image must be PNG or JPEG bytes matching its data URL type'],
    'a PNG declared as JPEG' => ['data:image/jpeg;base64,' . base64_encode(Letterhead::logoPng()), 'a header or footer image must be PNG or JPEG bytes matching its data URL type'],
    'not base64' => ['data:image/png;base64,***', 'a header or footer image must be PNG or JPEG bytes matching its data URL type'],
    'a remote URL' => ['https://example.com/logo.png', 'image src must be a PNG or JPEG data URL (data:image/png;base64,… or data:image/jpeg;base64,…)'],
]);

it('caps a band image, and band images together, at the template media caps', function (): void {
    ini_set('memory_limit', '1G');
    $png = static fn (int $bytes): string => 'data:image/png;base64,' . base64_encode("\x89PNG\r\n\x1a\n" . str_repeat("\0", $bytes - 8));

    $one = Agent::validate(bbDoc(['header' => ['blocks' => [['type' => 'image', 'src' => $png(TemplateSection::MEDIA_LIMIT + 1)]]]]));
    expect($one)->toContain(['path' => 'page.header.blocks[0].src', 'message' => 'a header or footer image is over ' . TemplateSection::MEDIA_LIMIT . ' bytes']);

    $third = intdiv(TemplateSection::MEDIA_TOTAL_LIMIT, 3) + 1;
    $all = Agent::validate(bbDoc([
        'header' => ['blocks' => [['type' => 'image', 'src' => $png($third)], ['type' => 'image', 'src' => $png($third)]]],
        'footer' => ['blocks' => [['type' => 'image', 'src' => $png($third)]]],
    ]));
    expect($all)->toContain(['path' => 'page.footer.blocks[0].src', 'message' => 'header and footer images together are over ' . TemplateSection::MEDIA_TOTAL_LIMIT . ' bytes']);
});

it('never fetches a remote image source: the writer degrades it to its alt text', function (): void {
    // Unvalidated, straight into the writer, the way a host could misuse it.
    $writer = new LastWord\Writer\DocxWriter();
    $parts = Package::parts($writer->toBytes(bbDoc(['header' => ['blocks' => [['type' => 'image', 'src' => 'https://example.com/logo.png', 'alt' => 'Logo']]]])));

    expect($parts['word/header1.xml'])->toContain('[image: Logo]')
        ->and(implode('', array_keys($parts)))->not->toContain('media/');
});

it('repairs a band by dropping what it cannot hold, and says so', function (): void {
    $r = Agent::validateAndRepair(bbDoc(['header' => ['blocks' => [bbPara('Keep'), ['type' => 'pageBreak'], 'loose text']]]));

    expect($r['ok'])->toBeTrue()
        ->and($r['schema']['page']['header']['blocks'])->toBe([bbPara('Keep'), bbPara('loose text')])
        ->and(array_column($r['errors'], 'path'))->toContain('page.header.blocks[1]');
});

it('throws on write for a band it refuses', function (): void {
    Agent::toBytes(bbDoc(['header' => ['blocks' => [['type' => 'pageBreak']]]]));
})->throws(SchemaException::class);

// ─── Schema, describe, exports ──────────────────────────────────────────

it('publishes both band forms in the schema, leaving $defs.band as it was', function (): void {
    $schema = Agent::jsonSchema();
    $header = $schema['properties']['page']['properties']['header'];

    expect($header['oneOf'])->toBe([['$ref' => '#/$defs/band'], ['$ref' => '#/$defs/blocksBand']])
        ->and(array_keys($schema['$defs']['band']['properties']))->toBe(['left', 'center', 'right', 'size', 'color'])
        ->and($schema['$defs']['blocksBand']['required'])->toBe(['blocks'])
        ->and($schema['$defs']['blocksBand']['additionalProperties'])->toBeFalse()
        ->and(json_encode($schema['$defs']['blocksBand']))->toContain('pageBreak')->toContain('{pages}');
});

it('describes a blocks band, so an agent knows the header has a logo', function (): void {
    $text = Agent::describe(Agent::read(Letterhead::bytes(['addressBlock' => true])));

    expect($text)->toContain('Page header: 3 blocks (1 image, 2 paragraph)')
        ->not->toContain('Not represented');
});

it('keeps a blocks band through the docx export', function (): void {
    $out = Agent::export(Letterhead::bytes(), 'docx', [], new ExportPolicy(['allow' => ['docx']]));

    expect($out['ok'])->toBeTrue();
    $parts = Package::parts($out['bytes']);
    expect($parts['word/header1.xml'])->toContain('<w:drawing>');
    Package::assertConsistent($parts);
});

it('leaves catalog and extract to the body: page furniture is not content', function (): void {
    $catalog = Agent::catalog(Letterhead::bytes());
    $extract = Agent::extract(Letterhead::bytes(), null, []);

    expect($catalog['blocks'])->toBe(4)
        ->and(json_encode($extract['content']))->not->toContain(Letterhead::HEADER_TEXT);
});
