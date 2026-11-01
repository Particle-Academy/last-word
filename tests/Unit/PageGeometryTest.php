<?php

declare(strict_types=1);

use LastWord\Agent;
use LastWord\Schema\Schema;
use LastWord\Tests\Support\Package;

/**
 * The page model a paged viewer needs (MOIC, 2026-11-01): every margin, and
 * the header and footer DISTANCES -- `w:pgMar`'s `header` / `footer`, how far
 * the header sits from the top edge and the footer from the bottom edge.
 *
 * The model keeps its rule that a value equal to the writer's default is not
 * surfaced (a side at 72pt, a distance at 36pt), so every document read before
 * this release reads the same. `Schema::pageMargins()` resolves a document's
 * page to all six numbers, defaults filled in, and is what a viewer should
 * call rather than re-deriving the defaults.
 */
function pgDoc(array $margins): array
{
    return ['page' => ['margins' => $margins], 'blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'Body']]]]];
}

it('publishes the header and footer distances under page.margins', function () {
    $page = Agent::jsonSchema()['properties']['page']['properties'];
    $margins = Agent::jsonSchema()['$defs'][substr($page['margins']['$ref'], strlen('#/$defs/'))];

    expect(array_keys($margins['properties']))->toBe(['top', 'right', 'bottom', 'left', 'header', 'footer'])
        ->and($margins['properties']['header']['minimum'])->toBe(0)
        ->and($margins['properties']['footer']['minimum'])->toBe(0);
});

it('writes the distances into w:pgMar', function () {
    $xml = Package::parts(Agent::toBytes(pgDoc(['bottom' => 54, 'header' => 18, 'footer' => 27])))['word/document.xml'];

    expect($xml)->toContain('<w:pgMar w:top="1440" w:right="1440" w:bottom="1080" w:left="1440" w:header="360" w:footer="540" w:gutter="0"/>');
});

it('reads a non-default bottom margin and both distances back', function () {
    $doc = pgDoc(['bottom' => 54, 'header' => 18, 'footer' => 27]);

    expect(Agent::read(Agent::toBytes($doc))['page'])->toBe(['margins' => ['bottom' => 54, 'header' => 18, 'footer' => 27]]);
});

it('reads every side and both distances when none is the default', function () {
    $margins = ['top' => 90, 'left' => 63, 'bottom' => 81, 'right' => 45, 'header' => 22.5, 'footer' => 40];

    expect(Agent::read(Agent::toBytes(pgDoc($margins)))['page']['margins'])->toBe($margins);
});

it('omits defaults exactly as before, so existing documents read unchanged', function () {
    $read = Agent::read(Agent::toBytes(pgDoc(['top' => 72, 'bottom' => 72, 'header' => 36, 'footer' => 36])));

    expect($read)->not->toHaveKey('page');
});

it('resolves every margin and both distances for a viewer, defaults included', function () {
    expect(Schema::pageMargins(['blocks' => []]))->toBe(['top' => 72.0, 'right' => 72.0, 'bottom' => 72.0, 'left' => 72.0, 'header' => 36.0, 'footer' => 36.0])
        ->and(Schema::pageMargins(Agent::read(Agent::toBytes(pgDoc(['bottom' => 54, 'footer' => 27])))))
        ->toBe(['top' => 72.0, 'right' => 72.0, 'bottom' => 54.0, 'left' => 72.0, 'header' => 36.0, 'footer' => 27.0]);
});

it('refuses a distance that is not a non-negative number', function (mixed $value) {
    expect(array_column(Agent::validate(pgDoc(['header' => $value])), 'path'))->toContain('page.margins.header');
})->with([[-1], ['18pt']]);

it('lets the document own a distance a template would otherwise set', function () {
    // Without a document value the template's (or the 720-twip default) stands:
    // pinned by the existing letterhead tests. With one, the document wins,
    // field by field, as it already did for the four sides.
    $xml = Package::parts(Agent::toBytes(pgDoc(['footer' => 12])))['word/document.xml'];

    expect($xml)->toContain('w:header="720" w:footer="240"');
});
