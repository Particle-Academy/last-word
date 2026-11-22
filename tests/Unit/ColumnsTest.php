<?php

declare(strict_types=1);

use LastWord\Agent;
use LastWord\Tests\Support\Package;

/**
 * Text columns: the Word parity rows "newspaper columns per section" and
 * "column break; uneven widths; line between".
 *
 *   - `page.columns` (and a break's `page.columns`) is a section's `w:cols`
 *     (ISO/IEC 29500-1 §17.6.4): `count` is `w:num`, `space` `w:space`,
 *     `separator` `w:sep`; `widths` (and `spaces`) are `w:equalWidth="0"` with
 *     one `w:col` (§17.6.3) per column;
 *   - a `columnBreak` block is `w:br w:type="column"`;
 *   - `pageBreak.page.start: "continuous"` is a section whose `w:type` is
 *     `continuous`, so the columns change on the same page.
 *
 * Done means a ROUND TRIP: written, read back, the same model, and the read
 * model written again gives the same bytes. Every value below is a literal, so
 * this file loads, and fails by assertion, without the feature.
 */

/** @return array{type: string, runs: list<array{text: string}>} */
function colP(string $text): array
{
    return ['type' => 'paragraph', 'runs' => [['text' => $text]]];
}

/** A valid document's bytes; an invalid one fails here, by assertion, before toBytes() can throw. */
function colBytes(array $doc): string
{
    expect(Agent::validate($doc))->toBe([]);

    return Agent::toBytes($doc);
}

/** @return list<string> every `code` validate() gives the document, in order */
function colCodes(array $doc): array
{
    return array_values(array_filter(array_map(static fn (array $e): ?string => $e['code'] ?? null, Agent::validate($doc))));
}

/** word/document.xml of a valid document. */
function colXml(array $doc): string
{
    $parts = Package::parts(colBytes($doc));
    Package::assertConsistent($parts);

    return $parts['word/document.xml'];
}

/** @return list<string> every w:sectPr of a document part, paragraph-borne ones first and the body's own last */
function colSectPrs(string $xml): array
{
    preg_match_all('/<w:sectPr>.*?<\/w:sectPr>/s', $xml, $m);

    return $m[0];
}

/**
 * A .docx whose word/document.xml body is exactly `$bodyXml` (its own w:sectPr
 * included): a written document with that one part swapped, so every other part
 * is a real one.
 */
function colDocx(string $bodyXml): string
{
    $bytes = Agent::toBytes(['blocks' => [colP('x')]]);
    $path = lwTemp('lw-col', '.docx');
    file_put_contents($path, $bytes);
    $zip = new ZipArchive();
    expect($zip->open($path))->toBeTrue();
    $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<w:body>' . $bodyXml . '</w:body></w:document>');
    $zip->close();
    $out = (string) file_get_contents($path);
    @unlink($path);

    return $out;
}

const COL_LETTER = '<w:pgSz w:w="12240" w:h="15840"/><w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440" w:header="720" w:footer="720" w:gutter="0"/>';

function colTextP(string $text): string
{
    return '<w:p><w:r><w:t xml:space="preserve">' . $text . '</w:t></w:r></w:p>';
}

/** Every run text the read model holds, in order: what a degraded read must keep. */
function colTexts(array $doc): array
{
    $out = [];
    $walk = static function (array $blocks) use (&$walk, &$out): void {
        foreach ($blocks as $b) {
            foreach ($b['runs'] ?? [] as $r) {
                $out[] = $r['text'];
            }
            foreach ($b['rows'] ?? [] as $row) {
                foreach ($row['cells'] as $cell) {
                    $walk($cell['blocks']);
                }
            }
            $walk($b['blocks'] ?? []);
        }
    };
    $walk($doc['blocks'] ?? []);

    return $out;
}

/* ---------------- the schema ---------------- */

