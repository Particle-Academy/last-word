<?php

declare(strict_types=1);

use LastWord\Agent;
use LastWord\Exceptions\TrackedChangesRefused;

/*
 * Reading a .docx flattens revision markup: an insertion's text is kept, a
 * deletion's is dropped, a formatting change keeps the new formatting. An agent
 * that reads, edits and writes back would erase a person's pending tracked
 * changes without being told. The read result says they were there
 * (`trackedChanges`), and `refuseTrackedChanges` makes the read fail instead.
 */

/** @param array<string, string> $parts extra parts, by archive name */
function lwTrackedDocx(string $body, array $parts = []): string
{
    $tmp = lwTemp('lw-tracked', '.docx');
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
        . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
        . '<w:body>' . $body . '<w:sectPr><w:pgSz w:w="12240" w:h="15840"/></w:sectPr></w:body>'
        . '</w:document>');
    foreach ($parts as $name => $xml) {
        $zip->addFromString($name, $xml);
    }
    $zip->close();

    return (string) file_get_contents($tmp);
}

/** Two insertions, one deletion, one move and two formatting changes, as Word writes them. */
function lwRevisedBody(): string
{
    $by = ' w:author="Ann Editor" w:date="2026-11-20T10:00:00Z"';

    return
        // Insertion and deletion in one paragraph.
        '<w:p><w:r><w:t xml:space="preserve">The fee is </w:t></w:r>'
        . '<w:del w:id="1"' . $by . '><w:r><w:delText>ten</w:delText></w:r></w:del>'
        . '<w:ins w:id="2"' . $by . '><w:r><w:t>twelve</w:t></w:r></w:ins>'
        . '<w:r><w:t xml:space="preserve"> pounds.</w:t></w:r></w:p>'
        // A whole inserted paragraph: its mark carries an insertion too.
        . '<w:p><w:pPr><w:rPr><w:ins w:id="3"' . $by . '/></w:rPr></w:pPr>'
        . '<w:ins w:id="4"' . $by . '><w:r><w:t>Added clause.</w:t></w:r></w:ins></w:p>'
        // A move: one passage, its source and destination tied by name.
        . '<w:p><w:moveFromRangeStart w:id="5" w:name="move1"' . $by . '/>'
        . '<w:moveFrom w:id="6"' . $by . '><w:r><w:t>Moved sentence.</w:t></w:r></w:moveFrom>'
        . '<w:moveFromRangeEnd w:id="5"/></w:p>'
        . '<w:p><w:moveToRangeStart w:id="7" w:name="move1"' . $by . '/>'
        . '<w:moveTo w:id="8"' . $by . '><w:r><w:t>Moved sentence.</w:t></w:r></w:moveTo>'
        . '<w:moveToRangeEnd w:id="7"/></w:p>'
        // Formatting changes: a run made bold, a paragraph centred.
        . '<w:p><w:pPr><w:jc w:val="center"/><w:pPrChange w:id="9"' . $by . '><w:pPr/></w:pPrChange></w:pPr>'
        . '<w:r><w:rPr><w:b/><w:rPrChange w:id="10"' . $by . '><w:rPr/></w:rPrChange></w:rPr><w:t>Bold now.</w:t></w:r></w:p>';
}

it('reports the tracked changes a .docx holds, by kind', function () {
    $doc = Agent::read(lwTrackedDocx(lwRevisedBody()));

    expect($doc['trackedChanges'] ?? null)->toBe([
        'present' => true,
        'insertions' => 3,
        'deletions' => 1,
        'moves' => 1,
        'formatChanges' => 2,
    ]);
});

it('still accepts the revisions by default, as before', function () {
    $doc = Agent::read(lwTrackedDocx(lwRevisedBody()));

    expect($doc['blocks'][0]['runs'][0]['text'] ?? null)->toBe('The fee is twelve pounds.');
});

it('reads a moved passage once, at its destination, as accepting the move does', function () {
    $doc = Agent::read(lwTrackedDocx(lwRevisedBody()));
    $texts = array_map(static fn (array $b): string => implode('', array_column($b['runs'] ?? [], 'text')), $doc['blocks']);

    expect(array_count_values($texts)['Moved sentence.'] ?? 0)->toBe(1)
        ->and($texts)->toBe(['The fee is twelve pounds.', 'Added clause.', 'Moved sentence.', 'Bold now.']);
});

