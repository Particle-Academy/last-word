<?php

declare(strict_types=1);

use LastWord\Agent;
use LastWord\Schema\Schema;
use LastWord\Tests\Support\Package;

/**
 * Bookmarks, internal links, body fields and a table of contents: the Word
 * parity rows "bookmarks", "internal links (hyperlinks to bookmarks)" and
 * "fields: TOC and page numbers in body text".
 *
 *   - a run's `bookmark` names the place it marks: `w:bookmarkStart` /
 *     `w:bookmarkEnd` around the run (an empty run is a point);
 *   - a run's `link: "#name"` jumps to the bookmark of that name:
 *     `w:hyperlink w:anchor`, with no relationship;
 *   - a run's `field` is one allow-listed field token, `{page}` `{pages}`
 *     `{date:yyyy-MM-dd}` ..., and its `text` the cached result;
 *   - a `toc` block is a `TOC \o "1-n" \h \z \u` field Word updates on open.
 *
 * Done means a ROUND TRIP: written, read back, the same model.
 */

/** @param  list<array<string, mixed>>  $blocks */
function blfDoc(array $blocks, array $extra = []): array
{
    return ['blocks' => $blocks] + $extra;
}

/** @return array<string, string> */
function blfParts(array $doc): array
{
    return Package::parts(Agent::toBytes($doc));
}

/** A model, written and read back. */
function blfRoundTrip(array $doc): array
{
    return Agent::read(Agent::toBytes($doc));
}

/** Every instruction a part holds: fldSimple `w:instr` and complex-field instrText joined per field. */
function blfInstructions(string $xml): array
{
    $dom = new DOMDocument();
    $dom->loadXML($xml);
    $out = [];
    $depth = 0;
    $current = [];
    foreach ($dom->getElementsByTagName('*') as $el) {
        if ($el->localName === 'fldSimple') {
            $out[] = $el->getAttribute('w:instr');
        } elseif ($el->localName === 'fldChar') {
            $type = $el->getAttribute('w:fldCharType');
            if ($type === 'begin') {
                $current[++$depth] = '';
            } elseif ($type === 'end' && $depth > 0) {
                $out[] = $current[$depth];
                unset($current[$depth]);
                $depth--;
            }
        } elseif ($el->localName === 'instrText' && $depth > 0) {
            $current[$depth] .= $el->textContent;
        }
    }

    return $out;
}

/**
 * A .docx whose body is the given WordprocessingML: a written document with its
 * word/document.xml swapped, so every other part is a real one.
 */
