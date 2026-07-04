<?php

declare(strict_types=1);

use LastWord\Agent;

/*
 * Spec vector 5: the reader tolerates hand-built / Word-authored files —
 * outlineLvl headings, Heading9 styles, unknown wrappers and inline field
 * constructs degrade to headings/paragraphs without throwing.
 */

function lwBuildMinimalDocx(string $documentXml): string
{
    $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lw-tolerance-' . bin2hex(random_bytes(6)) . '.docx';
    $zip = new ZipArchive();
    expect($zip->open($tmp, ZipArchive::OVERWRITE | ZipArchive::CREATE))->toBeTrue();
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
        . '</Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
        . '</Relationships>');
    $zip->addFromString('word/document.xml', $documentXml);
    $zip->close();
    $bytes = file_get_contents($tmp);
    @unlink($tmp);

    return $bytes;
}

it('reads a hand-built document with outlineLvl headings and unknown elements without throwing', function () {
    $documentXml = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
        . '<w:body>'
        // outlineLvl-based heading (no pStyle at all)
        . '<w:p><w:pPr><w:outlineLvl w:val="0"/></w:pPr><w:r><w:t>Chapter</w:t></w:r></w:p>'
        // Heading9 style — beyond the model's range, must clamp to 6
        . '<w:p><w:pPr><w:pStyle w:val="Heading9"/></w:pPr><w:r><w:t>Deep heading</w:t></w:r></w:p>'
        // content wrapped in an unknown-ish container
        . '<w:customXml><w:p><w:r><w:t>Wrapped paragraph</w:t></w:r></w:p></w:customXml>'
        // inline field + proofErr noise inside a normal paragraph
        . '<w:p><w:proofErr w:type="spellStart"/><w:r><w:t xml:space="preserve">Plain </w:t></w:r>'
        . '<w:fldSimple w:instr=" PAGE "><w:r><w:t>1</w:t></w:r></w:fldSimple>'
        . '<w:r><w:t xml:space="preserve"> text</w:t></w:r></w:p>'
        // constructs the reader has no mapping for at all
        . '<w:altChunk r:id="rId99" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"/>'
        . '<w:sectPr><w:pgSz w:w="12240" w:h="15840"/></w:sectPr>'
        . '</w:body>'
        . '</w:document>';

    $doc = Agent::read(lwBuildMinimalDocx($documentXml));

    expect($doc['blocks'])->toHaveCount(4)
        ->and($doc['blocks'][0])->toEqual(['type' => 'heading', 'level' => 1, 'runs' => [['text' => 'Chapter']]])
        ->and($doc['blocks'][1])->toEqual(['type' => 'heading', 'level' => 6, 'runs' => [['text' => 'Deep heading']]])
        ->and($doc['blocks'][2])->toEqual(['type' => 'paragraph', 'runs' => [['text' => 'Wrapped paragraph']]])
        ->and($doc['blocks'][3])->toEqual(['type' => 'paragraph', 'runs' => [['text' => 'Plain 1 text']]]);
});

it('maps named w:highlight colors and buckets unknown numIds as unordered', function () {
    $documentXml = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
        . '<w:body>'
        . '<w:p><w:r><w:rPr><w:highlight w:val="yellow"/></w:rPr><w:t>marked</w:t></w:r></w:p>'
        // list paragraphs referencing a numId no numbering.xml defines
        . '<w:p><w:pPr><w:numPr><w:ilvl w:val="0"/><w:numId w:val="7"/></w:numPr></w:pPr><w:r><w:t>alpha</w:t></w:r></w:p>'
        . '<w:p><w:pPr><w:numPr><w:ilvl w:val="1"/><w:numId w:val="7"/></w:numPr></w:pPr><w:r><w:t>beta</w:t></w:r></w:p>'
        . '</w:body>'
        . '</w:document>';

    $doc = Agent::read(lwBuildMinimalDocx($documentXml));

    expect($doc['blocks'][0]['runs'][0]['highlight'])->toBe('#FFFF00');

    $list = $doc['blocks'][1];
    expect($list['type'])->toBe('list')
        ->and($list)->not->toHaveKey('ordered')
        ->and($list['items'][0]['runs'][0]['text'])->toBe('alpha')
        ->and($list['items'][0]['children'][0]['runs'][0]['text'])->toBe('beta');
});

it('rejects non-docx input with a clear error', function () {
    expect(fn () => Agent::read('this is not a docx'))->toThrow(InvalidArgumentException::class);
});
