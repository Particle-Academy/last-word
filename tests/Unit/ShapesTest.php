<?php

declare(strict_types=1);

use LastWord\Agent;
use LastWord\Tests\Support\Package;

/**
 * Text boxes and shapes: the Word parity rows "text box" and "shapes
 * (rectangle, line, callout)".
 *
 *   - a `shape` block is a DrawingML shape, `wps:wsp`, in a `w:drawing`:
 *     `wp:inline` without an `anchor`, `wp:anchor` with one;
 *   - `geometry` is `a:prstGeom/@prst`, `fill` an `a:solidFill`, `outline` an
 *     `a:ln`;
 *   - `blocks` make it a text box: `wps:txbx` holding `w:txbxContent`, the
 *     body's own blocks;
 *   - `anchor` is `wp:positionH` / `wp:positionV` and one of the `wp:wrap*`
 *     elements, `behindDoc` for behind and in front of the text;
 *   - read back from `wps:wsp`, and from VML (`w:pict`), including the
 *     fallback of an `mc:AlternateContent`.
 *
 * Done means a ROUND TRIP: written, read back, the same model, and the read
 * model written again gives the same bytes. Every value below is a literal, so
 * this file loads, and fails by assertion, without the feature.
 */

/** @return array{type: string, runs: list<array{text: string}>} */
function shpP(string $text): array
{
    return ['type' => 'paragraph', 'runs' => [['text' => $text]]];
}

/** A text box holding one paragraph. */
function shpBox(string $text, array $extra = []): array
{
    return ['type' => 'shape', 'widthPx' => 240, 'heightPx' => 96] + $extra + ['blocks' => [shpP($text)]];
}

/** A valid document's bytes; an invalid one fails here, by assertion, before toBytes() can throw. */
function shpBytes(array $doc): string
{
    expect(Agent::validate($doc))->toBe([]);

    return Agent::toBytes($doc);
}

/** word/document.xml of a valid document, after checking the package is consistent. */
function shpXml(array $doc): string
{
    $parts = Package::parts(shpBytes($doc));
    Package::assertConsistent($parts);

    return $parts['word/document.xml'];
}

/** @return list<string> every `code` validate() gives the document, in order */
function shpCodes(array $doc): array
{
    return array_values(array_filter(array_map(static fn (array $e): ?string => $e['code'] ?? null, Agent::validate($doc))));
}

/** The read model without the read's own signals: what a round trip compares. */
function shpModel(array $read): array
{
    unset($read['unrepresented'], $read['trackedChanges'], $read['sourceKind']);

    return $read;
}

/** @return array<string, int> unrepresented kind => count, at /blocks */
function shpReport(array $read): array
{
    $out = [];
    foreach ($read['unrepresented'] ?? [] as $note) {
        $out[$note['kind']] = ($out[$note['kind']] ?? 0) + $note['count'];
    }
    ksort($out);

    return $out;
}

/** Every run, code and footnote text a model holds, in reading order: what a degraded read or repair must keep. */
function shpTexts(array $blocks): array
{
    $out = [];
    $runs = static function (array $runs) use (&$out, &$walk): void {
        foreach ($runs as $r) {
            if (($r['text'] ?? '') !== '') {
                $out[] = $r['text'];
            }
            if (isset($r['footnote']['blocks'])) {
                $walk($r['footnote']['blocks']);
            }
        }
    };
    $items = static function (array $list) use (&$items, $runs): void {
        foreach ($list as $i) {
            $runs($i['runs'] ?? []);
            $items($i['children'] ?? []);
        }
    };
    $walk = static function (array $blocks) use (&$walk, $runs, $items, &$out): void {
        foreach ($blocks as $b) {
            $runs($b['runs'] ?? []);
            if (is_string($b['text'] ?? null)) {
                $out[] = $b['text'];
            }
            $items($b['items'] ?? []);
            foreach ($b['rows'] ?? [] as $row) {
                foreach ($row['cells'] as $cell) {
                    $walk($cell['blocks']);
                }
            }
            if (is_string($b['blocks'] ?? null)) {
                $out[] = $b['blocks']; // a text box's text typed as a string
            }
            $walk(is_array($b['blocks'] ?? null) ? $b['blocks'] : []);
        }
    };
    $walk($blocks);

    return $out;
}

/** @return list<array<string, mixed>> every shape in the blocks, at any depth */
function shpShapes(array $blocks): array
{
    $out = [];
    foreach ($blocks as $b) {
        if (($b['type'] ?? null) === 'shape') {
            $out[] = $b;
        }
        foreach ($b['rows'] ?? [] as $row) {
            foreach ($row['cells'] as $cell) {
                array_push($out, ...shpShapes($cell['blocks']));
            }
        }
        array_push($out, ...shpShapes($b['blocks'] ?? []));
    }

    return $out;
}

const SHP_NS = ' xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"'
    . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"'
    . ' xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"'
    . ' xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"'
    . ' xmlns:wps="http://schemas.microsoft.com/office/word/2010/wordprocessingShape"'
    . ' xmlns:wp14="http://schemas.microsoft.com/office/word/2010/wordprocessingDrawing"'
    . ' xmlns:wpg="http://schemas.microsoft.com/office/word/2010/wordprocessingGroup"'
    . ' xmlns:mc="http://schemas.openxmlformats.org/markup-compatibility/2006"'
    . ' xmlns:v="urn:schemas-microsoft-com:vml"'
    . ' xmlns:o="urn:schemas-microsoft-com:office:office"'
    . ' xmlns:w10="urn:schemas-microsoft-com:office:word"';

/**
 * A .docx whose word/document.xml body is `$bodyXml` (a w:sectPr is added): the
 * given document written, with that one part swapped, so every other part --
 * footnotes included, when the document has them -- is a real one.
 */
function shpDocx(string $bodyXml, ?array $base = null): string
{
    $bytes = Agent::toBytes($base ?? ['blocks' => [shpP('x')]]);
    $path = lwTemp('lw-shp', '.docx');
    file_put_contents($path, $bytes);
    $zip = new ZipArchive();
    expect($zip->open($path))->toBeTrue();
    $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:document' . SHP_NS . '><w:body>' . $bodyXml . '<w:sectPr/></w:body></w:document>');
    $zip->close();
    $out = (string) file_get_contents($path);
    @unlink($path);

    return $out;
}

function shpRun(string $text): string
{
    return '<w:r><w:t xml:space="preserve">' . $text . '</w:t></w:r>';
}

function shpPara(string $text): string
{
    return '<w:p>' . shpRun($text) . '</w:p>';
}

/**
 * One `wps:wsp` text box in a `wp:anchor` or `wp:inline` frame, as Word writes
 * it; every part replaceable, for the hostile reads.
 */
function shpWsp(string $content, array $o = []): string
{
    $o += [
        'frame' => 'inline', 'cx' => '2286000', 'cy' => '914400', 'prst' => 'rect', 'geom' => null,
        'fill' => '<a:solidFill><a:srgbClr val="FFFFFF"/></a:solidFill>',
        'ln' => '<a:ln w="9525"><a:solidFill><a:srgbClr val="000000"/></a:solidFill></a:ln>',
        'position' => '<wp:positionH relativeFrom="column"><wp:posOffset>0</wp:posOffset></wp:positionH><wp:positionV relativeFrom="paragraph"><wp:posOffset>0</wp:posOffset></wp:positionV>',
        'wrap' => '<wp:wrapSquare wrapText="bothSides"/>', 'behind' => '0', 'xfrm' => '', 'txbx' => true,
    ];
    $geom = $o['geom'] ?? '<a:prstGeom prst="' . $o['prst'] . '"><a:avLst/></a:prstGeom>';
    $wsp = '<wps:wsp><wps:cNvSpPr txBox="1"/><wps:spPr><a:xfrm' . $o['xfrm'] . '><a:off x="0" y="0"/><a:ext cx="' . $o['cx'] . '" cy="' . $o['cy'] . '"/></a:xfrm>'
        . $geom . $o['fill'] . $o['ln'] . '</wps:spPr>'
        . ($o['txbx'] ? '<wps:txbx><w:txbxContent>' . $content . '</w:txbxContent></wps:txbx>' : '')
        . '<wps:bodyPr/></wps:wsp>';
    $graphic = '<wp:docPr id="7" name="Text Box 7"/><wp:cNvGraphicFramePr/><a:graphic><a:graphicData uri="http://schemas.microsoft.com/office/word/2010/wordprocessingShape">' . $wsp . '</a:graphicData></a:graphic>';
    $extent = '<wp:extent cx="' . $o['cx'] . '" cy="' . $o['cy'] . '"/><wp:effectExtent l="0" t="0" r="0" b="0"/>';
    if ($o['frame'] === 'inline') {
        return '<w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0">' . $extent . $graphic . '</wp:inline></w:drawing>';
    }

    return '<w:drawing><wp:anchor distT="0" distB="0" distL="114300" distR="114300" simplePos="0" relativeHeight="251659264" behindDoc="' . $o['behind'] . '" locked="0" layoutInCell="1" allowOverlap="1">'
        . '<wp:simplePos x="0" y="0"/>' . $o['position'] . $extent . $o['wrap'] . $graphic . '</wp:anchor></w:drawing>';
}

/** A paragraph holding one run holding `$inner`. */
function shpInRun(string $inner): string
{
    return '<w:p><w:r>' . $inner . '</w:r></w:p>';
}

/** Word's own form: the DrawingML text box, and the same box again in VML as the fallback. */
function shpWordBox(string $text, string $requires = 'wps'): string
{
    return '<mc:AlternateContent><mc:Choice Requires="' . $requires . '">' . shpWsp(shpPara($text)) . '</mc:Choice>'
        . '<mc:Fallback><w:pict><v:rect style="width:180pt;height:72pt" fillcolor="#FFFFFF"><v:textbox><w:txbxContent>' . shpPara($text) . '</w:txbxContent></v:textbox></v:rect></w:pict></mc:Fallback></mc:AlternateContent>';
}

/* ---------------- the schema ---------------- */

