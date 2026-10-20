<?php

declare(strict_types=1);

use LastWord\Agent;
use LastWord\Helpers\Chart;

/*
 * The `chart` block (last-word#4): a native Word chart -- word/charts/chartN.xml,
 * an inline drawing in the body, and an embedded workbook so Word's "Edit Data"
 * opens the numbers -- written from the same ECharts `option` dark-slide's chart
 * element takes, so one prompt teaches charts in both formats.
 */

/** @return array<string, array<string, mixed>> */
function lwChartCases(): array
{
    $cases = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/charts.json'), true, 512, JSON_THROW_ON_ERROR);
    unset($cases['_comment']);

    return $cases;
}

/** @return array<string, mixed> */
function lwChartCase(string $name): array
{
    return lwChartCases()[$name];
}

/**
 * Every part of a zip, by name.
 *
 * @return array<string, string>
 */
function lwUnzip(string $bytes): array
{
    $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lw-chart-' . bin2hex(random_bytes(6)) . '.zip';
    file_put_contents($tmp, $bytes);
    $parts = [];
    try {
        $zip = new ZipArchive();
        expect($zip->open($tmp, ZipArchive::RDONLY))->toBeTrue();
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $parts[(string) $zip->getNameIndex($i)] = (string) $zip->getFromIndex($i);
        }
        $zip->close();
    } finally {
        @unlink($tmp);
    }

    return $parts;
}

/** A chart part's XML, as a DOMXPath with c: / a: registered. */
function lwChartXPath(string $xml): DOMXPath
{
    $dom = new DOMDocument();
    expect($dom->loadXML($xml))->toBeTrue();
    $xp = new DOMXPath($dom);
    $xp->registerNamespace('c', 'http://schemas.openxmlformats.org/drawingml/2006/chart');
    $xp->registerNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
    $xp->registerNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

    return $xp;
}

function lwXValue(DOMXPath $xp, string $query): ?string
{
    $node = $xp->query($query)->item(0);

    return $node === null ? null : ($node instanceof DOMAttr ? $node->value : $node->textContent);
}

// ─── The package ──────────────────────────────────────────────────────────

it('writes a chart part, its relationship, its content type and an embedded workbook', function () {
    $parts = lwUnzip(Agent::toBytes(lwChartCase('column')));

    expect($parts)->toHaveKeys([
        'word/charts/chart1.xml',
        'word/charts/_rels/chart1.xml.rels',
        'word/embeddings/Microsoft_Excel_Worksheet1.xlsx',
    ]);

    expect($parts['[Content_Types].xml'])
        ->toContain('<Override PartName="/word/charts/chart1.xml" ContentType="application/vnd.openxmlformats-officedocument.drawingml.chart+xml"/>')
        ->toContain('<Default Extension="xlsx" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"/>');

    expect($parts['word/_rels/document.xml.rels'])
        ->toMatch('#<Relationship Id="rId\d+" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/chart" Target="charts/chart1.xml"/>#');

    expect($parts['word/charts/_rels/chart1.xml.rels'])
        ->toContain('Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/package" Target="../embeddings/Microsoft_Excel_Worksheet1.xlsx"');

    // The chart points at its workbook, so "Edit Data" opens the numbers.
    $xp = lwChartXPath($parts['word/charts/chart1.xml']);
    expect(lwXValue($xp, '/c:chartSpace/c:externalData/@r:id'))->toBe('rId1');
});

it('puts the chart inline in the body, sized from widthPx / heightPx', function () {
    $parts = lwUnzip(Agent::toBytes(lwChartCase('column')));
    $body = $parts['word/document.xml'];

    expect($body)->toContain('<a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/chart">')
        ->toContain('<wp:extent cx="5715000" cy="3048000"/>')
        ->toContain('descr="Revenue by quarter, 2025 against 2026"');
    // Unique drawing ids: a chart must never share a docPr id with an image.
    expect($body)->toMatch('#<wp:docPr id="\d+" name="Chart 1"#');
});

