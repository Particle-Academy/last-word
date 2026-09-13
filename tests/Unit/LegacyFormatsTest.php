<?php

declare(strict_types=1);

use LastWord\Agent;
use LastWord\Exceptions\UnsupportedFormatException;
use LastWord\Tests\Support\LegacyFiles;

/**
 * `.doc`, `.odt` and `.rtf` read as the SAME document a `.docx` does (last-word#1).
 *
 * The caller's need is not "some text came out". Their uploads arrive in all four
 * formats and an agent analyses them through one code path, so the question is
 * whether the same document gives the same answer whichever format it came in.
 *
 * `tests/fixtures/formats/` holds one document in four formats: `report.docx`
 * written by this package from `source.json`, and LibreOffice's conversions of it
 * (see the README there). Each reader is held to the docx read of that file, as a
 * whole, with `toBe`: text, structure, formatting, links, lists and tables. Where a
 * format cannot carry something, the test removes exactly that from the
 * expectation and says why, rather than loosening the comparison.
 */
function lfFixture(string $extension): string
{
    return (string) file_get_contents(__DIR__.'/../fixtures/formats/report.'.$extension);
}

function lfSource(): array
{
    return json_decode((string) file_get_contents(__DIR__.'/../fixtures/formats/source.json'), true, 512, JSON_THROW_ON_ERROR);
}

/** @return list<string> every run's text in document order, through lists and tables */
function lfTexts(array $blocks): array
{
    $out = [];
    $items = static function (array $list) use (&$items): array {
        $texts = [];
        foreach ($list as $item) {
            array_push($texts, ...array_column($item['runs'], 'text'), ...$items($item['children'] ?? []));
        }

        return $texts;
    };
    foreach ($blocks as $block) {
        if (isset($block['runs'])) {
            array_push($out, ...array_column($block['runs'], 'text'));
        } elseif ($block['type'] === 'list') {
            array_push($out, ...$items($block['items']));
        } elseif ($block['type'] === 'table') {
            foreach ($block['rows'] as $row) {
                foreach ($row['cells'] as $cell) {
                    array_push($out, ...lfTexts($cell['blocks']));
                }
            }
        }
    }

    return $out;
}

function lfThrown(callable $read): Throwable
{
    try {
        $read();
    } catch (Throwable $e) {
        return $e;
    }

    throw new RuntimeException('expected the read to throw, and it returned');
}

describe('one document, four formats, one answer', function () {
    it('reads the docx as the source document, so agreeing with it means something', function () {
        // The anchor for everything below. Were the docx read empty or flattened,
        // three formats could "match" it and prove nothing.
        $expected = lwNormalizeDoc(lfSource());
        // The writer bolds a header row's text directly, and a reader reports it.
        foreach ($expected['blocks'][8]['rows'][0]['cells'] as $c => $cell) {
            $expected['blocks'][8]['rows'][0]['cells'][$c]['blocks'][0]['runs'][0]['bold'] = true;
        }

        expect(lwNormalizeDoc(Agent::read(lfFixture('docx'))))->toEqual($expected);
    });

    it('reads the docx exactly as report.read.json, which the Node and Python ports also assert', function () {
        // One committed answer for three runtimes: each port holds its .doc,
        // .odt and .rtf reads to this same file, so they cannot agree with their
        // own docx reader while disagreeing with this one.
        $expected = json_decode((string) file_get_contents(__DIR__.'/../fixtures/formats/report.read.json'), true, 512, JSON_THROW_ON_ERROR);

        expect(Agent::read(lfFixture('docx')))->toBe($expected);
    });

    it('reads the legacy .doc as exactly the docx', function () {
        expect(Agent::read(lfFixture('doc')))->toBe(Agent::read(lfFixture('docx')));
    });

    it('reads the .odt as exactly the docx', function () {
        expect(Agent::read(lfFixture('odt')))->toBe(Agent::read(lfFixture('docx')));
    });

    it('reads the .rtf as the docx, less the header-row flag the file does not carry', function () {
        // RTF marks a repeating header row with \trhdr. LibreOffice does not write
        // it, so there is nothing in this file to recover; everything else must match.
        expect(lfFixture('rtf'))->not->toContain('\trhdr');

        $expected = Agent::read(lfFixture('docx'));
        unset($expected['blocks'][8]['rows'][0]['header']);

        expect(Agent::read(lfFixture('rtf')))->toBe($expected);
    });

    it('recovers the text that is hardest to get right in every format', function () {
        foreach (['doc', 'odt', 'rtf'] as $format) {
            $text = implode('', lfTexts(Agent::read(lfFixture($format))['blocks']));

            expect($text)
                ->toContain('Café, naïve, jalapeño — 日本語のテキスト and an emoji 🎉 in one line.')
                ->toContain('São Paulo')
                ->toContain('−4.2%')
                ->not->toContain('HYPERLINK')
                ->not->toContain('Hyperlink')
                ->not->toContain('Times New Roman');
        }
    });

    it('dispatches on the bytes, not the file name', function () {
        $path = sys_get_temp_dir().'/lw-legacy-'.bin2hex(random_bytes(4)).'.docx';
        file_put_contents($path, lfFixture('doc'));

        try {
            expect(Agent::read($path))->toBe(Agent::read(lfFixture('docx')));
        } finally {
            @unlink($path);
        }
    });
});

