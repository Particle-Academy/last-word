<?php

declare(strict_types=1);

use LastWord\Agent;
use LastWord\Archive\SourceArchive;
use LastWord\Reader\DocxComments;
use LastWord\Schema\Comments;
use LastWord\Tests\Support\Package;

/*
 * Word review comments. A comment lives in the document's top-level `comments`
 * list ({id, author, initials?, date?, text, replyTo?, done?}); the text it is
 * anchored to is the body runs whose `comments` list names its id. A range is
 * those runs, consecutive in body order, so it may span paragraphs, list items
 * and table cells, and two comments may overlap. A run with `text: ""` that
 * names an id is a point comment. A reply names its thread's first comment in
 * `replyTo` and has no anchor of its own: Word puts it on the same range.
 *
 * Word: `w:commentRangeStart` / `w:commentRangeEnd` around the range and a
 * `w:commentReference` run after it; `word/comments.xml` holds each comment;
 * `word/commentsExtended.xml` (w15) holds the threading and the done state.
 *
 * Body only. Word cannot put a comment in a header or footer, and a comment in
 * a footnote would anchor across two parts; both are refused on write and
 * reported on read.
 */

function cmDoc(): array
{
    return [
        'blocks' => [
            ['type' => 'paragraph', 'runs' => [
                ['text' => 'Revenue grew '],
                ['text' => '18%', 'bold' => true, 'comments' => ['c1']],
                ['text' => ' on the prior year.'],
            ]],
        ],
        'comments' => [
            ['id' => 'c1', 'author' => 'Ann Reviewer', 'initials' => 'AR', 'date' => '2026-11-21T09:30:00Z', 'text' => 'Is this audited?'],
        ],
    ];
}

/** A review with every shape the model has: overlap, a range across blocks, a point, a thread, done. */
function cmReviewDoc(): array
{
    return [
        'blocks' => [
            ['type' => 'heading', 'level' => 1, 'runs' => [['text' => 'Results', 'comments' => ['c1']]]],
            ['type' => 'paragraph', 'runs' => [
                ['text' => 'Revenue grew ', 'comments' => ['c1']],
                ['text' => '18%', 'comments' => ['c1', 'c2']],
                ['text' => ' year on year', 'comments' => ['c2']],
                ['text' => '.'],
                ['text' => '', 'comments' => ['c4']],
            ]],
            ['type' => 'list', 'items' => [
                ['runs' => [['text' => 'First item', 'comments' => ['c5']]]],
                ['runs' => [['text' => 'Second item']]],
            ]],
            ['type' => 'table', 'rows' => [
                ['cells' => [
                    ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => '12.5', 'comments' => ['c6']]]]]],
                    ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => '13.0']]]]],
                ]],
            ]],
            ['type' => 'quote', 'blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'Quoted', 'italic' => true, 'comments' => ['c7']]]]]],
        ],
        'comments' => [
            ['id' => 'c1', 'author' => 'Ann Reviewer', 'initials' => 'AR', 'date' => '2026-11-21T09:30:00Z', 'text' => "Spans the heading and the sentence.\nSecond paragraph,\twith a tab."],
            ['id' => 'c2', 'author' => 'Bo Editor', 'text' => 'Overlaps c1.'],
            ['id' => 'c3', 'author' => 'Ann Reviewer', 'initials' => 'AR', 'date' => '2026-11-21T10:00:00Z', 'text' => 'A reply to c2.', 'replyTo' => 'c2'],
            ['id' => 'c4', 'author' => 'Bo Editor', 'text' => 'A point comment.', 'done' => true],
            ['id' => 'c5', 'author' => 'Bo Editor', 'text' => 'On a list item.'],
            ['id' => 'c6', 'author' => 'Bo Editor', 'text' => 'In a table cell.'],
            ['id' => 'c7', 'author' => 'Bo Editor', 'text' => 'In a quote.'],
        ],
    ];
}

/** @return array<string, string> */
function cmParts(array $doc): array
{
    return Package::parts(Agent::toBytes($doc));
}

/** The codes of the errors at a path (or every code when the path is null). */
function cmCodes(array $doc, ?string $path = null): array
{
    $codes = [];
    foreach (Agent::validate($doc) as $error) {
        if ($path === null || $error['path'] === $path) {
            $codes[] = $error['code'] ?? '(none)';
        }
    }

    return $codes;
}

/**
 * A .docx built by hand, the way Word writes one: document.xml with the given
 * body, word/comments.xml and (optionally) word/commentsExtended.xml, wired
 * by relationships and content types.
 *
 * @param  array<string, string>  $parts  extra parts, by archive name
 */
function cmDocx(string $body, ?string $comments, ?string $extended = null, array $parts = [], string $extraRels = ''): string
{
    $tmp = lwTemp('lw-comments', '.docx');
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
    $rels = '';
    if ($comments !== null) {
        $zip->addFromString('word/comments.xml', $comments);
        $rels .= '<Relationship Id="rIdC" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/comments" Target="comments.xml"/>';
    }
    if ($extended !== null) {
        $zip->addFromString('word/commentsExtended.xml', $extended);
        $rels .= '<Relationship Id="rIdCx" Type="http://schemas.microsoft.com/office/2011/relationships/commentsExtended" Target="commentsExtended.xml"/>';
    }
    $zip->addFromString('word/_rels/document.xml.rels', '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . $extraRels . '</Relationships>');
    $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8"?>'
        . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<w:body>' . $body . '<w:sectPr><w:pgSz w:w="12240" w:h="15840"/></w:sectPr></w:body>'
        . '</w:document>');
    foreach ($parts as $name => $xml) {
        $zip->addFromString($name, $xml);
    }
    $zip->close();

    return (string) file_get_contents($tmp);
}

/** word/comments.xml from `w:comment` elements. */
function cmCommentsXml(string $inner): string
{
    return '<?xml version="1.0" encoding="UTF-8"?>'
        . '<w:comments xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:w14="http://schemas.microsoft.com/office/word/2010/wordml">'
        . $inner . '</w:comments>';
}

