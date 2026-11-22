<?php

declare(strict_types=1);

use LastWord\Agent;
use LastWord\Exceptions\TrackedChangesRefused;

/*
 * Tracked changes as part of the model: a run or a paragraph carries `revision`,
 * the writer emits w:ins, w:del (w:delText), w:moveFrom / w:moveTo with their
 * range markers, w:rPrChange and w:pPrChange, and `read()` with
 * `preserveTrackedChanges` gives the same model back. The default read still
 * accepts every revision; `refuseTrackedChanges` still refuses.
 */

/* Literal values, not Revisions::MAX_AUTHOR and ::MAX_REVISIONS, so this file loads, and fails by assertion, before the package has them. */
const LWR_MAX_AUTHOR = 255;
const LWR_MAX_REVISIONS = 10000;

const LWR_W = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"';

/** One revision entry, by Ann unless told otherwise. */
function lwrBy(string $id, array $extra = [], string $author = 'Ann Editor'): array
{
    return array_replace(['id' => $id, 'author' => $author, 'date' => '2026-11-20T10:00:00Z'], $extra);
}

function lwrPara(array $runs, array $extra = []): array
{
    return ['type' => 'paragraph', 'runs' => $runs] + $extra;
}

/**
 * Write, read back with revisions preserved, and require the same model and,
 * written again, the same bytes.
 *
 * @return array{bytes: string, read: array<string, mixed>}
 */
function lwrRoundTrip(array $doc): array
{
    expect(Agent::validate($doc))->toBe([]);
    $bytes = Agent::toBytes($doc);
    $read = Agent::read($bytes, ['preserveTrackedChanges' => true]);
    expect($read)->not->toHaveKey('unrepresented');
    $model = $read;
    unset($model['trackedChanges']);
    expect($model)->toBe($doc)
        ->and(Agent::toBytes($model))->toBe($bytes);

    return ['bytes' => $bytes, 'read' => $read];
}

function lwrPart(string $bytes, string $name): string
{
    $tmp = lwTemp('lw-rev-part', '.docx');
    file_put_contents($tmp, $bytes);
    $zip = new ZipArchive();
    expect($zip->open($tmp))->toBeTrue();
    $xml = (string) $zip->getFromName($name);
    $zip->close();

    return $xml;
}

/** A hand-built .docx: the body, optional footnotes, optional extra parts and relationships. */
function lwrDocx(string $body, ?string $footnotes = null, array $parts = [], string $rels = '', string $sectPr = ''): string
{
    $tmp = lwTemp('lw-rev', '.docx');
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
    if ($footnotes !== null) {
        $zip->addFromString('word/footnotes.xml', '<?xml version="1.0" encoding="UTF-8"?><w:footnotes ' . LWR_W . '>' . $footnotes . '</w:footnotes>');
        $rels .= '<Relationship Id="rIdFn" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footnotes" Target="footnotes.xml"/>';
    }
    $zip->addFromString('word/_rels/document.xml.rels', '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '</Relationships>');
    $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8"?><w:document ' . LWR_W
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><w:body>' . $body
        . '<w:sectPr>' . $sectPr . '<w:pgSz w:w="12240" w:h="15840"/></w:sectPr></w:body></w:document>');
    foreach ($parts as $name => $xml) {
        $zip->addFromString($name, $xml);
    }
    $zip->close();

    return (string) file_get_contents($tmp);
}

function lwrRead(string $bytes): array
{
    return Agent::read($bytes, ['preserveTrackedChanges' => true]);
}

function lwrUnkept(array $read, string $where = '/blocks'): int
{
    $n = 0;
    foreach ($read['unrepresented'] ?? [] as $note) {
        if ($note['kind'] === 'tracked-change' && $note['where'] === $where) {
            $n += $note['count'];
        }
    }

    return $n;
}

function lwrCodes(array $doc): array
{
    return array_values(array_unique(array_map(static fn (array $e): string => $e['code'] ?? '', Agent::validate($doc))));
}

it('spells the caps as the package does', function () {
    $c = static fn (string $name): mixed => defined('LastWord\Schema\Revisions::' . $name) ? constant('LastWord\Schema\Revisions::' . $name) : null;

    expect($c('MAX_AUTHOR'))->toBe(LWR_MAX_AUTHOR)
        ->and($c('MAX_REVISIONS'))->toBe(LWR_MAX_REVISIONS)
        ->and($c('UNKNOWN_AUTHOR'))->toBe('Unknown');
});

// ─── Each kind, alone: write, read back, same model, same bytes ──────────────