describe('the schema', function () {
    it('offers a shape block, a closed anchor and a closed outline', function () {
        $s = Agent::jsonSchema();
        $shape = null;
        foreach ($s['$defs']['block']['oneOf'] as $b) {
            if (($b['properties']['type']['const'] ?? null) === 'shape') {
                $shape = $b;
            }
        }
        $anchor = $s['$defs']['anchor'] ?? [];

        expect($shape['required'] ?? null)->toBe(['type', 'widthPx', 'heightPx'])
            ->and($shape['properties']['geometry']['enum'] ?? [])->toContain('rect', 'roundRect', 'ellipse', 'line', 'rightArrow', 'wedgeRectCallout')
            ->and($shape['properties']['outline']['additionalProperties'] ?? null)->toBeFalse()
            ->and(array_keys($shape['properties']['outline']['properties'] ?? []))->toBe(['color', 'width', 'style', 'headEnd', 'tailEnd'])
            ->and($shape['properties']['anchor'] ?? null)->toBe(['$ref' => '#/$defs/anchor'])
            ->and($anchor['additionalProperties'] ?? null)->toBeFalse()
            ->and(array_keys($anchor['properties'] ?? []))->toBe(['horizontal', 'vertical', 'wrap', 'allowOverlap'])
            ->and($anchor['properties']['wrap']['enum'] ?? null)->toBe(['square', 'tight', 'through', 'topAndBottom', 'behind', 'inFront'])
            ->and($anchor['properties']['horizontal']['properties']['relativeTo']['enum'] ?? null)->toBe(['page', 'margin', 'column', 'character'])
            ->and($anchor['properties']['vertical']['properties']['relativeTo']['enum'] ?? null)->toBe(['page', 'margin', 'paragraph', 'line'])
            ->and($anchor['properties']['horizontal']['properties']['offset']['maximum'] ?? null)->toBe(1584);
    });

    it('keeps shapes out of headers, footers and footnotes in the schema too', function () {
        $s = Agent::jsonSchema();
        $refused = static fn (array $def): array => $def['properties']['blocks']['items']['allOf'][1]['not']['properties']['type']['enum'] ?? [];

        expect($refused($s['$defs']['blocksBand']))->toContain('shape')
            ->and($refused($s['$defs']['footnote']))->toContain('shape');
    });
});

/* ---------------- writing ---------------- */

describe('writing', function () {
    it('writes an inline text box as wps:wsp in wp:inline, its blocks in w:txbxContent', function () {
        $xml = shpXml(['blocks' => [shpBox('Inside the box.')]]);

        expect($xml)->toContain('<wp:inline distT="0" distB="0" distL="0" distR="0"><wp:extent cx="2286000" cy="914400"/>')
            ->and($xml)->toContain('<a:graphicData uri="http://schemas.microsoft.com/office/word/2010/wordprocessingShape"><wps:wsp xmlns:wps="http://schemas.microsoft.com/office/word/2010/wordprocessingShape"><wps:cNvSpPr txBox="1"/>')
            ->and($xml)->toContain('<a:prstGeom prst="rect"><a:avLst/></a:prstGeom><a:noFill/><a:ln w="9525"><a:solidFill><a:srgbClr val="000000"/></a:solidFill></a:ln>')
            ->and($xml)->toContain('<wps:txbx><w:txbxContent><w:p><w:r><w:t xml:space="preserve">Inside the box.</w:t></w:r></w:p></w:txbxContent></wps:txbx>')
            ->and($xml)->toContain('<wp:docPr id="20001" name="Text Box 1"/>')
            ->and($xml)->not->toContain('wp:anchor')
            ->and($xml)->not->toContain('mc:AlternateContent');
    });

    it('writes a plain shape with no text box, and a line with arrowheads', function () {
        $xml = shpXml(['blocks' => [
            ['type' => 'shape', 'geometry' => 'ellipse', 'widthPx' => 96, 'heightPx' => 96, 'fill' => '#1F6FEB', 'outline' => ['style' => 'none']],
            ['type' => 'shape', 'geometry' => 'line', 'widthPx' => 200, 'heightPx' => 0, 'flipV' => true, 'outline' => ['color' => '#C00000', 'width' => 2, 'style' => 'dashed', 'headEnd' => 'oval', 'tailEnd' => 'triangle']],
        ]]);

        expect($xml)->toContain('<wps:cNvSpPr/><wps:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="914400" cy="914400"/></a:xfrm><a:prstGeom prst="ellipse"><a:avLst/></a:prstGeom><a:solidFill><a:srgbClr val="1F6FEB"/></a:solidFill><a:ln><a:noFill/></a:ln></wps:spPr><wps:bodyPr/></wps:wsp>')
            ->and($xml)->toContain('<a:xfrm flipV="1"><a:off x="0" y="0"/><a:ext cx="1905000" cy="0"/></a:xfrm><a:prstGeom prst="line"><a:avLst/></a:prstGeom><a:noFill/><a:ln w="25400"><a:solidFill><a:srgbClr val="C00000"/></a:solidFill><a:prstDash val="dash"/><a:headEnd type="oval"/><a:tailEnd type="triangle"/></a:ln>')
            ->and($xml)->toContain('name="Shape 1"')
            ->and($xml)->toContain('name="Shape 2"')
            ->and($xml)->not->toContain('w:txbxContent');
    });

    it('writes an anchor as wp:anchor: the positions, then the extent, then the wrap', function (array $anchor, string $expected) {
        $xml = shpXml(['blocks' => [shpP('Body text.'), shpBox('Floating.', ['anchor' => $anchor])]]);

        expect($xml)->toContain($expected);
    })->with([
        'margin right, below the paragraph' => [
            ['horizontal' => ['relativeTo' => 'margin', 'align' => 'right'], 'vertical' => ['relativeTo' => 'paragraph', 'offset' => 36], 'wrap' => 'square'],
            '<wp:anchor distT="0" distB="0" distL="114300" distR="114300" simplePos="0" relativeHeight="1" behindDoc="0" locked="0" layoutInCell="1" allowOverlap="1"><wp:simplePos x="0" y="0"/><wp:positionH relativeFrom="margin"><wp:align>right</wp:align></wp:positionH><wp:positionV relativeFrom="paragraph"><wp:posOffset>457200</wp:posOffset></wp:positionV><wp:extent cx="2286000" cy="914400"/><wp:effectExtent l="0" t="0" r="0" b="0"/><wp:wrapSquare wrapText="bothSides"/><wp:docPr',
        ],
        'the defaults' => [
            [],
            '<wp:positionH relativeFrom="column"><wp:posOffset>0</wp:posOffset></wp:positionH><wp:positionV relativeFrom="paragraph"><wp:posOffset>0</wp:posOffset></wp:positionV><wp:extent cx="2286000" cy="914400"/><wp:effectExtent l="0" t="0" r="0" b="0"/><wp:wrapSquare wrapText="bothSides"/>',
        ],
        'behind the text, past the page edge' => [
            ['horizontal' => ['relativeTo' => 'page', 'offset' => -18], 'vertical' => ['relativeTo' => 'page', 'offset' => 0], 'wrap' => 'behind'],
            'distL="0" distR="0" simplePos="0" relativeHeight="1" behindDoc="1" locked="0" layoutInCell="1" allowOverlap="1"><wp:simplePos x="0" y="0"/><wp:positionH relativeFrom="page"><wp:posOffset>-228600</wp:posOffset></wp:positionH><wp:positionV relativeFrom="page"><wp:posOffset>0</wp:posOffset></wp:positionV><wp:extent cx="2286000" cy="914400"/><wp:effectExtent l="0" t="0" r="0" b="0"/><wp:wrapNone/>',
        ],
        'in front of the text' => [
            ['wrap' => 'inFront'],
            'behindDoc="0" locked="0" layoutInCell="1" allowOverlap="1"><wp:simplePos x="0" y="0"/><wp:positionH relativeFrom="column"><wp:posOffset>0</wp:posOffset></wp:positionH><wp:positionV relativeFrom="paragraph"><wp:posOffset>0</wp:posOffset></wp:positionV><wp:extent cx="2286000" cy="914400"/><wp:effectExtent l="0" t="0" r="0" b="0"/><wp:wrapNone/>',
        ],
        'top and bottom, from the line' => [
            ['vertical' => ['relativeTo' => 'line', 'align' => 'top'], 'wrap' => 'topAndBottom'],
            '<wp:positionV relativeFrom="line"><wp:align>top</wp:align></wp:positionV><wp:extent cx="2286000" cy="914400"/><wp:effectExtent l="0" t="0" r="0" b="0"/><wp:wrapTopAndBottom/>',
        ],
        'tight, from the character' => [
            ['horizontal' => ['relativeTo' => 'character', 'offset' => 4.5], 'wrap' => 'tight'],
            '<wp:positionH relativeFrom="character"><wp:posOffset>57150</wp:posOffset></wp:positionH>',
        ],
        'tight carries the rectangle as its polygon' => [
            ['wrap' => 'tight'],
            '<wp:wrapTight wrapText="bothSides"><wp:wrapPolygon edited="0"><wp:start x="0" y="0"/><wp:lineTo x="0" y="21600"/><wp:lineTo x="21600" y="21600"/><wp:lineTo x="21600" y="0"/><wp:lineTo x="0" y="0"/></wp:wrapPolygon></wp:wrapTight>',
        ],
        'no overlap' => [
            ['allowOverlap' => false],
            'behindDoc="0" locked="0" layoutInCell="1" allowOverlap="0">',
        ],
        'through' => [
            ['wrap' => 'through'],
            '<wp:wrapThrough wrapText="bothSides"><wp:wrapPolygon edited="0">',
        ],
    ]);

    it('stacks floating shapes in document order, and numbers drawing ids apart from pictures and charts', function () {
        $parts = Package::parts(shpBytes(['blocks' => [
            ['type' => 'image', 'src' => lwRedPngDataUrl()],
            shpBox('One', ['anchor' => ['wrap' => 'inFront']]),
            shpBox('Two', ['anchor' => ['wrap' => 'inFront']]),
        ]]));
        Package::assertConsistent($parts);
        $xml = $parts['word/document.xml'];

        expect($xml)->toContain('<wp:docPr id="1" name="Picture 1"/>')
            ->and($xml)->toContain('relativeHeight="1"')
            ->and($xml)->toContain('relativeHeight="2"')
            ->and($xml)->toContain('<wp:docPr id="20001" name="Text Box 1"/>')
            ->and($xml)->toContain('<wp:docPr id="20002" name="Text Box 2"/>');
    });

    it('writes alt text, vertical alignment and the fill as given', function () {
        $xml = shpXml(['blocks' => [shpBox('Callout', ['geometry' => 'wedgeRectCallout', 'fill' => '#fff4d6', 'verticalAlign' => 'middle', 'alt' => 'A callout about "Q3" & more'])]]);

        expect($xml)->toContain('<wp:docPr id="20001" name="Text Box 1" descr="A callout about &quot;Q3&quot; &amp; more"/>')
            ->and($xml)->toContain('<a:prstGeom prst="wedgeRectCallout"><a:avLst/></a:prstGeom><a:solidFill><a:srgbClr val="FFF4D6"/></a:solidFill>')
            ->and($xml)->toContain('anchor="ctr" anchorCtr="0"><a:noAutofit/></wps:bodyPr>');
    });

    it('does not list a heading inside a text box in the table of contents', function () {
        $xml = shpXml(['blocks' => [
            ['type' => 'toc'],
            ['type' => 'heading', 'level' => 1, 'runs' => [['text' => 'Real heading']]],
            ['type' => 'shape', 'widthPx' => 240, 'heightPx' => 96, 'blocks' => [['type' => 'heading', 'level' => 1, 'runs' => [['text' => 'Boxed heading']]]]],
        ]]);

        expect(substr_count($xml, 'w:name="_Toc'))->toBe(1)
            ->and($xml)->toContain('Boxed heading');
    });

    it('closes a text box that ends in a table with a paragraph, and writes the same bytes twice', function () {
        $doc = ['blocks' => [['type' => 'shape', 'widthPx' => 240, 'heightPx' => 96, 'blocks' => [
            ['type' => 'table', 'rows' => [['cells' => [['blocks' => [shpP('a')]], ['blocks' => [shpP('b')]]]]]],
        ]]]];
        $xml = shpXml($doc);

        expect($xml)->toContain('</w:tbl><w:p/></w:txbxContent>')
            ->and(shpBytes($doc))->toBe(shpBytes($doc));
    });
});

