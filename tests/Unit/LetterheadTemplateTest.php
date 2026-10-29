<?php

declare(strict_types=1);

use LastWord\Agent;
use LastWord\Exceptions\TemplateException;
use LastWord\Tests\Support\GeneratedLetterhead as Letterhead;
use LastWord\Tests\Support\GeneratedTemplate;
use LastWord\Tests\Support\Package;

/**
 * A customer's letterhead survives -- `templateParts` (last-word#5).
 *
 * `['template' => $letterhead, 'templateParts' => ['styles', 'headers',
 * 'footers', 'page']]` takes the template's SECTION as well as its look: its
 * header and footer parts with their images, byte for byte, and its page size
 * and margins. Its body is never taken. `template` alone is unchanged.
 */
const LH_ALL = ['styles', 'headers', 'footers', 'page'];

function lhDoc(array $page = []): array
{
    $doc = ['title' => 'Quote 42', 'blocks' => [
        ['type' => 'heading', 'level' => 1, 'runs' => [['text' => 'Our quote']]],
        ['type' => 'paragraph', 'runs' => [['text' => 'Written by an agent onto the customer letterhead.']]],
    ]];
    if ($page !== []) {
        $doc['page'] = $page;
    }

    return $doc;
}

/** @return array<string, string> */
function lhParts(string $bytes): array
{
    return Package::parts($bytes);
}

function lhSectPr(array $parts): string
{
    preg_match('#<w:sectPr>.*</w:sectPr>#s', $parts['word/document.xml'], $m);

    return $m[0] ?? '';
}

/** The package is self-consistent ({@see Package::assertConsistent()}). */
function lhAssertConsistent(array $parts): void
{
    Package::assertConsistent($parts);
}

// ─── Defaults ────────────────────────────────────────────────────────────

it('takes only the look when templateParts is not given, exactly as before', function (): void {
    $plain = Agent::toBytes(lhDoc(), ['template' => Letterhead::bytes()]);
    $explicit = Agent::toBytes(lhDoc(), ['template' => Letterhead::bytes(), 'templateParts' => ['styles']]);

    expect($plain)->toBe($explicit);
    $parts = lhParts($plain);
    expect($parts)->not->toHaveKey('word/header1.xml');
    expect($parts)->not->toHaveKey('word/media/template1.png');
    expect(lhSectPr($parts))->toContain('<w:pgSz w:w="12240" w:h="15840"/>');
    expect(lhSectPr($parts))->toContain('w:header="720" w:footer="720" w:gutter="0"');
});

it('does not read the section at all unless it is asked for, so a hostile one cannot fail the look', function (): void {
    $bytes = Agent::toBytes(lhDoc(), ['template' => Letterhead::bytes(['doctypeHeader' => true, 'externalImage' => true])]);

    expect(lhParts($bytes))->not->toHaveKey('word/header1.xml');
});

// ─── The letterhead ──────────────────────────────────────────────────────

it('carries the template header byte for byte, with its image rel and media', function (): void {
    $template = Letterhead::bytes();
    $source = lhParts($template);
    $parts = lhParts(Agent::toBytes(lhDoc(), ['template' => $template, 'templateParts' => LH_ALL]));

    expect($parts['word/header1.xml'])->toBe($source['word/header1.xml']);
    expect($parts['word/_rels/header1.xml.rels'])->toContain('Id="rId1"')
        ->and($parts['word/_rels/header1.xml.rels'])->toContain('Target="media/template1.png"');
    expect($parts['word/media/template1.png'])->toBe(Letterhead::logoPng());
    expect($parts['[Content_Types].xml'])->toContain('<Override PartName="/word/header1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.header+xml"/>');
    expect($parts['word/_rels/document.xml.rels'])->toContain('Id="rIdTplHeader1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/header" Target="header1.xml"');
    expect(lhSectPr($parts))->toContain('<w:headerReference w:type="default" r:id="rIdTplHeader1"/>');
    lhAssertConsistent($parts);
});

it('declares a content type for media the writer has no Default for', function (): void {
    $parts = lhParts(Agent::toBytes(lhDoc(), ['template' => Letterhead::bytes(['gifLogo' => true]), 'templateParts' => LH_ALL]));

    expect($parts['word/media/template1.gif'])->toBe(Letterhead::gif());
    expect($parts['[Content_Types].xml'])->toContain('<Default Extension="gif" ContentType="image/gif"/>');
    lhAssertConsistent($parts);
});