function blfDocxWithBody(string $bodyXml): string
{
    $bytes = Agent::toBytes(['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'x']]]]]);
    $path = lwTemp('lw-blf', '.docx');
    file_put_contents($path, $bytes);
    $zip = new ZipArchive();
    expect($zip->open($path))->toBeTrue();
    $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<w:body>' . $bodyXml . '<w:sectPr/></w:body></w:document>');
    $zip->close();
    $out = (string) file_get_contents($path);
    @unlink($path);

    return $out;
}

/** @return list<string> */
function blfMessages(array $doc): array
{
    return array_column(Agent::validate($doc), 'message');
}

describe('bookmarks and internal links', function () {
    it('writes a bookmark around its run and an internal link as w:anchor with no relationship', function () {
        $parts = blfParts(blfDoc([
            ['type' => 'heading', 'level' => 1, 'runs' => [['text' => 'Introduction', 'bookmark' => 'intro']]],
            ['type' => 'paragraph', 'runs' => [['text' => 'See '], ['text' => 'the introduction', 'link' => '#intro'], ['text' => '.']]],
        ]));
        $xml = $parts['word/document.xml'];

        expect($xml)->toMatch('/<w:bookmarkStart w:id="(\d+)" w:name="intro"\/><w:r>.*?Introduction.*?<\/w:r><w:bookmarkEnd w:id="\1"\/>/')
            ->and($xml)->toContain('<w:hyperlink w:anchor="intro" w:history="1">')
            ->and($parts['word/_rels/document.xml.rels'])->not->toContain('#intro')
            ->and($parts['word/_rels/document.xml.rels'])->not->toContain('TargetMode="External"');
        Package::assertConsistent($parts);
    });

    it('round-trips bookmarks and the links to them, in the body, a list, a table cell and a quote', function () {
        $blocks = [
            ['type' => 'heading', 'level' => 1, 'runs' => [['text' => 'Introduction', 'bookmark' => 'intro']]],
            ['type' => 'paragraph', 'runs' => [['text' => 'Point here: '], ['text' => '', 'bookmark' => 'here'], ['text' => 'and on.']]],
            ['type' => 'list', 'items' => [['runs' => [['text' => 'Item', 'bookmark' => 'item_1']]]]],
            ['type' => 'table', 'rows' => [['cells' => [['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'Cell', 'bookmark' => 'Cell2']]]]]]]]],
            ['type' => 'quote', 'blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'Quoted', 'bookmark' => 'q']]]]],
            ['type' => 'paragraph', 'runs' => [
                ['text' => 'Back to '],
                ['text' => 'the start', 'link' => '#intro'],
                ['text' => ', '],
                ['text' => 'the point', 'link' => '#here', 'bold' => true],
                ['text' => ', '],
                ['text' => 'the cell', 'link' => '#Cell2'],
                ['text' => ' and '],
                ['text' => 'a site', 'link' => 'https://example.com/'],
            ]],
        ];

        expect(blfRoundTrip(blfDoc($blocks))['blocks'])->toBe($blocks);
    });

    it('round-trips an internal link in a footnote and in a header to a body bookmark', function () {
        $doc = blfDoc([
            ['type' => 'heading', 'level' => 1, 'runs' => [['text' => 'Results', 'bookmark' => 'results']]],
            ['type' => 'paragraph', 'runs' => [['text' => 'Up 18%'], ['text' => '', 'footnote' => ['blocks' => [
                ['type' => 'paragraph', 'runs' => [['text' => 'See '], ['text' => 'Results', 'link' => '#results']]],
            ]]]]],
        ], ['page' => ['header' => ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'Jump to results', 'link' => '#results']]]]]]]);

        $read = blfRoundTrip($doc);

        expect($read['blocks'])->toBe($doc['blocks'])
            ->and($read['page']['header'])->toBe($doc['page']['header']);
    });

    it('reads a Word bookmark that spans several runs, or several paragraphs, as a point where it starts', function () {
        $read = Agent::read(blfDocxWithBody(
            '<w:p><w:bookmarkStart w:id="0" w:name="span"/><w:r><w:t>One</w:t></w:r><w:r><w:rPr><w:b/></w:rPr><w:t>Two</w:t></w:r><w:bookmarkEnd w:id="0"/></w:p>'
            . '<w:p><w:bookmarkStart w:id="1" w:name="across"/><w:r><w:t>Three</w:t></w:r></w:p>'
            . '<w:p><w:r><w:t>Four</w:t></w:r><w:bookmarkEnd w:id="1"/></w:p>'
        ));

        expect($read['blocks'][0]['runs'])->toBe([['text' => '', 'bookmark' => 'span'], ['text' => 'One'], ['text' => 'Two', 'bold' => true]])
            ->and($read['blocks'][1]['runs'])->toBe([['text' => '', 'bookmark' => 'across'], ['text' => 'Three']])
            ->and($read['blocks'][2]['runs'])->toBe([['text' => 'Four']]);
    });

    it('reads Word\'s hidden bookmarks (_Toc, _GoBack, _Ref) as nothing, and drops a link to one', function () {
        $read = Agent::read(blfDocxWithBody(
            '<w:p><w:bookmarkStart w:id="0" w:name="_Toc12345"/><w:bookmarkStart w:id="1" w:name="_GoBack"/><w:r><w:t>Heading</w:t></w:r><w:bookmarkEnd w:id="0"/><w:bookmarkEnd w:id="1"/></w:p>'
            . '<w:p><w:hyperlink w:anchor="_Toc12345"><w:r><w:t>jump</w:t></w:r></w:hyperlink></w:p>'
        ));

        expect($read['blocks'][0]['runs'])->toBe([['text' => 'Heading']])
            ->and($read['blocks'][1]['runs'])->toBe([['text' => 'jump']])
            ->and($read['unrepresented'])->toContain(['where' => '/blocks', 'kind' => 'link', 'count' => 1]);
    });

    it('reads an internal link to a bookmark the file does not have as plain text, and says so', function () {
        $read = Agent::read(blfDocxWithBody('<w:p><w:hyperlink w:anchor="nowhere"><w:r><w:t>dangling</w:t></w:r></w:hyperlink></w:p>'));

        expect($read['blocks'][0]['runs'])->toBe([['text' => 'dangling']])
            ->and($read['unrepresented'])->toBe([['where' => '/blocks', 'kind' => 'link', 'count' => 1]])
            ->and(Agent::validate($read))->toBe([]);
    });

    it('reads hostile bookmark names from a file as nothing: too long, not a name, duplicated, reserved', function () {
        $long = str_repeat('a', 41);
        $read = Agent::read(blfDocxWithBody(
            '<w:p><w:bookmarkStart w:id="0" w:name="' . $long . '"/><w:bookmarkEnd w:id="0"/><w:r><w:t>a</w:t></w:r></w:p>'
            . '<w:p><w:bookmarkStart w:id="1" w:name="has space"/><w:bookmarkEnd w:id="1"/><w:r><w:t>b</w:t></w:r></w:p>'
            . '<w:p><w:bookmarkStart w:id="2" w:name="dup"/><w:bookmarkEnd w:id="2"/><w:r><w:t>c</w:t></w:r></w:p>'
            . '<w:p><w:bookmarkStart w:id="3" w:name="DUP"/><w:bookmarkEnd w:id="3"/><w:r><w:t>d</w:t></w:r></w:p>'
            . '<w:p><w:bookmarkStart w:id="4" w:name="LastWordCode_php"/><w:bookmarkEnd w:id="4"/><w:r><w:t>e</w:t></w:r></w:p>'
        ));

        $runs = array_map(static fn (array $b): array => $b['runs'], $read['blocks']);
        expect($runs)->toBe([
            [['text' => 'a']],
            [['text' => 'b']],
            [['text' => '', 'bookmark' => 'dup'], ['text' => 'c']],
            [['text' => 'd']],
            [['text' => 'e']],
        ])->and(Agent::validate($read))->toBe([]);
    });

    it('refuses hostile bookmark names', function (mixed $name, string $message) {
        expect(blfMessages(blfDoc([['type' => 'paragraph', 'runs' => [['text' => 'x', 'bookmark' => $name]]]])))->toBe([$message]);
    })->with([
        'too long' => [str_repeat('a', 41), 'a bookmark name is a letter, then letters, digits or _, at most 40 characters'],
        'starts with a digit' => ['1intro', 'a bookmark name is a letter, then letters, digits or _, at most 40 characters'],
        'a space' => ['my mark', 'a bookmark name is a letter, then letters, digits or _, at most 40 characters'],
        'a quote' => ['a"b', 'a bookmark name is a letter, then letters, digits or _, at most 40 characters'],
        'hidden' => ['_Toc1', 'a bookmark name is a letter, then letters, digits or _, at most 40 characters'],
        'unicode' => ['café', 'a bookmark name is a letter, then letters, digits or _, at most 40 characters'],
        'empty' => ['', 'a bookmark name is a letter, then letters, digits or _, at most 40 characters'],
        'reserved' => ['LastWordCode_php', 'bookmark names starting LastWordCode_ are reserved'],
        'not a string' => [7, 'a bookmark name is a letter, then letters, digits or _, at most 40 characters'],
    ]);

    it('refuses a duplicate bookmark, whatever its case', function () {
        expect(Agent::validate(blfDoc([
            ['type' => 'paragraph', 'runs' => [['text' => 'a', 'bookmark' => 'intro']]],
            ['type' => 'paragraph', 'runs' => [['text' => 'b', 'bookmark' => 'Intro']]],
        ])))->toBe([['path' => 'blocks[1].runs[0].bookmark', 'message' => 'bookmark "Intro" is already used at blocks[0].runs[0] (names are compared ignoring case, as Word does)']]);
    });

    it('refuses more bookmarks than the cap', function () {
        $runs = [];
        for ($i = 0; $i <= Schema::MAX_BOOKMARKS; $i++) {
            $runs[] = ['text' => '', 'bookmark' => "b{$i}"];
        }

        expect(Agent::validate(blfDoc([['type' => 'paragraph', 'runs' => $runs]])))
            ->toBe([['path' => 'blocks', 'message' => 'a document holds at most ' . Schema::MAX_BOOKMARKS . ' bookmarks (found ' . (Schema::MAX_BOOKMARKS + 1) . ')']]);
    });

    it('refuses a link to a bookmark that does not exist, with a code', function () {
        expect(Agent::validate(blfDoc([
            ['type' => 'paragraph', 'runs' => [['text' => 'a', 'bookmark' => 'intro']]],
            ['type' => 'paragraph', 'runs' => [['text' => 'go', 'link' => '#intor']]],
        ])))->toBe([[
            'path' => 'blocks[1].runs[0].link',
            'message' => 'no bookmark named "intor" in the document (bookmarks: intro); put "bookmark": "intor" on the run to jump to',
            'code' => 'bookmark_unknown',
        ]]);
    });

    it('refuses an internal link that is not a bookmark name', function (string $link) {
        expect(blfMessages(blfDoc([['type' => 'paragraph', 'runs' => [['text' => 'go', 'link' => $link]]]])))
            ->toBe(['an internal link is "#" and a bookmark name: a letter, then letters, digits or _, at most 40 characters']);
    })->with(['#', '#a b', '#_Toc1', '#' . str_repeat('a', 41), '#a"><w:r>']);

    it('refuses a bookmark in a header, a footer or a footnote, and on a footnote mark', function () {
        expect(blfMessages(blfDoc([
            ['type' => 'paragraph', 'runs' => [['text' => '', 'footnote' => ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'n', 'bookmark' => 'inNote']]]]]]]],
            ['type' => 'paragraph', 'runs' => [['text' => '', 'bookmark' => 'mark', 'footnote' => ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'n']]]]]]]],
        ], ['page' => ['footer' => ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'f', 'bookmark' => 'inFooter']]]]]]])))->toBe([
            'a bookmark goes in the body, not in a header or footer',
            'a bookmark goes in the body, not in a footnote',
            'a footnote mark cannot carry a bookmark; put it on the run before it',
        ]);
    });

    it('repairs: a dangling link loses its link and keeps its text, a duplicate or bad bookmark is dropped', function () {
        $r = Agent::validateAndRepair(blfDoc([
            ['type' => 'paragraph', 'runs' => [['text' => 'a', 'bookmark' => 'intro']]],
            ['type' => 'paragraph', 'runs' => [['text' => 'b', 'bookmark' => 'INTRO'], ['text' => 'c', 'bookmark' => 'no good']]],
            ['type' => 'paragraph', 'runs' => [['text' => 'go', 'link' => '#missing'], ['text' => 'ok', 'link' => '#intro']]],
        ]));

        expect($r['ok'])->toBeTrue()
            ->and($r['schema']['blocks'])->toBe([
                ['type' => 'paragraph', 'runs' => [['text' => 'a', 'bookmark' => 'intro']]],
                ['type' => 'paragraph', 'runs' => [['text' => 'b'], ['text' => 'c']]],
                ['type' => 'paragraph', 'runs' => [['text' => 'go'], ['text' => 'ok', 'link' => '#intro']]],
            ]);
    });
});