/* ---------------- round trip ---------------- */

describe('round trip', function () {
    it('reads back the model it wrote, and the read model writes the same bytes', function (array $doc) {
        $bytes = shpBytes($doc);
        $read = Agent::read($bytes);

        expect($read['unrepresented'] ?? [])->toBe([])
            ->and(shpModel($read))->toBe($doc)
            ->and(shpBytes(shpModel($read)))->toBe($bytes);
    })->with([
        'an inline text box' => [['blocks' => [
            shpP('Before.'),
            ['type' => 'shape', 'widthPx' => 240, 'heightPx' => 96, 'blocks' => [shpP('Inside the box.')]],
            shpP('After.'),
        ]]],
        'a floating sidebar, in full' => [['blocks' => [
            shpP('Body text the sidebar sits beside.'),
            ['type' => 'shape', 'geometry' => 'roundRect', 'widthPx' => 192, 'heightPx' => 288, 'fill' => '#F3F6FA', 'outline' => ['color' => '#1F6FEB', 'width' => 1.5],
                'anchor' => ['horizontal' => ['relativeTo' => 'margin', 'align' => 'right'], 'vertical' => ['relativeTo' => 'paragraph', 'offset' => 0], 'wrap' => 'square', 'allowOverlap' => true],
                'verticalAlign' => 'middle', 'alt' => 'Key figures',
                'blocks' => [
                    ['type' => 'heading', 'level' => 3, 'runs' => [['text' => 'Key figures']]],
                    ['type' => 'list', 'items' => [['runs' => [['text' => 'Revenue up 18%']]], ['runs' => [['text' => 'Margin 31%', 'bold' => true]]]]],
                ]],
        ]]],
        'a full-width band behind the text, past the page edge' => [['blocks' => [
            ['type' => 'shape', 'widthPx' => 816, 'heightPx' => 96, 'fill' => '#0B1F3A', 'outline' => ['style' => 'none'],
                'anchor' => ['horizontal' => ['relativeTo' => 'page', 'offset' => -18], 'vertical' => ['relativeTo' => 'page', 'offset' => 0], 'wrap' => 'behind', 'allowOverlap' => true]],
            shpP('Title over the band.'),
        ]]],
        'every wrap' => [['blocks' => [
            shpBox('square', ['anchor' => ['horizontal' => ['relativeTo' => 'column', 'offset' => 12], 'vertical' => ['relativeTo' => 'paragraph', 'offset' => 6], 'wrap' => 'square', 'allowOverlap' => true]]),
            shpBox('tight', ['anchor' => ['horizontal' => ['relativeTo' => 'character', 'offset' => 0], 'vertical' => ['relativeTo' => 'line', 'offset' => 0], 'wrap' => 'tight', 'allowOverlap' => true]]),
            shpBox('through', ['anchor' => ['horizontal' => ['relativeTo' => 'page', 'align' => 'center'], 'vertical' => ['relativeTo' => 'margin', 'align' => 'bottom'], 'wrap' => 'through', 'allowOverlap' => true]]),
            shpBox('top and bottom', ['anchor' => ['horizontal' => ['relativeTo' => 'margin', 'align' => 'inside'], 'vertical' => ['relativeTo' => 'page', 'align' => 'outside'], 'wrap' => 'topAndBottom', 'allowOverlap' => true]]),
            shpBox('behind', ['anchor' => ['horizontal' => ['relativeTo' => 'margin', 'offset' => -36.5], 'vertical' => ['relativeTo' => 'margin', 'offset' => 1584], 'wrap' => 'behind', 'allowOverlap' => false]]),
            shpBox('inFront', ['anchor' => ['horizontal' => ['relativeTo' => 'margin', 'align' => 'left'], 'vertical' => ['relativeTo' => 'line', 'align' => 'center'], 'wrap' => 'inFront', 'allowOverlap' => true]]),
        ]]],
        'plain shapes, every outline' => [['blocks' => [
            ['type' => 'shape', 'geometry' => 'ellipse', 'widthPx' => 96, 'heightPx' => 64, 'fill' => '#1F6FEB'],
            ['type' => 'shape', 'geometry' => 'star5', 'widthPx' => 96, 'heightPx' => 96, 'outline' => ['color' => '#B7791F', 'width' => 3, 'style' => 'dotted']],
            ['type' => 'shape', 'geometry' => 'rightArrow', 'widthPx' => 144, 'heightPx' => 48, 'fill' => '#E6F4EA', 'outline' => ['style' => 'dashed']],
            ['type' => 'shape', 'geometry' => 'diamond', 'widthPx' => 48.5, 'heightPx' => 100.25, 'fill' => '#000000', 'outline' => ['style' => 'none']],
            ['type' => 'shape', 'geometry' => 'flowChartDecision', 'widthPx' => 120, 'heightPx' => 80, 'flipH' => true],
        ]]],
        'lines and connectors' => [['blocks' => [
            ['type' => 'shape', 'geometry' => 'line', 'widthPx' => 624, 'heightPx' => 0, 'outline' => ['color' => '#C00000', 'width' => 2]],
            ['type' => 'shape', 'geometry' => 'line', 'widthPx' => 0, 'heightPx' => 120],
            ['type' => 'shape', 'geometry' => 'straightConnector1', 'widthPx' => 200, 'heightPx' => 80, 'outline' => ['headEnd' => 'oval', 'tailEnd' => 'stealth'], 'flipV' => true],
        ]]],
        'callouts and pull quotes' => [['blocks' => [
            ['type' => 'shape', 'geometry' => 'wedgeRoundRectCallout', 'widthPx' => 220, 'heightPx' => 90, 'fill' => '#FFF4D6', 'verticalAlign' => 'bottom', 'blocks' => [shpP('Note the dip in March.')]],
            ['type' => 'shape', 'widthPx' => 480, 'heightPx' => 72, 'outline' => ['style' => 'none'],
                'anchor' => ['horizontal' => ['relativeTo' => 'margin', 'align' => 'center'], 'vertical' => ['relativeTo' => 'paragraph', 'offset' => 12], 'wrap' => 'topAndBottom', 'allowOverlap' => true],
                'blocks' => [['type' => 'paragraph', 'runs' => [['text' => '"We shipped it in a week."', 'italic' => true, 'size' => 16.0]], 'align' => 'center']]],
        ]]],
        'a text box holding the body vocabulary' => [['blocks' => [
            ['type' => 'shape', 'widthPx' => 400, 'heightPx' => 400, 'blocks' => [
                ['type' => 'heading', 'level' => 2, 'runs' => [['text' => 'Boxed']]],
                ['type' => 'paragraph', 'runs' => [['text' => 'See '], ['text' => 'the site', 'link' => 'https://example.com/'], ['text' => ', page '], ['text' => '1', 'field' => '{page}']]],
                ['type' => 'list', 'ordered' => true, 'items' => [['runs' => [['text' => 'one']], 'children' => [['runs' => [['text' => 'one.a']]]]], ['runs' => [['text' => 'two']]]]],
                ['type' => 'code', 'language' => 'php', 'text' => "echo 1;\necho 2;"],
                ['type' => 'quote', 'blocks' => [shpP('Quoted in a box.')]],
                ['type' => 'image', 'src' => lwRedPngDataUrl(), 'widthPx' => 2, 'heightPx' => 2],
                ['type' => 'hr'],
            ]],
        ]]],
        'shapes in a table cell and in a quote' => [['blocks' => [
            ['type' => 'table', 'rows' => [['cells' => [
                ['blocks' => [shpBox('In a cell')]],
                ['blocks' => [shpP('beside')]],
            ]]]],
            ['type' => 'quote', 'blocks' => [shpP('Quoted.'), ['type' => 'shape', 'geometry' => 'ellipse', 'widthPx' => 24, 'heightPx' => 24, 'fill' => '#1F6FEB']]],
        ]]],
        'an empty text box' => [['blocks' => [['type' => 'shape', 'widthPx' => 100, 'heightPx' => 50, 'blocks' => []]]]],
    ]);

    it('reads a partial anchor back in full, and that writes the same bytes', function () {
        $bytes = shpBytes(['blocks' => [shpBox('Float', ['anchor' => ['wrap' => 'inFront', 'allowOverlap' => true]])]]);
        $read = Agent::read($bytes);

        expect($read['blocks'][0]['anchor'] ?? null)->toBe(['horizontal' => ['relativeTo' => 'column', 'offset' => 0], 'vertical' => ['relativeTo' => 'paragraph', 'offset' => 0], 'wrap' => 'inFront', 'allowOverlap' => true])
            ->and(shpBytes(shpModel($read)))->toBe($bytes);
    });

    it('reads every preset geometry back as itself', function (string $geometry) {
        $doc = ['blocks' => [['type' => 'shape', 'geometry' => $geometry, 'widthPx' => 96, 'heightPx' => 48]]];

        expect(shpModel(Agent::read(shpBytes($doc))))->toBe($doc);
    })->with(['roundRect', 'ellipse', 'triangle', 'rtTriangle', 'diamond', 'parallelogram', 'trapezoid', 'pentagon', 'hexagon', 'octagon', 'plus',
        'star5', 'heart', 'cloud', 'can', 'cube', 'plaque', 'snip1Rect', 'round1Rect', 'line', 'straightConnector1',
        'rightArrow', 'leftArrow', 'upArrow', 'downArrow', 'leftRightArrow', 'upDownArrow', 'chevron', 'homePlate',
        'wedgeRectCallout', 'wedgeRoundRectCallout', 'wedgeEllipseCallout', 'cloudCallout',
        'flowChartProcess', 'flowChartDecision', 'flowChartTerminator', 'flowChartDocument', 'flowChartAlternateProcess', 'flowChartInputOutput']);
});

