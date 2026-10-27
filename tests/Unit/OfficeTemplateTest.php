<?php

declare(strict_types=1);

use LastWord\Agent;
use LastWord\Exceptions\ArchiveRefused;
use LastWord\Exceptions\OfficeTemplateRefused;
use LastWord\Exceptions\UnsupportedFormatException;
use LastWord\Reader\OdtReader;

/*
 * Official template formats: Word .dotx and LibreOffice .ott.
 *
 * A template IS its document twin with a different declared type: the main
 * part's content type in [Content_Types].xml (OOXML), or the `mimetype` entry
 * plus the manifest's root entry (ODF 1.3 Part 2 section 3.3 requires the two to
 * be equal). The reader marks `sourceKind: "template"`; the converter flips the
 * declaration and copies every other part byte for byte.
 *
 * Every fixture here is built by this file with ZipArchive, independently of
 * the production converter, so a converter bug cannot also forge its own
 * evidence.
 */

const LWT_DOC = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml';
const LWT_TEMPLATE = 'application/vnd.openxmlformats-officedocument.wordprocessingml.template.main+xml';
const LWT_MACRO_TEMPLATE = 'application/vnd.ms-word.template.macroEnabledTemplate.main+xml';
const LWT_MACRO_DOC = 'application/vnd.ms-word.document.macroEnabled.main+xml';
const LWT_PPT_TEMPLATE = 'application/vnd.openxmlformats-officedocument.presentationml.template.main+xml';
const LWT_ODT = 'application/vnd.oasis.opendocument.text';
const LWT_OTT = 'application/vnd.oasis.opendocument.text-template';

function lwtDoc(): array
{
    return ['title' => 'Letterhead', 'blocks' => [
        ['type' => 'heading', 'level' => 1, 'runs' => [['text' => 'Dear reader']]],
        ['type' => 'paragraph', 'runs' => [['text' => 'Body text, '], ['text' => 'bold', 'bold' => true]]],
        ['type' => 'image', 'src' => lwRedPngDataUrl(), 'widthPx' => 2, 'heightPx' => 2],
    ]];
}

/** @return array<string, string> entry name => uncompressed bytes, in archive order */
function lwtEntries(string $bytes): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'lwt_');
    file_put_contents($tmp, $bytes);
    $zip = new ZipArchive();
    expect($zip->open($tmp, ZipArchive::RDONLY))->toBeTrue();
    $out = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $out[$zip->getNameIndex($i)] = $zip->getFromIndex($i);
    }
    $zip->close();
    unlink($tmp);

    return $out;
}

/** Compression method per entry, read from ZipArchive's own index. */
function lwtMethods(string $bytes): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'lwt_');
    file_put_contents($tmp, $bytes);
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::RDONLY);
    $out = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        $out[$stat['name']] = $stat['comp_method'];
    }
    $zip->close();
    unlink($tmp);

    return $out;
}

/** Re-zip name => bytes in order; `mimetype` is written STORED as ODF requires. */
function lwtZip(array $entries): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'lwt_').'.zip';
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($entries as $name => $data) {
        $zip->addFromString($name, $data);
        if ($name === 'mimetype') {
            $zip->setCompressionName($name, ZipArchive::CM_STORE);
        }
    }
    $zip->close();
    $bytes = (string) file_get_contents($tmp);
    unlink($tmp);

    return $bytes;
}

function lwtDocx(): string
{
    return Agent::toBytes(lwtDoc());
}

/** The docx with its main part re-declared; nothing else differs. */
function lwtRetyped(string $contentType, array $extra = []): string
{
    $entries = lwtEntries(lwtDocx());
    $entries['[Content_Types].xml'] = str_replace(LWT_DOC, $contentType, $entries['[Content_Types].xml']);

    return lwtZip($entries + $extra);
}

function lwtDotx(): string
{
    return lwtRetyped(LWT_TEMPLATE);
}