/** One `w:comment` as Word writes it: the annotation mark, then the text, one paragraph per line. */
function cmComment(int $id, string $text, string $author = 'Ann Reviewer', ?string $paraId = null, string $attrs = ''): string
{
    $paragraphs = '';
    $lines = explode("\n", $text);
    foreach ($lines as $i => $line) {
        $pid = $paraId !== null && $i === count($lines) - 1 ? ' w14:paraId="' . $paraId . '" w14:textId="77777777"' : '';
        $paragraphs .= '<w:p' . $pid . '><w:pPr><w:pStyle w:val="CommentText"/></w:pPr>'
            . ($i === 0 ? '<w:r><w:rPr><w:rStyle w:val="CommentReference"/></w:rPr><w:annotationRef/></w:r>' : '')
            . '<w:r><w:t xml:space="preserve">' . htmlspecialchars($line, ENT_XML1) . '</w:t></w:r></w:p>';
    }

    return '<w:comment w:id="' . $id . '" w:author="' . htmlspecialchars($author, ENT_XML1 | ENT_QUOTES) . '"' . $attrs . '>' . $paragraphs . '</w:comment>';
}

function cmRef(int $id): string
{
    return '<w:r><w:rPr><w:rStyle w:val="CommentReference"/></w:rPr><w:commentReference w:id="' . $id . '"/></w:r>';
}

function cmRun(string $text): string
{
    return '<w:r><w:t xml:space="preserve">' . $text . '</w:t></w:r>';
}

// ─── Schema ──────────────────────────────────────────────────────────────

it('publishes comments on the document and on the run', function () {
    $schema = Agent::jsonSchema();

    expect($schema['properties']['comments']['type'])->toBe('array')
        ->and($schema['properties']['comments']['items'])->toBe(['$ref' => '#/$defs/comment'])
        ->and($schema['properties']['comments']['maxItems'])->toBe(Comments::MAX_COMMENTS)
        ->and($schema['$defs']['run']['properties']['comments']['type'])->toBe('array')
        ->and($schema['$defs']['run']['properties']['comments']['maxItems'])->toBe(Comments::MAX_PER_RUN)
        ->and($schema['$defs']['comment']['required'])->toBe(['id', 'author', 'text'])
        ->and(array_keys($schema['$defs']['comment']['properties']))->toBe(['id', 'author', 'initials', 'date', 'text', 'replyTo', 'done'])
        ->and($schema['$defs']['comment']['additionalProperties'])->toBeFalse()
        ->and($schema['$defs']['comment']['properties']['text']['maxLength'])->toBe(Comments::MAX_TEXT);
});

it('pins the caps', function () {
    expect(Comments::MAX_COMMENTS)->toBe(10000)
        ->and(Comments::MAX_PER_RUN)->toBe(32)
        ->and(Comments::MAX_TEXT)->toBe(32768)
        ->and(Comments::MAX_AUTHOR)->toBe(255)
        ->and(Comments::MAX_INITIALS)->toBe(16);
});

it('describes the comments a document holds', function () {
    expect(Agent::describe(cmReviewDoc()))->toContain('Comments: 7 (1 reply, 1 done)');
});

// ─── Validation ──────────────────────────────────────────────────────────

it('accepts comments: a range, overlap, across blocks, in lists, cells and quotes, a point, a thread, done', function () {
    expect(Agent::validate(cmDoc()))->toBe([])
        ->and(Agent::validate(cmReviewDoc()))->toBe([]);
});

it('accepts a comment on a footnote mark, which sits in the body', function () {
    $doc = cmDoc();
    $doc['blocks'][0]['runs'][] = ['text' => '', 'footnote' => ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'Source.']]]]], 'comments' => ['c2']];
    $doc['comments'][] = ['id' => 'c2', 'author' => 'Ann Reviewer', 'text' => 'Cite the ledger.'];

    expect(Agent::validate($doc))->toBe([]);
});

it('refuses an anchor that names no comment, a reply, or a comment twice', function (array $runComments, string $code) {
    $doc = cmDoc();
    $doc['comments'][] = ['id' => 'c2', 'author' => 'Bo', 'text' => 'Reply.', 'replyTo' => 'c1'];
    $doc['blocks'][0]['runs'][0]['comments'] = $runComments;

    expect(cmCodes($doc, 'blocks[0].runs[0].comments'))->toContain($code);
})->with([
    'unknown id' => [['nope'], 'comment_unknown'],
    'a reply' => [['c2'], 'comment_reply_anchored'],
    'the same id twice' => [['c1', 'c1'], 'comment_anchor_invalid'],
    'not a list' => [['x' => 'c1'], 'comment_anchor_invalid'],
    'not strings' => [[1], 'comment_anchor_invalid'],
]);

it('refuses a range that breaks and starts again', function () {
    $doc = cmDoc();
    $doc['blocks'][0]['runs'][2]['comments'] = ['c1']; // runs 1 and 2 carry it, 0 does not: fine
    expect(Agent::validate($doc))->toBe([]);

    $doc['blocks'][0]['runs'][0]['comments'] = ['c1'];
    $doc['blocks'][0]['runs'][1] = ['text' => '18%', 'bold' => true]; // 0 and 2, not 1
    expect(cmCodes($doc, 'blocks[0].runs[2].comments'))->toBe(['comment_range_broken']);
});

it('refuses a range broken by another paragraph, but not one that runs across paragraphs', function () {
    $doc = cmDoc();
    $doc['blocks'][] = ['type' => 'paragraph', 'runs' => [['text' => 'More.', 'comments' => ['c1']]]];
    $doc['blocks'][0]['runs'][2]['comments'] = ['c1'];
    expect(Agent::validate($doc))->toBe([]);

    $doc['blocks'][0]['runs'][2] = ['text' => ' on the prior year.'];
    expect(cmCodes($doc))->toBe(['comment_range_broken']);
});

it('refuses a comment nothing anchors, and accepts a reply that has none of its own', function () {
    $doc = cmDoc();
    $doc['comments'][] = ['id' => 'c2', 'author' => 'Bo', 'text' => 'Nowhere.'];
    $doc['comments'][] = ['id' => 'c3', 'author' => 'Bo', 'text' => 'Reply to c1.', 'replyTo' => 'c1'];

    expect(Agent::validate($doc))->toBe([['path' => 'comments[1]', 'message' => 'comment "c2" is anchored by no run: put "c2" in the comments of the runs it is about (or a run with text "" for a point)', 'code' => 'comment_unanchored']]);
});