/* ---------------- validation: the hostile set ---------------- */

describe('validation', function () {
    it('refuses a shape it cannot write, with a code', function (array $shape, array $codes) {
        expect(shpCodes(['blocks' => [$shape]]))->toBe($codes);
    })->with([
        'an unknown preset' => [['type' => 'shape', 'geometry' => 'star32', 'widthPx' => 10, 'heightPx' => 10], ['shape_geometry_unknown']],
        'a geometry that is not a string' => [['type' => 'shape', 'geometry' => 5, 'widthPx' => 10, 'heightPx' => 10], ['shape_geometry_unknown']],
        'no width' => [['type' => 'shape', 'heightPx' => 10], ['shape_size_invalid']],
        'no size at all' => [['type' => 'shape'], ['shape_size_invalid', 'shape_size_invalid']],
        'a negative width' => [['type' => 'shape', 'widthPx' => -1, 'heightPx' => 10], ['shape_size_invalid']],
        'a negative height' => [['type' => 'shape', 'widthPx' => 10, 'heightPx' => -0.5], ['shape_size_invalid']],
        'a huge width' => [['type' => 'shape', 'widthPx' => 2113, 'heightPx' => 10], ['shape_size_invalid']],
        'a billion px' => [['type' => 'shape', 'widthPx' => 1e9, 'heightPx' => 1e9], ['shape_size_invalid', 'shape_size_invalid']],
        'a string width' => [['type' => 'shape', 'widthPx' => '100', 'heightPx' => 10], ['shape_size_invalid']],
        'zero high, not a line' => [['type' => 'shape', 'widthPx' => 10, 'heightPx' => 0], ['shape_size_invalid']],
        'a line with no length' => [['type' => 'shape', 'geometry' => 'line', 'widthPx' => 0, 'heightPx' => 0], ['shape_size_invalid']],
        'a fill that is a name' => [['type' => 'shape', 'widthPx' => 10, 'heightPx' => 10, 'fill' => 'red'], ['shape_invalid']],
        'an outline that is a string' => [['type' => 'shape', 'widthPx' => 10, 'heightPx' => 10, 'outline' => 'thin'], ['shape_invalid']],
        'a zero outline width' => [['type' => 'shape', 'widthPx' => 10, 'heightPx' => 10, 'outline' => ['width' => 0]], ['shape_invalid']],
        'a huge outline width' => [['type' => 'shape', 'widthPx' => 10, 'heightPx' => 10, 'outline' => ['width' => 101]], ['shape_invalid']],
        'an unknown outline style' => [['type' => 'shape', 'widthPx' => 10, 'heightPx' => 10, 'outline' => ['style' => 'wavy']], ['shape_invalid']],
        'an unknown arrowhead' => [['type' => 'shape', 'geometry' => 'line', 'widthPx' => 10, 'heightPx' => 0, 'outline' => ['tailEnd' => 'big']], ['shape_invalid']],
        'an unknown outline key' => [['type' => 'shape', 'widthPx' => 10, 'heightPx' => 10, 'outline' => ['glow' => 1]], ['shape_invalid']],
        'a flip that is not a boolean' => [['type' => 'shape', 'widthPx' => 10, 'heightPx' => 10, 'flipH' => 'yes'], ['shape_invalid']],
        'alt that is not a string' => [['type' => 'shape', 'widthPx' => 10, 'heightPx' => 10, 'alt' => ['x']], ['shape_invalid']],
        'verticalAlign without blocks' => [['type' => 'shape', 'widthPx' => 10, 'heightPx' => 10, 'verticalAlign' => 'middle'], ['shape_invalid']],
        'an unknown verticalAlign' => [['type' => 'shape', 'widthPx' => 10, 'heightPx' => 10, 'verticalAlign' => 'center', 'blocks' => []], ['shape_invalid']],
        'blocks that are a string' => [['type' => 'shape', 'widthPx' => 10, 'heightPx' => 10, 'blocks' => 'text'], ['shape_invalid']],
        'a line holding text' => [['type' => 'shape', 'geometry' => 'line', 'widthPx' => 10, 'heightPx' => 0, 'blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'x']]]]], ['shape_text_on_line']],
    ]);

    it('refuses an anchor it cannot write, with a code', function (mixed $anchor, array $codes) {
        expect(shpCodes(['blocks' => [['type' => 'shape', 'widthPx' => 10, 'heightPx' => 10, 'anchor' => $anchor]]]))->toBe($codes);
    })->with([
        'a string' => ['left', ['anchor_invalid']],
        'a list' => [[1, 2], ['anchor_invalid']],
        'an unknown key' => [['zIndex' => 3], ['anchor_invalid']],
        'a horizontal from the paragraph' => [['horizontal' => ['relativeTo' => 'paragraph']], ['anchor_invalid']],
        'a vertical from the column' => [['vertical' => ['relativeTo' => 'column']], ['anchor_invalid']],
        'both offset and align' => [['horizontal' => ['offset' => 0, 'align' => 'left']], ['anchor_invalid']],
        'a vertical align of middle' => [['vertical' => ['align' => 'middle']], ['anchor_invalid']],
        'a vertical align from the paragraph, which Word cannot make' => [['vertical' => ['relativeTo' => 'paragraph', 'align' => 'top']], ['anchor_invalid']],
        'a vertical align from the default, the paragraph' => [['vertical' => ['align' => 'bottom']], ['anchor_invalid']],
        'an axis that is a number' => [['horizontal' => 12], ['anchor_invalid']],
        'an offset that is a string' => [['horizontal' => ['offset' => '12']], ['anchor_offset_invalid']],
        'an offset past 22 inches' => [['horizontal' => ['offset' => 1585]], ['anchor_offset_invalid']],
        'a huge negative offset' => [['vertical' => ['offset' => -99999999]], ['anchor_offset_invalid']],
        'an unknown wrap' => [['wrap' => 'inline'], ['anchor_wrap_invalid']],
        'a wrap that is not a string' => [['wrap' => true], ['anchor_wrap_invalid']],
        'the old spelling of in front' => [['wrap' => 'front'], ['anchor_wrap_invalid']],
        'allowOverlap that is not a boolean' => [['allowOverlap' => 'yes'], ['anchor_overlap_invalid']],
    ]);

    it('accepts the extremes it can write', function (array $shape) {
        expect(Agent::validate(['blocks' => [$shape]]))->toBe([]);
    })->with([
        '22 inches square' => [['type' => 'shape', 'widthPx' => 2112, 'heightPx' => 2112]],
        'offsets at the cap' => [['type' => 'shape', 'widthPx' => 10, 'heightPx' => 10, 'anchor' => ['horizontal' => ['relativeTo' => 'page', 'offset' => -1584], 'vertical' => ['relativeTo' => 'page', 'offset' => 1584]]]],
        'an empty anchor' => [['type' => 'shape', 'widthPx' => 10, 'heightPx' => 10, 'anchor' => []]],
        'a vertical line' => [['type' => 'shape', 'geometry' => 'line', 'widthPx' => 0, 'heightPx' => 10]],
        'an empty text box' => [['type' => 'shape', 'widthPx' => 10, 'heightPx' => 10, 'blocks' => []]],
    ]);

    it('refuses what a text box cannot hold', function (array $blocks, array $codes) {
        expect(shpCodes(['blocks' => [['type' => 'shape', 'widthPx' => 240, 'heightPx' => 96, 'blocks' => $blocks]]]))->toBe($codes);
    })->with([
        'a text box in a text box' => [[['type' => 'shape', 'widthPx' => 10, 'heightPx' => 10, 'blocks' => []]], ['shape_nested']],
        'a shape in a table in a text box' => [[['type' => 'table', 'rows' => [['cells' => [['blocks' => [['type' => 'shape', 'widthPx' => 10, 'heightPx' => 10]]]]]]]], ['shape_nested']],
        'a shape in a quote in a text box' => [[['type' => 'quote', 'blocks' => [['type' => 'shape', 'widthPx' => 10, 'heightPx' => 10]]]], ['shape_nested']],
        'a page break' => [[['type' => 'pageBreak']], ['shape_content_refused']],
        'a table of contents' => [[['type' => 'toc']], ['shape_content_refused']],
        'a column break' => [[['type' => 'columnBreak']], ['shape_content_refused']],
        'a chart' => [[['type' => 'chart', 'option' => ['series' => [['type' => 'pie', 'data' => [1, 2]]]]]], ['shape_content_refused']],
        'a footnote' => [[['type' => 'paragraph', 'runs' => [['text' => 'x'], ['text' => '', 'footnote' => ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'note']]]]]]]]], ['shape_content_refused']],
        'a bookmark' => [[['type' => 'paragraph', 'runs' => [['text' => 'x', 'bookmark' => 'boxed']]]], ['shape_content_refused']],
        'a run revision' => [[['type' => 'paragraph', 'runs' => [['text' => 'x', 'revision' => ['insert' => ['id' => 'r1', 'author' => 'Ann']]]]]], ['shape_content_refused']],
        'a paragraph revision' => [[['type' => 'paragraph', 'runs' => [['text' => 'x']], 'revision' => ['insert' => ['id' => 'r1', 'author' => 'Ann']]]], ['shape_content_refused']],
        'a list item revision' => [[['type' => 'list', 'items' => [['runs' => [['text' => 'x']], 'revision' => ['insert' => ['id' => 'r1', 'author' => 'Ann']]]]]], ['shape_content_refused']],
        'a comment' => [[['type' => 'paragraph', 'runs' => [['text' => 'x', 'comments' => ['c1']]]]], ['comment_outside_body']],
        '501 blocks' => [array_fill(0, 501, ['type' => 'paragraph', 'runs' => [['text' => 'x']]]), ['shape_content_too_large']],
        '32,769 characters' => [[['type' => 'paragraph', 'runs' => [['text' => str_repeat('a', 32769)]]]], ['shape_content_too_large']],
    ]);

    it('lets a link in a text box jump to a body bookmark, and refuses one to nothing', function () {
        $box = static fn (string $link): array => ['type' => 'shape', 'widthPx' => 240, 'heightPx' => 96, 'blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'see', 'link' => $link]]]]];
        $target = ['type' => 'heading', 'level' => 1, 'runs' => [['text' => 'Results', 'bookmark' => 'results']]];

        expect(Agent::validate(['blocks' => [$target, $box('#results')]]))->toBe([])
            ->and(shpCodes(['blocks' => [$target, $box('#nowhere')]]))->toBe(['bookmark_unknown']);
    });

    it('accepts 500 blocks and 32,768 characters in one text box', function () {
        $doc = ['blocks' => [
            ['type' => 'shape', 'widthPx' => 240, 'heightPx' => 96, 'blocks' => array_fill(0, 500, ['type' => 'paragraph', 'runs' => [['text' => 'x']]])],
            ['type' => 'shape', 'widthPx' => 240, 'heightPx' => 96, 'blocks' => [['type' => 'paragraph', 'runs' => [['text' => str_repeat('a', 32768)]]]]],
        ]];

        expect(Agent::validate($doc))->toBe([]);
    });

    it('refuses more than 1,000 shapes in a document, counting the ones in cells', function () {
        $shape = ['type' => 'shape', 'widthPx' => 4, 'heightPx' => 4];
        $doc = ['blocks' => [...array_fill(0, 999, $shape), ['type' => 'table', 'rows' => [['cells' => [['blocks' => [$shape, $shape]]]]]]]];

        expect(shpCodes($doc))->toBe(['shape_count_exceeded'])
            ->and(Agent::validate(['blocks' => array_fill(0, 1000, $shape)]))->toBe([]);
    });

    it('refuses a shape in a header, a footer and a footnote', function (array $doc, string $message) {
        expect(array_column(Agent::validate($doc), 'message'))->toContain($message);
    })->with([
        'a header' => [['page' => ['header' => ['blocks' => [['type' => 'shape', 'widthPx' => 10, 'heightPx' => 10]]]], 'blocks' => []], 'a header or footer cannot hold a shape block'],
        'a footer, in a table' => [['page' => ['footer' => ['blocks' => [['type' => 'table', 'rows' => [['cells' => [['blocks' => [['type' => 'shape', 'widthPx' => 10, 'heightPx' => 10]]]]]]]]]], 'blocks' => []], 'a header or footer cannot hold a shape block'],
        'a footnote' => [['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'x'], ['text' => '', 'footnote' => ['blocks' => [['type' => 'shape', 'widthPx' => 10, 'heightPx' => 10]]]]]]]], 'a footnote cannot hold a shape block'],
    ]);

    it('never repeats a key the document made up', function () {
        $evil = "\u{202E}evil<script>";
        $doc = ['blocks' => [['type' => 'shape', 'widthPx' => 10, 'heightPx' => 10,
            'outline' => [$evil => 1],
            'anchor' => [$evil => 1, 'horizontal' => [$evil => 1]],
        ]]];
        $messages = implode("\n", array_column(Agent::validate($doc), 'message'));
        $notes = implode("\n", array_column(Agent::validateAndRepair($doc)['errors'], 'message'));

        expect(shpCodes($doc))->toBe(['shape_invalid', 'anchor_invalid', 'anchor_invalid'])
            ->and($messages)->not->toContain('evil')
            ->and($notes)->not->toContain('evil');
    });
});

/* ---------------- repair ---------------- */

describe('repair', function () {
    it('makes a hostile shape valid and keeps every word', function (array $doc, array $expected) {
        $r = Agent::validateAndRepair($doc);

        expect($r['ok'])->toBeTrue()
            ->and($r['schema']['blocks'])->toBe($expected)
            ->and(shpTexts($r['schema']['blocks']))->toBe(shpTexts($doc['blocks']));
    })->with([
        'an unknown preset becomes a rect' => [
            ['blocks' => [['type' => 'shape', 'geometry' => 'star32', 'widthPx' => 10, 'heightPx' => 10]]],
            [['type' => 'shape', 'widthPx' => 10, 'heightPx' => 10]],
        ],
        'sizes clamp and default' => [
            ['blocks' => [['type' => 'shape', 'widthPx' => -5, 'heightPx' => 99999]]],
            [['type' => 'shape', 'widthPx' => 240, 'heightPx' => 2112]],
        ],
        'a numeric string size is a number, a missing one the default' => [
            ['blocks' => [['type' => 'shape', 'widthPx' => '64']]],
            [['type' => 'shape', 'widthPx' => 64.0, 'heightPx' => 120]],
        ],
        'bad fill and outline keys go, good ones stay' => [
            ['blocks' => [['type' => 'shape', 'widthPx' => 10, 'heightPx' => 10, 'fill' => 'red', 'outline' => ['color' => '#123456', 'width' => 400, 'style' => 'wavy', 'glow' => true], 'flipH' => 'yes']]],
            [['type' => 'shape', 'widthPx' => 10, 'heightPx' => 10, 'outline' => ['color' => '#123456', 'width' => 100]]],
        ],
        'a junk anchor is mended' => [
            ['blocks' => [['type' => 'shape', 'widthPx' => 10, 'heightPx' => 10, 'anchor' => ['horizontal' => ['relativeTo' => 'paragraph', 'offset' => 99999, 'align' => 'left'], 'vertical' => ['align' => 'middle'], 'wrap' => 'inline', 'allowOverlap' => 'no', 'z' => 1]]]],
            [['type' => 'shape', 'widthPx' => 10, 'heightPx' => 10, 'anchor' => ['horizontal' => ['relativeTo' => 'column', 'offset' => 1584], 'vertical' => ['offset' => 0], 'wrap' => 'square']]],
        ],
        'a vertical align from the paragraph becomes an offset' => [
            ['blocks' => [['type' => 'shape', 'widthPx' => 10, 'heightPx' => 10, 'anchor' => ['vertical' => ['relativeTo' => 'paragraph', 'align' => 'center'], 'wrap' => 'square']]]],
            [['type' => 'shape', 'widthPx' => 10, 'heightPx' => 10, 'anchor' => ['vertical' => ['relativeTo' => 'paragraph', 'offset' => 0], 'wrap' => 'square']]],
        ],
        'an anchor that is not an object makes the shape inline' => [
            ['blocks' => [['type' => 'shape', 'widthPx' => 10, 'heightPx' => 10, 'anchor' => 'left']]],
            [['type' => 'shape', 'widthPx' => 10, 'heightPx' => 10]],
        ],
        'a line holding text becomes a box' => [
            ['blocks' => [['type' => 'shape', 'geometry' => 'line', 'widthPx' => 10, 'heightPx' => 0, 'blocks' => [shpP('kept')]]]],
            [['type' => 'shape', 'widthPx' => 10, 'heightPx' => 120, 'blocks' => [shpP('kept')]]],
        ],
        'string text becomes a paragraph' => [
            ['blocks' => [['type' => 'shape', 'widthPx' => 10, 'heightPx' => 10, 'blocks' => 'Typed as a string.']]],
            [['type' => 'shape', 'widthPx' => 10, 'heightPx' => 10, 'blocks' => [shpP('Typed as a string.')]]],
        ],
        'a nested text box gives its text to the outer one' => [
            ['blocks' => [['type' => 'shape', 'widthPx' => 240, 'heightPx' => 96, 'blocks' => [shpP('outer'), ['type' => 'shape', 'widthPx' => 10, 'heightPx' => 10, 'blocks' => [shpP('inner'), ['type' => 'shape', 'widthPx' => 10, 'heightPx' => 10, 'blocks' => [shpP('innermost')]]]], shpP('outer again')]]]],
            [['type' => 'shape', 'widthPx' => 240, 'heightPx' => 96, 'blocks' => [shpP('outer'), shpP('inner'), shpP('innermost'), shpP('outer again')]]],
        ],
        'a shape in a footnote becomes its text' => [
            ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'x'], ['text' => '', 'footnote' => ['blocks' => [shpBox('noted')]]]]]]],
            [['type' => 'paragraph', 'runs' => [['text' => 'x'], ['text' => '', 'footnote' => ['blocks' => [shpP('noted')]]]]]],
        ],
        'what a text box cannot hold is dropped, its words are not' => [
            ['blocks' => [['type' => 'shape', 'widthPx' => 240, 'heightPx' => 96, 'blocks' => [shpP('before'), ['type' => 'pageBreak'], ['type' => 'toc'], ['type' => 'paragraph', 'runs' => [['text' => 'marked', 'bookmark' => 'boxed']]]]]]],
            [['type' => 'shape', 'widthPx' => 240, 'heightPx' => 96, 'blocks' => [shpP('before'), shpP('marked')]]],
        ],
        'a footnote in a text box follows the box' => [
            ['blocks' => [['type' => 'shape', 'widthPx' => 240, 'heightPx' => 96, 'blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'claim'], ['text' => '', 'footnote' => ['blocks' => [shpP('the source')]]]]]]], shpP('after')]],
            [['type' => 'shape', 'widthPx' => 240, 'heightPx' => 96, 'blocks' => [shpP('claim')]], shpP('the source'), shpP('after')],
        ],
        'a tracked change in a text box is accepted' => [
            ['blocks' => [['type' => 'shape', 'widthPx' => 240, 'heightPx' => 96, 'blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'kept '], ['text' => 'added', 'revision' => ['insert' => ['id' => 'r1', 'author' => 'Ann']]]]]]]]],
            [['type' => 'shape', 'widthPx' => 240, 'heightPx' => 96, 'blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'kept '], ['text' => 'added']]]]]],
        ],
    ]);

    it('turns a shape in a header into its text', function () {
        $r = Agent::validateAndRepair(['page' => ['header' => ['blocks' => [shpP('Acme'), shpBox('Letterhead line')]]], 'blocks' => [shpP('Body')]]);

        expect($r['ok'])->toBeTrue()
            ->and($r['schema']['page']['header']['blocks'] ?? null)->toBe([shpP('Acme'), shpP('Letterhead line')]);
    });

    it('moves what a full text box cannot hold after it', function () {
        $doc = ['blocks' => [['type' => 'shape', 'widthPx' => 240, 'heightPx' => 96, 'blocks' => array_fill(0, 503, shpP('x'))], shpP('end')]];
        $r = Agent::validateAndRepair($doc);

        expect($r['ok'])->toBeTrue()
            ->and(count($r['schema']['blocks'][0]['blocks'] ?? []))->toBe(500)
            ->and(count($r['schema']['blocks']))->toBe(5)
            ->and(shpTexts($r['schema']['blocks']))->toBe(shpTexts($doc['blocks']));
    });

    it('moves a paragraph too long for any text box after it', function () {
        $long = shpP(str_repeat('b', 40000));
        $r = Agent::validateAndRepair(['blocks' => [['type' => 'shape', 'widthPx' => 240, 'heightPx' => 96, 'blocks' => [shpP('short'), $long]]]]);

        expect($r['ok'])->toBeTrue()
            ->and($r['schema']['blocks'])->toBe([['type' => 'shape', 'widthPx' => 240, 'heightPx' => 96, 'blocks' => [shpP('short')]], $long]);
    });

    it('turns the shapes past 1,000 into their text', function () {
        $doc = ['blocks' => array_fill(0, 1002, shpBox('t'))];
        $r = Agent::validateAndRepair($doc);

        expect($r['ok'])->toBeTrue()
            ->and(count(shpShapes($r['schema']['blocks'])))->toBe(1000)
            ->and(array_slice($r['schema']['blocks'], 1000))->toBe([shpP('t'), shpP('t')]);
    });
});

/* ---------------- reading: what Word and others write ---------------- */

describe('reading', function () {
    it('reads Word\'s text box once, from the DrawingML branch', function () {
        $read = Agent::read(shpDocx(shpPara('Before') . shpInRun(shpWordBox('In the box')) . shpPara('After')));

        expect(shpModel($read)['blocks'])->toBe([
            shpP('Before'),
            ['type' => 'shape', 'widthPx' => 240, 'heightPx' => 96, 'fill' => '#FFFFFF', 'blocks' => [shpP('In the box')]],
            shpP('After'),
        ])->and($read['unrepresented'] ?? [])->toBe([]);
    });

    it('reads the VML fallback when the choice needs what it does not know', function () {
        $read = Agent::read(shpDocx(shpInRun(shpWordBox('Grouped', 'wpg'))));

        expect(shpModel($read)['blocks'])->toBe([['type' => 'shape', 'widthPx' => 240, 'heightPx' => 96, 'fill' => '#FFFFFF', 'blocks' => [shpP('Grouped')]]])
            ->and(shpReport($read))->toBe(['format-approximated' => 1]);
    });

    it('reads a choice whose Requires is empty or unknown as its fallback, never both', function (string $requires) {
        $read = Agent::read(shpDocx(shpInRun(shpWordBox('Once', $requires))));

        expect(shpTexts($read['blocks']))->toBe(['Once']);
    })->with(['', 'zz', 'wps zz']);

    it('reads VML-only shapes', function (string $vml, array $expected) {
        $read = Agent::read(shpDocx(shpInRun('<w:pict>' . $vml . '</w:pict>')));

        expect(shpModel($read)['blocks'])->toBe($expected)
            ->and(shpReport($read)['format-approximated'] ?? 0)->toBe(1)
            ->and(Agent::validate(shpModel($read)))->toBe([]);
    })->with([
        'a rect text box' => [
            '<v:rect style="width:144pt;height:1in" fillcolor="#fde" strokecolor="#C00000" strokeweight="2pt"><v:textbox><w:txbxContent>' . shpPara('VML text') . '</w:txbxContent></v:textbox></v:rect>',
            [['type' => 'shape', 'widthPx' => 192, 'heightPx' => 96, 'fill' => '#FFDDEE', 'outline' => ['color' => '#C00000', 'width' => 2], 'blocks' => [shpP('VML text')]]],
        ],
        'an oval, unfilled, unstroked' => [
            '<v:oval style="width:72pt;height:36pt" filled="f" stroked="f"/>',
            [['type' => 'shape', 'geometry' => 'ellipse', 'widthPx' => 96, 'heightPx' => 48, 'outline' => ['style' => 'none']]],
        ],
        'a rounded rect' => [
            '<v:roundrect style="width:96px;height:48px" fillcolor="blue"/>',
            [['type' => 'shape', 'geometry' => 'roundRect', 'widthPx' => 96, 'heightPx' => 48, 'fill' => '#FFFFFF']],
        ],
        'a line' => [
            '<v:line from="0,0" to="150pt,0"/>',
            [['type' => 'shape', 'geometry' => 'line', 'widthPx' => 200, 'heightPx' => 0, 'fill' => '#FFFFFF']],
        ],
        'a floating text box behind the text' => [
            '<v:shape type="#_x0000_t202" style="position:absolute;margin-left:36pt;margin-top:-12pt;width:108pt;height:54pt;z-index:-5;mso-position-horizontal-relative:page;mso-position-vertical-relative:margin" stroked="f"><v:textbox><w:txbxContent>' . shpPara('Behind') . '</w:txbxContent></v:textbox><w10:wrap type="none"/></v:shape>',
            [['type' => 'shape', 'widthPx' => 144, 'heightPx' => 72, 'fill' => '#FFFFFF', 'outline' => ['style' => 'none'],
                'anchor' => ['horizontal' => ['relativeTo' => 'page', 'offset' => 36], 'vertical' => ['relativeTo' => 'margin', 'offset' => -12], 'wrap' => 'behind', 'allowOverlap' => true], 'blocks' => [shpP('Behind')]]],
        ],
        'a floating box wrapped square, aligned' => [
            '<v:rect style="position:absolute;width:72pt;height:72pt;mso-position-horizontal:right;mso-position-horizontal-relative:margin"><v:textbox><w:txbxContent>' . shpPara('Right') . '</w:txbxContent></v:textbox><w10:wrap type="square"/></v:rect>',
            [['type' => 'shape', 'widthPx' => 96, 'heightPx' => 96, 'fill' => '#FFFFFF',
                'anchor' => ['horizontal' => ['relativeTo' => 'margin', 'align' => 'right'], 'vertical' => ['relativeTo' => 'paragraph', 'offset' => 0], 'wrap' => 'square', 'allowOverlap' => true], 'blocks' => [shpP('Right')]]],
        ],
        'a floating box aligned to the top of its paragraph, which Word cannot make' => [
            '<v:rect style="position:absolute;width:72pt;height:72pt;margin-top:6pt;mso-position-vertical:top"><v:textbox><w:txbxContent>' . shpPara('Top') . '</w:txbxContent></v:textbox><w10:wrap type="square"/></v:rect>',
            [['type' => 'shape', 'widthPx' => 96, 'heightPx' => 96, 'fill' => '#FFFFFF',
                'anchor' => ['horizontal' => ['relativeTo' => 'column', 'offset' => 0], 'vertical' => ['relativeTo' => 'paragraph', 'offset' => 6], 'wrap' => 'square', 'allowOverlap' => true], 'blocks' => [shpP('Top')]]],
        ],
        'a style with no size' => [
            '<v:rect style="nonsense"><v:textbox><w:txbxContent>' . shpPara('Sized by default') . '</w:txbxContent></v:textbox></v:rect>',
            [['type' => 'shape', 'widthPx' => 240, 'heightPx' => 120, 'fill' => '#FFFFFF', 'blocks' => [shpP('Sized by default')]]],
        ],
    ]);

    it('leaves a VML picture alone, as before', function () {
        $read = Agent::read(shpDocx(shpPara('Text') . shpInRun('<w:pict><v:shape style="width:10pt;height:10pt"><v:imagedata r:id="rId99"/></v:shape></w:pict>')));

        expect(shpModel($read)['blocks'])->toBe([shpP('Text')]);
    });

    it('degrades malformed DrawingML to the nearest shape, keeping the text and saying so', function (array $o, array $expected, array $report) {
        $read = Agent::read(shpDocx(shpInRun(shpWsp(shpPara('Kept'), $o))));
        $blocks = shpModel($read)['blocks'];

        expect($blocks)->toBe([$expected + ['blocks' => [shpP('Kept')]]])
            ->and(shpReport($read))->toBe($report)
            ->and(Agent::validate(shpModel($read)))->toBe([]);
    })->with([
        'an unknown preset' => [['prst' => 'star32'], ['type' => 'shape', 'widthPx' => 240, 'heightPx' => 96, 'fill' => '#FFFFFF'], ['format-approximated' => 1]],
        'a custom geometry' => [['geom' => '<a:custGeom><a:pathLst/></a:custGeom>'], ['type' => 'shape', 'widthPx' => 240, 'heightPx' => 96, 'fill' => '#FFFFFF'], ['format-approximated' => 1]],
        'a 20-digit width' => [['cx' => '99999999999999999999'], ['type' => 'shape', 'widthPx' => 240, 'heightPx' => 96, 'fill' => '#FFFFFF'], ['layout-not-preserved' => 1]],
        'a negative width' => [['cx' => '-914400'], ['type' => 'shape', 'widthPx' => 240, 'heightPx' => 96, 'fill' => '#FFFFFF'], ['layout-not-preserved' => 1]],
        'a width in letters' => [['cx' => 'wide', 'cy' => '1e9'], ['type' => 'shape', 'widthPx' => 240, 'heightPx' => 120, 'fill' => '#FFFFFF'], ['layout-not-preserved' => 1]],
        'a huge width' => [['cx' => '914400000000000'], ['type' => 'shape', 'widthPx' => 2112, 'heightPx' => 96, 'fill' => '#FFFFFF'], ['layout-not-preserved' => 1]],
        'a zero height' => [['cy' => '0'], ['type' => 'shape', 'widthPx' => 240, 'heightPx' => 1, 'fill' => '#FFFFFF'], ['layout-not-preserved' => 1]],
        'a gradient fill' => [['fill' => '<a:gradFill><a:gsLst/></a:gradFill>'], ['type' => 'shape', 'widthPx' => 240, 'heightPx' => 96], ['format-approximated' => 1]],
        'a theme colour fill' => [['fill' => '<a:solidFill><a:schemeClr val="accent1"/></a:solidFill>'], ['type' => 'shape', 'widthPx' => 240, 'heightPx' => 96], ['format-approximated' => 1]],
        'no line at all' => [['ln' => ''], ['type' => 'shape', 'widthPx' => 240, 'heightPx' => 96, 'fill' => '#FFFFFF', 'outline' => ['style' => 'none']], []],
        'a line 30 digits wide' => [['ln' => '<a:ln w="999999999999999999999999999999"><a:solidFill><a:srgbClr val="000000"/></a:solidFill></a:ln>'], ['type' => 'shape', 'widthPx' => 240, 'heightPx' => 96, 'fill' => '#FFFFFF'], ['format-approximated' => 1]],
        'a line a mile wide' => [['ln' => '<a:ln w="99999999999"/>'], ['type' => 'shape', 'widthPx' => 240, 'heightPx' => 96, 'fill' => '#FFFFFF', 'outline' => ['width' => 100]], ['format-approximated' => 1]],
        'a rotation' => [['xfrm' => ' rot="5400000" flipH="1"'], ['type' => 'shape', 'widthPx' => 240, 'heightPx' => 96, 'fill' => '#FFFFFF', 'flipH' => true], ['format-approximated' => 1]],
        'an offset past the cap' => [['frame' => 'anchor', 'position' => '<wp:positionH relativeFrom="page"><wp:posOffset>99999999999</wp:posOffset></wp:positionH><wp:positionV relativeFrom="page"><wp:posOffset>-99999999999</wp:posOffset></wp:positionV>'],
            ['type' => 'shape', 'widthPx' => 240, 'heightPx' => 96, 'fill' => '#FFFFFF', 'anchor' => ['horizontal' => ['relativeTo' => 'page', 'offset' => 1584], 'vertical' => ['relativeTo' => 'page', 'offset' => -1584], 'wrap' => 'square', 'allowOverlap' => true]], ['layout-not-preserved' => 1]],
        'an offset in letters, a relativeFrom the model does not name' => [['frame' => 'anchor', 'position' => '<wp:positionH relativeFrom="insideMargin"><wp:posOffset>abc</wp:posOffset></wp:positionH><wp:positionV relativeFrom="topMargin"><wp:align>middle</wp:align></wp:positionV>'],
            ['type' => 'shape', 'widthPx' => 240, 'heightPx' => 96, 'fill' => '#FFFFFF', 'anchor' => ['horizontal' => ['relativeTo' => 'page', 'offset' => 0], 'vertical' => ['relativeTo' => 'page', 'offset' => 0], 'wrap' => 'square', 'allowOverlap' => true]], ['layout-not-preserved' => 1]],
        'no position at all, no wrap' => [['frame' => 'anchor', 'position' => '', 'wrap' => ''],
            ['type' => 'shape', 'widthPx' => 240, 'heightPx' => 96, 'fill' => '#FFFFFF', 'anchor' => ['horizontal' => ['relativeTo' => 'column', 'offset' => 0], 'vertical' => ['relativeTo' => 'paragraph', 'offset' => 0], 'wrap' => 'inFront', 'allowOverlap' => true]], ['layout-not-preserved' => 1]],
        'a vertical align from the paragraph, which Word cannot make' => [['frame' => 'anchor', 'position' => '<wp:positionH relativeFrom="column"><wp:posOffset>0</wp:posOffset></wp:positionH><wp:positionV relativeFrom="paragraph"><wp:align>top</wp:align></wp:positionV>'],
            ['type' => 'shape', 'widthPx' => 240, 'heightPx' => 96, 'fill' => '#FFFFFF', 'anchor' => ['horizontal' => ['relativeTo' => 'column', 'offset' => 0], 'vertical' => ['relativeTo' => 'paragraph', 'offset' => 0], 'wrap' => 'square', 'allowOverlap' => true]], ['layout-not-preserved' => 1]],
        'Word 2010 percentage offsets, read from their fallback' => [['frame' => 'anchor', 'position' => '<wp:positionH relativeFrom="margin"><mc:AlternateContent><mc:Choice Requires="wp14"><wp14:pctPosHOffset>50000</wp14:pctPosHOffset></mc:Choice><mc:Fallback><wp:posOffset>12700</wp:posOffset></mc:Fallback></mc:AlternateContent></wp:positionH><wp:positionV relativeFrom="line"><wp:align>bottom</wp:align></wp:positionV>', 'wrap' => '<wp:wrapNone/>', 'behind' => '1'],
            ['type' => 'shape', 'widthPx' => 240, 'heightPx' => 96, 'fill' => '#FFFFFF', 'anchor' => ['horizontal' => ['relativeTo' => 'margin', 'offset' => 1], 'vertical' => ['relativeTo' => 'line', 'align' => 'bottom'], 'wrap' => 'behind', 'allowOverlap' => true]], []],
    ]);

    it('reads a text box inside a text box as the outer box\'s text', function () {
        $inner = shpWsp(shpPara('inner') . shpInRun(shpWsp(shpPara('innermost'))));
        $read = Agent::read(shpDocx(shpInRun(shpWsp(shpPara('outer') . shpInRun($inner) . shpPara('outer again')))));

        expect(shpModel($read)['blocks'])->toBe([['type' => 'shape', 'widthPx' => 240, 'heightPx' => 96, 'fill' => '#FFFFFF', 'blocks' => [shpP('outer'), shpP('inner'), shpP('innermost'), shpP('outer again')]]])
            ->and(shpReport($read))->toBe(['shape' => 2])
            ->and(Agent::validate(shpModel($read)))->toBe([]);
    });

    it('reads deeply nested mc:AlternateContent without losing its text', function () {
        $xml = shpWordBox('Deep inside');
        for ($i = 0; $i < 50; $i++) {
            $xml = '<mc:AlternateContent><mc:Choice Requires="wps">' . $xml . '</mc:Choice></mc:AlternateContent>';
        }
        $read = Agent::read(shpDocx(shpPara('Before') . shpInRun($xml) . shpPara('After')));

        expect(shpTexts($read['blocks']))->toBe(['Before', 'Deep inside', 'After'])
            ->and(shpReport($read))->toBe(['shape' => 1])
            ->and(Agent::validate(shpModel($read)))->toBe([]);
    });

    it('reads mc:AlternateContent at the paragraph and body level as one branch', function () {
        $body = '<mc:AlternateContent><mc:Choice Requires="wps">' . shpPara('chosen') . '</mc:Choice><mc:Fallback>' . shpPara('fallback') . '</mc:Fallback></mc:AlternateContent>'
            . '<w:p><mc:AlternateContent><mc:Choice Requires="zz">' . shpRun('not this') . '</mc:Choice><mc:Fallback>' . shpRun('this') . '</mc:Fallback></mc:AlternateContent></w:p>';

        expect(shpTexts(Agent::read(shpDocx($body))['blocks']))->toBe(['chosen', 'this']);
    });

    it('reads every paragraph and table under body-level mc:AlternateContent, however deep', function (int $depth) {
        $xml = shpPara('first') . '<w:tbl><w:tr><w:tc>' . shpPara('cell') . '</w:tc></w:tr></w:tbl>' . shpPara('last');
        for ($i = 0; $i < $depth; $i++) {
            $xml = '<mc:AlternateContent><mc:Choice Requires="wps">' . $xml . '</mc:Choice></mc:AlternateContent>';
        }
        $read = Agent::read(shpDocx(shpPara('Before') . $xml . shpPara('After')));

        expect(array_column(shpModel($read)['blocks'], 'type'))->toBe(['paragraph', 'paragraph', 'table', 'paragraph', 'paragraph'])
            ->and(shpTexts($read['blocks']))->toBe(['Before', 'first', 'cell', 'last', 'After'])
            ->and($read['unrepresented'] ?? [])->toBe([])
            ->and(Agent::validate(shpModel($read)))->toBe([]);
    })->with(['nine deep' => 9, 'twenty deep' => 20]);

    it('reads a choice it does not understand, with no fallback, as its text and says so', function (string $xml) {
        $read = Agent::read(shpDocx(shpPara('Before') . $xml . shpPara('After')));

        expect(shpTexts($read['blocks']))->toBe(['Before', 'kept', 'After'])
            ->and(shpReport($read))->toBe(['shape' => 1])
            ->and(Agent::validate(shpModel($read)))->toBe([]);
    })->with([
        'between paragraphs' => ['<mc:AlternateContent><mc:Choice Requires="zz"><w:p><w:r><w:t>kept</w:t></w:r></w:p></mc:Choice></mc:AlternateContent>'],
        'in a paragraph' => ['<w:p><mc:AlternateContent><mc:Choice Requires="zz"><w:r><w:t>kept</w:t></w:r></mc:Choice></mc:AlternateContent></w:p>'],
        'in a run' => ['<w:p><w:r><mc:AlternateContent><mc:Choice Requires="zz"><w:drawing><w:txbxContent><w:p><w:r><w:t>kept</w:t></w:r></w:p></w:txbxContent></w:drawing></mc:Choice></mc:AlternateContent></w:r></w:p>'],
        'between paragraphs, nine deep' => ['<mc:AlternateContent><mc:Choice Requires="wps"><mc:AlternateContent><mc:Choice Requires="wps"><mc:AlternateContent><mc:Choice Requires="wps"><mc:AlternateContent><mc:Choice Requires="wps"><mc:AlternateContent><mc:Choice Requires="wps"><mc:AlternateContent><mc:Choice Requires="wps"><mc:AlternateContent><mc:Choice Requires="wps"><mc:AlternateContent><mc:Choice Requires="wps"><mc:AlternateContent><mc:Choice Requires="zz"><w:p><w:r><w:t>kept</w:t></w:r></w:p></mc:Choice></mc:AlternateContent></mc:Choice></mc:AlternateContent></mc:Choice></mc:AlternateContent></mc:Choice></mc:AlternateContent></mc:Choice></mc:AlternateContent></mc:Choice></mc:AlternateContent></mc:Choice></mc:AlternateContent></mc:Choice></mc:AlternateContent></mc:Choice></mc:AlternateContent>'],
    ]);

    it('reads mc:AlternateContent in a header paragraph as one branch, as it read before shapes, without the copy', function () {
        $hdr = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:hdr' . SHP_NS . '><w:p><mc:AlternateContent><mc:Choice Requires="wps"><w:r><w:t>Acme</w:t></w:r></mc:Choice><mc:Fallback><w:r><w:t>Acme</w:t></w:r></mc:Fallback></mc:AlternateContent></w:p></w:hdr>';
        $bytes = Agent::toBytes(['page' => ['header' => ['blocks' => [shpP('H')]]], 'blocks' => [shpP('x')]]);
        $path = lwTemp('lw-shp', '.docx');
        file_put_contents($path, $bytes);
        $zip = new ZipArchive();
        expect($zip->open($path))->toBeTrue();
        $zip->addFromString('word/header1.xml', $hdr);
        $zip->close();
        $read = Agent::read((string) file_get_contents($path));
        @unlink($path);

        expect($read['page']['header'] ?? null)->toBe(['blocks' => [shpP('Acme')]]);
    });

    it('moves what a text box cannot hold after it', function () {
        $read = Agent::read(shpDocx(shpInRun(shpWsp(str_repeat(shpPara('x'), 503))) . shpPara('end')));
        $blocks = shpModel($read)['blocks'];

        expect(count($blocks[0]['blocks'] ?? []))->toBe(500)
            ->and(count($blocks))->toBe(5)
            ->and(shpReport($read))->toBe(['layout-not-preserved' => 1])
            ->and(Agent::validate(shpModel($read)))->toBe([]);
    });

    it('reads the shapes past 1,000 as their text', function () {
        $read = Agent::read(shpDocx(str_repeat(shpInRun(shpWsp(shpPara('t'))), 1003)));

        expect(count(shpShapes($read['blocks'])))->toBe(1000)
            ->and(count(shpTexts($read['blocks'])))->toBe(1003)
            ->and(shpReport($read))->toBe(['shape' => 3])
            ->and(Agent::validate(shpModel($read)))->toBe([]);
    });

    it('reads a page break, a chart and a section break in a text box without breaking the box', function () {
        $read = Agent::read(shpDocx(shpInRun(shpWsp('<w:p>' . shpRun('one') . '<w:r><w:br w:type="page"/></w:r>' . shpRun('two') . '</w:p>'))));

        expect(shpModel($read)['blocks'])->toBe([['type' => 'shape', 'widthPx' => 240, 'heightPx' => 96, 'fill' => '#FFFFFF', 'blocks' => [shpP('onetwo')]]])
            ->and(shpReport($read))->toBe(['layout-not-preserved' => 1]);
    });

    it('accepts a tracked change in a text box, even when keeping them, and counts it', function () {
        $content = '<w:p>' . shpRun('kept ') . '<w:ins w:id="1" w:author="Ann">' . shpRun('added') . '</w:ins><w:del w:id="2" w:author="Ann"><w:r><w:delText>gone</w:delText></w:r></w:del></w:p>';
        $docx = shpDocx(shpInRun(shpWsp($content)));
        $kept = Agent::read($docx, ['preserveTrackedChanges' => true]);
        $default = Agent::read($docx);
        $box = [['type' => 'shape', 'widthPx' => 240, 'heightPx' => 96, 'fill' => '#FFFFFF', 'blocks' => [shpP('kept added')]]];

        expect(shpModel($kept)['blocks'])->toBe($box)
            ->and(shpReport($kept))->toBe(['tracked-change' => 2])
            ->and(shpModel($default)['blocks'])->toBe($box)
            ->and(Agent::validate(shpModel($kept)))->toBe([]);
    });

    it('reads a footnote in a text box after the box', function () {
        $base = ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'x'], ['text' => '', 'footnote' => ['blocks' => [shpP('The source.')]]]]]]];
        $content = '<w:p>' . shpRun('claim') . '<w:r><w:footnoteReference w:id="1"/></w:r></w:p>';
        $read = Agent::read(shpDocx(shpInRun(shpWsp($content)) . shpPara('after'), $base));

        expect(shpModel($read)['blocks'])->toBe([
            ['type' => 'shape', 'widthPx' => 240, 'heightPx' => 96, 'fill' => '#FFFFFF', 'blocks' => [shpP('claim')]],
            shpP('The source.'),
            shpP('after'),
        ])->and(shpReport($read))->toBe(['nested-block' => 1]);
    });

    it('reads a shape that holds no text box, and a text box of a linked chain', function () {
        $plain = shpWsp('', ['txbx' => false, 'prst' => 'ellipse']);
        $linked = str_replace('<wps:bodyPr/>', '<wps:linkedTxbx id="1" seq="1"/><wps:bodyPr/>', shpWsp('', ['txbx' => false]));
        $read = Agent::read(shpDocx(shpInRun($plain) . shpInRun($linked)));

        expect(shpModel($read)['blocks'])->toBe([
            ['type' => 'shape', 'geometry' => 'ellipse', 'widthPx' => 240, 'heightPx' => 96, 'fill' => '#FFFFFF'],
            ['type' => 'shape', 'widthPx' => 240, 'heightPx' => 96, 'fill' => '#FFFFFF'],
        ])->and(shpReport($read))->toBe(['layout-not-preserved' => 1]);
    });

    it('keeps a paragraph\'s own text beside the shape it anchors', function () {
        $read = Agent::read(shpDocx('<w:p>' . shpRun('Text first ') . '<w:r>' . shpWsp(shpPara('boxed')) . '</w:r>' . shpRun('then more') . '</w:p>'));

        expect(shpModel($read)['blocks'])->toBe([
            shpP('Text first then more'),
            ['type' => 'shape', 'widthPx' => 240, 'heightPx' => 96, 'fill' => '#FFFFFF', 'blocks' => [shpP('boxed')]],
        ]);
    });
});