describe('the schema', function () {
    it('offers columns on the page and on a break, start on a break, and a columnBreak block', function () {
        $s = Agent::jsonSchema();
        $types = array_map(static fn (array $b): string => $b['properties']['type']['const'], $s['$defs']['block']['oneOf']);
        $break = null;
        foreach ($s['$defs']['block']['oneOf'] as $b) {
            if ($b['properties']['type']['const'] === 'pageBreak') {
                $break = $b['properties']['page'];
            }
        }

        expect($types)->toContain('columnBreak')
            ->and($s['properties']['page']['properties'])->toHaveKey('columns')
            ->and($s['properties']['page']['properties'])->not->toHaveKey('start')
            ->and($break['properties'])->toHaveKey('columns')
            ->and($break['properties']['start']['enum'] ?? null)->toBe(['nextPage', 'continuous'])
            ->and($s['$defs']['columns']['additionalProperties'] ?? null)->toBeFalse()
            ->and($s['$defs']['columns']['required'] ?? null)->toBe(['count'])
            ->and($s['$defs']['columns']['properties']['count']['maximum'] ?? null)->toBe(45)
            ->and($s['$defs']['columns']['properties']['space']['minimum'] ?? null)->toBe(0);
    });

    it('accepts columns from one to 45, zero spacing, and exact fits', function (array $doc) {
        expect(Agent::validate($doc))->toBe([]);
    })->with([
        'back to one' => [['page' => ['columns' => ['count' => 1]], 'blocks' => []]],
        'two' => [['page' => ['columns' => ['count' => 2]], 'blocks' => []]],
        'zero gap' => [['page' => ['columns' => ['count' => 2, 'space' => 0]], 'blocks' => []]],
        'separator' => [['page' => ['columns' => ['count' => 3, 'space' => 18, 'separator' => true]], 'blocks' => []]],
        '26 at 18pt fill Letter exactly' => [['page' => ['columns' => ['count' => 26, 'space' => 0]], 'blocks' => []]],
        '45 fit landscape legal with narrow margins' => [['page' => ['size' => 'legal', 'orientation' => 'landscape', 'margins' => ['left' => 18, 'right' => 18], 'columns' => ['count' => 45, 'space' => 0]], 'blocks' => []]],
        'unequal, an exact fit' => [['page' => ['columns' => ['count' => 2, 'widths' => [300, 150], 'space' => 18]], 'blocks' => []]],
        'unequal, own gaps' => [['page' => ['columns' => ['count' => 3, 'widths' => [150, 150, 140], 'spaces' => [12, 16]]], 'blocks' => []]],
        'a continuous break that changes columns' => [['blocks' => [colP('a'), ['type' => 'pageBreak', 'page' => ['start' => 'continuous', 'columns' => ['count' => 2]]], colP('b'), ['type' => 'columnBreak'], colP('c')]]],
        'nextPage said out loud' => [['blocks' => [colP('a'), ['type' => 'pageBreak', 'page' => ['start' => 'nextPage', 'orientation' => 'landscape']], colP('b')]]],
    ]);
});

/* ---------------- writing ---------------- */