function lwtOdf(string $mimetype, string $body = 'text', ?string $manifestType = null): string
{
    $manifestType ??= $mimetype;

    return lwtZip([
        'mimetype' => $mimetype,
        'content.xml' => '<?xml version="1.0" encoding="UTF-8"?>'
            .'<office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"'
            .' xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0">'
            ."<office:body><office:{$body}><text:h text:outline-level=\"1\">A Heading</text:h><text:p>Hello from ODF</text:p></office:{$body}></office:body>"
            .'</office:document-content>',
        'meta.xml' => '<?xml version="1.0" encoding="UTF-8"?><office:document-meta xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:dc="http://purl.org/dc/elements/1.1/"><office:meta><dc:title>Memo</dc:title></office:meta></office:document-meta>',
        'Pictures/opaque.bin' => "\0\x01\x02binary\xff",
        'META-INF/manifest.xml' => '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<manifest:manifest xmlns:manifest="urn:oasis:names:tc:opendocument:xmlns:manifest:1.0" manifest:version="1.3">'."\n"
            .' <manifest:file-entry manifest:full-path="/" manifest:version="1.3" manifest:media-type="'.$manifestType.'"/>'."\n"
            .' <manifest:file-entry manifest:full-path="content.xml" manifest:media-type="text/xml"/>'."\n"
            .'</manifest:manifest>',
    ]);
}

function lwtRefused(callable $action, string $reason): void
{
    try {
        $action();
    } catch (OfficeTemplateRefused $error) {
        expect($error->reason)->toBe($reason);

        return;
    }
    throw new RuntimeException("Expected a {$reason} refusal; the input was accepted.");
}

function lwtArchiveRefused(callable $action, string $reason): void
{
    try {
        $action();
    } catch (ArchiveRefused $error) {
        expect($error->reason)->toBe($reason);

        return;
    }
    throw new RuntimeException("Expected archive refusal {$reason}; the input was accepted.");
}

// ─── Reading ──────────────────────────────────────────────────────────────

it('reads a Word .dotx and marks it as a template', function () {
    $document = Agent::read(lwtDocx());
    $template = Agent::read(lwtDotx());

    expect($template['sourceKind'] ?? null)->toBe('template');
    unset($template['sourceKind']);
    expect($template)->toBe($document);
});

it('leaves a .docx read exactly as it was: no sourceKind key', function () {
    expect(Agent::read(lwtDocx()))->not->toHaveKey('sourceKind');
});

it('reads a LibreOffice .ott and marks it as a template', function () {
    $document = Agent::read(lwtOdf(LWT_ODT));
    $template = Agent::read(lwtOdf(LWT_OTT));

    expect($document)->not->toHaveKey('sourceKind')
        ->and($template['sourceKind'] ?? null)->toBe('template');
    unset($template['sourceKind']);
    expect($template)->toBe($document);
});

it('refuses a macro-enabled .dotm by name rather than reading around the VBA', function () {
    lwtRefused(fn () => Agent::read(lwtRetyped(LWT_MACRO_TEMPLATE)), 'macro_enabled');
});

it('refuses a .dotx that carries a VBA project anyway', function () {
    $bytes = lwtRetyped(LWT_TEMPLATE, ['word/vbaProject.bin' => "\xD0\xCF\x11\xE0 not really"]);
    lwtRefused(fn () => Agent::read($bytes), 'macro_enabled');
});

it('still reads a macro-enabled DOCUMENT (.docm) as before: only templates changed', function () {
    expect(Agent::read(lwtRetyped(LWT_MACRO_DOC)))->not->toHaveKey('sourceKind');
});

// ─── Hostile input ────────────────────────────────────────────────────────

it('refuses a template whose content type claims another family', function () {
    // The word part declared as a PowerPoint template.
    lwtRefused(fn () => Agent::read(lwtRetyped(LWT_PPT_TEMPLATE)), 'family_mismatch');

    // A PowerPoint template declared beside intact Word parts.
    $entries = lwtEntries(lwtDocx());
    $entries['[Content_Types].xml'] = str_replace('</Types>',
        '<Override PartName="/ppt/presentation.xml" ContentType="'.LWT_PPT_TEMPLATE.'"/></Types>',
        $entries['[Content_Types].xml']);
    lwtRefused(fn () => Agent::read(lwtZip($entries)), 'family_mismatch');

    // Every .xml part declared a template through a Default.
    $entries = lwtEntries(lwtDocx());
    $entries['[Content_Types].xml'] = str_replace('ContentType="application/xml"',
        'ContentType="'.LWT_TEMPLATE.'"', $entries['[Content_Types].xml']);
    lwtRefused(fn () => Agent::read(lwtZip($entries)), 'family_mismatch');
});

