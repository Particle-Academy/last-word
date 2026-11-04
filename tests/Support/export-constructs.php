<?php

declare(strict_types=1);

/*
 * The exporter for the mixed-page rows of fancy-conformance's
 * `last-word/docx-constructs` (0.45.0 onward). PHP is the reference: every golden
 * here is the output of RUNNING this package, never what a value obviously is.
 *
 *     php tests/Support/export-constructs.php [<fancy-conformance checkout>]
 *
 * Rules a future edit must keep:
 *
 * - **Append, never insert.** Ids are never renumbered. A row whose id is already in
 *   the table must come out identical, or this stops: a golden that moved is a change
 *   to be looked at, not to be overwritten. Text before the first new row is left as it
 *   is, byte for byte.
 * - **No golden holds an empty object.** PHP has one empty array; a `{}` written from it
 *   comes out `[]`, which is a different thing to Node. `{"page": {}}` is proved by a
 *   fixpoint row instead.
 */

use LastWord\Agent;
use LastWord\Tests\Support\ConformanceExtract;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/Pest.php'; // lwTemp()
require_once __DIR__ . '/ConformanceExtract.php';

$root = $argv[1] ?? dirname(__DIR__, 3) . '/fancy-conformance';
$suiteDir = $root . '/suites/last-word/docx-constructs';
const SINCE = '0.45.0';

/* ---------------- files this package did not write ---------------- */

/** A package holding one document.xml body, with sectPr where Word puts it. */
function exportDocx(string $body): string
{
    $path = tempnam(sys_get_temp_dir(), 'lw-export-');
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
    $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>' . $body . '</w:body></w:document>');
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $zip->setMtimeIndex($i, 315619200);
    }
    $zip->close();
    $bytes = (string) file_get_contents($path);
    unlink($path);

    return $bytes;
}

$text = static fn (string $t): string => "<w:p><w:r><w:t>{$t}</w:t></w:r></w:p>";
$portrait = '<w:pgSz w:w="12240" w:h="15840"/>';
$landscape = '<w:pgSz w:w="15840" w:h="12240" w:orient="landscape"/>';
$a4 = '<w:pgSz w:w="11906" w:h="16838"/>';
$narrow = '<w:pgMar w:top="720" w:right="1440" w:bottom="1440" w:left="1440" w:header="720" w:footer="720" w:gutter="0"/>';
$sect = static fn (string $inner): string => "<w:sectPr>{$inner}</w:sectPr>";
$ends = static fn (string $t, string $inner): string => "<w:p><w:pPr><w:sectPr>{$inner}</w:sectPr></w:pPr><w:r><w:t>{$t}</w:t></w:r></w:p>";

$fixtures = [
    'two-sections.docx' => (string) file_get_contents(dirname(__DIR__) . '/fixtures/formats/two-sections.docx'),
    // One of each row of the plan's table, in the order a Word file can hold them.
    'section-starts.docx' => exportDocx(
        $ends('first section', $portrait)
        . $ends('continuous, same page: no break, reported', '<w:type w:val="continuous"/>' . $portrait)
        . $ends('continuous, other margins: no break, reported', '<w:type w:val="continuous"/>' . $portrait . $narrow)
        . $ends('continuous, other orientation: a break', '<w:type w:val="continuous"/>' . $landscape)
        . $ends('oddPage: a break, reported', '<w:type w:val="oddPage"/>' . $a4)
        . $ends('evenPage: a break, reported', '<w:type w:val="evenPage"/>' . $landscape . $narrow)
        . $ends('columns: a break, reported', $portrait . '<w:cols w:num="2"/>')
        . $ends('a restart: a break, reported', $landscape . '<w:pgNumType w:start="1"/>')
        . $text('unknown paper: a break, reported')
        . $sect('<w:pgSz w:w="10000" w:h="14000"/>'),
    ),
];

/* ---------------- the rows ---------------- */