it('sizes a chart to the text column by default, and to a percentage of it with width', function () {
    $full = lwUnzip(Agent::toBytes(lwChartCase('minimal')))['word/document.xml'];
    // 9360 twips of text column = 5943600 EMU; the default height is 0.6 of the width.
    expect($full)->toContain('<wp:extent cx="5943600" cy="3566160"/>');

    $doc = lwChartCase('minimal');
    $doc['blocks'][0]['width'] = 50;
    $half = lwUnzip(Agent::toBytes($doc))['word/document.xml'];
    expect($half)->toContain('<wp:extent cx="2971800" cy="1783080"/>');

    // Never wider than the column, whatever widthPx says.
    $doc = lwChartCase('minimal');
    $doc['blocks'][0]['widthPx'] = 2000;
    $capped = lwUnzip(Agent::toBytes($doc))['word/document.xml'];
    expect($capped)->toContain('<wp:extent cx="5943600" ');
});

it('embeds a real workbook holding the plotted numbers', function () {
    $xlsx = lwUnzip(lwUnzip(Agent::toBytes(lwChartCase('column')))['word/embeddings/Microsoft_Excel_Worksheet1.xlsx']);

    expect($xlsx)->toHaveKeys(['[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels', 'xl/worksheets/sheet1.xml', 'xl/styles.xml']);

    $sheet = $xlsx['xl/worksheets/sheet1.xml'];
    expect($sheet)
        ->toContain('<c r="B1" t="inlineStr"><is><t xml:space="preserve">2025</t></is></c>')
        ->toContain('<c r="C1" t="inlineStr"><is><t xml:space="preserve">2026</t></is></c>')
        ->toContain('<c r="A2" t="inlineStr"><is><t xml:space="preserve">Q1</t></is></c>')
        ->toContain('<c r="C4" s="1"><v>171.5</v></c>');
    expect($xlsx['xl/styles.xml'])->toContain('<numFmt numFmtId="164" formatCode="#,##0"/>');
    expect($xlsx['xl/workbook.xml'])->toContain('<sheet name="Sheet1" sheetId="1" r:id="rId1"/>');

    // The series reference those cells, so the chart and the sheet agree.
    $xp = lwChartXPath(lwUnzip(Agent::toBytes(lwChartCase('column')))['word/charts/chart1.xml']);
    expect(lwXValue($xp, '//c:ser[1]/c:tx/c:strRef/c:f'))->toBe('Sheet1!$B$1')
        ->and(lwXValue($xp, '//c:ser[2]/c:cat/c:strRef/c:f'))->toBe('Sheet1!$A$2:$A$5')
        ->and(lwXValue($xp, '//c:ser[2]/c:val/c:numRef/c:f'))->toBe('Sheet1!$C$2:$C$5')
        ->and(lwXValue($xp, '//c:ser[2]/c:val/c:numRef/c:numCache/c:pt[@idx="2"]/c:v'))->toBe('171.5')
        ->and(lwXValue($xp, '//c:ser[2]/c:val/c:numRef/c:numCache/c:formatCode'))->toBe('#,##0');
});

// ─── Chart kinds ──────────────────────────────────────────────────────────

it('writes an ECharts bar series over a category x-axis as a COLUMN chart', function () {
    $xp = lwChartXPath(lwUnzip(Agent::toBytes(lwChartCase('column')))['word/charts/chart1.xml']);

    expect(lwXValue($xp, '//c:barChart/c:barDir/@val'))->toBe('col')
        ->and(lwXValue($xp, '//c:barChart/c:grouping/@val'))->toBe('clustered')
        ->and(lwXValue($xp, '//c:catAx/c:axPos/@val'))->toBe('b')
        ->and(lwXValue($xp, '//c:valAx/c:axPos/@val'))->toBe('l');
});

