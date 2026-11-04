<?php

declare(strict_types=1);

use LastWord\Agent;
use LastWord\Exceptions\SchemaException;
use LastWord\Layout\TableGrid;
use LastWord\Schema\Schema;
use LastWord\Writer\DocxWriter;

/*
 * colSpan has a ceiling, case for case with Node's table-span-limit.test.ts and
 * Python's test_table_span_limit.py. rowSpan has none on purpose: nothing costs
 * more than the rows that exist.
 */

function lwSpanDoc(array $cell): array
{
    return ['blocks' => [['type' => 'table', 'rows' => [['cells' => [
        ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'kept']]]]] + $cell,
    ]]]]]];
}

function lwSpanDocumentXml(string $bytes): string
{
    $tmp = lwTemp('lw-span');
    file_put_contents($tmp, $bytes);
    $zip = new ZipArchive();
    expect($zip->open($tmp))->toBeTrue();
    $xml = (string) $zip->getFromName('word/document.xml');
    $zip->close();
    unlink($tmp);

    return $xml;
}

function lwSpanMinimalDocx(string $documentXml): string
{
    $tmp = lwTemp('lw-span');
    $zip = new ZipArchive();
    expect($zip->open($tmp, ZipArchive::OVERWRITE))->toBeTrue();
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?>'
        .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        .'<Default Extension="xml" ContentType="application/xml"/>'
        .'<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
        .'</Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?>'
        .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
        .'</Relationships>');
    $zip->addFromString('word/document.xml', $documentXml);
    $zip->close();
    $bytes = (string) file_get_contents($tmp);
    unlink($tmp);

    return $bytes;
}

function lwSpanChild(string $kind): array
{
    $cmd = [PHP_BINARY, '-d', 'memory_limit=32M', '-d', 'max_execution_time=20', '-d', 'display_errors=1',
        dirname(__DIR__).'/Support/span-child.php', $kind];
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($proc), $out.$err];
}

/** The slot-by-slot placement TableGrid must keep agreeing with. */
function lwSpanReferenceGrid(array $rows): array
{
    $n = count($rows);
    $occupied = array_fill(0, $n, []);
    $join = array_fill(0, $n, false);
    $placed = [];
    foreach ($rows as $r => $row) {
        $col = 0;
        $line = [];
        foreach ($row['cells'] as $c => $cell) {
            while (isset($occupied[$r][$col])) {
                $col++;
            }
            $cs = $cell['colSpan'] ?? 1;
            $rs = min($cell['rowSpan'] ?? 1, $n - $r);
            for ($dr = 0; $dr < $rs; $dr++) {
                for ($dc = 0; $dc < $cs; $dc++) {
                    $occupied[$r + $dr][$col + $dc] = true;
                }
                if ($dr > 0) {
                    $join[$r + $dr] = true;
                }
            }
            $line[] = ['row' => $r, 'cell' => $c, 'col' => $col, 'colSpan' => $cs, 'rowSpan' => $rs];
            $col += $cs;
        }
        $placed[] = $line;
    }
    $count = 0;
    foreach ($occupied as $set) {
        foreach (array_keys($set) as $c) {
            $count = max($count, $c + 1);
        }
    }

    return ['colCount' => max(1, $count), 'rows' => $placed, 'joinPrevious' => $join];
}

test('the ceiling is 1000', function () {
    expect(Schema::MAX_COL_SPAN)->toBe(1000);
});

test('validate accepts a colSpan at the ceiling and refuses one past it, by name', function () {
    expect(Agent::validate(lwSpanDoc(['colSpan' => 1000])))->toBe([]);
    expect(Agent::validate(lwSpanDoc(['colSpan' => 1001])))->toBe([[
        'path' => 'blocks[0].rows[0].cells[0].colSpan',
        'message' => 'colSpan must be at most 1000',
    ]]);
});

test('toBytes refuses past the ceiling before it does any work', function () {
    expect(fn () => Agent::toBytes(lwSpanDoc(['colSpan' => PHP_INT_MAX])))->toThrow(SchemaException::class);
});

test('rowSpan has no ceiling: a merge down a long table stays valid', function () {
    expect(Agent::validate(lwSpanDoc(['rowSpan' => 1000000])))->toBe([]);
    expect(lwSpanDocumentXml(Agent::toBytes(lwSpanDoc(['rowSpan' => 1000000]))))->toContain('<w:vMerge w:val="restart"/>');
});