describe('writing', function () {
    it('writes equal columns as w:cols after w:pgMar, in CT_SectPr order', function () {
        $xml = colXml(['page' => ['columns' => ['count' => 3, 'space' => 18, 'separator' => true]], 'blocks' => [colP('a')]]);

        expect($xml)->toContain('<w:cols w:num="3" w:sep="1" w:space="360"/>')
            ->and($xml)->toMatch('/<w:pgMar [^>]*\/><w:cols /');
    });

    it('writes the default gap as Word\'s half inch', function () {
        expect(colXml(['page' => ['columns' => ['count' => 2]], 'blocks' => [colP('a')]]))->toContain('<w:cols w:num="2" w:space="720"/>');
    });

    it('writes w:cols before w:titlePg, and after a page frame', function () {
        $xml = colXml(['page' => [
            'columns' => ['count' => 2],
            'border' => ['style' => 'single'],
            'header' => ['left' => 'H'],
            'firstHeader' => ['left' => 'First'],
        ], 'blocks' => [colP('a')]]);

        expect($xml)->toMatch('/<\/w:pgBorders><w:cols w:num="2" w:space="720"\/><w:titlePg\/>/');
    });

    it('writes unequal widths as one w:col each, the last with no space after it', function () {
        $xml = colXml(['page' => ['columns' => ['count' => 2, 'widths' => [300, 150], 'space' => 18]], 'blocks' => [colP('a')]]);

        expect($xml)->toContain('<w:cols w:num="2" w:space="360" w:equalWidth="0"><w:col w:w="6000" w:space="360"/><w:col w:w="3000"/></w:cols>');
    });

    it('writes each gap of its own', function () {
        $xml = colXml(['page' => ['columns' => ['count' => 3, 'widths' => [150, 150, 140], 'spaces' => [12, 16]]], 'blocks' => [colP('a')]]);

        expect($xml)->toContain('<w:col w:w="3000" w:space="240"/><w:col w:w="3000" w:space="320"/><w:col w:w="2800"/></w:cols>');
    });

    it('writes nothing for one column, so a document without columns writes what it always did', function () {
        expect(colXml(['page' => ['columns' => ['count' => 1]], 'blocks' => [colP('a')]]))->not->toContain('<w:cols')
            ->and(colXml(['blocks' => [colP('a')]]))->not->toContain('<w:cols');
    });

    it('writes a column break as w:br w:type="column"', function () {
        $xml = colXml(['page' => ['columns' => ['count' => 2]], 'blocks' => [colP('a'), ['type' => 'columnBreak'], colP('b')]]);

        expect($xml)->toContain('<w:p><w:r><w:br w:type="column"/></w:r></w:p>');
    });

    it('writes a continuous break as a section whose w:type is continuous, before w:pgSz', function () {
        $sects = colSectPrs(colXml(['blocks' => [
            colP('Masthead'),
            ['type' => 'pageBreak', 'page' => ['start' => 'continuous', 'columns' => ['count' => 3, 'space' => 12]]],
            colP('Story'),
            ['type' => 'pageBreak', 'page' => ['start' => 'continuous', 'columns' => ['count' => 1]]],
            colP('Footer note'),
        ]]));

        expect($sects)->toHaveCount(3)
            ->and($sects[0])->not->toContain('<w:type')
            ->and($sects[0])->not->toContain('<w:cols')
            ->and($sects[1])->toMatch('/^<w:sectPr><w:type w:val="continuous"\/><w:pgSz /')
            ->and($sects[1])->toContain('<w:cols w:num="3" w:space="240"/>')
            ->and($sects[2])->toMatch('/^<w:sectPr><w:type w:val="continuous"\/><w:pgSz /')
            ->and($sects[2])->not->toContain('<w:cols');
    });

    it('writes no w:type for a break that starts a new page, and carries columns across it', function () {
        $sects = colSectPrs(colXml(['page' => ['columns' => ['count' => 2]], 'blocks' => [
            colP('a'),
            ['type' => 'pageBreak', 'page' => ['start' => 'nextPage', 'orientation' => 'landscape']],
            colP('b'),
        ]]));

        expect($sects)->toHaveCount(2)
            ->and(implode('', $sects))->not->toContain('<w:type')
            ->and($sects[0])->toContain('<w:cols w:num="2" w:space="720"/>')
            ->and($sects[1])->toContain('<w:cols w:num="2" w:space="720"/>');
    });

    it('lays a table out against the column, not the page', function () {
        $xml = colXml(['page' => ['columns' => ['count' => 2, 'space' => 36]], 'blocks' => [
            ['type' => 'table', 'rows' => [['cells' => [['blocks' => [colP('a')]], ['blocks' => [colP('b')]]]]]],
        ]]);
        preg_match_all('/<w:gridCol w:w="(\d+)"\/>/', $xml, $m);

        // Letter less two inches is 9360 twips; less one 720 gap, halved: 4320.
        expect(array_sum(array_map('intval', $m[1])))->toBe(4320);
    });

    it('writes the same bytes twice', function () {
        $doc = ['page' => ['columns' => ['count' => 2, 'widths' => [300, 150], 'space' => 18, 'separator' => true]], 'blocks' => [colP('a'), ['type' => 'columnBreak'], colP('b')]];

        expect(colBytes($doc))->toBe(colBytes($doc));
    });
});

/* ---------------- the round trip ---------------- */