it('writes a category y-axis as a HORIZONTAL bar chart, as ECharts draws it', function () {
    $xp = lwChartXPath(lwUnzip(Agent::toBytes(lwChartCase('horizontalBar')))['word/charts/chart1.xml']);

    expect(lwXValue($xp, '//c:barChart/c:barDir/@val'))->toBe('bar')
        ->and(lwXValue($xp, '//c:catAx/c:axPos/@val'))->toBe('l')
        ->and(lwXValue($xp, '//c:valAx/c:axPos/@val'))->toBe('b')
        // The category axis title comes from the CATEGORY axis, wherever it sits.
        ->and(lwXValue($xp, '//c:catAx/c:title//a:t'))->toBe('Region')
        ->and(lwXValue($xp, '//c:valAx/c:title//a:t'))->toBe('People')
        // One highlighted point.
        ->and(lwXValue($xp, '//c:ser/c:dPt[c:idx/@val="1"]/c:spPr/a:solidFill/a:srgbClr/@val'))->toBe('DC2626');
});

it('stacks bar series that name a stack', function () {
    $xp = lwChartXPath(lwUnzip(Agent::toBytes(lwChartCase('stackedColumn')))['word/charts/chart1.xml']);

    expect(lwXValue($xp, '//c:barChart/c:grouping/@val'))->toBe('stacked')
        ->and(lwXValue($xp, '//c:barChart/c:overlap/@val'))->toBe('100')
        // outEnd is not a legal label position on a stacked bar; Word rejects the file.
        ->and(lwXValue($xp, '//c:ser[2]/c:dLbls/c:dLblPos/@val'))->toBe('ctr');
});

it('writes line and pie charts', function () {
    $line = lwChartXPath(lwUnzip(Agent::toBytes(lwChartCase('line')))['word/charts/chart1.xml']);
    expect($line->query('//c:lineChart/c:ser')->length)->toBe(2)
        ->and(lwXValue($line, '//c:ser[1]/c:smooth/@val'))->toBe('1')
        ->and(lwXValue($line, '//c:ser[2]/c:smooth/@val'))->toBe('0')
        ->and(lwXValue($line, '//c:ser[2]/c:spPr/a:ln/a:solidFill/a:srgbClr/@val'))->toBe('94A3B8')
        ->and(lwXValue($line, '//c:valAx/c:numFmt/@formatCode'))->toBe('0.0%');

    $pie = lwChartXPath(lwUnzip(Agent::toBytes(lwChartCase('pie')))['word/charts/chart1.xml']);
    expect($pie->query('//c:pieChart/c:ser/c:dPt')->length)->toBe(4)
        ->and(lwXValue($pie, '//c:ser/c:dPt[c:idx/@val="1"]/c:spPr/a:solidFill/a:srgbClr/@val'))->toBe('0F766E')
        ->and(lwXValue($pie, '//c:ser/c:cat//c:pt[@idx="3"]/c:v'))->toBe('Other')
        ->and(lwXValue($pie, '//c:ser/c:dLbls/c:dLblPos/@val'))->toBe('bestFit')
        ->and($pie->query('//c:catAx')->length)->toBe(0);
});