describe('round trip, one kind at a time', function () {
    it('an insertion', function () {
        $doc = ['blocks' => [lwrPara([
            ['text' => 'The fee is '],
            ['text' => 'twelve', 'revision' => ['insert' => lwrBy('r1')]],
            ['text' => ' pounds.'],
        ])]];
        $xml = lwrPart(lwrRoundTrip($doc)['bytes'], 'word/document.xml');

        expect($xml)->toContain('<w:ins w:id="1" w:author="Ann Editor" w:date="2026-11-20T10:00:00Z"><w:r><w:t xml:space="preserve">twelve</w:t></w:r></w:ins>');
    });

    it('a deletion, written as w:delText, line break and tab included', function () {
        $doc = ['blocks' => [lwrPara([
            ['text' => 'Keep '],
            ['text' => "gone\tand\nmore", 'bold' => true, 'revision' => ['delete' => lwrBy('r1')]],
        ])]];
        $xml = lwrPart(lwrRoundTrip($doc)['bytes'], 'word/document.xml');

        expect($xml)->toContain('<w:del w:id="1" w:author="Ann Editor" w:date="2026-11-20T10:00:00Z"><w:r><w:rPr><w:b/></w:rPr><w:delText xml:space="preserve">')
            ->and($xml)->not->toContain('<w:t xml:space="preserve">gone');
    });

    it('a move, its source and destination in different paragraphs', function () {
        $doc = ['blocks' => [
            lwrPara([['text' => 'Moved sentence.', 'revision' => ['moveFrom' => lwrBy('r1', ['name' => 'move1'])]], ['text' => ' Stays.']]),
            lwrPara([['text' => 'Middle.']]),
            lwrPara([['text' => 'Moved sentence.', 'revision' => ['moveTo' => lwrBy('r2', ['name' => 'move1'])]]]),
        ]];
        $xml = lwrPart(lwrRoundTrip($doc)['bytes'], 'word/document.xml');

        expect($xml)->toMatch('#<w:moveFromRangeStart w:id="(\d+)" w:author="Ann Editor" w:date="2026-11-20T10:00:00Z" w:name="move1"/><w:moveFrom w:id="\d+"[^>]*>.*</w:moveFrom><w:moveFromRangeEnd w:id="\1"/>#U')
            ->and($xml)->toMatch('#<w:moveToRangeStart w:id="(\d+)"[^>]* w:name="move1"/><w:moveTo [^>]*>.*</w:moveTo><w:moveToRangeEnd w:id="\1"/>#U');
    });

    it('a move that spans two paragraphs is one range', function () {
        $from = ['moveFrom' => lwrBy('r1', ['name' => 'm.a'])];
        $doc = ['blocks' => [
            lwrPara([['text' => 'First moved.', 'revision' => $from]]),
            lwrPara([['text' => 'Second moved.', 'revision' => ['moveFrom' => lwrBy('r2', ['name' => 'm.a'])]]]),
            lwrPara([['text' => 'First moved.Second moved.', 'revision' => ['moveTo' => lwrBy('r3', ['name' => 'm.a'])]]]),
        ]];
        $xml = lwrPart(lwrRoundTrip($doc)['bytes'], 'word/document.xml');

        expect(substr_count($xml, '<w:moveFromRangeStart'))->toBe(1)
            ->and(substr_count($xml, '<w:moveFromRangeEnd'))->toBe(1)
            ->and(substr_count($xml, '<w:moveFrom '))->toBe(2);
    });

    it('a run formatting change, from no formatting and from some', function () {
        $doc = ['blocks' => [lwrPara([
            ['text' => 'Bold now.', 'bold' => true, 'revision' => ['format' => lwrBy('r1', ['from' => []])]],
            ['text' => ' Was italic.', 'underline' => true, 'revision' => ['format' => lwrBy('r2', ['from' => ['italic' => true, 'color' => '#FF0000']])]],
        ])]];
        $xml = lwrPart(lwrRoundTrip($doc)['bytes'], 'word/document.xml');

        expect($xml)->toContain('<w:rPr><w:b/><w:rPrChange w:id="1" w:author="Ann Editor" w:date="2026-11-20T10:00:00Z"><w:rPr/></w:rPrChange></w:rPr>')
            ->and($xml)->toContain('<w:rPrChange w:id="2" w:author="Ann Editor" w:date="2026-11-20T10:00:00Z"><w:rPr><w:i/><w:color w:val="FF0000"/></w:rPr></w:rPrChange>');
    });

    it('a paragraph formatting change keeps the style it had', function () {
        $doc = ['blocks' => [
            lwrPara([['text' => 'Centred now.']], ['align' => 'center', 'revision' => ['format' => lwrBy('r1', ['from' => []])]]),
            ['type' => 'heading', 'level' => 2, 'runs' => [['text' => 'Title']], 'align' => 'right', 'spaceAfter' => 6, 'revision' => ['format' => lwrBy('r2', ['from' => ['align' => 'center']])]],
        ]];
        $xml = lwrPart(lwrRoundTrip($doc)['bytes'], 'word/document.xml');

        expect($xml)->toContain('<w:pPrChange w:id="1" w:author="Ann Editor" w:date="2026-11-20T10:00:00Z"><w:pPr/></w:pPrChange>')
            ->and($xml)->toContain('<w:pPrChange w:id="2" w:author="Ann Editor" w:date="2026-11-20T10:00:00Z"><w:pPr><w:pStyle w:val="Heading2"/><w:jc w:val="center"/></w:pPr></w:pPrChange>');
    });

    it('an inserted and a deleted paragraph mark', function () {
        $doc = ['blocks' => [
            lwrPara([['text' => 'A new paragraph.', 'revision' => ['insert' => lwrBy('r2')]]], ['revision' => ['insert' => lwrBy('r1')]]),
            lwrPara([['text' => 'Joined to the next.']], ['revision' => ['delete' => lwrBy('r3', [], 'Bo Reviewer')]]),
            lwrPara([['text' => 'Next.']]),
        ]];
        $xml = lwrPart(lwrRoundTrip($doc)['bytes'], 'word/document.xml');

        expect($xml)->toContain('<w:pPr><w:rPr><w:ins w:id="1" w:author="Ann Editor" w:date="2026-11-20T10:00:00Z"/></w:rPr></w:pPr>')
            ->and($xml)->toContain('<w:rPr><w:del w:id="3" w:author="Bo Reviewer" w:date="2026-11-20T10:00:00Z"/></w:rPr>');
    });

    it('a revision with no date writes no date', function () {
        $doc = ['blocks' => [lwrPara([['text' => 'Undated.', 'revision' => ['insert' => ['id' => 'r1', 'author' => 'Ann']]]])]];
        $xml = lwrPart(lwrRoundTrip($doc)['bytes'], 'word/document.xml');

        expect($xml)->toContain('<w:ins w:id="1" w:author="Ann">')
            ->and($xml)->not->toContain('w:date');
    });
});

