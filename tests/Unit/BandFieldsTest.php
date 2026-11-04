<?php

declare(strict_types=1);

use LastWord\Agent;
use LastWord\Tests\Support\Package;

/**
 * Word's standard fields in header and footer text, beyond `{page}` /
 * `{pages}` (MOIC, 2026-11-01), and the document metadata AUTHOR and TITLE
 * read from.
 *
 * Grammar, in a band slot or in a `{blocks}` band's run text:
 *
 *   {page} {pages} {section} {sectionpages} {author} {title} {filename}
 *   {date} {date:FORMAT} {time} {time:FORMAT}
 *
 * FORMAT is a Word date-time picture (`yyyy-MM-dd`, `d MMMM yyyy`, `HH:mm`),
 * one or more characters, none of `{`, `}`, `"` or a backslash. Anything else in
 * braces is literal text, as it always was. In the body every token is literal.
 *
 * Each token is a COMPLEX field -- begin, ` INSTR ` instruction, separate, the
 * cached result, end -- every piece in the band's (or run's) formatting, as
 * `{page}` already was. The cached result is what a viewer and LibreOffice show
 * before fields update, and is deterministic: see FIELD_ROWS.
 */
const FIELD_ROWS = [
    // token, instruction, cached result (with meta.author "Ada Lovelace" and title "Board memo")
    ['{page}', ' PAGE ', '1'],
    ['{pages}', ' NUMPAGES ', '1'],
    ['{section}', ' SECTION ', '1'],
    ['{sectionpages}', ' SECTIONPAGES ', '1'],
    ['{author}', ' AUTHOR ', 'Ada Lovelace'],
    ['{title}', ' TITLE ', 'Board memo'],
    ['{filename}', ' FILENAME ', ''],
    ['{date}', ' DATE ', ''],
    ['{date:yyyy-MM-dd}', ' DATE \@ "yyyy-MM-dd" ', ''],
    ['{date:d MMMM yyyy}', ' DATE \@ "d MMMM yyyy" ', ''],
    ['{time}', ' TIME ', ''],
    ['{time:HH:mm}', ' TIME \@ "HH:mm" ', ''],
];

function bfDoc(array $page, array $extra = []): array
{
    return $extra + [
        'title' => 'Board memo',
        'meta' => ['author' => 'Ada Lovelace'],
        'page' => $page,
        'blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'Body']]]],
    ];
}

/**
 * Every complex field in a part, in order, as [instruction, cached result].
 *
 * @return list<array{0: string, 1: string}>
 */
function bfFields(string $xml): array
{
    $dom = new DOMDocument();
    $dom->loadXML($xml);
    $out = [];
    $state = null;
    $instr = $result = '';
    foreach ($dom->getElementsByTagName('*') as $el) {
        if ($el->localName === 'fldChar') {
            $type = $el->getAttribute('w:fldCharType');
            if ($type === 'begin') {
                [$state, $instr, $result] = ['instr', '', ''];
            } elseif ($type === 'separate') {
                $state = 'result';
            } elseif ($type === 'end') {
                $out[] = [$instr, $result];
                $state = null;
            }
        } elseif ($el->localName === 'instrText' && $state === 'instr') {
            $instr .= $el->textContent;
        } elseif ($el->localName === 't' && $state === 'result') {
            $result .= $el->textContent;
        }
    }

    return $out;
}

it('writes each token in a band slot as a live field with its cached result', function (string $token, string $instr, string $result): void {
    $parts = Package::parts(Agent::toBytes(bfDoc(['footer' => ['left' => "A {$token} B"]])));

    expect(bfFields($parts['word/footer1.xml']))->toBe([[$instr, $result]])
        ->and($parts['word/footer1.xml'])->toContain('<w:t xml:space="preserve">A </w:t>')
        ->and($parts['word/footer1.xml'])->toContain('<w:t xml:space="preserve"> B</w:t>');
})->with(FIELD_ROWS);

it('writes each token in a blocks band as a live field in the run\'s own formatting', function (string $token, string $instr, string $result): void {
    $parts = Package::parts(Agent::toBytes(bfDoc(['header' => ['blocks' => [
        ['type' => 'paragraph', 'runs' => [['text' => "On {$token}", 'bold' => true]]],
    ]]])));

    expect(bfFields($parts['word/header1.xml']))->toBe([[$instr, $result]]);
    // Every piece of the field carries the run's bold.
    preg_match_all('/<w:r><w:rPr><w:b\/><\/w:rPr><w:(fldChar|instrText)/', $parts['word/header1.xml'], $m);
    expect(count($m[1]))->toBe(4);
})->with(FIELD_ROWS);

it('reads every token back from a slot band and a blocks band', function (string $token): void {
    $page = [
        'header' => ['left' => "L {$token}", 'right' => 'R'],
        'footer' => ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => "Footer {$token} end"]]]]],
    ];

    expect(Agent::read(Agent::toBytes(bfDoc($page)))['page'])->toBe($page);
})->with(array_column(FIELD_ROWS, 0));

it('round-trips every token together, with meta and title', function (): void {
    $doc = bfDoc(['footer' => ['left' => implode(' | ', array_column(FIELD_ROWS, 0))]]);

    expect(Agent::read(Agent::toBytes($doc)))->toBe($doc);
});