it('writes the title, legend, axis titles and data labels the option asks for -- and only those', function () {
    $xp = lwChartXPath(lwUnzip(Agent::toBytes(lwChartCase('column')))['word/charts/chart1.xml']);
    expect(lwXValue($xp, '/c:chartSpace/c:chart/c:title//a:t'))->toBe('Revenue by quarter')
        ->and($xp->query('/c:chartSpace/c:chart/c:legend')->length)->toBe(1)
        ->and(lwXValue($xp, '//c:catAx/c:title//a:t'))->toBe('Quarter')
        ->and(lwXValue($xp, '//c:valAx/c:title//a:t'))->toBe('USD (thousands)')
        ->and($xp->query('//c:ser[1]/c:dLbls')->length)->toBe(0)
        ->and(lwXValue($xp, '//c:ser[2]/c:dLbls/c:showVal/@val'))->toBe('1')
        ->and(lwXValue($xp, '//c:ser[2]/c:dLbls/c:numFmt/@formatCode'))->toBe('#,##0')
        // Rounded corners are Word's default when the element is absent.
        ->and(lwXValue($xp, '/c:chartSpace/c:roundedCorners/@val'))->toBe('0');

    $bare = lwChartXPath(lwUnzip(Agent::toBytes(lwChartCase('minimal')))['word/charts/chart1.xml']);
    expect($bare->query('/c:chartSpace/c:chart/c:title')->length)->toBe(0)
        ->and(lwXValue($bare, '/c:chartSpace/c:chart/c:autoTitleDeleted/@val'))->toBe('1')
        ->and($bare->query('/c:chartSpace/c:chart/c:legend')->length)->toBe(0)
        ->and(lwXValue($bare, '//c:ser/c:tx//c:v'))->toBe('Series 1');
});

it('colours series from itemStyle, then option.color, then the default palette', function () {
    $doc = lwChartCase('stackedColumn');
    $doc['blocks'][0]['option']['color'] = ['#112233', '#445566'];
    $xp = lwChartXPath(lwUnzip(Agent::toBytes($doc))['word/charts/chart1.xml']);
    expect(lwXValue($xp, '//c:ser[1]/c:spPr/a:solidFill/a:srgbClr/@val'))->toBe('112233')
        ->and(lwXValue($xp, '//c:ser[2]/c:spPr/a:solidFill/a:srgbClr/@val'))->toBe('445566');

    $xp = lwChartXPath(lwUnzip(Agent::toBytes(lwChartCase('column')))['word/charts/chart1.xml']);
    expect(lwXValue($xp, '//c:ser[1]/c:spPr/a:solidFill/a:srgbClr/@val'))->toBe(Chart::DEFAULT_PALETTE[0])
        ->and(lwXValue($xp, '//c:ser[2]/c:spPr/a:solidFill/a:srgbClr/@val'))->toBe('1F3A5F');
});

it('starts the value axis of a non-negative bar chart at zero', function () {
    $xp = lwChartXPath(lwUnzip(Agent::toBytes(lwChartCase('column')))['word/charts/chart1.xml']);
    expect(lwXValue($xp, '//c:valAx/c:scaling/c:min/@val'))->toBe('0');
});

it('numbers several charts and keeps their parts apart', function () {
    $doc = ['blocks' => array_merge(lwChartCase('column')['blocks'], lwChartCase('pie')['blocks'], [['type' => 'image', 'src' => lwRedPngDataUrl()]])];
    $parts = lwUnzip(Agent::toBytes($doc));

    expect($parts)->toHaveKeys(['word/charts/chart1.xml', 'word/charts/chart2.xml', 'word/embeddings/Microsoft_Excel_Worksheet2.xlsx', 'word/media/image1.png']);
    preg_match_all('#<wp:docPr id="(\d+)"#', $parts['word/document.xml'], $m);
    expect(count($m[1]))->toBe(3)
        ->and(count(array_unique($m[1])))->toBe(3);
});

it('writes identical bytes twice for a document with a chart', function () {
    $doc = lwChartCase('column');

    expect(hash('sha256', Agent::toBytes($doc)))->toBe(hash('sha256', Agent::toBytes($doc)));
});

// ─── Numbers ──────────────────────────────────────────────────────────────

it('formats numbers the same way in every engine: shortest round-trip, never an exponent', function () {
    expect(Chart::num(120.0))->toBe('120')
        ->and(Chart::num(-0.0))->toBe('0')
        ->and(Chart::num(171.5))->toBe('171.5')
        ->and(Chart::num(0.1))->toBe('0.1')
        ->and(Chart::num(-1.25))->toBe('-1.25')
        ->and(Chart::num(1234567.891))->toBe('1234567.891')
        ->and(Chart::num(3.0e-7))->toBe('0.0000003')
        ->and(Chart::num(1.0e16))->toBe('10000000000000000')
        ->and(Chart::num(0.1 + 0.2))->toBe('0.30000000000000004')
        ->and(Chart::num(1.0e21))->toBe('1000000000000000000000')
        ->and(Chart::num(INF))->toBe('0');
});