it('refuses an ODF text template whose body is a spreadsheet', function () {
    lwtRefused(fn () => (new OdtReader())->read(lwtOdf(LWT_OTT, 'spreadsheet')), 'family_mismatch');
});

it('refuses an ODF template of another family, by name or by refusal', function () {
    lwtRefused(fn () => (new OdtReader())->read(lwtOdf('application/vnd.oasis.opendocument.presentation-template')), 'family_mismatch');
    expect(fn () => Agent::read(lwtOdf('application/vnd.oasis.opendocument.spreadsheet-template', 'spreadsheet')))
        ->toThrow(UnsupportedFormatException::class);
});

it('refuses an ODF template whose manifest names another family', function () {
    lwtRefused(fn () => Agent::read(lwtOdf(LWT_OTT, 'text', 'application/vnd.oasis.opendocument.spreadsheet-template')), 'family_mismatch');
});

it('refuses a template zip bomb through the archive guard, reading and converting', function () {
    $bomb = lwtRetyped(LWT_TEMPLATE, ['word/media/bomb.bin' => str_repeat("\0", 65 * 1024 * 1024)]);
    lwtArchiveRefused(fn () => Agent::read($bomb), 'archive_limit');
    lwtArchiveRefused(fn () => Agent::convertTemplate($bomb), 'archive_limit');
});

it('refuses a template with a traversing entry name, reading and converting', function () {
    $evil = lwtRetyped(LWT_TEMPLATE, ['../evil.xml' => '<x/>']);
    lwtArchiveRefused(fn () => Agent::read($evil), 'archive_path');
    lwtArchiveRefused(fn () => Agent::convertTemplate($evil), 'archive_path');
});

// ─── Converting ───────────────────────────────────────────────────────────

it('converts a .dotx to a .docx changing only the content-type value', function () {
    $dotx = lwtDotx();
    $docx = Agent::convertTemplate($dotx, 'document');

    $before = lwtEntries($dotx);
    $after = lwtEntries($docx);
    expect(array_keys($after))->toBe(array_keys($before));
    foreach ($before as $name => $data) {
        if ($name === '[Content_Types].xml') {
            expect($after[$name])->toBe(str_replace(LWT_TEMPLATE, LWT_DOC, $data));
        } else {
            expect($after[$name])->toBe($data, "{$name} changed");
        }
    }
    expect(Agent::read($docx))->not->toHaveKey('sourceKind');
});

it('round-trips docx to dotx and back with every part byte-identical', function () {
    $docx = lwtDocx();
    $dotx = Agent::convertTemplate($docx, 'template');
    $back = Agent::convertTemplate($dotx, 'document');

    expect(Agent::read($dotx)['sourceKind'])->toBe('template')
        ->and(lwtEntries($back))->toBe(lwtEntries($docx));

    // Untouched parts are copied as RECORDS, not re-encoded: same compression.
    $methods = lwtMethods($docx);
    unset($methods['[Content_Types].xml']);
    $converted = lwtMethods($dotx);
    unset($converted['[Content_Types].xml']);
    expect($converted)->toBe($methods);
});

it('flips direction when no target is given', function () {
    expect(Agent::read(Agent::convertTemplate(lwtDocx()))['sourceKind'])->toBe('template')
        ->and(Agent::read(Agent::convertTemplate(lwtDotx())))->not->toHaveKey('sourceKind');
});