// ─── Mixed ───────────────────────────────────────────────────────────────────

describe('round trip, mixed', function () {
    it('a deletion inside an insertion: inserted by one person, deleted by another', function () {
        $ins = lwrBy('r1');
        $doc = ['blocks' => [lwrPara([
            ['text' => 'Kept insert ', 'revision' => ['insert' => $ins]],
            ['text' => 'then deleted', 'revision' => ['insert' => $ins, 'delete' => lwrBy('r2', [], 'Bo Reviewer')]],
            ['text' => ' end', 'revision' => ['insert' => $ins]],
        ])]];
        $xml = lwrPart(lwrRoundTrip($doc)['bytes'], 'word/document.xml');

        expect(substr_count($xml, '<w:ins '))->toBe(1)
            ->and($xml)->toMatch('#<w:ins w:id="1"[^>]*><w:r>.*</w:r><w:del w:id="2" w:author="Bo Reviewer"[^>]*><w:r><w:delText xml:space="preserve">then deleted</w:delText></w:r></w:del><w:r>.*</w:r></w:ins>#U');
    });

    it('every kind in one document, across a list, a table, a quote, a link and a footnote', function () {
        $doc = ['blocks' => [
            ['type' => 'heading', 'level' => 1, 'runs' => [['text' => 'Agreement']], 'revision' => ['format' => lwrBy('r1', ['from' => ['align' => 'center']])]],
            lwrPara([
                ['text' => 'The fee is '],
                ['text' => 'ten', 'revision' => ['delete' => lwrBy('r2')]],
                ['text' => 'twelve', 'revision' => ['insert' => lwrBy('r3')]],
                ['text' => ' pounds', 'italic' => true, 'revision' => ['format' => lwrBy('r4', ['from' => []])]],
                ['text' => '', 'footnote' => ['blocks' => [lwrPara([
                    ['text' => 'Audited '],
                    ['text' => 'FY2026', 'revision' => ['insert' => lwrBy('r6')]],
                ])]], 'revision' => ['insert' => lwrBy('r5')]],
                ['text' => ' (see '],
                ['text' => 'site', 'link' => 'https://example.com/', 'revision' => ['insert' => lwrBy('r7')]],
                ['text' => ').'],
            ]),
            ['type' => 'list', 'items' => [
                ['runs' => [['text' => 'Item one']]],
                ['runs' => [['text' => 'Item two', 'revision' => ['insert' => lwrBy('r9')]]], 'revision' => ['insert' => lwrBy('r8')]],
            ]],
            ['type' => 'table', 'rows' => [['cells' => [
                ['blocks' => [lwrPara([['text' => 'Old cell', 'revision' => ['delete' => lwrBy('r10')]], ['text' => 'New cell', 'revision' => ['insert' => lwrBy('r11')]]])]],
                ['blocks' => [lwrPara([['text' => 'Moved out', 'revision' => ['moveFrom' => lwrBy('r12', ['name' => 'move1'])]]])]],
            ]]]],
            ['type' => 'quote', 'blocks' => [lwrPara([['text' => 'Moved out', 'revision' => ['moveTo' => lwrBy('r13', ['name' => 'move1'])]]])]],
        ]];

        $result = lwrRoundTrip($doc);

        expect($result['read']['trackedChanges'])->toBe([
            'present' => true, 'insertions' => 7, 'deletions' => 2, 'moves' => 1, 'formatChanges' => 2,
        ])->and(Agent::describe($doc))->toContain('Tracked changes: 12 (7 insertions, 2 deletions, 1 move, 2 formatting changes)');
    });
});

// ─── The default read, the refusal, and every other consumer ─────────────────