describe('a format it still cannot read is refused by name', function () {
    it('names an .xls, which is a compound file but not a Word document', function () {
        $e = lfThrown(fn () => Agent::read(LegacyFiles::cfb(['Workbook' => 'cells'])));

        expect($e)->toBeInstanceOf(UnsupportedFormatException::class);
        expect($e->format())->toBe('xls');
        expect($e->getMessage())->toContain('Excel');
    });

    it('names a compound file it does not recognise at all', function () {
        $e = lfThrown(fn () => Agent::read(LegacyFiles::cfb(['Contents' => 'something'])));

        expect($e)->toBeInstanceOf(UnsupportedFormatException::class);
        expect($e->format())->toBe('cfb');
    });

    it('names a Word 95 file, which predates the binary format it reads', function () {
        $e = lfThrown(fn () => Agent::read(LegacyFiles::cfb(LegacyFiles::word("Old\r", ['nFib' => 0x0065]))));

        expect($e)->toBeInstanceOf(UnsupportedFormatException::class);
        expect($e->format())->toBe('doc');
        expect($e->getMessage())->toContain('Word 95');
    });

    it('names an encrypted .doc rather than reading ciphertext as text', function () {
        $e = lfThrown(fn () => Agent::read(LegacyFiles::cfb(LegacyFiles::word("Secret\r", ['flags' => 0x0100]))));

        expect($e)->toBeInstanceOf(UnsupportedFormatException::class);
        expect($e->format())->toBe('doc');
        expect($e->getMessage())->toContain('password');
    });

    it('names an Office zip that is not a word-processing document', function (array $entries, string $format) {
        $e = lfThrown(fn () => Agent::read(LegacyFiles::zip($entries)));

        expect($e)->toBeInstanceOf(UnsupportedFormatException::class);
        expect($e->format())->toBe($format);
    })->with([
        'xlsx' => [['[Content_Types].xml' => '<Types/>', 'xl/workbook.xml' => '<workbook/>'], 'xlsx'],
        'pptx' => [['[Content_Types].xml' => '<Types/>', 'ppt/presentation.xml' => '<presentation/>'], 'pptx'],
        'ods' => [['mimetype' => 'application/vnd.oasis.opendocument.spreadsheet', 'content.xml' => '<x/>'], 'ods'],
    ]);

    it('refuses bytes that are no document with the same exception type', function () {
        $e = lfThrown(fn () => Agent::read("just some text\n"));

        expect($e)->toBeInstanceOf(UnsupportedFormatException::class);
        expect($e->format())->toBe('unknown');
    });
});

describe('a hand-built .doc', function () {
    it('reads its paragraphs, which is what the guard tests below break', function () {
        expect(Agent::read(LegacyFiles::cfb(LegacyFiles::word("Hello\rWorld\r")))['blocks'])->toBe([
            ['type' => 'paragraph', 'runs' => [['text' => 'Hello']]],
            ['type' => 'paragraph', 'runs' => [['text' => 'World']]],
        ]);
    });

    it('keeps a field result and drops its instruction', function () {
        $text = "See \x13 PAGE \x14"."7\x15 now\r";

        expect(Agent::read(LegacyFiles::cfb(LegacyFiles::word($text)))['blocks'][0]['runs'])->toBe([['text' => 'See 7 now']]);
    });

    it('decodes an 8-bit piece as Windows-1252, not Latin-1', function () {
        // 0x93 and 0x94 are curly quotes in 1252 and C1 controls in Latin-1.
        expect(Agent::read(LegacyFiles::cfb(LegacyFiles::word("\x93Hi\x94 \x80\r")))['blocks'][0]['runs'][0]['text'])->toBe('“Hi” €');
    });

    it('joins a UTF-16 surrogate pair and replaces half of one', function () {
        $text = pack('v*', 0xD83C, 0xDF89, 0x20, 0xD800, 0x41, 0x0D);

        expect(Agent::read(LegacyFiles::cfb(LegacyFiles::word($text, ['unicode' => true])))['blocks'][0]['runs'][0]['text'])
            ->toBe("🎉 \u{FFFD}A");
    });
});