$p = static fn (string $t): array => ['type' => 'paragraph', 'runs' => [['text' => $t]]];
$wide = ['orientation' => 'landscape', 'margins' => ['left' => 28.8, 'right' => 28.8]];
$break = static fn (array|stdClass $page): array => ['type' => 'pageBreak', 'page' => $page];
$bands = ['header' => ['left' => 'Report', 'right' => 'Page {page} of {pages}'], 'footer' => ['center' => 'Page {page}']];
$mixed = ['page' => $bands, 'blocks' => [$p('Portrait'), $break($wide), $p('Landscape'), $break(['orientation' => 'portrait']), $p('Portrait again')]];

$rows = [
    ['0080-sections-in-order', 'Each section has its own w:sectPr, in document order, and each text width its own band parts', 'sections', ['doc' => $mixed], ['mixed-pages'], 'Tab stops are the point of the separate parts: 9360, then 14688, then 11088, because margins merge and portrait keeps the narrow ones.'],
    ['0081-sections-first-stretch-by-slot', 'The first stretch keeps header1-3 by slot; later ones number from 5', 'sections', ['doc' => ['page' => ['header' => ['left' => 'default'], 'firstHeader' => ['left' => 'first'], 'evenHeader' => ['left' => 'even']], 'blocks' => [$p('One'), $break(['orientation' => 'landscape']), $p('Two')]]], ['mixed-pages'], 'firstHeader is not inherited, so the second section has no first reference and no w:titlePg.'],
    ['0082-sections-same-width-shares-a-part', 'A stretch at a width already seen shares the part', 'sections', ['doc' => ['page' => ['header' => ['left' => 'x']], 'blocks' => [$p('a'), $break(new stdClass()), $p('b'), $break(['orientation' => 'landscape']), $p('c'), $break(['orientation' => 'portrait']), $p('d')]]], ['mixed-pages'], null],
    ['0083-sections-first-band-on-a-break', 'firstHeader on a break means the first page after it, and w:titlePg is that section\'s', 'sections', ['doc' => ['page' => ['header' => ['left' => 'run']], 'blocks' => [$p('a'), $break(['firstHeader' => ['left' => 'second cover']]), $p('b'), $break(new stdClass()), $p('c')]]], ['mixed-pages'], 'The third section has neither: never inherited.'],
    ['0084-sections-even-is-the-documents', 'An even band on a later break turns even-and-odd on everywhere, and the first stretch references its own default as even', 'sections', ['doc' => ['page' => ['header' => ['left' => 'run']], 'blocks' => [$p('a'), $break(['evenHeader' => ['left' => 'even']]), $p('b')]]], ['mixed-pages'], null],
    ['0085-sections-blank-band', 'A band a break empties is a blank part: Word would inherit the last one', 'sections', ['doc' => ['page' => ['header' => ['left' => 'run']], 'blocks' => [$p('a'), $break(['header' => ['blocks' => []]]), $p('b')]]], ['mixed-pages'], null],
    ['0086-band-parts-settings-for-a-later-even-band', 'evenAndOddHeaders is in settings for a band only a later stretch has', 'bandParts', ['doc' => ['page' => ['header' => ['left' => 'run']], 'blocks' => [$p('a'), $break(['evenHeader' => ['left' => 'even']]), $p('b')]]], ['mixed-pages'], 'bandParts describes the body sectPr, the last section.'],
    ['0087-section-number-cached-per-part', 'A {section} band has a part per stretch, its cached result that stretch\'s number', 'bandFields', ['doc' => ['page' => ['footer' => ['left' => '{section} of {sectionpages}']], 'blocks' => [$p('a'), $break(new stdClass()), $p('b')]]], ['mixed-pages', 'fields'], 'LibreOffice shows the cached SECTION rather than computing it. SECTIONPAGES stays 1: the writer cannot know it.'],
    ['0088-read-back-mixed', 'read() gives top-level page from the first section and each later one as a break', 'readBack', ['doc' => $mixed], ['mixed-pages'], null],
    ['0089-fixpoint-mixed', 'A mixed document comes back as written', 'roundTripFixpoint', ['doc' => $mixed], ['mixed-pages'], null],
    ['0090-fixpoint-empty-page', 'A break with an empty page comes back with an empty page', 'roundTripFixpoint', ['doc' => ['blocks' => [$p('a'), $break(new stdClass()), $p('b')]]], ['mixed-pages'], 'A fixpoint, not a golden: see the empty-object note in the exporter.'],
    ['0091-read-file-two-sections', 'A file with sectPr on the last paragraph of the section, and no w:type', 'readFile', ['file' => 'two-sections.docx'], ['mixed-pages', 'reader'], 'The deferred reader row. This package laid the section properties out by hand, as Word does; its writer uses a dedicated paragraph.'],
    ['0092-read-file-section-starts', 'Every section start the model cannot hold: no break or a break, and a report at the block', 'readFile', ['file' => 'section-starts.docx'], ['mixed-pages', 'reader'], 'w:type is how THAT section starts. Checked against LibreOffice, not against Word, which was not available.'],
];