it('converts .odt to .ott keeping mimetype first, STORED and without an extra field', function () {
    $odt = lwtOdf(LWT_ODT);
    $ott = Agent::convertTemplate($odt, 'template');

    $before = lwtEntries($odt);
    $after = lwtEntries($ott);
    expect(array_keys($after))->toBe(array_keys($before))
        ->and(array_key_first($after))->toBe('mimetype')
        ->and($after['mimetype'])->toBe(LWT_OTT)
        ->and(lwtMethods($ott)['mimetype'])->toBe(ZipArchive::CM_STORE)
        // The first local header: name `mimetype` at byte 30, extra length 0.
        ->and(substr($ott, 0, 4))->toBe("PK\x03\x04")
        ->and(unpack('v', substr($ott, 28, 2))[1])->toBe(0)
        ->and(substr($ott, 30, 8))->toBe('mimetype')
        ->and(substr($ott, 38, strlen(LWT_OTT)))->toBe(LWT_OTT);

    // ODF 1.3 Part 2 section 3.3: mimetype SHALL equal the manifest's root
    // media-type, so that one attribute value moves with it and nothing else.
    expect($after['META-INF/manifest.xml'])->toBe(str_replace(
        'manifest:full-path="/" manifest:version="1.3" manifest:media-type="'.LWT_ODT.'"',
        'manifest:full-path="/" manifest:version="1.3" manifest:media-type="'.LWT_OTT.'"',
        $before['META-INF/manifest.xml'],
    ));
    foreach ($before as $name => $data) {
        if (! in_array($name, ['mimetype', 'META-INF/manifest.xml'], true)) {
            expect($after[$name])->toBe($data, "{$name} changed");
        }
    }

    expect(Agent::read($ott)['sourceKind'])->toBe('template')
        ->and(lwtEntries(Agent::convertTemplate($ott, 'document')))->toBe($before);
});

it('refuses conversions it must not make', function () {
    lwtRefused(fn () => Agent::convertTemplate(lwtDotx(), 'template'), 'already_template');
    lwtRefused(fn () => Agent::convertTemplate(lwtDocx(), 'document'), 'already_document');
    lwtRefused(fn () => Agent::convertTemplate(lwtRetyped(LWT_MACRO_TEMPLATE), 'document'), 'macro_enabled');
    lwtRefused(fn () => Agent::convertTemplate(lwtRetyped(LWT_MACRO_DOC), 'template'), 'macro_enabled');
    lwtRefused(fn () => Agent::convertTemplate(lwtDocx(), 'spreadsheet'), 'bad_target');
    lwtRefused(fn () => Agent::convertTemplate(lwtRetyped(LWT_PPT_TEMPLATE)), 'family_mismatch');
});

it('refuses an ODF package whose mimetype is not stored first', function () {
    $entries = lwtEntries(lwtOdf(LWT_ODT));
    $mimetype = ['mimetype' => $entries['mimetype']];
    unset($entries['mimetype']);
    lwtRefused(fn () => Agent::convertTemplate(lwtZip($entries + $mimetype)), 'odf_mimetype');
});

it('refuses a content-type declaration it cannot rewrite unambiguously', function () {
    // The real declaration hidden from a text scan by a comment carrying a decoy.
    $entries = lwtEntries(lwtDotx());
    $entries['[Content_Types].xml'] = str_replace(
        '<Override PartName="/word/document.xml"',
        '<!-- <Override PartName="/word/document.xml" ContentType="'.LWT_TEMPLATE.'"/> --><Override PartName="/word/document.xml"',
        $entries['[Content_Types].xml'],
    );
    lwtRefused(fn () => Agent::convertTemplate(lwtZip($entries)), 'not_convertible');
});

it('names a spreadsheet instead of converting it', function () {
    $xlsx = lwtZip(['[Content_Types].xml' => '<Types/>', 'xl/workbook.xml' => '<workbook/>']);
    expect(fn () => Agent::convertTemplate($xlsx))->toThrow(UnsupportedFormatException::class);
});

// ─── CLI ──────────────────────────────────────────────────────────────────

function lwtCli(array $args): array
{
    $command = array_merge([PHP_BINARY, dirname(__DIR__, 2).'/bin/last-word'], $args);
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), $stdout, $stderr];
}