describe('damaged and hostile files fail fast, and say what is wrong', function () {
    // A damaged file is not an unsupported format: a person needs to be told
    // "this file is broken", not "save it as .docx". So these must NOT be an
    // UnsupportedFormatException.
    $damaged = function (string $bytes, string $message): void {
        $e = lfThrown(fn () => Agent::read($bytes));

        expect($e)->toBeInstanceOf(RuntimeException::class);
        expect($e)->not->toBeInstanceOf(UnsupportedFormatException::class);
        expect($e->getMessage())->toContain($message);
    };

    it('refuses a compound file cut off inside its header', function () use ($damaged) {
        $damaged("\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".str_repeat("\0", 200), 'header is missing or truncated');
    });

    it('refuses a sector chain that loops', function () use ($damaged) {
        // FAT[1] = 1: the directory's chain points back at itself.
        $bytes = LegacyFiles::cfb(LegacyFiles::word("Hello\r"));
        $damaged(substr_replace($bytes, pack('V', 1), LegacyFiles::sectorOffset(0) + 4, 4), 'directory chain loops');
    });

    it('refuses a chain that points outside the file', function () use ($damaged) {
        $bytes = LegacyFiles::cfb(LegacyFiles::word("Hello\r"));
        $damaged(substr_replace($bytes, pack('V', 100000), 48, 4), 'points outside the file');
    });

    it('refuses a stream whose declared size its chain cannot hold', function () use ($damaged) {
        // Entry 1 is WordDocument; claim 64 MB for a chain of a few sectors.
        $bytes = LegacyFiles::cfb(LegacyFiles::word("Hello\r"));
        $damaged(substr_replace($bytes, pack('V', 64 * 1024 * 1024), LegacyFiles::entryOffset(1) + 120, 4), 'shorter than its declared size');
    });

    it('refuses a stream size too large to read, before reading it', function () use ($damaged) {
        $bytes = LegacyFiles::cfb(LegacyFiles::word("Hello\r"));
        $damaged(substr_replace($bytes, pack('V', 0xF0000000), LegacyFiles::entryOffset(1) + 120, 4), 'too large to read');
    });

    it('refuses a DIFAT chain that loops', function () use ($damaged) {
        // First DIFAT sector = 2, and sector 2's last slot (the next DIFAT) = 2.
        $bytes = LegacyFiles::cfb(LegacyFiles::word("Hello\r"));
        $bytes = substr_replace($bytes, pack('V', 2), 68, 4);
        $bytes = substr_replace($bytes, str_repeat(pack('V', LegacyFiles::FREESECT), 127).pack('V', 2), LegacyFiles::sectorOffset(2), 512);
        $damaged($bytes, 'DIFAT chain loops');
    });

    it('refuses an allocation table that lists more sectors than the file holds', function () use ($damaged) {
        // Every DIFAT slot names sector 0: 109 FAT sectors in a file of a dozen.
        $bytes = LegacyFiles::cfb(LegacyFiles::word("Hello\r"));
        $damaged(substr_replace($bytes, str_repeat(pack('V', 0), 109), 76, 436), 'more sectors than the file holds');
    });

    it('stops walking a directory whose siblings form a cycle', function () {
        // Entry 1's left sibling is entry 2 and entry 2's right is entry 1. Read
        // naively this never ends; it must finish and find the streams once.
        $bytes = LegacyFiles::cfb(['Contents' => 'a', 'Other' => 'b']);
        $bytes = substr_replace($bytes, pack('V', 2), LegacyFiles::entryOffset(1) + 68, 4);
        $bytes = substr_replace($bytes, pack('V', 1), LegacyFiles::entryOffset(2) + 72, 4);

        $e = lfThrown(fn () => Agent::read($bytes));
        expect($e)->toBeInstanceOf(UnsupportedFormatException::class);
        expect($e->format())->toBe('cfb');
    });

    it('refuses a piece table whose property records do not move forward', function () use ($damaged) {
        // A Prc of size 0xFFFD read as signed is -3, and 3 + -3 is a step of zero.
        $clx = "\x01".pack('v', 0xFFFD).LegacyFiles::clx([[0, 6, 1024, true]]);
        $damaged(LegacyFiles::cfb(LegacyFiles::word("Hello\r", ['clx' => $clx])), 'piece table is malformed');
    });

    it('reads overlapping pieces once, not once per piece', function () {
        // Character positions 0, 6, 0, 6: the table runs backwards and then covers
        // characters 0-6 a second time. Skipping only the backwards piece would
        // read "Hello" twice; a larger table of these repeats it without limit.
        $clx = LegacyFiles::clx([[0, 6, 1024, true], [6, 0, 1024, true], [0, 6, 1024, true]]);
        $doc = Agent::read(LegacyFiles::cfb(LegacyFiles::word("Hello\r", ['clx' => $clx, 'ccpText' => 12])));

        expect($doc['blocks'])->toBe([['type' => 'paragraph', 'runs' => [['text' => 'Hello']]]]);
    });

    it('bounds a text length of four billion characters by the bytes present', function () {
        $clx = LegacyFiles::clx([[0, 0xFFFFFFF0, 1024, true]]);
        $doc = Agent::read(LegacyFiles::cfb(LegacyFiles::word("Hello\r", ['clx' => $clx, 'ccpText' => 0xFFFFFFF0])));

        expect(lfTexts($doc['blocks'])[0])->toBe('Hello');
    });

    it('refuses an ODT part carrying a DOCTYPE, the door to entity expansion', function () use ($damaged) {
        $xml = '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY a "aaaa">]><office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"/>';
        $damaged(LegacyFiles::odt('', $xml), 'DOCTYPE');
    });

    it('parses an ODT part nested 257 elements deep and refuses one nested 258', function () use ($damaged) {
        // libxml's limit without XML_PARSE_HUGE, which the Node and Python
        // engines reproduce. The part's own wrappers are four deep
        // (document-content, body, text, p).
        $nested = fn (int $spans): string => LegacyFiles::odt(
            '<text:p>'.str_repeat('<text:span>', $spans).'deep'.str_repeat('</text:span>', $spans).'</text:p>'
        );

        expect(lfTexts(Agent::read($nested(253))['blocks']))->toBe(['deep']);
        $damaged($nested(254), 'Could not parse content.xml');
    });

    it('caps an ODT space run of two billion', function () {
        $doc = Agent::read(LegacyFiles::odt('<text:p>a<text:s text:c="2000000000"/>b</text:p>'));

        expect(strlen($doc['blocks'][0]['runs'][0]['text']))->toBe(1002);
    });

    it('caps the cells ODT repeat attributes can add in total', function () {
        $row = '<table:table-row table:number-rows-repeated="1000">'
            .'<table:table-cell table:number-columns-repeated="1000"><text:p>x</text:p></table:table-cell>'
            .'</table:table-row>';
        $doc = Agent::read(LegacyFiles::odt('<table:table>'.str_repeat($row, 5).'</table:table>'));

        $cells = array_sum(array_map(static fn (array $r): int => count($r['cells']), $doc['blocks'][0]['rows']));
        expect($cells)->toBeLessThanOrEqual(5 * 1000 + 100_000 + 1000);
    });

    it('refuses RTF nested past any real document', function () use ($damaged) {
        $damaged('{\rtf1 '.str_repeat('{', 20000).'x'.str_repeat('}', 20000).'}', 'nests groups too deeply');
    });

    it('skips \bin data by its length without reading past the end', function () {
        $doc = Agent::read('{\rtf1 before\par{\bin4000000000 abc}}');

        expect(lfTexts($doc['blocks']))->toBe(['before']);
    });

    it('survives unbalanced RTF braces', function () {
        expect(Agent::read('{\rtf1 {\b one}\par}}}two\par')['blocks'])->toBe([
            ['type' => 'paragraph', 'runs' => [['text' => 'one', 'bold' => true]]],
            ['type' => 'paragraph', 'runs' => [['text' => 'two']]],
        ]);
    });
});