describe('fields in body text', function () {
    it('writes a field run as a complex field with its text as the cached result', function () {
        $xml = blfParts(blfDoc([['type' => 'paragraph', 'runs' => [
            ['text' => 'Page '], ['text' => '3', 'field' => '{page}', 'bold' => true], ['text' => ' of '], ['text' => '9', 'field' => '{pages}'],
        ]]]))['word/document.xml'];

        expect(blfInstructions($xml))->toBe([' PAGE ', ' NUMPAGES '])
            ->and($xml)->toContain('<w:r><w:rPr><w:b/></w:rPr><w:fldChar w:fldCharType="separate"/></w:r><w:r><w:rPr><w:b/></w:rPr><w:t>3</w:t></w:r>');
    });

    it('round-trips every allow-listed field token in body text, a list and a table cell', function () {
        $runs = [
            ['text' => 'Page '], ['text' => '3', 'field' => '{page}'], ['text' => ' of '], ['text' => '9', 'field' => '{pages}'],
            ['text' => ' / '], ['text' => '2', 'field' => '{section}'], ['text' => ' / '], ['text' => '4', 'field' => '{sectionpages}'],
            ['text' => ' / '], ['text' => 'Ada', 'field' => '{author}', 'italic' => true], ['text' => ' / '], ['text' => 'Memo', 'field' => '{title}'],
            ['text' => ' / '], ['text' => 'memo.docx', 'field' => '{filename}'], ['text' => ' / '], ['text' => '21/11/2026', 'field' => '{date}'],
            ['text' => ' / '], ['text' => '2026-11-21', 'field' => '{date:yyyy-MM-dd}'], ['text' => ' / '], ['text' => '09:30', 'field' => '{time:HH:mm}'],
            ['text' => ' / '], ['text' => '9:30 AM', 'field' => '{time}'],
        ];
        $blocks = [
            ['type' => 'paragraph', 'runs' => $runs],
            ['type' => 'list', 'items' => [['runs' => [['text' => 'p. '], ['text' => '1', 'field' => '{page}']]]]],
            ['type' => 'table', 'rows' => [['cells' => [['blocks' => [['type' => 'paragraph', 'runs' => [['text' => '1', 'field' => '{page}']]]]]]]]],
        ];

        expect(blfRoundTrip(blfDoc($blocks))['blocks'])->toBe($blocks);
    });

    it('keeps two identical fields side by side as two fields', function () {
        $blocks = [['type' => 'paragraph', 'runs' => [['text' => '1', 'field' => '{page}'], ['text' => '1', 'field' => '{page}']]]];

        expect(blfRoundTrip(blfDoc($blocks))['blocks'])->toBe($blocks);
    });

    it('caches what the band caches when the field run\'s text is empty', function () {
        $xml = blfParts(blfDoc([['type' => 'paragraph', 'runs' => [['text' => '', 'field' => '{page}']]]]))['word/document.xml'];

        expect($xml)->toContain('<w:fldChar w:fldCharType="separate"/></w:r><w:r><w:t>1</w:t></w:r>');
    });

    it('leaves braces in plain body text literal, as before', function () {
        $xml = blfParts(blfDoc([['type' => 'paragraph', 'runs' => [['text' => 'Write {page} here']]]]))['word/document.xml'];

        expect(blfInstructions($xml))->toBe([])
            ->and($xml)->toContain('Write {page} here');
    });

    it('refuses a field that is not exactly one allow-listed token', function (mixed $field) {
        expect(blfMessages(blfDoc([['type' => 'paragraph', 'runs' => [['text' => 'x', 'field' => $field]]]])))
            ->toBe(['field must be exactly one field token: {page}, {pages}, {section}, {sectionpages}, {author}, {title}, {filename}, {date}, {time}, {date:FORMAT} or {time:FORMAT}']);
    })->with([
        'upper case' => ['{PAGE}'],
        'two tokens' => ['{page} {pages}'],
        'bare instruction' => ['PAGE'],
        'another field' => ['{toc}'],
        'merge field' => ['MERGEFIELD Name'],
        'include text' => ['INCLUDETEXT "C:\\\\secret.txt"'],
        'a quote in the picture' => ['{date:yyyy" \\* MERGEFORMAT}'],
        'a backslash in the picture' => ['{date:\\o}'],
        'an empty picture' => ['{date:}'],
        'a picture on page' => ['{page:x}'],
        'leading space' => [' {page}'],
        'not a string' => [['page']],
    ]);

    it('refuses a field in a header or footer, where the token goes in the text', function () {
        expect(blfMessages(blfDoc([], ['page' => ['footer' => ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => '1', 'field' => '{page}']]]]]]])))
            ->toBe(['in a header or footer write the token in the text ("Page {page}"); field is for body text']);
    });

    it('repairs a field in a header or footer into its token in the text', function () {
        $r = Agent::validateAndRepair(blfDoc([], ['page' => ['footer' => ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => '1', 'field' => '{page}']]]]]]]));

        expect($r['ok'])->toBeTrue()
            ->and($r['schema']['page']['footer']['blocks'][0]['runs'])->toBe([['text' => '{page}']]);
    });

    it('never writes an instruction it did not build from the allow-list', function () {
        $xml = blfParts(blfDoc([
            ['type' => 'toc', 'levels' => 4],
            ['type' => 'heading', 'level' => 1, 'runs' => [['text' => 'H " \\o PAGE']]],
            ['type' => 'paragraph', 'runs' => [['text' => 'x', 'field' => '{date:d MMMM yyyy}'], ['text' => 'INCLUDETEXT "x"']]],
        ]))['word/document.xml'];

        foreach (blfInstructions($xml) as $instruction) {
            expect($instruction)->toMatch('/^ (PAGE|NUMPAGES|SECTION|SECTIONPAGES|AUTHOR|TITLE|FILENAME|(DATE|TIME)( \\\\@ "[^"\\\\{}]+")?|TOC \\\\o "1-[1-6]" \\\\h \\\\z \\\\u) $/');
        }
        expect(blfInstructions($xml))->toBe([' TOC \o "1-4" \h \z \u ', ' DATE \@ "d MMMM yyyy" ']);
    });

    it('reads an allow-listed field from a Word file, simple or complex, and any other field as its text', function () {
        $read = Agent::read(blfDocxWithBody(
            '<w:p><w:fldSimple w:instr=" PAGE \* MERGEFORMAT "><w:r><w:t>4</w:t></w:r></w:fldSimple></w:p>'
            . '<w:p><w:r><w:fldChar w:fldCharType="begin"/></w:r><w:r><w:instrText xml:space="preserve"> NUMPAGES </w:instrText></w:r><w:r><w:fldChar w:fldCharType="separate"/></w:r><w:r><w:t>1</w:t></w:r><w:r><w:t>2</w:t></w:r><w:r><w:fldChar w:fldCharType="end"/></w:r></w:p>'
            . '<w:p><w:fldSimple w:instr=" INCLUDETEXT &quot;C:\\\\secret.txt&quot; "><w:r><w:t>included</w:t></w:r></w:fldSimple></w:p>'
            . '<w:p><w:r><w:fldChar w:fldCharType="begin"/></w:r><w:r><w:instrText xml:space="preserve"> MERGEFIELD Name </w:instrText></w:r><w:r><w:fldChar w:fldCharType="separate"/></w:r><w:r><w:t>«Name»</w:t></w:r><w:r><w:fldChar w:fldCharType="end"/></w:r></w:p>'
            . '<w:p><w:r><w:fldChar w:fldCharType="begin"/></w:r><w:r><w:instrText xml:space="preserve"> IF </w:instrText></w:r><w:r><w:fldChar w:fldCharType="begin"/></w:r><w:r><w:instrText xml:space="preserve"> PAGE </w:instrText></w:r><w:r><w:fldChar w:fldCharType="separate"/></w:r><w:r><w:t>1</w:t></w:r><w:r><w:fldChar w:fldCharType="end"/></w:r><w:r><w:instrText xml:space="preserve"> = 1 "one" "many" </w:instrText></w:r><w:r><w:fldChar w:fldCharType="separate"/></w:r><w:r><w:t>one</w:t></w:r><w:r><w:fldChar w:fldCharType="end"/></w:r></w:p>'
        ));

        expect(array_map(static fn (array $b): array => $b['runs'], $read['blocks']))->toBe([
            [['text' => '4', 'field' => '{page}']],
            [['text' => '12', 'field' => '{pages}']],
            [['text' => 'included']],
            [['text' => '«Name»']],
            [['text' => 'one']],
        ]);
    });
});