describe('the round trip', function () {
    it('reads back the same model, and the read model writes the same bytes', function (array $doc) {
        $bytes = colBytes($doc);
        $read = Agent::read($bytes);

        expect($read)->not->toHaveKey('unrepresented')
            ->and($read['page'] ?? null)->toBe($doc['page'] ?? null)
            ->and($read['blocks'])->toBe($doc['blocks'])
            ->and(Agent::toBytes($read))->toBe($bytes);
    })->with([
        'three equal, a line between' => [['page' => ['columns' => ['count' => 3, 'space' => 18, 'separator' => true]], 'blocks' => [colP('One'), ['type' => 'columnBreak'], colP('Two'), ['type' => 'columnBreak'], colP('Three')]]],
        'two at the default gap' => [['page' => ['columns' => ['count' => 2]], 'blocks' => [colP('a')]]],
        'zero gap' => [['page' => ['columns' => ['count' => 2, 'space' => 0]], 'blocks' => [colP('a')]]],
        'unequal' => [['page' => ['columns' => ['count' => 2, 'widths' => [300, 150], 'space' => 18]], 'blocks' => [colP('Main'), ['type' => 'columnBreak'], colP('Sidebar')]]],
        'unequal, own gaps' => [['page' => ['columns' => ['count' => 3, 'widths' => [150, 150, 140], 'spaces' => [12, 16]]], 'blocks' => [colP('a')]]],
        'unequal, a line between' => [['page' => ['columns' => ['count' => 2, 'widths' => [234, 198], 'space' => 36, 'separator' => true]], 'blocks' => [colP('a')]]],
        'a newsletter: one column, three, one, on one page' => [['blocks' => [
            ['type' => 'heading', 'level' => 1, 'runs' => [['text' => 'The Quarterly']]],
            ['type' => 'pageBreak', 'page' => ['start' => 'continuous', 'columns' => ['count' => 3, 'space' => 12, 'separator' => true]]],
            colP('First story.'),
            ['type' => 'columnBreak'],
            colP('Second story.'),
            ['type' => 'columnBreak'],
            colP('Third story.'),
            ['type' => 'pageBreak', 'page' => ['start' => 'continuous', 'columns' => ['count' => 1]]],
            colP('Subscribe below.'),
        ]]],
        'columns carried across a new page, then back to one' => [['page' => ['columns' => ['count' => 2]], 'blocks' => [
            colP('Portrait, two columns'),
            ['type' => 'pageBreak', 'page' => ['orientation' => 'landscape']],
            colP('Landscape, still two'),
            ['type' => 'pageBreak', 'page' => ['columns' => ['count' => 1]]],
            colP('One again'),
        ]]],
        'a new page that changes the columns' => [['blocks' => [colP('a'), ['type' => 'pageBreak', 'page' => ['columns' => ['count' => 4, 'space' => 9]]], colP('b')]]],
        'a continuous break that changes nothing' => [['blocks' => [colP('a'), ['type' => 'pageBreak', 'page' => ['start' => 'continuous']], colP('b')]]],
        'a table in a column' => [['page' => ['columns' => ['count' => 2, 'space' => 18]], 'blocks' => [
            ['type' => 'table', 'rows' => [['cells' => [['blocks' => [colP('a')]], ['blocks' => [colP('b')]]]]]],
        ]]],
    ]);

    it('reads what Word itself writes for unequal columns: w:equalWidth="0" and no w:num', function () {
        $read = Agent::read(colDocx(colTextP('Body') . '<w:sectPr>' . COL_LETTER
            . '<w:cols w:equalWidth="0"><w:col w:w="2640" w:space="720"/><w:col w:w="6000"/></w:cols></w:sectPr>'));

        expect($read['page'] ?? null)->toBe(['columns' => ['count' => 2, 'widths' => [132, 300]]])
            ->and($read)->not->toHaveKey('unrepresented');
    });

    it('reads a column break in the middle of a paragraph as the paragraph, then the break: no text lost', function () {
        $read = Agent::read(colDocx('<w:p><w:r><w:t>Left</w:t><w:br w:type="column"/><w:t>Right</w:t></w:r></w:p>'
            . '<w:sectPr>' . COL_LETTER . '<w:cols w:num="2" w:space="720"/></w:sectPr>'));

        expect($read['blocks'])->toBe([['type' => 'paragraph', 'runs' => [['text' => 'LeftRight']]], ['type' => 'columnBreak']]);
    });
});

/* ---------------- what validation refuses ---------------- */