describe('without preserveTrackedChanges', function () {
    $doc = ['blocks' => [
        lwrPara([
            ['text' => 'A '],
            ['text' => 'B', 'revision' => ['delete' => lwrBy('r1')]],
            ['text' => 'C', 'revision' => ['insert' => lwrBy('r2')]],
            ['text' => 'D', 'revision' => ['moveFrom' => lwrBy('r3', ['name' => 'move1'])]],
        ]),
        lwrPara([['text' => 'D', 'revision' => ['moveTo' => lwrBy('r4', ['name' => 'move1'])]]]),
    ]];

    it('the default read still accepts them: insertions and moves land, deletions go', function () use ($doc) {
        $read = Agent::read(Agent::toBytes($doc));

        expect($read['blocks'])->toBe([
            ['type' => 'paragraph', 'runs' => [['text' => 'A C']]],
            ['type' => 'paragraph', 'runs' => [['text' => 'D']]],
        ])->and($read['trackedChanges'])->toBe(['present' => true, 'insertions' => 1, 'deletions' => 1, 'moves' => 1, 'formatChanges' => 0]);
    });

    it('refuseTrackedChanges still refuses', function () use ($doc) {
        Agent::read(Agent::toBytes($doc), ['refuseTrackedChanges' => true]);
    })->throws(TrackedChangesRefused::class);

    it('refuses both options at once, and a non-boolean', function () {
        $bytes = Agent::toBytes(['blocks' => [lwrPara([['text' => 'x']])]]);
        expect(fn () => Agent::read($bytes, ['preserveTrackedChanges' => true, 'refuseTrackedChanges' => true]))->toThrow(InvalidArgumentException::class, 'preserveTrackedChanges')
            ->and(fn () => Agent::fromBytes($bytes, ['preserveTrackedChanges' => 1]))->toThrow(InvalidArgumentException::class, 'preserveTrackedChanges');
    });

    it('markdown and portable content show the accepted text and report the rest', function () use ($doc) {
        $md = Agent::toMarkdownWithReport($doc);
        $portable = Agent::toPortable($doc);

        expect($md['markdown'])->toContain('A C')->not->toContain('B')
            ->and(json_encode($md['report']))->toContain('tracked-change')
            ->and(json_encode($portable['content']['items']))->toContain('"A "')->toContain('"C"')->not->toContain('"B"')
            ->and(json_encode($portable['content']['report']))->toContain('tracked-change');
    });

    it('a clean document writes the bytes it always did', function () {
        $doc = ['blocks' => [lwrPara([['text' => 'Plain', 'bold' => true]], ['align' => 'center'])]];

        expect(lwrPart(Agent::toBytes($doc), 'word/document.xml'))->not->toContain('Change')->not->toContain('<w:ins');
    });
});

// ─── Validation ──────────────────────────────────────────────────────────────