it('reads a moved passage once when its source sits in a smart tag or a content control', function (string $source) {
    $to = '<w:p><w:moveToRangeStart w:id="7" w:name="m" w:author="Ann"/><w:moveTo w:id="8" w:author="Ann"><w:r><w:t>Moved.</w:t></w:r></w:moveTo><w:moveToRangeEnd w:id="7"/></w:p>';
    $doc = Agent::read(lwTrackedDocx('<w:p>' . $source . '<w:r><w:t xml:space="preserve"> Stays.</w:t></w:r></w:p>' . $to));
    $texts = array_map(static fn (array $b): string => implode('', array_column($b['runs'] ?? [], 'text')), $doc['blocks']);

    expect($texts)->toBe([' Stays.', 'Moved.']);
})->with([
    'smart tag' => ['<w:smartTag w:uri="urn:x" w:element="place"><w:moveFromRangeStart w:id="1" w:name="m" w:author="Ann"/><w:moveFrom w:id="2" w:author="Ann"><w:r><w:t>Moved.</w:t></w:r></w:moveFrom><w:moveFromRangeEnd w:id="1"/></w:smartTag>'],
    'content control' => ['<w:sdt><w:sdtContent><w:moveFrom w:id="2" w:author="Ann"><w:r><w:t>Moved.</w:t></w:r></w:moveFrom></w:sdtContent></w:sdt>'],
]);

it('counts revisions outside the body, in a header part', function () {
    $header = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<w:hdr xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
        . '<w:p><w:del w:id="1" w:author="Ann"><w:r><w:delText>Draft</w:delText></w:r></w:del></w:p>'
        . '</w:hdr>';
    $doc = Agent::read(lwTrackedDocx('<w:p><w:r><w:t>Clean body.</w:t></w:r></w:p>', ['word/header1.xml' => $header]));

    expect($doc['trackedChanges'] ?? null)->toBe([
        'present' => true,
        'insertions' => 0,
        'deletions' => 1,
        'moves' => 0,
        'formatChanges' => 0,
    ]);
});

it('counts table cell and row revisions', function () {
    $by = ' w:author="Ann"';
    $body = '<w:tbl><w:tr><w:trPr><w:ins w:id="1"' . $by . '/></w:trPr>'
        . '<w:tc><w:tcPr><w:cellDel w:id="2"' . $by . '/></w:tcPr><w:p><w:r><w:t>A</w:t></w:r></w:p></w:tc>'
        . '</w:tr></w:tbl>';
    $doc = Agent::read(lwTrackedDocx($body));

    expect($doc['trackedChanges'] ?? null)->toMatchArray(['present' => true, 'insertions' => 1, 'deletions' => 1]);
});

it('reports nothing for a document with no revisions', function () {
    $written = Agent::read(Agent::toBytes([
        'title' => 'Clean',
        'blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'Nothing pending.']]]],
    ]));
    $handBuilt = Agent::read(lwTrackedDocx('<w:p><w:r><w:t>Plain.</w:t></w:r></w:p>'));

    expect($written)->not->toHaveKey('trackedChanges')
        ->and($handBuilt)->not->toHaveKey('trackedChanges');
});

it('does not count the track-revisions SETTING as a revision', function () {
    $settings = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<w:settings xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:trackRevisions/></w:settings>';
    $doc = Agent::read(lwTrackedDocx('<w:p><w:r><w:t>Plain.</w:t></w:r></w:p>', ['word/settings.xml' => $settings]));

    expect($doc)->not->toHaveKey('trackedChanges');
});

it('fails loudly with refuseTrackedChanges, naming the counts', function () {
    $bytes = lwTrackedDocx(lwRevisedBody());

    try {
        Agent::read($bytes, ['refuseTrackedChanges' => true]);
        $this->fail('expected TrackedChangesRefused');
    } catch (TrackedChangesRefused $e) {
        expect($e->reason)->toBe('tracked_changes_present')
            ->and($e->insertions)->toBe(3)
            ->and($e->deletions)->toBe(1)
            ->and($e->moves)->toBe(1)
            ->and($e->formatChanges)->toBe(2)
            ->and($e->getMessage())->toContain('3 insertions')
            ->toContain('1 deletion')
            ->toContain('1 move')
            ->toContain('2 formatting changes')
            ->not->toContain('twelve');
    }
});

it('fromBytes takes the same option', function () {
    Agent::fromBytes(lwTrackedDocx(lwRevisedBody()), ['refuseTrackedChanges' => true]);
})->throws(TrackedChangesRefused::class);

it('reads a clean document normally with refuseTrackedChanges', function () {
    $doc = Agent::read(lwTrackedDocx('<w:p><w:r><w:t>Plain.</w:t></w:r></w:p>'), ['refuseTrackedChanges' => true]);

    expect($doc['blocks'][0]['runs'][0]['text'])->toBe('Plain.')
        ->and($doc)->not->toHaveKey('trackedChanges');
});

it('refuses a refuseTrackedChanges that is not a boolean', function () {
    Agent::read(lwTrackedDocx('<w:p/>'), ['refuseTrackedChanges' => 'yes']);
})->throws(InvalidArgumentException::class, 'refuseTrackedChanges');