/* ---------------- describe, markdown, portable, layout ---------------- */

describe('everything else that sees a document', function () {
    $doc = ['blocks' => [
        shpP('Body.'),
        ['type' => 'shape', 'widthPx' => 240, 'heightPx' => 96, 'anchor' => ['wrap' => 'square'], 'blocks' => [shpP('Boxed words here.')]],
        ['type' => 'shape', 'geometry' => 'line', 'widthPx' => 200, 'heightPx' => 0],
    ]];

    it('describes the shapes and counts a text box\'s words', function () use ($doc) {
        $text = Agent::describe($doc);

        expect($text)->toContain('Shapes: 2 (1 text box, 1 floating)')
            ->and($text)->toContain('Words: 4');
    });

    it('keeps a text box\'s text in markdown and reports the shape', function () use ($doc) {
        $md = Agent::toMarkdownWithReport($doc);

        expect($md['markdown'])->toContain('Boxed words here.')
            ->and(array_column($md['report']['dropped'], 'kind'))->toContain('shape');
    });

    it('keeps a text box\'s text in portable content and reports the shape', function () use ($doc) {
        $portable = Agent::toPortable($doc);

        expect(json_encode($portable['content'] ?? $portable))->toContain('Boxed words here.')
            ->and(array_column($portable['content']['report']['dropped'] ?? [], 'kind'))->toContain('shape');
    });

    it('lays out a shape and says the layout is approximate', function () use ($doc) {
        expect(Agent::validate($doc))->toBe([]);
        $layout = Agent::layout($doc);

        expect($layout['approximate'] ?? [])->toContain('text box or shape')
            ->and($layout['pages'] ?? 0)->toBe(1);
    });
});