describe('the rules', function () {
    it('author injection: control, bidi, zero-width and edge-space characters are refused, and repair strips them', function (string $author) {
        $doc = ['blocks' => [lwrPara([['text' => 'x', 'revision' => ['insert' => ['id' => 'r1', 'author' => $author]]]])]];

        expect(lwrCodes($doc))->toBe(['revision_author_invalid']);
        $repaired = Agent::validateAndRepair($doc);
        expect($repaired['ok'])->toBeTrue()
            ->and($repaired['schema']['blocks'][0]['runs'][0]['revision']['insert']['author'])->toBe('Ann');
    })->with([
        'right-to-left override' => ["Ann\u{202E}"],
        'zero-width space' => ["A\u{200B}nn"],
        'bell' => ["Ann\x07"],
        'newline' => ["Ann\n"],
        'line separator' => ["Ann\u{2028}"],
        'trailing no-break space' => ["Ann\u{00A0}"],
        'leading ideographic space' => ["\u{3000}Ann"],
        'byte-order mark' => ["\u{FEFF}Ann"],
    ]);

    it('an author made of XML is fine, and escaped', function () {
        $doc = ['blocks' => [lwrPara([['text' => 'x', 'revision' => ['insert' => ['id' => 'r1', 'author' => 'A"/><w:b/>&<']]]])]];
        $xml = lwrPart(lwrRoundTrip($doc)['bytes'], 'word/document.xml');

        expect($xml)->toContain('w:author="A&quot;/&gt;&lt;w:b/&gt;&amp;&lt;"');
    });

    it('refuses a bad author, date, id, name or from, each by code', function (array $rev, string $code) {
        $doc = ['blocks' => [lwrPara([['text' => 'x', 'revision' => $rev]])]];

        expect(lwrCodes($doc))->toContain($code);
    })->with([
        'no author' => [['insert' => ['id' => 'r1']], 'revision_author_invalid'],
        'author too long' => [['insert' => ['id' => 'r1', 'author' => str_repeat('a', LWR_MAX_AUTHOR + 1)]], 'revision_author_invalid'],
        'impossible date' => [['insert' => lwrBy('r1', ['date' => '2026-13-01T00:00:00Z'])], 'revision_date_invalid'],
        'date with markup' => [['insert' => lwrBy('r1', ['date' => '2026-11-21T10:00:00Z"><w:b/>'])], 'revision_date_invalid'],
        'id with a space' => [['insert' => lwrBy('r 1')], 'revision_id_invalid'],
        'id too long' => [['insert' => lwrBy(str_repeat('r', 65))], 'revision_id_invalid'],
        'move name with markup' => [['moveTo' => lwrBy('r1', ['name' => 'm"/>'])], 'revision_name_invalid'],
        'from with a link' => [['format' => lwrBy('r1', ['from' => ['link' => 'https://x']])], 'revision_from_invalid'],
        'from that is not an object' => [['format' => lwrBy('r1', ['from' => 'bold'])], 'revision_from_invalid'],
        'unknown kind' => [['comment' => lwrBy('r1')], 'revision_invalid'],
        'unknown entry key' => [['insert' => lwrBy('r1', ['by' => 'x'])], 'revision_invalid'],
        'inserted and moved here' => [['insert' => lwrBy('r1'), 'moveTo' => lwrBy('r2', ['name' => 'm'])], 'revision_combination_invalid'],
        'deleted and moved away' => [['delete' => lwrBy('r1'), 'moveFrom' => lwrBy('r2', ['name' => 'm'])], 'revision_combination_invalid'],
    ]);

    it('refuses a revision on an empty run or paragraph', function () {
        expect(lwrCodes(['blocks' => [lwrPara([['text' => '', 'revision' => ['insert' => lwrBy('r1')]]])]]))->toBe(['revision_text_empty'])
            ->and(lwrCodes(['blocks' => [lwrPara([], ['revision' => ['insert' => lwrBy('r1')]])]]))->toBe(['revision_text_empty']);
    });

    it('one id is one revision: consecutive runs of one paragraph, one link', function () {
        $r = ['insert' => lwrBy('r1')];
        $gap = ['blocks' => [lwrPara([['text' => 'a', 'revision' => $r], ['text' => 'b'], ['text' => 'c', 'revision' => $r]])]];
        $twoParagraphs = ['blocks' => [lwrPara([['text' => 'a', 'revision' => $r]]), lwrPara([['text' => 'b', 'revision' => $r]])]];
        $linkChange = ['blocks' => [lwrPara([['text' => 'a', 'revision' => $r], ['text' => 'b', 'link' => 'https://x', 'revision' => $r]])]];
        $otherKind = ['blocks' => [lwrPara([['text' => 'a', 'revision' => $r], ['text' => 'b', 'revision' => ['delete' => lwrBy('r1')]]])]];
        $otherAuthor = ['blocks' => [lwrPara([['text' => 'a', 'revision' => $r], ['text' => 'b', 'revision' => ['insert' => lwrBy('r1', [], 'Bo')]]])]];
        $paragraphToo = ['blocks' => [lwrPara([['text' => 'a', 'revision' => $r]], ['revision' => $r])]];

        expect(lwrCodes($gap))->toBe(['revision_range_broken'])
            ->and(lwrCodes($twoParagraphs))->toBe(['revision_range_broken'])
            ->and(lwrCodes($linkChange))->toBe(['revision_range_broken'])
            ->and(lwrCodes($otherKind))->toBe(['revision_id_duplicate'])
            ->and(lwrCodes($otherAuthor))->toBe(['revision_id_duplicate'])
            ->and(lwrCodes($paragraphToo))->toBe(['revision_id_duplicate']);
    });

    it('a deletion inside an insertion stays inside it', function () {
        // The deletion starts outside the insertion and runs into it: no XML nests that way.
        $doc = ['blocks' => [lwrPara([
            ['text' => 'a', 'revision' => ['delete' => lwrBy('r2')]],
            ['text' => 'b', 'revision' => ['insert' => lwrBy('r1'), 'delete' => lwrBy('r2')]],
        ])]];

        expect(lwrCodes($doc))->toBe(['revision_range_broken']);
    });

    it('a move without its pair is refused, and so is a broken one', function () {
        $alone = ['blocks' => [lwrPara([['text' => 'a', 'revision' => ['moveFrom' => lwrBy('r1', ['name' => 'm'])]]])]];
        $broken = ['blocks' => [
            lwrPara([['text' => 'a', 'revision' => ['moveFrom' => lwrBy('r1', ['name' => 'm'])]]]),
            lwrPara([['text' => 'between']]),
            lwrPara([['text' => 'b', 'revision' => ['moveFrom' => lwrBy('r2', ['name' => 'm'])]]]),
            lwrPara([['text' => 'ab', 'revision' => ['moveTo' => lwrBy('r3', ['name' => 'm'])]]]),
        ]];

        expect(lwrCodes($alone))->toBe(['revision_move_unpaired'])
            ->and(lwrCodes($broken))->toBe(['revision_range_broken']);
    });

    it('decides where: footnotes yes, a move in a footnote no, headers and footers no', function () {
        $note = static fn (array $rev): array => ['blocks' => [lwrPara([['text' => 'x'], ['text' => '', 'footnote' => ['blocks' => [lwrPara([['text' => 'n', 'revision' => $rev]])]]]])]];
        $band = ['blocks' => [lwrPara([['text' => 'x']])], 'page' => ['header' => ['blocks' => [lwrPara([['text' => 'Draft', 'revision' => ['delete' => lwrBy('r1')]]])]]]];

        expect(lwrCodes($note(['insert' => lwrBy('r1')])))->toBe([])
            ->and(lwrCodes($note(['moveFrom' => lwrBy('r1', ['name' => 'm'])])))->toContain('revision_move_outside_body')
            ->and(lwrCodes($band))->toBe(['revision_in_band']);
    });

    it('caps the number of revisions', function () {
        $runs = [];
        for ($i = 1; $i <= LWR_MAX_REVISIONS + 1; $i++) {
            $runs[] = ['text' => 'w ', 'revision' => ['insert' => ['id' => "r{$i}", 'author' => 'A']]];
        }

        expect(lwrCodes(['blocks' => [lwrPara($runs)]]))->toBe(['revision_count_exceeded']);
    });

    it('repair gives a missing id one, drops a bad date, and never drops a deletion', function () {
        $doc = ['blocks' => [lwrPara([
            ['text' => 'a', 'revision' => ['insert' => ['author' => 'Ann', 'date' => 'yesterday']]],
            ['text' => 'b', 'revision' => ['delete' => ['id' => 'r9']]],
        ])]];
        $r = Agent::validateAndRepair($doc);

        expect($r['schema']['blocks'][0]['runs'][0]['revision']['insert'])->toBe(['id' => 'r1', 'author' => 'Ann'])
            ->and($r['schema']['blocks'][0]['runs'][1]['revision'])->toHaveKey('delete')
            ->and($r['ok'])->toBeFalse(); // the deletion has no author: still an error
    });
});