it('spells a numeric label with the same rule, and reads back plain decimals only', function () {
    expect(Chart::label(2025.0))->toBe('2025')
        ->and(Chart::label(1.0e16))->toBe('10000000000000000')
        ->and(Chart::label(7))->toBe('7')
        ->and(Chart::label('Q1'))->toBe('Q1')
        ->and(Chart::label(true))->toBeNull()
        ->and(Chart::parseNum(' 12.5 '))->toBe(12.5)
        ->and(Chart::parseNum('120'))->toBe(120)
        ->and(Chart::parseNum('3E-7'))->toBe(3.0e-7)
        ->and(Chart::parseNum('0x10'))->toBe(0)
        ->and(Chart::parseNum(''))->toBe(0);
});

// ─── Reading back ─────────────────────────────────────────────────────────

it('reads every canonical chart back to the document that wrote it', function (string $name) {
    $doc = lwChartCase($name);

    expect(Agent::read(Agent::toBytes($doc)))->toEqual($doc);
})->with(array_keys(lwChartCases()));

it('reads a percentage width back as the widthPx it resolved to', function () {
    $doc = lwChartCase('minimal');
    $doc['blocks'][0]['width'] = 50;

    $chart = Agent::read(Agent::toBytes($doc))['blocks'][0];
    expect($chart['widthPx'])->toBe(312)
        ->and($chart['heightPx'])->toBe(187)
        ->and($chart)->not->toHaveKey('width');
});

it('reads option.color back as each series\' own colour', function () {
    $doc = lwChartCase('stackedColumn');
    $doc['blocks'][0]['option']['color'] = ['#112233', '#445566'];

    $series = Agent::read(Agent::toBytes($doc))['blocks'][0]['option']['series'];
    expect($series[0]['itemStyle'])->toBe(['color' => '#112233'])
        ->and($series[1]['itemStyle'])->toBe(['color' => '#445566']);
});

it('reads a Word-authored chart that uses numRef caches and an unmodelled extra', function () {
    $doc = lwChartCase('column');
    $bytes = Agent::toBytes($doc);
    // Word writes c:lang and a c:txPr on the chart; neither changes the model.
    $patched = lwPatchPart($bytes, 'word/charts/chart1.xml', static fn (string $xml): string => str_replace(
        '<c:date1904 val="0"/>',
        '<c:date1904 val="0"/><c:lang val="en-US"/>',
        $xml,
    ));

    expect(Agent::read($patched))->toEqual($doc);
});

it('ignores a chart it cannot map instead of failing the read', function () {
    $doc = ['blocks' => [
        ['type' => 'paragraph', 'runs' => [['text' => 'Before']]],
        ...lwChartCase('minimal')['blocks'],
        ['type' => 'paragraph', 'runs' => [['text' => 'After']]],
    ]];
    $bytes = Agent::toBytes($doc);

    // A radar chart: outside the model.
    $radar = lwPatchPart($bytes, 'word/charts/chart1.xml', static fn (string $xml): string => str_replace(
        ['<c:barChart>', '</c:barChart>'],
        ['<c:radarChart>', '</c:radarChart>'],
        $xml,
    ));
    expect(Agent::read($radar)['blocks'])->toBe([
        ['type' => 'paragraph', 'runs' => [['text' => 'Before']]],
        ['type' => 'paragraph', 'runs' => [['text' => 'After']]],
    ]);

    // A chart part that is not even XML.
    $broken = lwPatchPart($bytes, 'word/charts/chart1.xml', static fn (): string => 'not xml <<<');
    expect(Agent::read($broken)['blocks'])->toHaveCount(2);

    // A chart part that is missing altogether.
    $missing = lwPatchPart($bytes, 'word/charts/chart1.xml', null);
    expect(Agent::read($missing)['blocks'])->toHaveCount(2);
});