it('carries the template footer byte for byte, keeping its web link', function (): void {
    $template = Letterhead::bytes();
    $parts = lhParts(Agent::toBytes(lhDoc(), ['template' => $template, 'templateParts' => LH_ALL]));

    expect($parts['word/footer1.xml'])->toBe(lhParts($template)['word/footer1.xml']);
    expect($parts['word/_rels/footer1.xml.rels'])->toContain('Target="https://example.com/" TargetMode="External"');
    expect(lhSectPr($parts))->toContain('<w:footerReference w:type="default" r:id="rIdTplFooter1"/>');
});

it('takes the template page size, margins and header/footer distances', function (): void {
    $parts = lhParts(Agent::toBytes(lhDoc(), ['template' => Letterhead::bytes(), 'templateParts' => LH_ALL]));

    expect(lhSectPr($parts))->toContain('<w:pgSz w:w="' . Letterhead::PAGE_W . '" w:h="' . Letterhead::PAGE_H . '"/>')
        ->and(lhSectPr($parts))->toContain('<w:pgMar w:top="' . Letterhead::MARGIN_TOP . '" w:right="1000" w:bottom="1500" w:left="1100" w:header="400" w:footer="300" w:gutter="0"/>');
});

it('never takes the template body or its media, even from a .docx that has one', function (): void {
    // MOIC: the letterhead usually arrives as a plain .docx with a sample letter
    // in it. Only the section may travel.
    $template = Letterhead::bytes();
    expect(lhParts($template)['word/document.xml'])->toContain(Letterhead::BODY_TEXT);

    $parts = lhParts(Agent::toBytes(lhDoc(), ['template' => $template, 'templateParts' => LH_ALL]));
    $all = implode("\n", $parts);

    expect($all)->not->toContain('Dear customer')
        ->and($all)->not->toContain('Body picture')
        ->and($all)->not->toContain(Letterhead::bodyPng());
    expect($parts)->not->toHaveKey('word/media/image2.png');
    expect(array_values(array_filter(array_keys($parts), fn ($n) => str_starts_with($n, 'word/media/'))))->toBe(['word/media/template1.png']);
    // And the written body is ours.
    expect($parts['word/document.xml'])->toContain('Written by an agent onto the customer letterhead.');
});

it('cannot collide with the writer\'s own media, rel ids or drawing ids', function (): void {
    // The template's logo is stored as word/media/image1.png with drawing id 7:
    // the writer's first image takes the same name.
    $doc = lhDoc();
    $own = Letterhead::png(4, 4, [200, 0, 0]);
    $doc['blocks'][] = ['type' => 'image', 'src' => 'data:image/png;base64,' . base64_encode($own), 'widthPx' => 40, 'heightPx' => 40];
    $doc['blocks'][] = ['type' => 'paragraph', 'runs' => [['text' => 'link', 'link' => 'https://example.org/']]];

    $parts = lhParts(Agent::toBytes($doc, ['template' => Letterhead::bytes(), 'templateParts' => LH_ALL]));

    expect($parts['word/media/image1.png'])->toBe($own);
    expect($parts['word/media/template1.png'])->toBe(Letterhead::logoPng());
    expect($parts['word/document.xml'])->toContain('<wp:docPr id="' . (Letterhead::LOGO_DRAWING_ID + 1) . '"');
    lhAssertConsistent($parts);
});

it('opens: the output reads back, the carried header as blocks with its logo', function (): void {
    $bytes = Agent::toBytes(lhDoc(), ['template' => Letterhead::bytes(), 'templateParts' => LH_ALL]);
    $read = Agent::read($bytes);

    expect($read['blocks'][0]['runs'][0]['text'])->toBe('Our quote');
    // last-word#6: the logo is read, not reported lost.
    expect(array_column($read['page']['header']['blocks'], 'type'))->toBe(['image', 'paragraph']);
    expect($read['page']['header']['blocks'][1]['runs'][0]['text'])->toContain(Letterhead::HEADER_TEXT);
    expect($read)->not->toHaveKey('unrepresented');
});

it('is deterministic with a letterhead', function (): void {
    $options = ['template' => Letterhead::bytes(), 'templateParts' => LH_ALL];

    expect(Agent::toBytes(lhDoc(), $options))->toBe(Agent::toBytes(lhDoc(), $options));
});