// ─── Reading what other producers write, and hostile files ──────────────────

describe('reading hostile and malformed revision markup', function () {
    $by = ' w:author="Ann" w:date="2026-11-20T10:00:00Z"';

    it('reads Word\'s own markup, which this writer never emits verbatim', function () use ($by) {
        $read = lwrRead(lwrDocx(
            '<w:p><w:r><w:t xml:space="preserve">The fee is </w:t></w:r>'
            . '<w:del w:id="17"' . $by . '><w:r><w:delText>ten</w:delText></w:r></w:del>'
            . '<w:ins w:id="18"' . $by . '><w:r><w:t>twelve</w:t></w:r></w:ins></w:p>'
        ));

        expect($read['blocks'][0]['runs'])->toBe([
            ['text' => 'The fee is '],
            ['text' => 'ten', 'revision' => ['delete' => ['id' => 'r1', 'author' => 'Ann', 'date' => '2026-11-20T10:00:00Z']]],
            ['text' => 'twelve', 'revision' => ['insert' => ['id' => 'r2', 'author' => 'Ann', 'date' => '2026-11-20T10:00:00Z']]],
        ]);
    });

    it('a Word file read with its revisions writes them back, and reads back the same', function () use ($by) {
        $read = lwrRead(lwrDocx(
            '<w:p><w:r><w:t xml:space="preserve">The fee is </w:t></w:r>'
            . '<w:del w:id="1"' . $by . '><w:r><w:delText>ten</w:delText></w:r></w:del>'
            . '<w:ins w:id="2"' . $by . '><w:r><w:t>twelve</w:t></w:r></w:ins></w:p>'
            . '<w:p><w:pPr><w:rPr><w:ins w:id="3"' . $by . '/></w:rPr></w:pPr><w:ins w:id="4"' . $by . '><w:r><w:t>Added clause.</w:t></w:r></w:ins></w:p>'
            . '<w:p><w:moveFromRangeStart w:id="5" w:name="move1"' . $by . '/><w:moveFrom w:id="6"' . $by . '><w:r><w:t>Moved.</w:t></w:r></w:moveFrom><w:moveFromRangeEnd w:id="5"/></w:p>'
            . '<w:p><w:moveToRangeStart w:id="7" w:name="move1"' . $by . '/><w:moveTo w:id="8"' . $by . '><w:r><w:t>Moved.</w:t></w:r></w:moveTo><w:moveToRangeEnd w:id="7"/></w:p>'
            . '<w:p><w:pPr><w:jc w:val="center"/><w:pPrChange w:id="9"' . $by . '><w:pPr/></w:pPrChange></w:pPr>'
            . '<w:r><w:rPr><w:b/><w:rPrChange w:id="10"' . $by . '><w:rPr/></w:rPrChange></w:rPr><w:t>Bold now.</w:t></w:r></w:p>'
        ));
        expect($read)->not->toHaveKey('unrepresented')
            ->and(Agent::validate($read))->toBe([]);
        unset($read['trackedChanges']);

        lwrRoundTrip($read);
    });

    it('cleans a hostile author and drops a hostile date', function () {
        $read = lwrRead(lwrDocx(
            '<w:p><w:ins w:id="1" w:author="&#x202E;nnA&#x200B; " w:date="2026-11-20T10:00:00Z&quot;"><w:r><w:t>x</w:t></w:r></w:ins>'
            . '<w:ins w:id="2" w:author="&#x200B;"><w:r><w:t>y</w:t></w:r></w:ins></w:p>'
        ));

        expect($read['blocks'][0]['runs'][0]['revision']['insert'])->toBe(['id' => 'r1', 'author' => 'nnA'])
            ->and($read['blocks'][0]['runs'][1]['revision']['insert'])->toBe(['id' => 'r2', 'author' => 'Unknown'])
            ->and(Agent::validate($read))->toBe([]);
    });

    it('nested insertions keep the outer one and every character', function () use ($by) {
        $read = lwrRead(lwrDocx('<w:p><w:ins w:id="1"' . $by . '><w:r><w:t>a</w:t></w:r><w:ins w:id="2" w:author="Bo"><w:r><w:t>b</w:t></w:r></w:ins></w:ins></w:p>'));

        expect(implode('', array_column($read['blocks'][0]['runs'], 'text')))->toBe('ab')
            ->and($read['blocks'][0]['runs'][0]['revision']['insert']['author'])->toBe('Ann')
            ->and(lwrUnkept($read))->toBe(1)
            ->and(Agent::validate($read))->toBe([]);
    });

    it('an insertion inside a deletion reads as both, in either nesting', function () use ($by) {
        $a = lwrRead(lwrDocx('<w:p><w:ins w:id="1"' . $by . '><w:del w:id="2" w:author="Bo"><w:r><w:delText>x</w:delText></w:r></w:del></w:ins></w:p>'));
        $b = lwrRead(lwrDocx('<w:p><w:del w:id="2" w:author="Bo"><w:ins w:id="1"' . $by . '><w:r><w:delText>x</w:delText></w:r></w:ins></w:del></w:p>'));

        expect(array_keys($a['blocks'][0]['runs'][0]['revision'] ?? []))->toBe(['insert', 'delete'])
            ->and(array_keys($b['blocks'][0]['runs'][0]['revision'] ?? []))->toBe(['insert', 'delete']);
    });

    it('a move without its pair keeps its text, as a deletion or an insertion', function () use ($by) {
        $from = lwrRead(lwrDocx('<w:p><w:moveFromRangeStart w:id="1" w:name="m"' . $by . '/><w:moveFrom w:id="2"' . $by . '><w:r><w:t>gone?</w:t></w:r></w:moveFrom><w:moveFromRangeEnd w:id="1"/></w:p>'));
        $to = lwrRead(lwrDocx('<w:p><w:moveTo w:id="2"' . $by . '><w:r><w:t>arrived</w:t></w:r></w:moveTo></w:p>'));

        expect($from['blocks'][0]['runs'][0])->toBe(['text' => 'gone?', 'revision' => ['delete' => ['id' => 'r1', 'author' => 'Ann', 'date' => '2026-11-20T10:00:00Z']]])
            ->and($to['blocks'][0]['runs'][0])->toBe(['text' => 'arrived', 'revision' => ['insert' => ['id' => 'r1', 'author' => 'Ann', 'date' => '2026-11-20T10:00:00Z']]])
            ->and(lwrUnkept($from))->toBe(1)
            ->and(lwrUnkept($to))->toBe(1);
    });

    it('an unbalanced or overlapping move range truncates nothing after it', function () use ($by) {
        $read = lwrRead(lwrDocx(
            '<w:p><w:moveFromRangeStart w:id="1" w:name="a"' . $by . '/><w:moveFromRangeStart w:id="2" w:name="b"' . $by . '/>'
            . '<w:moveFrom w:id="3"' . $by . '><w:r><w:t>one</w:t></w:r></w:moveFrom><w:moveFromRangeEnd w:id="1"/></w:p>'
            . '<w:p><w:r><w:t>two</w:t></w:r></w:p>'
            . '<w:p><w:moveToRangeStart w:id="4" w:name="a"' . $by . '/><w:moveTo w:id="5"' . $by . '><w:r><w:t>one</w:t></w:r></w:moveTo></w:p>'
            . '<w:p><w:r><w:t>three</w:t></w:r></w:p>'
        ));
        $text = implode('|', array_map(static fn (array $b): string => implode('', array_column($b['runs'], 'text')), $read['blocks']));

        expect($text)->toBe('one|two|one|three')
            ->and(Agent::validate($read))->toBe([]);
    });

    it('a deleted picture and a deleted page break are not made live', function () use ($by) {
        $read = lwrRead(lwrDocx('<w:p><w:r><w:t>Before</w:t></w:r><w:del w:id="1"' . $by . '><w:r><w:br w:type="page"/></w:r></w:del></w:p><w:p><w:r><w:t>After</w:t></w:r></w:p>'));

        expect(array_column($read['blocks'], 'type'))->toBe(['paragraph', 'paragraph'])
            ->and(lwrUnkept($read))->toBe(1);
    });

    it('a revision in a code block or the title is accepted there, and reported', function () use ($by) {
        $read = lwrRead(lwrDocx('<w:p><w:pPr><w:pStyle w:val="CodeBlock"/></w:pPr><w:r><w:t>let x</w:t></w:r><w:del w:id="1"' . $by . '><w:r><w:delText> = 1</w:delText></w:r></w:del><w:ins w:id="2"' . $by . '><w:r><w:t> = 2</w:t></w:r></w:ins></w:p>'));

        expect($read['blocks'][0])->toBe(['type' => 'code', 'text' => 'let x = 2'])
            ->and(lwrUnkept($read))->toBe(2);
    });

    it('revisions it cannot carry (table rows, cells, sections) are reported', function () use ($by) {
        $read = lwrRead(lwrDocx('<w:tbl><w:tr><w:trPr><w:ins w:id="1"' . $by . '/></w:trPr><w:tc><w:tcPr><w:cellIns w:id="2"' . $by . '/></w:tcPr><w:p><w:r><w:t>A</w:t></w:r></w:p></w:tc></w:tr></w:tbl>'));

        expect(lwrUnkept($read))->toBe(2);
    });

    it('a header or footer is read as the default read reads it, and reported', function () use ($by) {
        $header = '<?xml version="1.0" encoding="UTF-8"?><w:hdr ' . LWR_W . '><w:p><w:r><w:t>Kept </w:t></w:r><w:del w:id="1"' . $by . '><w:r><w:delText>Draft</w:delText></w:r></w:del></w:p></w:hdr>';
        $bytes = lwrDocx(
            '<w:p><w:r><w:t>Body</w:t></w:r></w:p>',
            null,
            ['word/header1.xml' => $header],
            '<Relationship Id="rIdH" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/header" Target="header1.xml"/>',
            '<w:headerReference w:type="default" r:id="rIdH"/>',
        );
        $preserved = lwrRead($bytes);
        $default = Agent::read($bytes);

        expect($preserved['page'] ?? null)->toBe($default['page'] ?? null)
            ->and(lwrUnkept($preserved, '/page'))->toBe(1)
            ->and(json_encode($preserved['page'] ?? []))->not->toContain('Draft');
    });

    it('a footnote\'s revisions are read, its moves become insertions and deletions', function () use ($by) {
        $notes = '<w:footnote w:id="1"><w:p><w:r><w:t>Note </w:t></w:r><w:ins w:id="5"' . $by . '><w:r><w:t>added</w:t></w:r></w:ins>'
            . '<w:moveFromRangeStart w:id="6" w:name="fm"' . $by . '/><w:moveFrom w:id="7"' . $by . '><w:r><w:t> moved</w:t></w:r></w:moveFrom><w:moveFromRangeEnd w:id="6"/></w:p></w:footnote>';
        $read = lwrRead(lwrDocx('<w:p><w:r><w:t>Body</w:t></w:r><w:r><w:footnoteReference w:id="1"/></w:r></w:p>', $notes));
        $note = $read['blocks'][0]['runs'][1]['footnote']['blocks'][0]['runs'];

        expect(array_keys($note[1]['revision'] ?? []))->toBe(['insert'])
            ->and(array_keys($note[2]['revision'] ?? []))->toBe(['delete'])
            ->and(Agent::validate($read))->toBe([]);
    });

    it('caps revisions read: past the cap they are accepted', function () {
        $body = '<w:p>';
        for ($i = 1; $i <= LWR_MAX_REVISIONS + 2; $i++) {
            $body .= '<w:ins w:id="' . $i . '" w:author="A"><w:r><w:t>w</w:t></w:r></w:ins>';
        }
        $read = lwrRead(lwrDocx($body . '</w:p>'));
        $runs = $read['blocks'][0]['runs'];

        expect(count(array_filter($runs, static fn (array $r): bool => isset($r['revision']))))->toBe(LWR_MAX_REVISIONS)
            ->and(strlen(implode('', array_column($runs, 'text'))))->toBe(LWR_MAX_REVISIONS + 2)
            ->and(lwrUnkept($read))->toBe(2)
            ->and(Agent::validate($read))->toBe([]);
    });
});