it('refuses comments outside the body: a header, a footer, a footnote', function (array $doc, string $path) {
    expect(cmCodes($doc, $path))->toBe(['comment_outside_body']);
})->with([
    'a header' => [array_merge(cmDoc(), ['page' => ['header' => ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'Head', 'comments' => ['c1']]]]]]]]), 'page.header.blocks[0].runs[0].comments'],
    'a first-page footer' => [array_merge(cmDoc(), ['page' => ['firstFooter' => ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'Foot', 'comments' => ['c1']]]]]]]]), 'page.firstFooter.blocks[0].runs[0].comments'],
    'a footnote' => [(function () {
        $doc = cmDoc();
        $doc['blocks'][0]['runs'][] = ['text' => '', 'footnote' => ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'Note', 'comments' => ['c1']]]]]]];

        return $doc;
    })(), 'blocks[0].runs[3].footnote.blocks[0].runs[0].comments'],
]);

it('refuses malformed comments', function (array $comment, string $path, string $code) {
    $doc = cmDoc();
    $doc['comments'][0] = $comment === ['nope'] ? 'nope' : $comment + ['id' => 'c1'];

    expect(cmCodes($doc, $path))->toContain($code);
})->with([
    'not an object' => [['nope'], 'comments[0]', 'comment_invalid'],
    'no author' => [['text' => 'x'], 'comments[0].author', 'comment_author_invalid'],
    'no text' => [['author' => 'Ann'], 'comments[0].text', 'comment_text_invalid'],
    'an unknown key' => [['author' => 'Ann', 'text' => 'x', 'resolved' => true], 'comments[0].resolved', 'comment_invalid'],
    'done not a boolean' => [['author' => 'Ann', 'text' => 'x', 'done' => 'yes'], 'comments[0].done', 'comment_invalid'],
]);

it('refuses an id that is not a short ASCII name, and a duplicate', function (mixed $id) {
    $doc = cmDoc();
    $doc['comments'][0]['id'] = $id;
    $doc['blocks'][0]['runs'][1]['comments'] = [is_string($id) ? $id : 'c1'];

    expect(cmCodes($doc, 'comments[0].id'))->toBe(['comment_id_invalid']);
})->with([
    'empty' => [''],
    'a space' => ['c 1'],
    'markup' => ['c1"/><w:b/>'],
    'unicode' => ['ç1'],
    'a homoglyph' => ["\u{0441}1"], // Cyrillic es
    'too long' => [str_repeat('a', 65)],
    'a number' => [1],
]);

it('refuses a duplicate id', function () {
    $doc = cmDoc();
    $doc['comments'][] = $doc['comments'][0];

    expect(cmCodes($doc, 'comments[1].id'))->toBe(['comment_id_duplicate']);
});

it('refuses author and initials injection: controls, bidi overrides, padding, markup is only escaped', function (string $field, mixed $value, bool $ok) {
    $doc = cmDoc();
    $doc['comments'][0][$field] = $value;

    $codes = cmCodes($doc, "comments[0].{$field}");
    if ($ok) {
        expect($codes)->toBe([]);
    } else {
        expect($codes)->toBe(["comment_{$field}_invalid"]);
    }
})->with([
    'author: markup is text, escaped on write' => ['author', 'Ann "<w:b/>" & Co', true],
    'author: a newline' => ['author', "Ann\nReviewer", false],
    'author: NUL' => ['author', "Ann\0", false],
    'author: a C1 control' => ['author', "Ann\u{0085}", false],
    'author: right-to-left override' => ['author', "Ann\u{202E}reweiveR", false],
    'author: an isolate' => ['author', "\u{2066}Ann", false],
    'author: zero-width space' => ['author', "Ann\u{200B}", false],
    'author: a trailing space' => ['author', 'Ann ', false],
    'author: a leading no-break space' => ['author', "\u{00A0}Ann", false],
    'author: an ideographic space at the end' => ['author', "Ann\u{3000}", false],
    'author: empty' => ['author', '', false],
    'author: spaces only' => ['author', '   ', false],
    'author: too long' => ['author', str_repeat('A', 256), false],
    'author: the longest' => ['author', str_repeat('A', 255), true],
    'author: not UTF-8' => ['author', "Ann\xC3", false],
    'author: not text' => ['author', ['Ann'], false],
    'initials: a tab' => ['initials', "A\tR", false],
    'initials: an override' => ['initials', "\u{202D}AR", false],
    'initials: too long' => ['initials', str_repeat('A', 17), false],
    'initials: empty' => ['initials', '', false],
    'initials: unicode letters are fine' => ['initials', 'ÅÖ', true],
]);

it('refuses a date that is not an ISO 8601 date-time', function (mixed $date, bool $ok) {
    $doc = cmDoc();
    $doc['comments'][0]['date'] = $date;

    expect(cmCodes($doc, 'comments[0].date'))->toBe($ok ? [] : ['comment_date_invalid']);
})->with([
    'UTC' => ['2026-11-21T09:30:00Z', true],
    'an offset' => ['2026-11-21T09:30:00+05:30', true],
    'local' => ['2026-11-21T09:30:00', true],
    'fractions' => ['2026-11-21T09:30:00.123Z', true],
    'date only' => ['2026-11-21', false],
    'the 30th of February' => ['2026-02-30T09:30:00Z', false],
    'hour 24' => ['2026-11-21T24:00:00Z', false],
    'markup' => ['2026-11-21T09:30:00Z"/><x', false],
    'a trailing newline' => ["2026-11-21T09:30:00Z\n", false],
    'not text' => [20261121, false],
]);