test('validateAndRepair clamps, says so and keeps the text', function () {
    $out = Agent::validateAndRepair(lwSpanDoc(['colSpan' => 1000000000]));
    expect($out['ok'])->toBeTrue();
    $cell = $out['schema']['blocks'][0]['rows'][0]['cells'][0];
    expect($cell['colSpan'])->toBe(1000);
    expect($cell['blocks'][0]['runs'][0]['text'])->toBe('kept');
    expect($out['errors'])->toContain([
        'path' => 'blocks[0].rows[0].cells[0].colSpan',
        'message' => 'clamped colSpan 1000000000 to 1000',
    ]);
});

test('the JSON schema declares the ceiling and leaves rowSpan open', function () {
    $json = json_encode(Agent::jsonSchema());
    expect($json)->toContain('"colSpan":{"type":"integer","minimum":1,"maximum":1000}');
    expect($json)->not->toContain('"rowSpan":{"type":"integer","minimum":1,"maximum"');
});

test('the writer clamps a document that never met the validator', function () {
    $xml = lwSpanDocumentXml((new DocxWriter())->toBytes(lwSpanDoc(['colSpan' => 2000])));
    expect(substr_count($xml, '<w:gridCol '))->toBe(1000);
    expect($xml)->toContain('<w:gridSpan w:val="1000"/>');
});

test('TableGrid clamps too', function () {
    $g = TableGrid::of([['cells' => [['colSpan' => 2000]]]]);
    expect($g['colCount'])->toBe(1000);
    expect($g['rows'][0][0]['colSpan'])->toBe(1000);
});

test('TableGrid places cells exactly as slot-by-slot placement does', function () {
    mt_srand(20261104);
    for ($t = 0; $t < 400; $t++) {
        $rows = [];
        $n = mt_rand(1, 6);
        for ($r = 0; $r < $n; $r++) {
            $cells = [];
            for ($c = mt_rand(0, 5); $c > 0; $c--) {
                $cells[] = ['colSpan' => mt_rand(1, 4), 'rowSpan' => mt_rand(1, 5)];
            }
            $rows[] = ['cells' => $cells];
        }
        $g = TableGrid::of($rows);
        $want = lwSpanReferenceGrid($rows);
        expect(['colCount' => $g['colCount'], 'rows' => $g['rows'], 'joinPrevious' => $g['joinPrevious']])->toBe($want);
    }
});

test('placing spans costs no memory for the slots they cover', function () {
    [$code, $out] = lwSpanChild('grid');
    expect($code)->toBe(0, $out);
    expect($out)->toBe('30000');
});

test('the exchange refuses an over-wide table without padding its short rows', function () {
    [$code, $out] = lwSpanChild('portable');
    expect($code)->toBe(0, $out);
    expect(json_decode($out, true))->toBe(['ok' => false, 'refused' => ['reason' => 'content_too_many_columns']]);
});

test('a table at the exchange column limit still reaches the writer', function () {
    $doc = Agent::fromPortable(['fancyContent' => 1, 'items' => [['type' => 'table', 'headerRows' => 0, 'rows' => [
        ['cells' => [['t' => 'string', 'v' => 'title', 'colSpan' => 64]]],
    ]]]]);
    expect($doc['ok'] ?? true)->toBeTrue();
    expect(Agent::validate(['blocks' => $doc['blocks']]))->toBe([]);
    expect(substr_count(lwSpanDocumentXml(Agent::toBytes(['blocks' => $doc['blocks']])), '<w:gridCol '))->toBe(64);
});

test('reading gridSpan is bounded and always keeps the text', function (string $val, ?int $want) {
    $tc = '<w:tc><w:tcPr><w:gridSpan w:val="'.$val.'"/></w:tcPr><w:p><w:r><w:t>kept</w:t></w:r></w:p></w:tc>';
    $bytes = lwSpanMinimalDocx('<?xml version="1.0" encoding="UTF-8"?>'
        .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
        .'<w:tbl><w:tr>'.$tc.'</w:tr></w:tbl></w:body></w:document>');
    $doc = Agent::read($bytes);
    $cell = $doc['blocks'][0]['rows'][0]['cells'][0];
    expect($cell['colSpan'] ?? null)->toBe($want);
    expect($cell['blocks'][0]['runs'][0]['text'])->toBe('kept');
    expect(Agent::validate($doc))->toBe([]);
})->with([
    'a real span' => ['3', 3],
    'at the ceiling' => ['1000', 1000],
    'past it' => ['1000000000', 1000],
    'past every integer' => ['99999999999999999999999', 1000],
    'a large exponent' => ['1e30', 1000],
    'an infinite exponent' => ['1e400', null],
    'negative' => ['-5', null],
    'fractional' => ['2.7', 2],
    'not a number' => ['abc', null],
]);