it('keeps anything else in braces literal', function (string $text): void {
    $parts = Package::parts(Agent::toBytes(bfDoc(['footer' => ['left' => $text]])));

    expect($parts['word/footer1.xml'])->not->toContain('fldChar')
        ->and(Agent::read(Agent::toBytes(bfDoc(['footer' => ['left' => $text]])))['page']['footer']['left'])->toBe($text);
})->with([
    '{Date}', '{AUTHOR}', '{foo}', '{date:}', '{date:a"b}', '{ page}', '{author:x}', '{filename:p}',
]);

it('keeps every token literal in the body', function (): void {
    $doc = ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'Dated {date} by {author}, {title}.']]]]];

    expect(Package::parts(Agent::toBytes($doc))['word/document.xml'])->not->toContain('fldChar');
});

it('reads another producer\'s fields as tokens, switches and all', function (string $instr, string $token): void {
    $bytes = Agent::toBytes(bfDoc(['footer' => ['left' => 'X {date} Y']]));
    $parts = Package::parts($bytes);
    $parts['word/footer1.xml'] = str_replace(' DATE ', $instr, $parts['word/footer1.xml']);

    expect(Agent::read(bfZip($parts))['page']['footer']['left'])->toBe("X {$token} Y");
})->with([
    'Word default date' => [' DATE \@ "M/d/yyyy" \* MERGEFORMAT ', '{date:M/d/yyyy}'],
    'unquoted picture' => [' DATE \@ yyyy ', '{date:yyyy}'],
    'lower case' => [' date ', '{date}'],
    'time' => [' TIME \@ "h:mm am/pm" ', '{time:h:mm am/pm}'],
    'section pages' => [' SECTIONPAGES ', '{sectionpages}'],
    'author with an argument' => [' AUTHOR "Someone" ', '{author}'],
    'filename with path' => [' FILENAME \p ', '{filename}'],
    'a picture the grammar cannot hold' => [' DATE \@ "d}M" ', '{date}'],
]);

it('keeps the cached result of a field that is not a token, in a one-line band', function (): void {
    $bytes = Agent::toBytes(bfDoc(['footer' => ['left' => 'X {page} Y']]));
    $parts = Package::parts($bytes);
    $parts['word/footer1.xml'] = str_replace(
        [' PAGE ', '<w:t>1</w:t>'],
        [' STYLEREF "Heading 1" ', '<w:t>Introduction</w:t>'],
        $parts['word/footer1.xml'],
    );

    expect(Agent::read(bfZip($parts))['page']['footer']['left'])->toBe('X Introduction Y');
});

function bfZip(array $parts): string
{
    $path = lwTemp('lw-bf', '.docx');
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($parts as $name => $xml) {
        $zip->addFromString($name, $xml);
    }
    $zip->close();
    $bytes = (string) file_get_contents($path);
    @unlink($path);

    return $bytes;
}

// ─── meta ────────────────────────────────────────────────────────────────

it('writes meta.author as dc:creator beside dc:title, and reads it back', function (): void {
    $doc = ['title' => 'Board memo', 'meta' => ['author' => 'Ada & Co'], 'blocks' => []];
    $parts = Package::parts(Agent::toBytes($doc));

    expect($parts['docProps/core.xml'])->toContain('<dc:title>Board memo</dc:title><dc:creator>Ada &amp; Co</dc:creator></cp:coreProperties>');
    expect(Agent::read(Agent::toBytes($doc)))->toBe($doc);
});

it('writes core.xml for an author with no title', function (): void {
    $doc = ['meta' => ['author' => 'Ada Lovelace'], 'blocks' => []];
    $parts = Package::parts(Agent::toBytes($doc));

    expect($parts['docProps/core.xml'])->toContain('<dc:creator>Ada Lovelace</dc:creator>')
        ->and($parts['docProps/core.xml'])->not->toContain('dc:title')
        ->and($parts['[Content_Types].xml'])->toContain('/docProps/core.xml')
        ->and($parts['_rels/.rels'])->toContain('docProps/core.xml');
    expect(Agent::read(Agent::toBytes($doc)))->toBe($doc);
    Package::assertConsistent($parts);
});

it('writes an empty cached result for AUTHOR and TITLE when there is no author or title', function (): void {
    $parts = Package::parts(Agent::toBytes(['page' => ['footer' => ['left' => '{author} {title}']], 'blocks' => []]));

    expect(bfFields($parts['word/footer1.xml']))->toBe([[' AUTHOR ', ''], [' TITLE ', '']]);
    expect($parts)->not->toHaveKey('docProps/core.xml');
});

it('publishes meta in the schema', function (): void {
    $meta = Agent::jsonSchema()['properties']['meta'];

    expect($meta['type'])->toBe('object')
        ->and($meta['additionalProperties'])->toBeFalse()
        ->and(array_keys($meta['properties']))->toBe(['author']);
});

it('validates meta', function (mixed $meta, string $path): void {
    expect(array_column(Agent::validate(['meta' => $meta, 'blocks' => []]), 'path'))->toContain($path);
})->with([
    'not an object' => ['Ada', 'meta'],
    'author not text' => [['author' => 7], 'meta.author'],
    'title belongs at the top' => [['title' => 'Board memo'], 'meta.title'],
]);

it('repairs meta.title into the document title', function (): void {
    $out = Agent::validateAndRepair(['meta' => ['author' => 'Ada', 'title' => 'Board memo'], 'blocks' => []]);

    expect($out['ok'])->toBeTrue()
        ->and($out['schema']['title'])->toBe('Board memo')
        ->and($out['schema']['meta'])->toBe(['author' => 'Ada']);
});
