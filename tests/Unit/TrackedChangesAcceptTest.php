<?php

declare(strict_types=1);

use LastWord\Agent;

/*
 * Accepting a DELETED PARAGRAPH MARK, as Word's Accept All does: the paragraph
 * whose mark is deleted joins the paragraph after it, and the joined paragraph
 * has the following paragraph's properties, because only that paragraph's mark
 * survives, and a paragraph's properties are in its mark. That follows the
 * markup and is unverified against the standard: ECMA-376's "del (Deleted
 * Paragraph)" says the content combines with the following paragraph, not
 * whose properties survive. The default read does it, and so
 * does every consumer that sees revisions accepted (markdown, portable content,
 * describe).
 */

/** A hand-built .docx around a body. */
function lwaDocx(string $body): string
{
    $tmp = lwTemp('lw-accept', '.docx');
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
    $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8"?>'
        . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>' . $body
        . '<w:sectPr><w:pgSz w:w="12240" w:h="15840"/></w:sectPr></w:body></w:document>');
    $zip->close();

    return (string) file_get_contents($tmp);
}

/** A paragraph, its mark deleted when `$deleted`, with optional pPr children before the mark's rPr. */
function lwaP(string $text, bool $deleted = false, string $pPr = ''): string
{
    $mark = $deleted ? '<w:rPr><w:del w:id="1" w:author="Ann"/></w:rPr>' : '';
    $props = $pPr . $mark;

    return '<w:p>' . ($props !== '' ? '<w:pPr>' . $props . '</w:pPr>' : '') . ($text !== '' ? '<w:r><w:t>' . $text . '</w:t></w:r>' : '') . '</w:p>';
}

function lwaItem(string $runs, bool $deleted = false): string
{
    $mark = $deleted ? '<w:rPr><w:del w:id="1" w:author="Ann"/></w:rPr>' : '';

    return '<w:p><w:pPr><w:pStyle w:val="ListParagraph"/><w:numPr><w:ilvl w:val="0"/><w:numId w:val="1"/></w:numPr>' . $mark . '</w:pPr>' . $runs . '</w:p>';
}

describe('the default read', function () {
    it('joins a paragraph whose mark is deleted to the next one, which keeps its own properties', function () {
        $read = Agent::read(lwaDocx(lwaP('First', true, '<w:jc w:val="right"/>') . lwaP('Second', false, '<w:jc w:val="center"/>')));

        expect($read['blocks'])->toBe([['type' => 'paragraph', 'runs' => [['text' => 'FirstSecond']], 'align' => 'center']]);
    });

    it('joins a heading to the paragraph after it, and a paragraph to the heading after it', function () {
        $headingFirst = Agent::read(lwaDocx(lwaP('First', true, '<w:pStyle w:val="Heading1"/>') . lwaP('Second')));
        $headingSecond = Agent::read(lwaDocx(lwaP('First', true) . lwaP('Second', false, '<w:pStyle w:val="Heading2"/>')));

        expect($headingFirst['blocks'])->toBe([['type' => 'paragraph', 'runs' => [['text' => 'FirstSecond']]]])
            ->and($headingSecond['blocks'])->toBe([['type' => 'heading', 'level' => 2, 'runs' => [['text' => 'FirstSecond']]]]);
    });

    it('joins a list item to the next item, and leaves no empty bullet for a deleted item', function () {
        $joined = Agent::read(lwaDocx(lwaItem('<w:r><w:t>A</w:t></w:r>', true) . lwaItem('<w:r><w:t>B</w:t></w:r>')));
        $deletedItem = Agent::read(lwaDocx(
            lwaItem('<w:r><w:t>Keep</w:t></w:r>')
            . lwaItem('<w:del w:id="2" w:author="Ann"><w:r><w:delText>Gone</w:delText></w:r></w:del>', true)
            . lwaItem('<w:r><w:t>Last</w:t></w:r>')
        ));

        expect($joined['blocks'][0]['items'])->toBe([['runs' => [['text' => 'AB']]]])
            ->and($deletedItem['blocks'][0]['items'])->toBe([['runs' => [['text' => 'Keep']]], ['runs' => [['text' => 'Last']]]])
            ->and(Agent::toMarkdown($deletedItem))->not->toMatch('/^- $/m');
    });

    it('leaves a deleted mark with nothing to join where it is: the last paragraph, one before a table', function () {
        $last = Agent::read(lwaDocx(lwaP('Only', true)));
        $beforeTable = Agent::read(lwaDocx(lwaP('Above', true) . '<w:tbl><w:tr><w:tc><w:p><w:r><w:t>Cell</w:t></w:r></w:p></w:tc></w:tr></w:tbl>'));

        expect($last['blocks'])->toBe([['type' => 'paragraph', 'runs' => [['text' => 'Only']]]])
            ->and($beforeTable['blocks'][0])->toBe(['type' => 'paragraph', 'runs' => [['text' => 'Above']]]);
    });

    it('joins inside a table cell too', function () {
        $read = Agent::read(lwaDocx('<w:tbl><w:tr><w:tc>' . lwaP('One', true) . lwaP('Two') . '</w:tc></w:tr></w:tbl>'));

        expect($read['blocks'][0]['rows'][0]['cells'][0]['blocks'])->toBe([['type' => 'paragraph', 'runs' => [['text' => 'OneTwo']]]]);
    });
});

describe('the accepted view of a model that keeps its revisions', function () {
    $by = ['id' => 'r1', 'author' => 'Ann'];
    $doc = ['blocks' => [
        ['type' => 'heading', 'level' => 1, 'runs' => [['text' => 'First']], 'revision' => ['delete' => $by]],
        ['type' => 'paragraph', 'runs' => [['text' => 'Second']]],
        ['type' => 'list', 'items' => [
            ['runs' => [['text' => 'Gone', 'revision' => ['delete' => ['id' => 'r2', 'author' => 'Ann']]]], 'revision' => ['delete' => ['id' => 'r3', 'author' => 'Ann']]],
            ['runs' => [['text' => 'Stays']]],
        ]],
    ]];

    it('markdown joins them and leaves no empty bullet', function () use ($doc) {
        $md = Agent::toMarkdown($doc);

        expect($md)->toContain('FirstSecond')->not->toContain('# First')
            ->and($md)->toContain('- Stays')->not->toMatch('/^- *$/m');
    });

    it('portable content joins them', function () use ($doc) {
        $items = json_encode(Agent::toPortable($doc)['content']['items']);

        expect($items)->toContain('FirstSecond')->not->toContain('"First"');
    });

    it('a model read with preserveTrackedChanges keeps the mark, and writes it back', function () {
        $read = Agent::read(lwaDocx(lwaP('First', true) . lwaP('Second')), ['preserveTrackedChanges' => true]);

        expect(array_keys($read['blocks'][0]['revision'] ?? []))->toBe(['delete'])
            ->and(count($read['blocks']))->toBe(2);
    });
});