// ─── Quadratic blowups ───────────────────────────────────────────────────────

it('stays linear: many revisions in one paragraph, and a long chain of moves', function () {
    $runs = [];
    for ($i = 1; $i <= 4000; $i++) {
        $runs[] = ['text' => "w{$i} ", 'revision' => [$i % 2 === 0 ? 'insert' : 'delete' => ['id' => "r{$i}", 'author' => 'A']]];
    }
    $chain = [];
    for ($m = 1; $m <= 1500; $m++) {
        $chain[] = lwrPara([['text' => "from{$m}", 'revision' => ['moveFrom' => ['id' => 'f' . $m, 'author' => 'A', 'name' => 'm' . $m]]]]);
        $chain[] = lwrPara([['text' => "from{$m}", 'revision' => ['moveTo' => ['id' => 't' . $m, 'author' => 'A', 'name' => 'm' . $m]]]]);
    }
    $doc = ['blocks' => [lwrPara($runs), ...$chain]];

    $started = hrtime(true);
    expect(Agent::validate($doc))->toBe([]);
    $bytes = Agent::toBytes($doc);
    $read = lwrRead($bytes);
    $seconds = (hrtime(true) - $started) / 1e9;

    expect(count($read['blocks']))->toBe(3001)
        ->and($read)->not->toHaveKey('unrepresented')
        ->and($seconds)->toBeLessThan(20.0);
});