describe('a table of contents', function () {
    it('writes a TOC field, its cached entries linked to bookmarks on the headings, and asks Word to update it', function () {
        $parts = blfParts(blfDoc([
            ['type' => 'toc', 'levels' => 2],
            ['type' => 'heading', 'level' => 1, 'runs' => [['text' => 'Summary']]],
            ['type' => 'heading', 'level' => 2, 'runs' => [['text' => 'Figures']]],
            ['type' => 'heading', 'level' => 3, 'runs' => [['text' => 'Detail']]],
        ]));
        $xml = $parts['word/document.xml'];

        expect(blfInstructions($xml))->toBe([' TOC \o "1-2" \h \z \u '])
            ->and($xml)->toContain('<w:fldChar w:fldCharType="begin" w:dirty="true"/>')
            ->and($xml)->toMatch('/<w:hyperlink w:anchor="(_Toc\d+)" w:history="1">.*?Summary.*?<\/w:hyperlink>.*<w:bookmarkStart w:id="\d+" w:name="\1"\/>/s')
            ->and($xml)->toMatch('/<w:hyperlink w:anchor="_Toc\d+" w:history="1">.*?Figures/s')
            ->and($xml)->not->toMatch('/<w:hyperlink w:anchor="_Toc\d+" w:history="1"><w:r><w:t xml:space="preserve">Detail/')
            ->and($parts['word/settings.xml'])->toContain('<w:updateFields w:val="true"/>');
        Package::assertConsistent($parts);
    });

    it('round-trips a toc, with and without levels, and the headings it lists', function () {
        $blocks = [
            ['type' => 'toc'],
            ['type' => 'heading', 'level' => 1, 'runs' => [['text' => 'Summary', 'bookmark' => 'summary']]],
            ['type' => 'paragraph', 'runs' => [['text' => 'Body']]],
            ['type' => 'toc', 'levels' => 1],
            ['type' => 'heading', 'level' => 2, 'runs' => [['text' => 'Figures']]],
        ];

        expect(blfRoundTrip(blfDoc($blocks))['blocks'])->toBe($blocks);
    });

    it('round-trips a toc in a document with no headings', function () {
        $blocks = [['type' => 'toc', 'levels' => 3], ['type' => 'paragraph', 'runs' => [['text' => 'Body']]]];

        expect(blfRoundTrip(blfDoc($blocks))['blocks'])->toBe([['type' => 'toc'], ['type' => 'paragraph', 'runs' => [['text' => 'Body']]]]);
    });

    it('writes no settings and no bookmarks for a document without a toc', function () {
        $parts = blfParts(blfDoc([['type' => 'heading', 'level' => 1, 'runs' => [['text' => 'Summary']]]]));

        expect($parts)->not->toHaveKey('word/settings.xml')
            ->and($parts['word/document.xml'])->not->toContain('bookmarkStart');
    });

    it('reads Word\'s own table of contents, in its content control, as a toc block', function () {
        $entry = static fn (string $text, string $anchor): string => '<w:hyperlink w:anchor="' . $anchor . '" w:history="1"><w:r><w:t>' . $text . '</w:t></w:r><w:r><w:tab/></w:r>'
            . '<w:r><w:fldChar w:fldCharType="begin"/></w:r><w:r><w:instrText xml:space="preserve"> PAGEREF ' . $anchor . ' \h </w:instrText></w:r><w:r><w:fldChar w:fldCharType="separate"/></w:r><w:r><w:t>2</w:t></w:r><w:r><w:fldChar w:fldCharType="end"/></w:r></w:hyperlink>';
        $read = Agent::read(blfDocxWithBody(
            '<w:sdt><w:sdtPr><w:docPartObj><w:docPartGallery w:val="Table of Contents"/><w:docPartUnique/></w:docPartObj></w:sdtPr><w:sdtContent>'
            . '<w:p><w:pPr><w:pStyle w:val="TOCHeading"/></w:pPr><w:r><w:t>Contents</w:t></w:r></w:p>'
            . '<w:p><w:pPr><w:pStyle w:val="TOC1"/></w:pPr><w:r><w:fldChar w:fldCharType="begin"/></w:r><w:r><w:instrText xml:space="preserve"> TOC \o "1-4" \h \z \u </w:instrText></w:r><w:r><w:fldChar w:fldCharType="separate"/></w:r>' . $entry('Summary', '_Toc1') . '</w:p>'
            . '<w:p><w:pPr><w:pStyle w:val="TOC2"/></w:pPr>' . $entry('Figures', '_Toc2') . '</w:p>'
            . '<w:p><w:r><w:fldChar w:fldCharType="end"/></w:r></w:p>'
            . '</w:sdtContent></w:sdt>'
            . '<w:p><w:pPr><w:pStyle w:val="Heading1"/></w:pPr><w:bookmarkStart w:id="0" w:name="_Toc1"/><w:r><w:t>Summary</w:t></w:r><w:bookmarkEnd w:id="0"/></w:p>'
        ));

        expect($read['blocks'])->toBe([
            ['type' => 'paragraph', 'runs' => [['text' => 'Contents']]],
            ['type' => 'toc', 'levels' => 4],
            ['type' => 'heading', 'level' => 1, 'runs' => [['text' => 'Summary']]],
        ])->and($read)->not->toHaveKey('unrepresented');
    });

    it('refuses a bad toc', function (array $doc, string $message) {
        expect(blfMessages($doc))->toBe([$message]);
    })->with([
        'levels 0' => [blfDoc([['type' => 'toc', 'levels' => 0]]), 'toc levels must be an integer from 1 to 6'],
        'levels 7' => [blfDoc([['type' => 'toc', 'levels' => 7]]), 'toc levels must be an integer from 1 to 6'],
        'levels as text' => [blfDoc([['type' => 'toc', 'levels' => '3']]), 'toc levels must be an integer from 1 to 6'],
        'in a quote' => [blfDoc([['type' => 'quote', 'blocks' => [['type' => 'toc']]]]), 'a toc goes at the top level of the body, not in a quote or a table cell'],
        'in a cell' => [blfDoc([['type' => 'table', 'rows' => [['cells' => [['blocks' => [['type' => 'toc']]]]]]]]), 'a toc goes at the top level of the body, not in a quote or a table cell'],
        'in a header' => [blfDoc([], ['page' => ['header' => ['blocks' => [['type' => 'toc']]]]]), 'a header or footer cannot hold a toc block'],
        'in a footnote' => [blfDoc([['type' => 'paragraph', 'runs' => [['text' => '', 'footnote' => ['blocks' => [['type' => 'toc']]]]]]]), 'a footnote cannot hold a toc block'],
    ]);

    it('refuses more toc blocks than the cap', function () {
        expect(blfMessages(blfDoc(array_fill(0, Schema::MAX_TOCS + 1, ['type' => 'toc']))))
            ->toBe(['a document holds at most ' . Schema::MAX_TOCS . ' toc blocks']);
    });

    it('repairs a toc: levels clamped, a nested one dropped', function () {
        $r = Agent::validateAndRepair(blfDoc([
            ['type' => 'toc', 'levels' => 9],
            ['type' => 'quote', 'blocks' => [['type' => 'toc'], ['type' => 'paragraph', 'runs' => [['text' => 'q']]]]],
        ]));

        expect($r['ok'])->toBeTrue()
            ->and($r['schema']['blocks'])->toBe([
                ['type' => 'toc', 'levels' => 6],
                ['type' => 'quote', 'blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'q']]]]],
            ]);
    });
});

describe('the schema and describe()', function () {
    it('declares bookmark, field, the internal link and the toc block', function () {
        $schema = Agent::jsonSchema();
        $run = $schema['$defs']['run']['properties'];
        $blocks = [];
        foreach ($schema['$defs']['block']['oneOf'] as $b) {
            $blocks[$b['properties']['type']['const']] = $b;
        }

        expect($run['bookmark']['pattern'])->toBe('^[A-Za-z][A-Za-z0-9_]{0,39}$')
            ->and($run['field']['pattern'])->toBe('^\{(?:page|pages|section|sectionpages|author|title|filename|(?:date|time)(?::[^{}"\\\\]+)?)\}$')
            ->and($run['link']['description'])->toContain('#')
            ->and($blocks)->toHaveKey('toc')
            ->and($blocks['toc']['properties']['levels'])->toBe(['type' => 'integer', 'minimum' => 1, 'maximum' => 6, 'description' => 'The deepest heading level listed: 3 lists headings 1 to 3. 3 by default.'])
            ->and(Schema::BLOCK_TYPES)->toContain('toc');
        foreach (['bookmark' => 'ok_1', 'field' => '{date:d MMMM yyyy}'] as $key => $good) {
            expect(preg_match('/' . str_replace('/', '\/', $run[$key]['pattern']) . '/', $good))->toBe(1);
        }
    });

    it('says what bookmarks and internal links a document has', function () {
        $summary = Agent::describe(blfDoc([
            ['type' => 'toc'],
            ['type' => 'heading', 'level' => 1, 'runs' => [['text' => 'A', 'bookmark' => 'a']]],
            ['type' => 'paragraph', 'runs' => [['text' => 'B', 'bookmark' => 'b'], ['text' => 'go', 'link' => '#a'], ['text' => 'p', 'field' => '{page}']]],
        ]));

        expect($summary)->toContain('1 toc')
            ->and($summary)->toContain('Bookmarks: 2 (a, b); internal links: 1; fields: 1');
    });
});