/* ---------------- run them ---------------- */

$normal = static fn (mixed $v): string => (string) json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

$out = [];
foreach ($rows as [$id, $title, $fn, $input, $tags, $notes]) {
    if ($fn === 'readFile') {
        $name = $input['file'];
        $bytes = $fixtures[$name];
        $inputJson = ['file' => ['file' => 'fixtures/' . $name, 'sha256' => hash('sha256', $bytes)]];
        $expected = Agent::read($bytes);
    } else {
        // Through JSON, as a loader would: a stdClass here is `{}` in the row and `[]` to PHP.
        $inputJson = $input;
        $run = json_decode((string) json_encode($input), true);
        $expected = match ($fn) {
            'sections' => ConformanceExtract::sections($run),
            'bandParts' => ConformanceExtract::bandParts($run),
            'bandFields' => ConformanceExtract::bandFields($run),
            'readBack' => Agent::read(ConformanceExtract::write($run)),
            'roundTripFixpoint' => ['fixpoint' => json_encode(Agent::read(ConformanceExtract::write($run))) === json_encode($run['doc'])],
        };
    }
    $row = ['id' => $id, 'title' => $title, 'since' => SINCE, 'fn' => $fn, 'input' => $inputJson, 'expected' => $expected, 'tags' => $tags];
    if ($notes !== null) {
        $row['notes'] = $notes;
    }
    $out[] = $row;
}

/* ---------------- write them ---------------- */

@mkdir($suiteDir . '/fixtures');
foreach ($fixtures as $name => $bytes) {
    $target = $suiteDir . '/fixtures/' . $name;
    if (is_file($target) && hash_file('sha256', $target) !== hash('sha256', $bytes)) {
        fwrite(STDERR, "fixture {$name} would change: stop and look\n");
        exit(1);
    }
    file_put_contents($target, $bytes);
}

$path = $suiteDir . '/cases.json';
$raw = (string) file_get_contents($path);
$table = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
$have = array_column($table['cases'], null, 'id');
$fresh = [];
foreach ($out as $row) {
    if (! isset($have[$row['id']])) {
        $fresh[] = $row;
        continue;
    }
    if ($normal($have[$row['id']]) !== $normal(json_decode($normal($row), true))) {
        fwrite(STDERR, "row {$row['id']} would change: stop and look\n");
        exit(1);
    }
}
if ($fresh === []) {
    echo "nothing to add\n";
    exit(0);
}

$lf = str_contains($raw, "\r\n") ? "\r\n" : "\n";
$text = '';
foreach ($fresh as $row) {
    $json = (string) json_encode($row, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $json = (string) preg_replace_callback('/^( +)/m', static fn (array $m): string => str_repeat(' ', (int) (strlen($m[1]) / 2)), $json);
    $text .= ',' . $lf . implode($lf, array_map(static fn (string $l): string => '    ' . $l, explode("\n", $json)));
}
$close = strrpos($raw, ']');
$before = rtrim(substr($raw, 0, $close));
file_put_contents($path, $before . $text . $lf . '  ' . substr($raw, $close));
echo 'appended ' . count($fresh) . " rows\n";