/**
 * Rewrite (or, with null, delete) one part of a zip.
 *
 * @param  (callable(string): string)|null  $edit
 */
function lwPatchPart(string $bytes, string $part, ?callable $edit): string
{
    $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lw-patch-' . bin2hex(random_bytes(6)) . '.zip';
    file_put_contents($tmp, $bytes);
    try {
        $zip = new ZipArchive();
        $zip->open($tmp);
        if ($edit === null) {
            $zip->deleteName($part);
        } else {
            $zip->addFromString($part, $edit((string) $zip->getFromName($part)));
        }
        $zip->close();

        return (string) file_get_contents($tmp);
    } finally {
        @unlink($tmp);
    }
}

// ─── Teaching it ──────────────────────────────────────────────────────────

it('accepts every canonical chart', function (string $name) {
    expect(Agent::validate(lwChartCase($name)))->toBe([]);
})->with(array_keys(lwChartCases()));

it('rejects a chart an agent got wrong, saying how to fix it', function (array $block, string $path, string $needle) {
    $errors = Agent::validate(['blocks' => [$block]]);

    $paths = array_column($errors, 'path');
    expect($paths)->toContain($path);
    $message = $errors[array_search($path, $paths, true)]['message'];
    expect($message)->toContain($needle);
})->with([
    'no option' => [['type' => 'chart'], 'blocks[0].option', 'option'],
    'no series' => [['type' => 'chart', 'option' => ['xAxis' => ['type' => 'category', 'data' => ['a']]]], 'blocks[0].option.series', 'series'],
    'unsupported type' => [['type' => 'chart', 'option' => ['series' => [['type' => 'radar', 'data' => [1]]]]], 'blocks[0].option.series[0].type', 'bar, line, pie'],
    'mixed types' => [['type' => 'chart', 'option' => ['xAxis' => ['type' => 'category', 'data' => ['a']], 'series' => [['type' => 'bar', 'data' => [1]], ['type' => 'line', 'data' => [2]]]]], 'blocks[0].option.series[1].type', 'same type'],
    'two pies' => [['type' => 'chart', 'option' => ['series' => [['type' => 'pie', 'data' => [['name' => 'a', 'value' => 1]]], ['type' => 'pie', 'data' => [['name' => 'b', 'value' => 1]]]]]], 'blocks[0].option.series', 'one series'],
    'data not a list' => [['type' => 'chart', 'option' => ['series' => [['type' => 'bar', 'data' => 'oops']]]], 'blocks[0].option.series[0].data', 'data'],
    'bad point' => [['type' => 'chart', 'option' => ['series' => [['type' => 'bar', 'data' => [1, 'x']]]]], 'blocks[0].option.series[0].data[1]', 'number'],
    'values instead of data' => [['type' => 'chart', 'option' => ['series' => [['type' => 'bar', 'values' => [1]]]]], 'blocks[0].option.series[0].data', 'data'],
    'bad colour' => [['type' => 'chart', 'option' => ['series' => [['type' => 'bar', 'data' => [1], 'itemStyle' => ['color' => 'red']]]]], 'blocks[0].option.series[0].itemStyle.color', '#RRGGBB'],
    'bad width' => [['type' => 'chart', 'width' => 150, 'option' => ['series' => [['type' => 'bar', 'data' => [1]]]]], 'blocks[0].width', 'percentage'],
    'bad numberFormat' => [['type' => 'chart', 'numberFormat' => 7, 'option' => ['series' => [['type' => 'bar', 'data' => [1]]]]], 'blocks[0].numberFormat', 'number format'],
]);

