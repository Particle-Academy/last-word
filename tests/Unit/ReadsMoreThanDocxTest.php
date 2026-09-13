<?php

declare(strict_types=1);

use LastWord\Agent;
use LastWord\Exceptions\UnsupportedFormatException;

/**
 * `Agent::read()` is the only entry point, so it has to know what it was given.
 *
 * ## Why this exists
 *
 * A consumer removed `phpoffice/phpword` and made this package the only docx
 * path in their app. PhpWord's `IOFactory` sniffed `.doc`, `.odt` and `.rtf`
 * alongside `.docx`; dropping it dropped those three.
 *
 * **The failure was silent, which is the part that matters.** In their app these
 * arrive as user uploads that an agent then analyses: the file stored fine and
 * contributed no text. Nothing raised, nothing logged, an answer built on a
 * document nobody read.
 *
 * So there are two jobs here and the second is not optional:
 *
 * 1. Read the formats we can read.
 * 2. **Refuse the ones we cannot, by name.** An `UnsupportedFormatException`
 *    that says "this is a legacy .doc, save it as .docx" lets a host tell a
 *    person what to do. A generic `InvalidArgumentException` — or worse, empty
 *    text — leaves them guessing why the answer was thin.
 *
 * ## A latent bug this also closes
 *
 * `read()` used to treat ANY existing path as docx bytes: it read the file and
 * handed it to `DocxReader` without looking at the signature. So a `.doc` on
 * disk failed inside the zip reader rather than at the door, and the error
 * named the wrong thing entirely.
 */
function lwMinimalOdt(string $text = 'Hello from ODT'): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'lw_odt_').'.odt';
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    // `mimetype` first and STORED is what the ODF spec asks for; we do not
    // depend on that here, but writing a fixture that would not open in a real
    // reader would be testing our own invention rather than the format.
    $zip->addFromString('mimetype', 'application/vnd.oasis.opendocument.text');
    $zip->addFromString('content.xml', <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <office:document-content
            xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"
            xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0">
          <office:body><office:text>
            <text:h text:outline-level="1">A Heading</text:h>
            <text:p>{$text}</text:p>
            <text:p/>
          </office:text></office:body>
        </office:document-content>
        XML);
    $zip->close();

    $bytes = (string) file_get_contents($tmp);
    @unlink($tmp);

    return $bytes;
}

function lwMinimalRtf(string $text = 'Hello from RTF'): string
{
    return '{\rtf1\ansi\deff0 {\fonttbl{\f0 Times;}}'
        ."\n".'\b A Heading\b0\par'
        ."\n".$text.'\par'
        ."\n".'}';
}

/**
 * The OLE2 compound-file signature every Word 97-2003 .doc starts with, and
 * nothing after it: a DAMAGED file since 0.5, when .doc became readable.
 */
function lwLegacyDocBytes(): string
{
    return "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".str_repeat("\x00", 200);
}

/** A real Word 97-2003 .doc, converted by LibreOffice (see tests/fixtures/formats/README.md). */
function lwRealDocBytes(): string
{
    return (string) file_get_contents(__DIR__.'/../fixtures/formats/report.doc');
}

describe('Agent::read dispatches on what the bytes ARE', function () {
    it('still reads docx, unchanged', function () {
        $bytes = Agent::toBytes(['blocks' => [
            ['type' => 'paragraph', 'runs' => [['text' => 'Round trip']]],
        ]]);

        $doc = Agent::read($bytes);

        expect($doc['blocks'])->not->toBeEmpty();
        expect(json_encode($doc))->toContain('Round trip');
    });

    it('reads an ODT and returns the SAME shape as a docx', function () {
        // Same shape is the whole point: a caller that already handles our
        // documents must not need a second code path per input format.
        $doc = Agent::read(lwMinimalOdt('Body text here'));

        expect($doc)->toHaveKey('blocks');
        expect(json_encode($doc['blocks']))->toContain('Body text here');
        expect(json_encode($doc['blocks']))->toContain('A Heading');
    });

    it('keeps the ODT heading a HEADING, not a paragraph', function () {
        // Flattening structure is exactly what the consumer left PhpWord over.
        // Text that survives with its shape lost is a worse input for a model
        // than text that fails loudly.
        $doc = Agent::read(lwMinimalOdt());

        $types = array_column($doc['blocks'], 'type');
        expect($types)->toContain('heading');
    });

    it('reads an RTF', function () {
        $doc = Agent::read(lwMinimalRtf('Body from rtf'));

        expect(json_encode($doc['blocks']))->toContain('Body from rtf');
    });

    it('does not leak RTF control words into the text', function () {
        // The naive extractor strips backslash-words and leaves the braces, or
        // keeps `\par` as literal text. Either reads as corruption to whoever
        // gets the output.
        $doc = Agent::read(lwMinimalRtf('Clean text'));

        $json = json_encode($doc['blocks']);
        expect($json)->not->toContain('rtf1');
        expect($json)->not->toContain('fonttbl');
        expect($json)->not->toContain('\\\\par');
    });
});

describe('what it cannot read, it refuses BY NAME', function () {
    it('reads a legacy .doc, which 0.4 could only name', function () {
        // 0.4 refused every .doc with "save it as .docx". Honest, and it left a
        // host with nothing to give a model. 0.5 reads the Word 97-2003 format.
        expect(json_encode(Agent::read(lwRealDocBytes()), JSON_UNESCAPED_UNICODE))->toContain('Quarterly Field Report');
    });

    it('calls a broken compound file broken, not an unsupported format', function () {
        // The signature with nothing behind it is a damaged file. Telling a person
        // to "save it as .docx" would send them to re-save a file that cannot open.
        expect(fn () => Agent::read(lwLegacyDocBytes()))->toThrow(RuntimeException::class);

        try {
            Agent::read(lwLegacyDocBytes());
        } catch (Throwable $e) {
            expect($e)->not->toBeInstanceOf(UnsupportedFormatException::class);
            expect($e->getMessage())->toContain('Compound File Binary');
        }
    });

    it('is a distinct type, so a host can catch it separately', function () {
        // The consumer asked for this explicitly: an unsupported FORMAT and a
        // malformed FILE need different messages to a person, so they cannot
        // share an exception class.
        expect(is_subclass_of(UnsupportedFormatException::class, InvalidArgumentException::class))->toBeTrue();
        expect(UnsupportedFormatException::class)->not->toBe(InvalidArgumentException::class);
    });

    it('refuses bytes that are no document at all', function () {
        expect(fn () => Agent::read("not a document, just text\n"))
            ->toThrow(InvalidArgumentException::class);
    });
});

describe('a PATH is sniffed too, not assumed to be docx', function () {
    it('reads an .odt from disk', function () {
        $path = tempnam(sys_get_temp_dir(), 'lw_').'.odt';
        file_put_contents($path, lwMinimalOdt('From a path'));

        try {
            expect(json_encode(Agent::read($path)))->toContain('From a path');
        } finally {
            @unlink($path);
        }
    });

    it('reads a legacy .doc ON DISK by its content', function () {
        // The latent bug 0.4 closed: any existing path was read and handed to
        // DocxReader without checking the signature, so this failed as a corrupt
        // archive and the message named the wrong problem.
        $path = tempnam(sys_get_temp_dir(), 'lw_').'.doc';
        file_put_contents($path, lwRealDocBytes());

        try {
            expect(json_encode(Agent::read($path), JSON_UNESCAPED_UNICODE))->toContain('São Paulo');
        } finally {
            @unlink($path);
        }
    });
});