it('refuses comment text with characters XML cannot carry, and over the cap', function (string $text, ?string $code) {
    $doc = cmDoc();
    $doc['comments'][0]['text'] = $text;

    expect(cmCodes($doc, 'comments[0].text'))->toBe($code === null ? [] : [$code]);
})->with([
    'tabs and newlines are fine' => ["a\tb\nc\r\nd", null],
    'right-to-left text is fine' => ["\u{202B}שלום\u{202C}", null],
    'markup is text' => ['<w:p/>&amp;', null],
    'NUL' => ["a\0b", 'comment_text_invalid'],
    'a vertical tab' => ["a\x0Bb", 'comment_text_invalid'],
    'U+FFFE' => ["a\u{FFFE}", 'comment_text_invalid'],
    'not UTF-8' => ["\xFF", 'comment_text_invalid'],
    'at the cap' => [str_repeat('x', 32768), null],
    'over the cap' => [str_repeat('x', 32769), 'comment_text_too_long'],
    'over the cap in characters, not bytes' => [str_repeat('é', 32769), 'comment_text_too_long'],
]);

it('refuses a reply to nothing, to itself, or to a reply', function (string $replyTo) {
    $doc = cmDoc();
    $doc['comments'][] = ['id' => 'c2', 'author' => 'Bo', 'text' => 'r1', 'replyTo' => 'c1'];
    $doc['comments'][] = ['id' => 'c3', 'author' => 'Bo', 'text' => 'r2', 'replyTo' => $replyTo];

    expect(cmCodes($doc, 'comments[2].replyTo'))->toBe(['comment_reply_invalid']);
})->with(['nothing' => ['c9'], 'itself' => ['c3'], 'a reply' => ['c2']]);

it('refuses more comments than the cap', function () {
    $doc = ['blocks' => [['type' => 'paragraph', 'runs' => []]], 'comments' => []];
    for ($i = 1; $i <= Comments::MAX_COMMENTS + 1; $i++) {
        $doc['blocks'][0]['runs'][] = ['text' => 'w', 'comments' => ["c{$i}"]];
        $doc['comments'][] = ['id' => "c{$i}", 'author' => 'A', 'text' => 't'];
    }

    expect(cmCodes($doc, 'comments'))->toBe(['comment_count_exceeded']);

    array_pop($doc['comments']);
    array_pop($doc['blocks'][0]['runs']);
    expect(Agent::validate($doc))->toBe([]);
});

it('refuses more overlapping comments on one run than the cap', function () {
    $doc = ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'w', 'comments' => []]]]], 'comments' => []];
    for ($i = 1; $i <= Comments::MAX_PER_RUN + 1; $i++) {
        $doc['blocks'][0]['runs'][0]['comments'][] = "c{$i}";
        $doc['comments'][] = ['id' => "c{$i}", 'author' => 'A', 'text' => 't'];
    }

    expect(cmCodes($doc, 'blocks[0].runs[0].comments'))->toBe(['comment_overlap_exceeded']);
});

it('refuses a comments key that is not a list', function () {
    expect(cmCodes(['blocks' => [], 'comments' => ['c1' => []]], 'comments'))->toBe(['comment_invalid']);
});

// ─── Repair ──────────────────────────────────────────────────────────────

it('repairs anchors: unknown ids and replies leave the run, a broken range keeps its first stretch', function () {
    $doc = cmDoc();
    $doc['comments'][] = ['id' => 'c2', 'author' => 'Bo', 'text' => 'r', 'replyTo' => 'c1'];
    $doc['blocks'][0]['runs'][0]['comments'] = ['nope', 'c1', 'c2'];
    $doc['blocks'][0]['runs'][1]['comments'] = ['c1'];
    $doc['blocks'][0]['runs'][2]['comments'] = [];
    $doc['blocks'][] = ['type' => 'paragraph', 'runs' => [['text' => 'Later', 'comments' => ['c1']]]];

    $out = Agent::validateAndRepair($doc);

    expect($out['ok'])->toBeTrue()
        ->and($out['schema']['blocks'][0]['runs'][0]['comments'])->toBe(['c1'])
        ->and($out['schema']['blocks'][0]['runs'][1]['comments'])->toBe(['c1'])
        ->and($out['schema']['blocks'][0]['runs'][2])->not->toHaveKey('comments')
        ->and($out['schema']['blocks'][1]['runs'][0])->toBe(['text' => 'Later'])
        ->and($out['schema']['comments'])->toHaveCount(2);
});

it('repairs away comments outside the body and comments nothing anchors, with their replies', function () {
    $doc = cmDoc();
    $doc['page'] = ['header' => ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'Head', 'comments' => ['c1']]]]]]];
    $doc['comments'][] = ['id' => 'c2', 'author' => 'Bo', 'text' => 'Nowhere.'];
    $doc['comments'][] = ['id' => 'c3', 'author' => 'Bo', 'text' => 'Reply to nowhere.', 'replyTo' => 'c2'];

    $out = Agent::validateAndRepair($doc);

    expect($out['ok'])->toBeTrue()
        ->and($out['schema']['page']['header']['blocks'][0]['runs'][0])->toBe(['text' => 'Head'])
        ->and(array_column($out['schema']['comments'], 'id'))->toBe(['c1']);
});

it('repairs padding and controls out of an author, and does not invent an author', function () {
    $doc = cmDoc();
    $doc['comments'][0]['author'] = "  Ann\u{202E} Reviewer\n";
    $doc['comments'][0]['initials'] = ' AR ';

    $out = Agent::validateAndRepair($doc);

    expect($out['ok'])->toBeTrue()
        ->and($out['schema']['comments'][0]['author'])->toBe('Ann Reviewer')
        ->and($out['schema']['comments'][0]['initials'])->toBe('AR');

    $doc['comments'][0]['author'] = "\u{202E}\n";
    expect(Agent::validateAndRepair($doc)['ok'])->toBeFalse();
});

// ─── Writer ──────────────────────────────────────────────────────────────