it('repairs the shorthand an agent reaches for into an ECharts option', function () {
    $result = Agent::validateAndRepair(['blocks' => [[
        'type' => 'chart',
        'chartType' => 'column',
        'title' => 'Revenue',
        'categories' => ['Q1', 'Q2'],
        'series' => [['name' => '2026', 'values' => [10, 12], 'color' => '#1F3A5F']],
    ]]]);

    expect($result['ok'])->toBeTrue()
        ->and($result['schema']['blocks'][0])->toBe([
            'type' => 'chart',
            'option' => [
                'title' => ['text' => 'Revenue'],
                'xAxis' => ['type' => 'category', 'data' => ['Q1', 'Q2']],
                'yAxis' => ['type' => 'value'],
                'series' => [['type' => 'bar', 'name' => '2026', 'data' => [10, 12], 'itemStyle' => ['color' => '#1F3A5F']]],
            ],
        ]);

    // Word's "bar" is horizontal; the shorthand means it the Word way.
    $bar = Agent::validateAndRepair(['blocks' => [[
        'type' => 'chart', 'chartType' => 'bar', 'categories' => ['a'], 'series' => [['values' => [1]]],
    ]]])['schema']['blocks'][0]['option'];
    expect($bar['yAxis'])->toBe(['type' => 'category', 'data' => ['a']])
        ->and($bar['xAxis'])->toBe(['type' => 'value']);

    $pie = Agent::validateAndRepair(['blocks' => [[
        'type' => 'chart', 'chartType' => 'pie', 'categories' => ['a', 'b'], 'series' => [['name' => 'Mix', 'values' => [1, 2]]],
    ]]])['schema']['blocks'][0]['option'];
    expect($pie)->toBe(['series' => [['type' => 'pie', 'name' => 'Mix', 'data' => [['name' => 'a', 'value' => 1], ['name' => 'b', 'value' => 2]]]]]);
});

it('repairs series.values to series.data, and drops a chart it cannot save', function () {
    $fixed = Agent::validateAndRepair(['blocks' => [[
        'type' => 'chart', 'option' => ['xAxis' => ['type' => 'category', 'data' => ['a']], 'series' => [['type' => 'bar', 'values' => [3]]]],
    ]]]);
    expect($fixed['ok'])->toBeTrue()
        ->and($fixed['schema']['blocks'][0]['option']['series'][0])->toBe(['type' => 'bar', 'data' => [3]]);

    $dropped = Agent::validateAndRepair(['blocks' => [['type' => 'chart', 'option' => ['series' => [['type' => 'radar', 'data' => [1]]]]]]]);
    expect($dropped['ok'])->toBeTrue()
        ->and($dropped['schema']['blocks'])->toBe([]);
});

it('publishes the chart block in the JSON schema, with the supported types', function () {
    $schema = Agent::jsonSchema();
    $chart = null;
    foreach ($schema['$defs']['block']['oneOf'] as $block) {
        if (($block['properties']['type']['const'] ?? null) === 'chart') {
            $chart = $block;
        }
    }

    expect($chart)->not->toBeNull()
        ->and($chart['required'])->toBe(['type', 'option'])
        ->and($chart['properties'])->toHaveKeys(['option', 'widthPx', 'heightPx', 'width', 'numberFormat', 'alt'])
        ->and($chart['properties']['option']['properties']['series']['items']['properties']['type']['enum'])->toBe(['bar', 'line', 'pie']);
});

it('teaches the chart block in the agent guide', function () {
    expect(Agent::guide())->toContain('{type:"chart"');
});

it('renders a chart into markdown as its data table', function () {
    $md = Agent::toMarkdown(lwChartCase('column'));

    expect($md)->toContain("**Revenue by quarter**\n\n| Quarter | 2025 | 2026 |\n| --- | --- | --- |\n| Q1 | 120 | 130 |")
        ->toContain('| Q3 | 150 | 171.5 |');
});