it('converts through the template:convert CLI', function () {
    $dir = sys_get_temp_dir().'/lwt-cli-'.bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents("{$dir}/in.dotx", lwtDotx());

    [$code, $stdout] = lwtCli(['template:convert', "{$dir}/in.dotx", "{$dir}/out.docx"]);
    expect($code)->toBe(0)
        ->and($stdout)->toContain('document')
        ->and(Agent::read("{$dir}/out.docx"))->not->toHaveKey('sourceKind');

    [$code, , $stderr] = lwtCli(['template:convert', "{$dir}/out.docx", "{$dir}/out.docx", '--to=template']);
    expect($code)->toBe(1)->and($stderr)->toContain('exists');

    [$code] = lwtCli(['template:convert', "{$dir}/out.docx", "{$dir}/back.dotx", '--to=template']);
    expect($code)->toBe(0)->and(Agent::read("{$dir}/back.dotx")['sourceKind'])->toBe('template');

    file_put_contents("{$dir}/macro.dotm", lwtRetyped(LWT_MACRO_TEMPLATE));
    [$code, , $stderr] = lwtCli(['template:convert', "{$dir}/macro.dotm", "{$dir}/macro.docx"]);
    expect($code)->toBe(1)->and($stderr)->toContain('macro_enabled')
        ->and(file_exists("{$dir}/macro.docx"))->toBeFalse();

    [$code, , $stderr] = lwtCli(['template:convert']);
    expect($code)->toBe(2)->and($stderr)->toContain('Usage');

    array_map('unlink', glob("{$dir}/*"));
    rmdir($dir);
});

// ─── LibreOffice as an oracle (skipped where it is not installed) ─────────

function lwtSoffice(): ?string
{
    $candidates = array_filter([getenv('LIBREOFFICE_BIN') ?: null,
        'C:/Program Files/LibreOffice/program/soffice.com', '/usr/bin/soffice', '/usr/local/bin/soffice',
        '/Applications/LibreOffice.app/Contents/MacOS/soffice']);
    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return null;
}

/** Headless convert; the profile lives in a SHORT temp path (long ones crash LO on Windows). */
function lwtLibreOffice(string $soffice, string $input, string $format, string $outDir): string
{
    $profile = sys_get_temp_dir().'/lwlo'.bin2hex(random_bytes(3));
    $url = 'file:///'.ltrim(str_replace(chr(92), '/', $profile), '/');
    $process = proc_open([$soffice, '-env:UserInstallation='.$url, '--headless', '--norestore',
        '--convert-to', $format, '--outdir', $outDir, $input], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    $out = $outDir.'/'.pathinfo($input, PATHINFO_FILENAME).'.'.explode(':', $format)[0];
    expect($code)->toBe(0)->and(is_file($out))->toBeTrue("LibreOffice produced no {$format}");

    return $out;
}

it('reads what LibreOffice writes as .dotx and .ott, and LibreOffice reads our conversions', function () {
    $soffice = lwtSoffice();
    if ($soffice === null) {
        $this->markTestSkipped('LibreOffice is not installed; the oracle check runs where it is.');
    }
    $dir = sys_get_temp_dir().'/lwo'.bin2hex(random_bytes(3));
    mkdir($dir);
    file_put_contents("{$dir}/src.docx", lwtDocx());

    foreach (['dotx' => 'docx', 'ott' => 'odt'] as $template => $document) {
        $loTemplate = lwtLibreOffice($soffice, "{$dir}/src.docx", $template, $dir);
        $read = Agent::read($loTemplate);
        expect($read['sourceKind'] ?? null)->toBe('template', "LibreOffice .{$template} not marked")
            ->and(json_encode($read))->toContain('Dear reader');

        // LibreOffice's own template back to its document twin: not a template.
        mkdir("{$dir}/{$template}");
        $loBack = lwtLibreOffice($soffice, $loTemplate, $document, "{$dir}/{$template}");
        expect(Agent::read($loBack))->not->toHaveKey('sourceKind');

        // OUR conversion of LibreOffice's template, read back by LibreOffice.
        file_put_contents("{$dir}/ours.{$document}", Agent::convertTemplate($loTemplate, 'document'));
        mkdir("{$dir}/ours-{$template}");
        $text = lwtLibreOffice($soffice, "{$dir}/ours.{$document}", 'txt:Text', "{$dir}/ours-{$template}");
        expect(file_get_contents($text))->toContain('Dear reader');
    }
})->group('libreoffice');