it('writes comments.xml and the range, wired in everywhere Word looks', function () {
    $parts = cmParts(cmDoc());

    expect($parts)->toHaveKey('word/comments.xml')
        ->and($parts)->not->toHaveKey('word/commentsExtended.xml');
    $doc = $parts['word/document.xml'];
    $c = $parts['word/comments.xml'];

    expect($doc)->toContain('<w:commentRangeStart w:id="0"/><w:r><w:rPr><w:b/></w:rPr><w:t xml:space="preserve">18%</w:t></w:r><w:commentRangeEnd w:id="0"/><w:r><w:rPr><w:rStyle w:val="CommentReference"/></w:rPr><w:commentReference w:id="0"/></w:r>')
        ->and($c)->toContain('<w:comment w:id="0" w:author="Ann Reviewer" w:date="2026-11-21T09:30:00Z" w:initials="AR">')
        ->and($c)->toContain('<w:annotationRef/>')
        ->and($c)->toContain('Is this audited?')
        ->and($parts['word/_rels/document.xml.rels'])->toContain('Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/comments" Target="comments.xml"')
        ->and($parts['[Content_Types].xml'])->toContain('<Override PartName="/word/comments.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.comments+xml"/>')
        ->and($parts['word/styles.xml'])->toContain('w:styleId="CommentText"')
        ->and($parts['word/styles.xml'])->toContain('w:styleId="CommentReference"');

    Package::assertConsistent($parts);
});

it('writes threads and done state to commentsExtended.xml', function () {
    $parts = cmParts(cmReviewDoc());

    $x = $parts['word/commentsExtended.xml'];
    expect($x)->toContain('<w15:commentEx w15:paraId="00000002" w15:done="0"/>')
        ->and($x)->toContain('<w15:commentEx w15:paraId="00000003" w15:paraIdParent="00000002" w15:done="0"/>')
        ->and($x)->toContain('<w15:commentEx w15:paraId="00000004" w15:done="1"/>')
        ->and($parts['word/comments.xml'])->toContain('w14:paraId="00000003"')
        ->and($parts['word/_rels/document.xml.rels'])->toContain('Type="http://schemas.microsoft.com/office/2011/relationships/commentsExtended" Target="commentsExtended.xml"')
        ->and($parts['[Content_Types].xml'])->toContain('<Override PartName="/word/commentsExtended.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.commentsExtended+xml"/>');

    // The reply is on its thread's range: it opens and closes with c2 (w:id 1).
    $d = $parts['word/document.xml'];
    expect($d)->toContain('<w:commentRangeStart w:id="1"/><w:commentRangeStart w:id="2"/>')
        ->and($d)->toContain('<w:commentRangeEnd w:id="1"/><w:commentRangeEnd w:id="2"/>');

    Package::assertConsistent($parts);
});

it('escapes an author, initials and text that look like markup, so every part still parses', function () {
    $doc = cmDoc();
    $doc['comments'][0]['author'] = 'Ann "<w:b/>" & Co';
    $doc['comments'][0]['initials'] = '"&<';
    $doc['comments'][0]['text'] = '</w:t></w:r><w:r><w:t>injected';

    $parts = cmParts($doc);
    $dom = new DOMDocument();
    expect($dom->loadXML($parts['word/comments.xml']))->toBeTrue();
    $comment = $dom->getElementsByTagNameNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'comment')->item(0);
    expect($comment->getAttributeNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'author'))->toBe('Ann "<w:b/>" & Co')
        ->and($comment->getAttributeNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'initials'))->toBe('"&<')
        ->and($dom->getElementsByTagNameNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'r')->length)->toBe(2)
        ->and(Agent::read(Agent::toBytes($doc))['comments'][0]['text'])->toBe('</w:t></w:r><w:r><w:t>injected');
});

it('writes a point comment as markers with no run between them', function () {
    $doc = ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'Here'], ['text' => '', 'comments' => ['c1']], ['text' => ' there']]]], 'comments' => [['id' => 'c1', 'author' => 'A', 'text' => 'p']]];

    expect(cmParts($doc)['word/document.xml'])->toContain('<w:commentRangeStart w:id="0"/><w:commentRangeEnd w:id="0"/><w:r><w:rPr><w:rStyle w:val="CommentReference"/></w:rPr><w:commentReference w:id="0"/></w:r>');
});

it('writes nothing new for a document without comments, and the same bytes twice', function () {
    $plain = cmDoc();
    unset($plain['comments'], $plain['blocks'][0]['runs'][1]['comments']);
    $parts = cmParts($plain);

    expect($parts)->not->toHaveKey('word/comments.xml')
        ->and($parts['word/document.xml'])->not->toContain('comment')
        ->and($parts['word/styles.xml'])->not->toContain('Comment')
        ->and(Agent::toBytes(cmReviewDoc()))->toBe(Agent::toBytes(cmReviewDoc()));
});

it('refuses to write an invalid comment', function () {
    $doc = cmDoc();
    $doc['comments'][0]['author'] = "Ann\u{202E}";

    expect(fn () => Agent::toBytes($doc))->toThrow(\LastWord\Exceptions\SchemaException::class);
});

// ─── Round trip ──────────────────────────────────────────────────────────

it('round-trips a comment: write, read back, the same model', function () {
    $doc = cmDoc();
    $read = Agent::read(Agent::toBytes($doc));

    expect($read['blocks'])->toBe($doc['blocks'])
        ->and($read['comments'])->toBe($doc['comments'])
        ->and($read)->not->toHaveKey('unrepresented');
});

it('round-trips a review: overlap, across blocks, lists, cells, quotes, a point, a thread and done', function () {
    $doc = cmReviewDoc();
    $read = Agent::read(Agent::toBytes($doc));

    expect($read['comments'])->toBe($doc['comments'])
        ->and($read['blocks'])->toBe($doc['blocks'])
        ->and($read)->not->toHaveKey('unrepresented')
        ->and(Agent::toBytes(['blocks' => $read['blocks'], 'comments' => $read['comments']]))->toBe(Agent::toBytes($doc));
});

it('round-trips a comment on a footnote mark', function () {
    $doc = cmDoc();
    $doc['blocks'][0]['runs'][] = ['text' => '', 'footnote' => ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'Source.']]]]], 'comments' => ['c2']];
    $doc['comments'][] = ['id' => 'c2', 'author' => 'Ann Reviewer', 'text' => 'Cite the ledger.'];

    $read = Agent::read(Agent::toBytes($doc));

    expect($read['blocks'])->toBe($doc['blocks'])
        ->and($read['comments'])->toBe($doc['comments']);
});

