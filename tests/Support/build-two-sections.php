<?php

declare(strict_types=1);

/*
 * Regenerates tests/fixtures/formats/two-sections.docx and two-sections.read.json:
 * a portrait section, then a landscape one, as Word lays the XML out. The
 * writer cannot produce this yet (stage 2 of mixed page orientation), so the
 * body is the writer's own, with the first section's w:sectPr placed on the last
 * paragraph of that section and the body's own turned landscape.
 *
 *   php tests/Support/build-two-sections.php
 *
 * The read JSON comes from Agent::read(), so run it only when the reader is
 * right. The Node and Python packages hold copies of both files.
 */

use LastWord\Agent;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$dir = dirname(__DIR__) . '/fixtures/formats';

$cell = static fn (string $text): array => ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => $text]]]]];
$bytes = Agent::toBytes([
    'title' => 'Two sections',
    'blocks' => [
        ['type' => 'heading', 'level' => 1, 'runs' => [['text' => 'Portrait']]],
        ['type' => 'paragraph', 'runs' => [['text' => 'The last paragraph of the portrait section carries its section properties.']]],
        ['type' => 'heading', 'level' => 1, 'runs' => [['text' => 'Landscape']]],
        ['type' => 'table', 'rows' => [
            ['header' => true, 'cells' => [$cell('Site'), $cell('Reading')]],
            ['cells' => [$cell('North'), $cell('12')]],
        ]],
    ],
]);

$out = $dir . '/two-sections.docx';
file_put_contents($out, $bytes);

$zip = new ZipArchive();
if ($zip->open($out) !== true) {
    throw new RuntimeException('could not open ' . $out);
}
$xml = (string) $zip->getFromName('word/document.xml');

$portrait = '<w:sectPr><w:pgSz w:w="12240" w:h="15840"/><w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440" w:header="720" w:footer="720" w:gutter="0"/></w:sectPr>';
$landscape = '<w:sectPr><w:pgSz w:w="15840" w:h="12240" w:orient="landscape"/><w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440" w:header="720" w:footer="720" w:gutter="0"/></w:sectPr>';

$count = 0;
$xml = (string) preg_replace('#<w:sectPr>.*</w:sectPr>(?=</w:body>)#sU', $landscape, $xml, -1, $count);
if ($count !== 1) {
    throw new RuntimeException('expected exactly one body-level w:sectPr');
}

// The paragraph whose text ends the first section: give it a w:pPr holding the sectPr.
$marker = 'The last paragraph of the portrait section';
$at = strpos($xml, $marker);
$open = $at === false ? false : strrpos(substr($xml, 0, $at), '<w:p>');
if ($open === false) {
    throw new RuntimeException('the writer no longer writes that paragraph as a bare <w:p>');
}
$xml = substr_replace($xml, '<w:p><w:pPr>' . $portrait . '</w:pPr>', $open, strlen('<w:p>'));

$zip->addFromString('word/document.xml', $xml);
$zip->close();

file_put_contents(
    $dir . '/two-sections.read.json',
    json_encode(Agent::read((string) file_get_contents($out)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
);