describe('the hostile set: validation', function () {
    it('refuses columns it cannot write, each with its code', function (array $doc, array $codes) {
        expect(colCodes($doc))->toBe($codes);
    })->with([
        'count 46, past Word\'s 45' => [['page' => ['columns' => ['count' => 46]], 'blocks' => []], ['columns_count_exceeded']],
        'a million columns' => [['page' => ['columns' => ['count' => 1000000]], 'blocks' => []], ['columns_count_exceeded']],
        'count 0' => [['page' => ['columns' => ['count' => 0]], 'blocks' => []], ['columns_invalid']],
        'count negative' => [['page' => ['columns' => ['count' => -2]], 'blocks' => []], ['columns_invalid']],
        'count a fraction' => [['page' => ['columns' => ['count' => 2.5]], 'blocks' => []], ['columns_invalid']],
        'count a string' => [['page' => ['columns' => ['count' => '3']], 'blocks' => []], ['columns_invalid']],
        'no count' => [['page' => ['columns' => ['space' => 12]], 'blocks' => []], ['columns_invalid']],
        'not an object' => [['page' => ['columns' => 'two'], 'blocks' => []], ['columns_invalid']],
        'a list' => [['page' => ['columns' => [2, 3]], 'blocks' => []], ['columns_invalid']],
        'an unknown key' => [['page' => ['columns' => ['count' => 2, 'gutter' => 5]], 'blocks' => []], ['columns_invalid']],
        'one column with a gap' => [['page' => ['columns' => ['count' => 1, 'space' => 12]], 'blocks' => []], ['columns_invalid']],
        'a separator that is not a boolean' => [['page' => ['columns' => ['count' => 2, 'separator' => 'yes']], 'blocks' => []], ['columns_invalid']],
        'negative spacing' => [['page' => ['columns' => ['count' => 2, 'space' => -1]], 'blocks' => []], ['columns_space_invalid']],
        'infinite spacing' => [['page' => ['columns' => ['count' => 2, 'space' => INF]], 'blocks' => []], ['columns_space_invalid']],
        'spacing that is text' => [['page' => ['columns' => ['count' => 2, 'space' => '12pt']], 'blocks' => []], ['columns_space_invalid']],
        'gaps wider than the page' => [['page' => ['columns' => ['count' => 2, 'space' => 500]], 'blocks' => []], ['columns_too_wide']],
        '45 on Letter' => [['page' => ['columns' => ['count' => 45]], 'blocks' => []], ['columns_too_wide']],
        '27 at zero gap on Letter' => [['page' => ['columns' => ['count' => 27, 'space' => 0]], 'blocks' => []], ['columns_too_wide']],
        'widths past the page' => [['page' => ['columns' => ['count' => 2, 'widths' => [300, 300]]], 'blocks' => []], ['columns_too_wide']],
        'widths and gaps past the page by one point' => [['page' => ['columns' => ['count' => 2, 'widths' => [300, 151], 'space' => 18]], 'blocks' => []], ['columns_too_wide']],
        'an enormous width' => [['page' => ['columns' => ['count' => 2, 'widths' => [1.0E308, 100]]], 'blocks' => []], ['columns_too_wide']],
        'too few widths' => [['page' => ['columns' => ['count' => 3, 'widths' => [100, 100]]], 'blocks' => []], ['columns_width_invalid']],
        'a zero width' => [['page' => ['columns' => ['count' => 2, 'widths' => [0, 100]]], 'blocks' => []], ['columns_width_invalid']],
        'a negative width' => [['page' => ['columns' => ['count' => 2, 'widths' => [-100, 100]]], 'blocks' => []], ['columns_width_invalid']],
        'a width under a quarter inch' => [['page' => ['columns' => ['count' => 2, 'widths' => [17, 100]]], 'blocks' => []], ['columns_width_invalid']],
        'widths that are not a list' => [['page' => ['columns' => ['count' => 2, 'widths' => ['a' => 100, 'b' => 100]]], 'blocks' => []], ['columns_width_invalid']],
        'gaps with no widths' => [['page' => ['columns' => ['count' => 2, 'spaces' => [12]]], 'blocks' => []], ['columns_invalid']],
        'space and gaps both' => [['page' => ['columns' => ['count' => 2, 'widths' => [100, 100], 'space' => 12, 'spaces' => [12]]], 'blocks' => []], ['columns_invalid']],
        'too many gaps' => [['page' => ['columns' => ['count' => 2, 'widths' => [100, 100], 'spaces' => [12, 12]]], 'blocks' => []], ['columns_space_invalid']],
        'a negative gap' => [['page' => ['columns' => ['count' => 2, 'widths' => [100, 100], 'spaces' => [-12]]], 'blocks' => []], ['columns_space_invalid']],
        'on a break too' => [['blocks' => [colP('a'), ['type' => 'pageBreak', 'page' => ['columns' => ['count' => 50]]], colP('b')]], ['columns_count_exceeded']],
        'inherited by a narrower page' => [['page' => ['orientation' => 'landscape', 'columns' => ['count' => 2, 'widths' => [500, 130], 'space' => 18]], 'blocks' => [colP('a'), ['type' => 'pageBreak', 'page' => ['orientation' => 'portrait']], colP('b')]], ['columns_too_wide']],
    ]);

    it('refuses a start it does not know, and a continuous break that would need a new page', function (array $doc, array $codes) {
        expect(colCodes($doc))->toBe($codes);
    })->with([
        'oddPage (not in this release)' => [['blocks' => [colP('a'), ['type' => 'pageBreak', 'page' => ['start' => 'oddPage']], colP('b')]], ['section_start_invalid']],
        'nonsense' => [['blocks' => [colP('a'), ['type' => 'pageBreak', 'page' => ['start' => 'sideways']], colP('b')]], ['section_start_invalid']],
        'not a string' => [['blocks' => [colP('a'), ['type' => 'pageBreak', 'page' => ['start' => true]], colP('b')]], ['section_start_invalid']],
        'on the document page' => [['page' => ['start' => 'continuous'], 'blocks' => []], ['section_start_invalid']],
        'continuous, a new orientation' => [['blocks' => [colP('a'), ['type' => 'pageBreak', 'page' => ['start' => 'continuous', 'orientation' => 'landscape']], colP('b')]], ['section_continuous_invalid']],
        'continuous, new margins' => [['blocks' => [colP('a'), ['type' => 'pageBreak', 'page' => ['start' => 'continuous', 'margins' => ['left' => 36]]], colP('b')]], ['section_continuous_invalid']],
        'continuous, a header' => [['blocks' => [colP('a'), ['type' => 'pageBreak', 'page' => ['start' => 'continuous', 'header' => ['left' => 'H']]], colP('b')]], ['section_continuous_invalid']],
    ]);

    it('refuses a column break where there are no columns, or where Word cannot break a column', function (array $doc, array $codes) {
        expect(colCodes($doc))->toBe($codes);
    })->with([
        'one column' => [['blocks' => [colP('a'), ['type' => 'columnBreak'], colP('b')]], ['column_break_outside_columns']],
        'after a break back to one' => [['page' => ['columns' => ['count' => 2]], 'blocks' => [colP('a'), ['type' => 'pageBreak', 'page' => ['columns' => ['count' => 1]]], ['type' => 'columnBreak']]], ['column_break_outside_columns']],
        'in a quote' => [['page' => ['columns' => ['count' => 2]], 'blocks' => [['type' => 'quote', 'blocks' => [colP('a'), ['type' => 'columnBreak']]]]], ['column_break_nested']],
        'in a table cell' => [['page' => ['columns' => ['count' => 2]], 'blocks' => [['type' => 'table', 'rows' => [['cells' => [['blocks' => [['type' => 'columnBreak']]]]]]]]], ['column_break_nested']],
    ]);

    it('refuses a column break in a header and in a footnote', function (array $doc, string $message) {
        expect(array_column(Agent::validate($doc), 'message'))->toContain($message);
    })->with([
        'header' => [['page' => ['columns' => ['count' => 2], 'header' => ['blocks' => [colP('H'), ['type' => 'columnBreak']]]], 'blocks' => [colP('a')]], 'a header or footer cannot hold a columnBreak block'],
        'footnote' => [['page' => ['columns' => ['count' => 2]], 'blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'a'], ['text' => '', 'footnote' => ['blocks' => [colP('n'), ['type' => 'columnBreak']]]]]]]], 'a footnote cannot hold a columnBreak block'],
    ]);

    it('refuses columns typed on the break itself, as for any page key', function () {
        expect(array_column(Agent::validate(['blocks' => [colP('a'), ['type' => 'pageBreak', 'columns' => ['count' => 2]], colP('b')]]), 'message'))
            ->toContain('a pageBreak has no columns key; use pageBreak.page');
    });
});

/* ---------------- what repair does ---------------- */

describe('the hostile set: repair', function () {
    it('repairs columns to the nearest thing it can write, and says so', function (array $doc, mixed $columns) {
        $r = Agent::validateAndRepair($doc);

        expect($r['ok'])->toBeTrue()
            ->and($r['schema']['page']['columns'] ?? null)->toBe($columns)
            ->and($r['errors'])->not->toBe([]);
    })->with([
        'count 46: 45, then as many as Letter holds' => [['page' => ['columns' => ['count' => 46]], 'blocks' => []], ['count' => 26, 'space' => 0]],
        'negative spacing: none' => [['page' => ['columns' => ['count' => 2, 'space' => -5]], 'blocks' => []], ['count' => 2, 'space' => 0]],
        'gaps too wide: as wide as fits' => [['page' => ['columns' => ['count' => 2, 'space' => 500]], 'blocks' => []], ['count' => 2, 'space' => 432]],
        'widths past the page: equal columns' => [['page' => ['columns' => ['count' => 2, 'widths' => [300, 300], 'space' => 18]], 'blocks' => []], ['count' => 2, 'space' => 18]],
        'a bad width: equal columns' => [['page' => ['columns' => ['count' => 2, 'widths' => [0, 100]]], 'blocks' => []], ['count' => 2]],
        'an unknown key: dropped' => [['page' => ['columns' => ['count' => 2, 'gutter' => 5]], 'blocks' => []], ['count' => 2]],
        'not an object: no columns' => [['page' => ['columns' => 'two'], 'blocks' => []], null],
        'count 0: no columns' => [['page' => ['columns' => ['count' => 0]], 'blocks' => []], null],
        'one column with a gap: just one column' => [['page' => ['columns' => ['count' => 1, 'space' => 12]], 'blocks' => []], ['count' => 1]],
    ]);

    it('drops a column break it cannot keep, and keeps every word', function () {
        $doc = ['blocks' => [colP('a'), ['type' => 'columnBreak'], ['type' => 'quote', 'blocks' => [colP('q'), ['type' => 'columnBreak']]], colP('b')]];
        $r = Agent::validateAndRepair($doc);

        expect($r['ok'])->toBeTrue()
            ->and($r['schema']['blocks'])->toBe([colP('a'), ['type' => 'quote', 'blocks' => [colP('q')]], colP('b')]);
    });

    it('turns a continuous break that changes the page into a new page, keeping the change', function () {
        $r = Agent::validateAndRepair(['blocks' => [colP('a'), ['type' => 'pageBreak', 'page' => ['start' => 'continuous', 'orientation' => 'landscape', 'columns' => ['count' => 2]]], colP('b')]]);

        expect($r['ok'])->toBeTrue()
            ->and($r['schema']['blocks'][1])->toBe(['type' => 'pageBreak', 'page' => ['orientation' => 'landscape', 'columns' => ['count' => 2]]]);
    });

    it('drops a start it does not know, and moves columns typed on the break into its page', function () {
        $r = Agent::validateAndRepair(['blocks' => [colP('a'), ['type' => 'pageBreak', 'columns' => ['count' => 2], 'page' => ['start' => 'oddPage']], colP('b')]]);

        expect($r['ok'])->toBeTrue()
            ->and($r['schema']['blocks'][1])->toBe(['type' => 'pageBreak', 'page' => ['columns' => ['count' => 2]]]);
    });

    it('fixes columns a narrower page inherits on the break that narrows it', function () {
        $r = Agent::validateAndRepair(['page' => ['orientation' => 'landscape', 'columns' => ['count' => 2, 'widths' => [500, 130], 'space' => 18]], 'blocks' => [colP('a'), ['type' => 'pageBreak', 'page' => ['orientation' => 'portrait']], colP('b')]]);

        expect($r['ok'])->toBeTrue()
            ->and($r['schema']['page']['columns'])->toBe(['count' => 2, 'widths' => [500, 130], 'space' => 18])
            ->and($r['schema']['blocks'][1]['page'])->toBe(['orientation' => 'portrait', 'columns' => ['count' => 2, 'space' => 18]]);
    });
});

/* ---------------- what reading a hostile file does ---------------- */

describe('the hostile set: reading', function () {
    it('degrades malformed w:cols to columns it can hold, keeps the text, and reports it', function (string $cols, mixed $columns) {
        $read = Agent::read(colDocx(colTextP('Kept text') . '<w:sectPr>' . COL_LETTER . $cols . '</w:sectPr>'));

        expect($read['page']['columns'] ?? null)->toBe($columns)
            ->and(colTexts($read))->toBe(['Kept text'])
            ->and($read['unrepresented'] ?? [])->toContain(['where' => '/page', 'kind' => 'layout-not-preserved', 'count' => 1])
            ->and(Agent::validate($read))->toBe([]);
    })->with([
        '99 columns: 45, then as many as fit' => ['<w:cols w:num="99" w:space="720"/>', ['count' => 26, 'space' => 0]],
        'a billion columns' => ['<w:cols w:num="1000000000"/>', ['count' => 26, 'space' => 0]],
        'num not a number' => ['<w:cols w:num="abc"/>', null],
        'num zero' => ['<w:cols w:num="0"/>', null],
        'num negative' => ['<w:cols w:num="-3"/>', null],
        'negative space' => ['<w:cols w:num="2" w:space="-720"/>', ['count' => 2]],
        'space past the page' => ['<w:cols w:num="2" w:space="99999"/>', ['count' => 2, 'space' => 432]],
        'unequal, a zero width' => ['<w:cols w:num="2" w:equalWidth="0"><w:col w:w="0" w:space="720"/><w:col w:w="4000"/></w:cols>', ['count' => 2]],
        'unequal, past the page' => ['<w:cols w:num="2" w:equalWidth="0"><w:col w:w="6000" w:space="720"/><w:col w:w="6000"/></w:cols>', ['count' => 2]],
        'unequal, an overflowing width' => ['<w:cols w:num="2" w:equalWidth="0"><w:col w:w="99999999999999999999999" w:space="720"/><w:col w:w="4000"/></w:cols>', ['count' => 2]],
        'unequal, too few w:col for w:num' => ['<w:cols w:num="3" w:equalWidth="0"><w:col w:w="3000" w:space="360"/><w:col w:w="3000"/></w:cols>', ['count' => 3]],
    ]);

    it('reads 100,000 w:col children without walking them all, and keeps the text', function () {
        $read = Agent::read(colDocx(colTextP('Kept text') . '<w:sectPr>' . COL_LETTER
            . '<w:cols w:equalWidth="0">' . str_repeat('<w:col w:w="100"/>', 100000) . '</w:cols></w:sectPr>'));

        expect(colTexts($read))->toBe(['Kept text'])
            ->and($read['page']['columns'] ?? null)->toBeNull()
            ->and($read['unrepresented'] ?? [])->toContain(['where' => '/page', 'kind' => 'layout-not-preserved', 'count' => 1]);
    });

    it('keeps the text of a column break the model cannot hold, and reports the break', function (string $body) {
        $read = Agent::read(colDocx($body));

        expect(colTexts($read))->toBe(['Before', 'After'])
            ->and(array_column($read['blocks'], 'type'))->not->toContain('columnBreak')
            ->and($read['unrepresented'] ?? [])->toContain(['where' => '/blocks', 'kind' => 'layout-not-preserved', 'count' => 1])
            ->and(Agent::validate($read))->toBe([]);
    })->with([
        'in a one-column section' => [colTextP('Before') . '<w:p><w:r><w:br w:type="column"/></w:r></w:p>' . colTextP('After') . '<w:sectPr>' . COL_LETTER . '</w:sectPr>'],
        'in a table cell' => ['<w:tbl><w:tblPr/><w:tblGrid><w:gridCol w:w="4320"/></w:tblGrid><w:tr><w:tc><w:p><w:r><w:t>Before</w:t><w:br w:type="column"/></w:r></w:p></w:tc></w:tr></w:tbl>'
            . colTextP('After') . '<w:sectPr>' . COL_LETTER . '<w:cols w:num="2" w:space="720"/></w:sectPr>'],
    ]);

    it('reads a continuous section that changes more than columns as the columns, and reports the rest', function () {
        $narrow = '<w:pgSz w:w="12240" w:h="15840"/><w:pgMar w:top="720" w:right="1440" w:bottom="1440" w:left="1440" w:header="720" w:footer="720" w:gutter="0"/>';
        $read = Agent::read(colDocx(
            '<w:p><w:pPr><w:sectPr>' . COL_LETTER . '</w:sectPr></w:pPr><w:r><w:t>One</w:t></w:r></w:p>'
            . colTextP('Two') . '<w:sectPr><w:type w:val="continuous"/>' . $narrow . '<w:cols w:num="2" w:space="720"/></w:sectPr>'
        ));

        expect($read['blocks'])->toBe([colP('One'), ['type' => 'pageBreak', 'page' => ['start' => 'continuous', 'columns' => ['count' => 2]]], colP('Two')])
            ->and($read['unrepresented'] ?? [])->toBe([['where' => '/blocks/1', 'kind' => 'layout-not-preserved', 'count' => 1]]);
    });
});

/* ---------------- what else reads the model ---------------- */

describe('describe, markdown, portable and layout', function () {
    it('describe() says which stretches are in columns, and counts the breaks', function () {
        $doc = ['blocks' => [
            colP('Masthead'),
            ['type' => 'pageBreak', 'page' => ['start' => 'continuous', 'columns' => ['count' => 3]]],
            colP('a'), ['type' => 'columnBreak'], colP('b'),
            ['type' => 'pageBreak', 'page' => ['start' => 'continuous', 'columns' => ['count' => 1]]],
            colP('c'),
        ]];

        expect(Agent::describe($doc))->toContain('Columns: 1, 3, 1 (by stretch); column breaks: 1; continuous breaks: 2')
            ->and(Agent::describe(['blocks' => [colP('a')]]))->not->toContain('Columns:');
    });

    it('markdown drops a column break, and reports it', function () {
        $r = Agent::toMarkdownWithReport(['page' => ['columns' => ['count' => 2]], 'blocks' => [colP('a'), ['type' => 'columnBreak'], colP('b')]]);

        expect($r['markdown'])->toBe("a\n\nb\n")
            ->and(json_encode($r['report']))->toContain('"\/blocks\/1"')
            ->and(json_encode($r['report']))->toContain('layout-not-preserved');
    });

    it('portable content carries no column break, and reports it', function () {
        $out = Agent::toPortable(['page' => ['columns' => ['count' => 2]], 'blocks' => [colP('a'), ['type' => 'columnBreak'], colP('b')]]);

        expect(json_encode($out))->not->toContain('columnBreak')
            ->and(json_encode($out))->toContain('layout-not-preserved');
    });

    it('layout() says it does not lay out columns or continuous breaks', function () {
        $doc = ['blocks' => [colP('a'), ['type' => 'pageBreak', 'page' => ['start' => 'continuous', 'columns' => ['count' => 2]]], colP('b'), ['type' => 'columnBreak'], colP('c')]];
        expect(Agent::validate($doc))->toBe([]);
        $layout = Agent::layout($doc);

        expect($layout['approximate'])->toContain('columns')
            ->and($layout['approximate'])->toContain('continuous section break');
    });
});