it('takes the section without the styles when styles is not named', function (): void {
    $parts = lhParts(Agent::toBytes(lhDoc(), ['template' => Letterhead::bytes(), 'templateParts' => ['headers']]));

    expect($parts['word/styles.xml'])->toContain('Calibri')
        ->and($parts['word/styles.xml'])->not->toContain(GeneratedTemplate::FONT);
    expect($parts)->toHaveKey('word/header1.xml')
        ->and($parts)->not->toHaveKey('word/footer1.xml');
    // `page` not named: the page is ours.
    expect(lhSectPr($parts))->toContain('<w:pgSz w:w="12240" w:h="15840"/>');
    lhAssertConsistent($parts);
});

// ─── Precedence ──────────────────────────────────────────────────────────

it('lets the document\'s own band replace the template\'s of that kind, and only that kind', function (): void {
    $parts = lhParts(Agent::toBytes(
        lhDoc(['header' => ['right' => 'Page {page}']]),
        ['template' => Letterhead::bytes(), 'templateParts' => LH_ALL],
    ));

    expect($parts['word/header1.xml'])->toContain('Page ')
        ->and($parts['word/header1.xml'])->not->toContain(Letterhead::HEADER_TEXT);
    // The header's logo goes with it; the template's footer stays.
    expect($parts)->not->toHaveKey('word/media/template1.png');
    expect($parts['word/footer1.xml'])->toContain(Letterhead::FOOTER_TEXT);
    expect(lhSectPr($parts))->toContain('<w:headerReference w:type="default" r:id="rIdHeader1"/>')
        ->and(lhSectPr($parts))->toContain('<w:footerReference w:type="default" r:id="rIdTplFooter1"/>');
    lhAssertConsistent($parts);
});

it('lets the document\'s size, orientation and each margin override the template\'s, field by field', function (): void {
    $parts = lhParts(Agent::toBytes(
        lhDoc(['size' => 'a4', 'margins' => ['top' => 36]]),
        ['template' => Letterhead::bytes(), 'templateParts' => LH_ALL],
    ));
    // A4 from the doc; top 36pt = 720 from the doc; the other margins and the
    // distances from the template.
    expect(lhSectPr($parts))->toContain('<w:pgSz w:w="11906" w:h="16838"/>')
        ->and(lhSectPr($parts))->toContain('<w:pgMar w:top="720" w:right="1000" w:bottom="1500" w:left="1100" w:header="400" w:footer="300" w:gutter="0"/>');

    $landscape = lhParts(Agent::toBytes(
        lhDoc(['orientation' => 'landscape']),
        ['template' => Letterhead::bytes(), 'templateParts' => LH_ALL],
    ));
    expect(lhSectPr($landscape))->toContain('<w:pgSz w:w="' . Letterhead::PAGE_H . '" w:h="' . Letterhead::PAGE_W . '" w:orient="landscape"/>');
});

// ─── First and even pages ───────────────────────────────────────────────

it('carries a first-page header with w:titlePg, and keeps the document\'s own band on page one', function (): void {
    $parts = lhParts(Agent::toBytes(
        lhDoc(['footer' => ['center' => '{page}']]),
        ['template' => Letterhead::bytes(['firstPage' => true]), 'templateParts' => LH_ALL],
    ));
    $sectPr = lhSectPr($parts);

    expect($parts['word/header2.xml'])->toContain(Letterhead::FIRST_HEADER_TEXT);
    expect($sectPr)->toContain('<w:headerReference w:type="first" r:id="rIdTplHeader2"/>')
        ->and($sectPr)->toContain('<w:titlePg/></w:sectPr>')
        // Our own footer is referenced for the first page too, so it is on every page.
        ->and($sectPr)->toContain('<w:footerReference w:type="first" r:id="rIdFooter1"/>');
    lhAssertConsistent($parts);
});

it('drops w:titlePg when the document replaces every part that needed it', function (): void {
    $parts = lhParts(Agent::toBytes(
        lhDoc(['header' => ['left' => 'Ours']]),
        ['template' => Letterhead::bytes(['firstPage' => true]), 'templateParts' => LH_ALL],
    ));

    expect(lhSectPr($parts))->not->toContain('titlePg');
    expect($parts)->not->toHaveKey('word/header2.xml');
});