it('reads a document without comments exactly as before', function () {
    $read = Agent::read(Agent::toBytes(['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'Plain']]]]]));

    expect($read)->not->toHaveKey('comments')
        ->and($read['blocks'])->toBe([['type' => 'paragraph', 'runs' => [['text' => 'Plain']]]]);
});

// ─── Reading what Word (and a hostile file) writes ───────────────────────

it('reads comments as Word writes them: a range, a multi-paragraph body, a thread and done', function () {
    $body = '<w:p>' . cmRun('Before ')
        . '<w:commentRangeStart w:id="5"/><w:commentRangeStart w:id="9"/>' . cmRun('anchored')
        . '<w:commentRangeEnd w:id="5"/><w:commentRangeEnd w:id="9"/>' . cmRef(5) . cmRef(9) . cmRun(' after') . '</w:p>';
    $comments = cmCommentsXml(
        cmComment(5, "First line\nSecond line", 'Ann Reviewer', '1A2B3C4D', ' w:date="2026-11-21T09:30:00Z" w:initials="AR"')
        . cmComment(9, 'Agreed.', 'Bo Editor', '5E6F7A8B'),
    );
    $ext = '<?xml version="1.0" encoding="UTF-8"?><w15:commentsEx xmlns:w15="http://schemas.microsoft.com/office/word/2012/wordml">'
        . '<w15:commentEx w15:paraId="1A2B3C4D" w15:done="1"/><w15:commentEx w15:paraId="5E6F7A8B" w15:paraIdParent="1A2B3C4D" w15:done="0"/></w15:commentsEx>';

    $read = Agent::read(cmDocx($body, $comments, $ext));

    expect($read['blocks'][0]['runs'])->toBe([['text' => 'Before '], ['text' => 'anchored', 'comments' => ['c1']], ['text' => ' after']])
        ->and($read['comments'])->toBe([
            ['id' => 'c1', 'author' => 'Ann Reviewer', 'initials' => 'AR', 'date' => '2026-11-21T09:30:00Z', 'text' => "First line\nSecond line", 'done' => true],
            ['id' => 'c2', 'author' => 'Bo Editor', 'text' => 'Agreed.', 'replyTo' => 'c1'],
        ])
        ->and(Agent::validate($read))->toBe([]);
});

it('reads a range that spans paragraphs, with its markers between them', function () {
    $body = '<w:commentRangeStart w:id="0"/><w:p>' . cmRun('One') . '</w:p><w:p>' . cmRun('Two') . '</w:p><w:commentRangeEnd w:id="0"/><w:p>' . cmRef(0) . cmRun('Three') . '</w:p>';
    $read = Agent::read(cmDocx($body, cmCommentsXml(cmComment(0, 'Both.'))));

    expect(array_column($read['blocks'], 'runs'))->toBe([
        [['text' => 'One', 'comments' => ['c1']]],
        [['text' => 'Two', 'comments' => ['c1']]],
        [['text' => 'Three']],
    ]);
});

it('closes a range that has no end at its reference', function () {
    $body = '<w:p><w:commentRangeStart w:id="0"/>' . cmRun('open') . cmRef(0) . cmRun(' rest') . '</w:p>';
    $read = Agent::read(cmDocx($body, cmCommentsXml(cmComment(0, 'No end.'))));

    expect($read['blocks'][0]['runs'])->toBe([['text' => 'open', 'comments' => ['c1']], ['text' => ' rest']])
        ->and($read)->not->toHaveKey('unrepresented');
});

it('drops a range that never closes, and says so', function () {
    $body = '<w:p><w:commentRangeStart w:id="0"/>' . cmRun('open to the end') . '</w:p><w:p>' . cmRun('and on') . '</w:p>';
    $read = Agent::read(cmDocx($body, cmCommentsXml(cmComment(0, 'Never closed.'))));

    expect($read)->not->toHaveKey('comments')
        ->and($read['blocks'][0]['runs'])->toBe([['text' => 'open to the end']])
        ->and($read['unrepresented'])->toBe([['where' => '/comments', 'kind' => 'comment', 'count' => 1]]);
});

it('ignores an end before its start, and closes at the reference after it', function () {
    $body = '<w:p><w:commentRangeEnd w:id="0"/>' . cmRun('a') . '<w:commentRangeStart w:id="0"/>' . cmRun('b') . cmRef(0) . '</w:p>';
    $read = Agent::read(cmDocx($body, cmCommentsXml(cmComment(0, 'Backwards.'))));

    expect($read['blocks'][0]['runs'])->toBe([['text' => 'a'], ['text' => 'b', 'comments' => ['c1']]]);
});

it('reads a reference with no range as a point comment', function () {
    $body = '<w:p>' . cmRun('Here') . cmRef(0) . cmRun(' there') . '</w:p>';
    $read = Agent::read(cmDocx($body, cmCommentsXml(cmComment(0, 'Point.'))));

    expect($read['blocks'][0]['runs'])->toBe([['text' => 'Here'], ['text' => '', 'comments' => ['c1']], ['text' => ' there']])
        ->and(Agent::validate($read))->toBe([]);
});

it('reads overlapping and crossing ranges', function () {
    $body = '<w:p><w:commentRangeStart w:id="0"/>' . cmRun('a') . '<w:commentRangeStart w:id="1"/>' . cmRun('b')
        . '<w:commentRangeEnd w:id="0"/>' . cmRef(0) . cmRun('c') . '<w:commentRangeEnd w:id="1"/>' . cmRef(1) . '</w:p>';
    $read = Agent::read(cmDocx($body, cmCommentsXml(cmComment(0, 'x') . cmComment(1, 'y'))));

    expect($read['blocks'][0]['runs'])->toBe([
        ['text' => 'a', 'comments' => ['c1']],
        ['text' => 'b', 'comments' => ['c1', 'c2']],
        ['text' => 'c', 'comments' => ['c2']],
    ])->and(Agent::validate($read))->toBe([]);
});

it('ignores orphan references, and drops comments nothing anchors, saying so', function () {
    $body = '<w:p>' . cmRun('Text') . cmRef(42) . '<w:commentRangeStart w:id="43"/><w:commentRangeEnd w:id="43"/></w:p>';
    $read = Agent::read(cmDocx($body, cmCommentsXml(cmComment(0, 'Never placed.') . cmComment(1, 'Also never placed.'))));

    expect($read['blocks'][0]['runs'])->toBe([['text' => 'Text']])
        ->and($read)->not->toHaveKey('comments')
        ->and($read['unrepresented'])->toBe([['where' => '/comments', 'kind' => 'comment', 'count' => 2]]);
});

it('reads no comments from a document without a comments part, even with markers', function () {
    $body = '<w:p><w:commentRangeStart w:id="0"/>' . cmRun('Text') . '<w:commentRangeEnd w:id="0"/>' . cmRef(0) . '</w:p>';
    $read = Agent::read(cmDocx($body, null));

    expect($read)->not->toHaveKey('comments')
        ->and($read)->not->toHaveKey('unrepresented')
        ->and($read['blocks'][0]['runs'])->toBe([['text' => 'Text']]);
});

it('does not read comments in a header, footer or footnote, and says so', function () {
    $w = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"';
    $header = '<?xml version="1.0" encoding="UTF-8"?><w:hdr ' . $w . '><w:p><w:commentRangeStart w:id="1"/>' . cmRun('Head') . '<w:commentRangeEnd w:id="1"/>' . cmRef(1) . '</w:p></w:hdr>';
    $footnotes = '<?xml version="1.0" encoding="UTF-8"?><w:footnotes ' . $w . '><w:footnote w:id="1"><w:p><w:commentRangeStart w:id="2"/>' . cmRun('Note') . '<w:commentRangeEnd w:id="2"/>' . cmRef(2) . '</w:p></w:footnote></w:footnotes>';
    $body = '<w:p>' . cmRun('Body') . '<w:r><w:footnoteReference w:id="1"/></w:r></w:p>'
        . '<w:p><w:pPr><w:sectPr><w:headerReference w:type="default" r:id="rIdH"/></w:sectPr></w:pPr></w:p>';
    $rels = '<Relationship Id="rIdH" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/header" Target="header1.xml"/>'
        . '<Relationship Id="rIdF" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footnotes" Target="footnotes.xml"/>';

    $read = Agent::read(cmDocx($body, cmCommentsXml(cmComment(1, 'In the header.') . cmComment(2, 'In a footnote.')), null, [
        'word/header1.xml' => $header,
        'word/footnotes.xml' => $footnotes,
    ], $rels));

    expect($read)->not->toHaveKey('comments')
        ->and($read['unrepresented'])->toContain(['where' => '/comments', 'kind' => 'comment', 'count' => 2])
        ->and(json_encode($read))->not->toContain('"comments":["');
});

it('cleans a hostile author, initials and date as it reads, so what it reads validates', function () {
    $body = '<w:p><w:commentRangeStart w:id="0"/>' . cmRun('x') . '<w:commentRangeEnd w:id="0"/>' . cmRef(0) . '</w:p>'
        . '<w:p><w:commentRangeStart w:id="1"/>' . cmRun('y') . '<w:commentRangeEnd w:id="1"/>' . cmRef(1) . '</w:p>';
    $comments = cmCommentsXml(
        cmComment(0, 'Bodytext',"  Ann\u{202E}\u{200B} Reviewer\t", null, ' w:initials=" A&#10;R " w:date="yesterday"')
        . cmComment(1, 'Two', "\u{202E} ", null, ' w:initials="' . str_repeat('I', 40) . '" w:date="2026-02-30T00:00:00Z"'),
    );

    $read = Agent::read(cmDocx($body, $comments));

    expect($read['comments'])->toBe([
        ['id' => 'c1', 'author' => 'Ann Reviewer', 'initials' => 'AR', 'text' => 'Bodytext'],
        ['id' => 'c2', 'author' => 'Unknown', 'initials' => str_repeat('I', 16), 'text' => 'Two'],
    ])->and(Agent::validate($read))->toBe([]);
});

it('truncates a comment body over the cap as it reads, and says so', function () {
    $body = '<w:p><w:commentRangeStart w:id="0"/>' . cmRun('x') . '<w:commentRangeEnd w:id="0"/>' . cmRef(0) . '</w:p>';
    $read = Agent::read(cmDocx($body, cmCommentsXml(cmComment(0, str_repeat('é', Comments::MAX_TEXT + 10)))));

    expect(mb_strlen($read['comments'][0]['text']))->toBe(Comments::MAX_TEXT)
        ->and($read['unrepresented'])->toBe([['where' => '/comments/0/text', 'kind' => 'comment', 'count' => 1]])
        ->and(Agent::validate($read))->toBe([]);
});

it('reads at most the cap of comments, and reports the rest', function () {
    $n = Comments::MAX_COMMENTS + 3;
    $runs = '';
    $comments = '';
    for ($i = 0; $i < $n; $i++) {
        $runs .= '<w:commentRangeStart w:id="' . $i . '"/>' . cmRun('w') . '<w:commentRangeEnd w:id="' . $i . '"/>' . cmRef($i);
        $comments .= '<w:comment w:id="' . $i . '" w:author="A"><w:p><w:r><w:t>t</w:t></w:r></w:p></w:comment>';
    }
    $read = Agent::read(cmDocx('<w:p>' . $runs . '</w:p>', cmCommentsXml($comments)));

    expect($read['comments'])->toHaveCount(Comments::MAX_COMMENTS)
        ->and($read['unrepresented'])->toBe([['where' => '/comments', 'kind' => 'comment', 'count' => 3]])
        ->and(Agent::validate($read))->toBe([]);
});

it('reads at most the cap of comments on one run, and reports the rest', function () {
    $n = Comments::MAX_PER_RUN + 2;
    $starts = $ends = $comments = '';
    for ($i = 0; $i < $n; $i++) {
        $starts .= '<w:commentRangeStart w:id="' . $i . '"/>';
        $ends .= '<w:commentRangeEnd w:id="' . $i . '"/>' . cmRef($i);
        $comments .= cmComment($i, "c{$i}");
    }
    $read = Agent::read(cmDocx('<w:p>' . $starts . cmRun('crowded') . $ends . '</w:p>', cmCommentsXml($comments)));

    expect($read['blocks'][0]['runs'][0]['comments'])->toHaveCount(Comments::MAX_PER_RUN)
        ->and($read['comments'])->toHaveCount(Comments::MAX_PER_RUN)
        ->and($read['unrepresented'])->toBe([['where' => '/comments', 'kind' => 'comment', 'count' => 2]])
        ->and(Agent::validate($read))->toBe([]);
});

it('reads a hostile thread: a reply to a reply goes to the root, a cycle and a stranger parent are no thread', function () {
    $body = '';
    foreach ([0, 3, 4] as $id) {
        $body .= '<w:p><w:commentRangeStart w:id="' . $id . '"/>' . cmRun("r{$id}") . '<w:commentRangeEnd w:id="' . $id . '"/>' . cmRef($id) . '</w:p>';
    }
    $comments = cmCommentsXml(
        cmComment(0, 'root', 'A', '00000010')
        . cmComment(1, 'reply', 'A', '00000011')
        . cmComment(2, 'reply to the reply', 'A', '00000012')
        . cmComment(3, 'cycle a', 'A', '00000013')
        . cmComment(4, 'stranger', 'A', '00000014'),
    );
    $ext = '<?xml version="1.0" encoding="UTF-8"?><w15:commentsEx xmlns:w15="http://schemas.microsoft.com/office/word/2012/wordml">'
        . '<w15:commentEx w15:paraId="00000011" w15:paraIdParent="00000010"/>'
        . '<w15:commentEx w15:paraId="00000012" w15:paraIdParent="00000011"/>'
        . '<w15:commentEx w15:paraId="00000013" w15:paraIdParent="00000013"/>'
        . '<w15:commentEx w15:paraId="00000014" w15:paraIdParent="DEADBEEF"/>'
        . '</w15:commentsEx>';

    $read = Agent::read(cmDocx($body, $comments, $ext));

    expect(array_map(static fn (array $c): ?string => $c['replyTo'] ?? null, $read['comments']))->toBe([null, 'c1', 'c1', null, null])
        ->and(Agent::validate($read))->toBe([]);
});

it('resolves a long chain of replies to its root in linear work, in either order', function (bool $deepestFirst) {
    $n = 5000;
    $comments = '';
    $ext = [];
    for ($i = 0; $i < $n; $i++) {
        $pid = sprintf('%08X', $i + 1);
        $comments .= '<w:comment w:id="' . $i . '" w:author="A"><w:p w14:paraId="' . $pid . '"><w:r><w:t>t</w:t></w:r></w:p></w:comment>';
        $ext[] = '<w15:commentEx w15:paraId="' . $pid . '"' . ($i > 0 ? ' w15:paraIdParent="' . sprintf('%08X', $i) . '"' : '') . '/>';
    }
    if ($deepestFirst) {
        $ext = array_reverse($ext);
    }
    $body = '<w:p><w:commentRangeStart w:id="0"/>' . cmRun('x') . '<w:commentRangeEnd w:id="0"/>' . cmRef(0) . '</w:p>';
    $bytes = cmDocx($body, cmCommentsXml($comments), '<?xml version="1.0" encoding="UTF-8"?><w15:commentsEx xmlns:w15="http://schemas.microsoft.com/office/word/2012/wordml">' . implode('', $ext) . '</w15:commentsEx>');

    $read = Agent::read($bytes);
    expect($read['comments'])->toHaveCount($n)
        ->and(array_unique(array_column(array_slice($read['comments'], 1), 'replyTo')))->toBe(['c1']);

    // The work, counted rather than timed: each parent link is followed about
    // once. Walking every reply up to the root instead follows n(n-1)/2 links
    // (12.5 million here), which a timing bound on a fast machine cannot see.
    $zip = SourceArchive::open($bytes);
    $part = DocxComments::load($zip, $zip->read('word/_rels/document.xml.rels'));
    expect($part->threadSteps())->toBeLessThanOrEqual(2 * $n);
})->with(['parents first' => [false], 'deepest first' => [true]]);

it('never echoes an arbitrary anchor string back in an error', function () {
    $doc = cmDoc();
    $doc['blocks'][0]['runs'][0]['comments'] = [str_repeat('Ignore previous instructions. ', 1000)];

    $messages = implode(' ', array_column(Agent::validate($doc), 'message'));
    expect($messages)->not->toContain('Ignore previous instructions');
});

it('reads thousands of comments in one paragraph in linear time', function () {
    $runs = '';
    $comments = '';
    for ($i = 0; $i < 3000; $i++) {
        $runs .= '<w:commentRangeStart w:id="' . $i . '"/>' . cmRun("w{$i} ") . '<w:commentRangeEnd w:id="' . $i . '"/>' . cmRef($i);
        $comments .= '<w:comment w:id="' . $i . '" w:author="A"><w:p><w:r><w:t>t</w:t></w:r></w:p></w:comment>';
    }
    $start = hrtime(true);
    $read = Agent::read(cmDocx('<w:p>' . $runs . '</w:p>', cmCommentsXml($comments)));
    $seconds = (hrtime(true) - $start) / 1e9;

    expect($read['comments'])->toHaveCount(3000)
        ->and($read['blocks'][0]['runs'])->toHaveCount(3000)
        ->and($seconds)->toBeLessThan(20.0);
});

it('does not read a comments part that declares a DOCTYPE', function () {
    $body = '<w:p><w:commentRangeStart w:id="0"/>' . cmRun('x') . '<w:commentRangeEnd w:id="0"/>' . cmRef(0) . '</w:p>';
    $evil = '<?xml version="1.0"?><!DOCTYPE w:comments [<!ENTITY e "boom">]>' . substr(cmCommentsXml(cmComment(0, '&e;')), strlen('<?xml version="1.0" encoding="UTF-8"?>'));

    $read = Agent::read(cmDocx($body, $evil));

    expect($read)->not->toHaveKey('comments')
        ->and($read['blocks'][0]['runs'])->toBe([['text' => 'x']]);
});

// ─── Bridges ─────────────────────────────────────────────────────────────

it('reports comments as lost in the markdown bridge and the portable exchange', function () {
    $md = Agent::toMarkdownWithReport(cmDoc());
    $portable = Agent::toPortable(cmDoc());

    expect(json_encode($md['report']))->toContain('"comment"')
        ->and(json_encode($portable))->toContain('"comment"');
});