it('carries an even-page header with the even/odd setting it needs', function (): void {
    $parts = lhParts(Agent::toBytes(lhDoc(), ['template' => Letterhead::bytes(['evenPage' => true]), 'templateParts' => LH_ALL]));

    expect(lhSectPr($parts))->toContain('<w:headerReference w:type="even" r:id="rIdTplHeader2"/>');
    expect($parts['word/header2.xml'])->toContain(Letterhead::EVEN_HEADER_TEXT);
    expect($parts['word/settings.xml'])->toContain('<w:evenAndOddHeaders/>')
        ->and($parts['word/settings.xml'])->not->toContain('displayBackgroundShape');
    lhAssertConsistent($parts);
});

// ─── Hostile templates ──────────────────────────────────────────────────

it('refuses a hostile letterhead rather than carrying it', function (array $variant): void {
    expect(fn () => Agent::toBytes(lhDoc(), ['template' => Letterhead::bytes($variant), 'templateParts' => LH_ALL]))
        ->toThrow(TemplateException::class);
})->with([
    'DOCTYPE in the header' => [['doctypeHeader' => true]],
    'DOCTYPE in the document' => [['doctypeDocument' => true]],
    'DOCTYPE in the header rels' => [['doctypeRels' => true]],
    'externally linked header image' => [['externalImage' => true]],
    'SVG logo' => [['svgLogo' => true]],
    'SVG renamed .png' => [['pngNamedSvg' => true]],
    'INCLUDEPICTURE field split across runs' => [['includePicture' => true]],
    'OLE object in the header' => [['oleObject' => true]],
    'VML image with a URL src' => [['vmlSrc' => true]],
    'relationship id the part does not declare' => [['undeclaredRel' => true]],
    'oversized header part' => [['oversizedHeader' => true]],
]);

it('refuses a DOCTYPE in the styles or theme even on the default styles-only path', function (array $variant): void {
    // The styles path predates templateParts and copied these parts unchecked.
    // A Word package never legitimately carries a DOCTYPE; an internal subset is
    // the entry point for entity expansion, so it is refused like every other part.
    expect(fn () => Agent::toBytes(lhDoc(), ['template' => Letterhead::bytes($variant)]))
        ->toThrow(TemplateException::class);
})->with([
    'DOCTYPE in styles.xml' => [['doctypeStyles' => true]],
    'DOCTYPE in theme1.xml' => [['doctypeTheme' => true]],
]);

it('refuses an unknown templateParts name rather than quietly dropping the letterhead', function (mixed $parts): void {
    expect(fn () => Agent::toBytes(lhDoc(), ['template' => Letterhead::bytes(), 'templateParts' => $parts]))
        ->toThrow(TemplateException::class);
})->with([
    'singular typo' => [['styles', 'header']],
    'not a list' => ['headers'],
    'empty' => [[]],
]);

// ─── Reader ─────────────────────────────────────────────────────────────

it('reports a header image it cannot represent, instead of dropping it silently', function (): void {
    // A GIF: outside the image block, so even the blocks form (last-word#6)
    // cannot hold it. A PNG logo is read; see BlocksBandTest.
    $read = Agent::read(Letterhead::bytes(['gifLogo' => true]));

    expect($read['unrepresented'])->toBe([['where' => '/page/header', 'kind' => 'image', 'count' => 1]]);
    expect(Agent::describe($read))->toContain('/page/header: 1 image');
});

it('reports nothing for its own text-only bands', function (): void {
    $read = Agent::read(Agent::toBytes(lhDoc(['header' => ['left' => 'A'], 'footer' => ['right' => '{page}']])));

    expect($read)->not->toHaveKey('unrepresented');
    expect(Agent::describe($read))->not->toContain('Not represented');
});

it('keeps the letterhead through read and write, the issue repro', function (): void {
    // last-word#5: read a .docx with a logo in its header, write it back. With
    // the original as the template, the output has header1.xml with the image.
    $letterhead = Letterhead::bytes();
    $doc = Agent::read($letterhead);
    // read() fills page.header / page.footer (the header as blocks since
    // last-word#6). Left in, they are the document's own bands and win (the
    // precedence rule), so a write-back onto the original drops them to let the
    // letterhead through byte for byte.
    expect($doc['page']['header']['blocks'][1]['runs'][0]['text'] ?? '')->toContain(Letterhead::HEADER_TEXT);
    unset($doc['unrepresented'], $doc['page']['header'], $doc['page']['footer']);

    $parts = lhParts(Agent::toBytes($doc, ['template' => $letterhead, 'templateParts' => LH_ALL]));

    expect($parts['word/header1.xml'])->toContain('r:embed="rId1"');
    expect($parts['word/media/template1.png'])->toBe(Letterhead::logoPng());
});
