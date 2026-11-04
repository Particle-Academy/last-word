<?php

declare(strict_types=1);

use LastWord\Agent;
use LastWord\Archive\PartXml;
use LastWord\Exceptions\TemplateException;
use LastWord\Tests\Support\GeneratedLetterhead;

/*
 * Every XML part of an uploaded file is read through one gate, which refuses a
 * DOCTYPE. Most of this goes through the public reader, because what matters is
 * what a hostile upload gets back, not what a helper returns.
 *
 * PX_MARKER is the visible text of each part in a clean file, and PX_ENTITY the
 * value its replacement declares, so PX_ENTITY in the result means it expanded.
 */
const PX_MARKER = 'LWBASE';
const PX_ENTITY = 'LWINT';
const PX_DOCTYPE = '<!DOCTYPE r [<!ENTITY x "' . PX_ENTITY . '">]>';

function pxUnzip(string $bytes): array
{
    $path = lwTemp('lw-px-', '.docx');
    file_put_contents($path, $bytes);
    $zip = new ZipArchive();
    $zip->open($path);
    $parts = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $parts[(string) $zip->getNameIndex($i)] = (string) $zip->getFromIndex($i);
    }
    $zip->close();

    return $parts;
}

function pxZip(array $parts): string
{
    $path = lwTemp('lw-px-', '.docx');
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE);
    foreach ($parts as $name => $body) {
        $zip->addFromString($name, $body);
    }
    $zip->close();

    return (string) file_get_contents($path);
}

/** A real package from the real writer, the marker reachable from each part that shows text. */
function pxDocx(): array
{
    return pxUnzip(Agent::toBytes([
        'title' => PX_MARKER,
        'meta' => ['author' => 'Auth'],
        'defaultFont' => PX_MARKER,
        'page' => ['header' => ['left' => PX_MARKER]],
        'blocks' => [
            ['type' => 'paragraph', 'runs' => [
                ['text' => PX_MARKER],
                ['text' => '', 'footnote' => ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => PX_MARKER]]]]]],
            ]],
            ['type' => 'chart', 'option' => [
                'title' => ['text' => PX_MARKER],
                'xAxis' => ['type' => 'category', 'data' => ['a']],
                'yAxis' => ['type' => 'value'],
                'series' => [['type' => 'bar', 'data' => [1]]],
            ]],
        ],
    ]));
}

function pxWithDoctype(string $xml, string $doctype = PX_DOCTYPE): string
{
    if (preg_match('/^<\?xml[^>]*\?>\s*/', $xml, $m) === 1) {
        return $m[0] . $doctype . substr($xml, strlen($m[0]));
    }

    return $doctype . $xml;
}

function pxDeclare(string $xml, string $encoding): string
{
    return (string) preg_replace('/^<\?xml[^>]*\?>/', '<?xml version="1.0" encoding="' . $encoding . '"?>', $xml);
}

/** @return array<string, callable(string): string> */
function pxEncodings(): array
{
    return [
        'plain UTF-8' => static fn (string $x): string => $x,
        'UTF-8 with a BOM' => static fn (string $x): string => "\xEF\xBB\xBF" . $x,
        'UTF-16LE with a BOM' => static fn (string $x): string => "\xFF\xFE" . mb_convert_encoding(pxDeclare($x, 'UTF-16'), 'UTF-16LE', 'UTF-8'),
        'UTF-16BE with a BOM' => static fn (string $x): string => "\xFE\xFF" . mb_convert_encoding(pxDeclare($x, 'UTF-16'), 'UTF-16BE', 'UTF-8'),
        'UTF-16LE without a BOM' => static fn (string $x): string => mb_convert_encoding(pxDeclare($x, 'UTF-16'), 'UTF-16LE', 'UTF-8'),
        'past a long comment' => static fn (string $x): string => (string) preg_replace('/^(<\?xml[^>]*\?>\s*)/', '$1<!-- ' . str_repeat('p', 70000) . ' -->', $x),
    ];
}

/** Parts whose marker reaches the result. */
function pxMarkerParts(): array
{
    return ['word/document.xml', 'word/header1.xml', 'word/footnotes.xml', 'docProps/core.xml', 'word/styles.xml', 'word/charts/chart1.xml'];
}

function pxRead(array $parts): string
{
    try {
        return (string) json_encode(Agent::read(pxZip($parts)));
    } catch (RuntimeException) {
        return '(refused)';
    }
}

it('reads the marker from every part of a clean file, so a missing one means refused', function (): void {
    expect(substr_count(pxRead(pxDocx()), PX_MARKER))->toBe(6);
});

it('uses a fixture whose entity is live, so its absence is the gate and not the fixture', function (): void {
    $dom = new DOMDocument();
    $dom->loadXML('<?xml version="1.0"?>' . PX_DOCTYPE . '<r>&x;</r>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);

    expect($dom->documentElement->textContent)->toBe(PX_ENTITY);
});

it('expands no internal entity from any part, in any encoding', function (string $part, string $encoding): void {
    $parts = pxDocx();
    $parts[$part] = pxEncodings()[$encoding](pxWithDoctype(str_replace(PX_MARKER, '&x;', $parts[$part])));

    expect(pxRead($parts))->not->toContain(PX_ENTITY);
})->with(pxMarkerParts())->with(array_keys(pxEncodings()));

it('still reads each encoding without a DOCTYPE', function (string $part, string $encoding): void {
    $parts = pxDocx();
    $parts[$part] = pxEncodings()[$encoding]($parts[$part]);

    expect(substr_count(pxRead($parts), PX_MARKER))->toBe(6);
})->with(pxMarkerParts())->with(array_keys(pxEncodings()));

it('takes nothing from a part that declares a DOCTYPE and never uses it', function (string $part, string $encoding): void {
    $parts = pxDocx();
    $parts[$part] = pxEncodings()[$encoding](pxWithDoctype($parts[$part]));

    expect(substr_count(pxRead($parts), PX_MARKER))->toBeLessThan(6);
})->with(pxMarkerParts())->with(array_keys(pxEncodings()));

it('keeps the reader\'s contract: a refused body part throws as an unparsable one does, any other reads as absent', function (): void {
    $parts = pxDocx();
    $malformed = $parts;
    $malformed['word/document.xml'] = '<w:document>';
    $refused = $parts;
    $refused['word/document.xml'] = pxWithDoctype($parts['word/document.xml']);

    $message = static function (array $parts): string {
        try {
            Agent::read(pxZip($parts));
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }

        return '';
    };
    expect($message($refused))->toBe($message($malformed))->not->toBe('');

    foreach (['word/header1.xml', 'word/footnotes.xml', 'docProps/core.xml', 'word/styles.xml', 'word/numbering.xml', 'word/settings.xml', 'word/_rels/document.xml.rels'] as $part) {
        if (! isset($parts[$part])) {
            continue;
        }
        $one = $parts;
        $one[$part] = pxWithDoctype($parts[$part]);
        expect(pxRead($one))->not->toBe('(refused)', $part);
    }
});

it('follows no relationship whose target comes from an entity', function (): void {
    $parts = pxDocx();
    $doctype = '<!DOCTYPE r [<!ENTITY x "header1.xml">]>';
    $parts['word/_rels/document.xml.rels'] = pxWithDoctype(str_replace('Target="header1.xml"', 'Target="&x;"', $parts['word/_rels/document.xml.rels']), $doctype);

    expect(json_decode(pxRead($parts), true)['page']['header'] ?? null)->toBeNull();
});

it('reads no settings from a part that declares a DOCTYPE', function (string $encoding): void {
    $on = ['page' => ['header' => ['left' => 'odd'], 'evenHeader' => ['left' => 'even']], 'blocks' => []];
    $parts = pxUnzip(Agent::toBytes($on));
    expect(json_decode(pxRead($parts), true)['page'])->toHaveKey('evenHeader');

    $parts['word/settings.xml'] = pxEncodings()[$encoding](pxWithDoctype($parts['word/settings.xml']));

    expect(json_decode(pxRead($parts), true)['page'])->not->toHaveKey('evenHeader');
})->with(array_keys(pxEncodings()));

it('does not load a SYSTEM entity, and reads nothing of the file it names', function (): void {
    $scratch = lwTemp('lw-px-scratch-');
    file_put_contents($scratch, 'SCRATCHFILE');
    $doctype = '<!DOCTYPE r [<!ENTITY x SYSTEM "file:///' . ltrim(str_replace(chr(92), '/', $scratch), '/') . '">]>';

    foreach (pxMarkerParts() as $part) {
        $parts = pxDocx();
        $parts[$part] = pxWithDoctype(str_replace(PX_MARKER, '&x;', $parts[$part]), $doctype);
        expect(pxRead($parts))->not->toContain('SCRATCHFILE');
    }
});

it('refuses a DOCTYPE in every ODT part it reads, by name', function (string $part, string $encoding): void {
    $odt = pxUnzip((string) file_get_contents(__DIR__ . '/../fixtures/formats/report.odt'));
    $odt[$part] = pxEncodings()[$encoding](pxWithDoctype($odt[$part]));

    expect(fn () => Agent::read(pxZip($odt)))->toThrow(RuntimeException::class);
})->with(['content.xml', 'styles.xml'])->with(['plain UTF-8', 'UTF-8 with a BOM', 'UTF-16LE with a BOM', 'UTF-16BE with a BOM', 'UTF-16LE without a BOM', 'past a long comment']);

it('refuses a DOCTYPE in a template part it reads or carries', function (string $part, string $encoding): void {
    $template = pxUnzip(GeneratedLetterhead::bytes());
    $template[$part] = pxEncodings()[$encoding](pxWithDoctype($template[$part]));

    expect(fn () => Agent::toBytes(['blocks' => []], ['template' => pxZip($template), 'templateParts' => ['styles', 'headers', 'footers', 'page']]))
        ->toThrow(TemplateException::class);
})->with(['word/styles.xml', 'word/document.xml', 'word/header1.xml'])->with(['plain UTF-8', 'UTF-16LE with a BOM', 'past a long comment']);

/* ---------------- the gate on its own ---------------- */

/** An encoding the byte walk does not read, and libxml decodes through iconv. */
function pxUtf7Doctype(): string
{
    return '<?xml version="1.0" encoding="UTF-7"?>'
        . '+ADw-+ACE-DOCTYPE r +AFs-+ADw-+ACE-ENTITY x +ACI-LWINT+ACI-+AD4-+AF0-+AD4-'
        . '+ADw-r+AD4-+ACY-x+ADs-+ADw-/r+AD4-';
}

it('finds a DOCTYPE before the root', function (string $xml): void {
    expect(PartXml::prologDeclaresDoctype($xml))->toBeTrue();
})->with([
    'bare' => [PX_DOCTYPE . '<r/>'],
    'after a declaration' => ['<?xml version="1.0"?>' . PX_DOCTYPE . '<r/>'],
    'after whitespace and a comment' => ["<?xml version=\"1.0\"?>\n <!-- c -->\n" . PX_DOCTYPE . '<r/>'],
    'after a UTF-8 BOM' => ["\xEF\xBB\xBF" . PX_DOCTYPE . '<r/>'],
    'past a long comment' => ['<!-- ' . str_repeat('p', 100000) . ' -->' . PX_DOCTYPE . '<r/>'],
    'after a processing instruction' => ['<?pi a?>' . PX_DOCTYPE . '<r/>'],
]);

it('finds one in UTF-16', function (string $bom, string $encoding): void {
    $xml = $bom . mb_convert_encoding('<?xml version="1.0" encoding="UTF-16"?>' . PX_DOCTYPE . '<r/>', $encoding, 'UTF-8');

    expect(PartXml::prologDeclaresDoctype($xml))->toBeTrue();
})->with([
    'LE with a BOM' => ["\xFF\xFE", 'UTF-16LE'],
    'BE with a BOM' => ["\xFE\xFF", 'UTF-16BE'],
    'LE without' => ['', 'UTF-16LE'],
    'BE without' => ['', 'UTF-16BE'],
]);

it('finds none where there is none', function (string $xml): void {
    expect(PartXml::prologDeclaresDoctype($xml))->toBeFalse();
})->with([
    'empty' => [''],
    'a root' => ['<r/>'],
    'text after the root' => ['<r><![CDATA[<!DOCTYPE html>]]></r>'],
    'in a comment' => ['<!-- <!DOCTYPE x> --><r/>'],
    'in a processing instruction' => ['<?pi <!DOCTYPE x>?><r/>'],
    'an unterminated comment' => ['<!-- ' . PX_DOCTYPE],
    'an unterminated declaration' => ['<?xml ' . PX_DOCTYPE],
]);

it('reads text that merely mentions a DOCTYPE', function (): void {
    expect(PartXml::dom('<r><![CDATA[<!DOCTYPE html>]]></r>')?->documentElement?->textContent)->toBe('<!DOCTYPE html>');
});

it('fails malformed XML the way it fails a DOCTYPE', function (): void {
    expect(PartXml::dom('<r>'))->toBeNull()
        ->and(PartXml::dom(PX_DOCTYPE . '<r/>'))->toBeNull()
        ->and(PartXml::dom(''))->toBeNull()
        ->and(PartXml::dom(null))->toBeNull();
});

it('refuses a DOCTYPE the prolog check cannot read, by asking the parser', function (): void {
    $utf7 = pxUtf7Doctype();
    $dom = new DOMDocument();
    $live = @$dom->loadXML($utf7, LIBXML_NONET);
    if (! $live || $dom->doctype === null) {
        $this->markTestSkipped('this libxml has no UTF-7 decoder, so there is nothing to refuse');
    }

    expect(PartXml::prologDeclaresDoctype($utf7))->toBeFalse()
        ->and(PartXml::dom($utf7))->toBeNull()
        ->and(PartXml::declaresDoctype($utf7))->toBeTrue();
});

it('says a carried part is clean when it merely does not parse', function (): void {
    expect(PartXml::declaresDoctype('<r>'))->toBeFalse()
        ->and(PartXml::declaresDoctype(''))->toBeFalse()
        ->and(PartXml::declaresDoctype(PX_DOCTYPE . '<r/>'))->toBeTrue();
});

it('leaves the caller\'s libxml error handling as it found it', function (): void {
    $before = libxml_use_internal_errors(false);
    PartXml::dom('<r>');
    PartXml::declaresDoctype('<r>');
    $after = libxml_use_internal_errors($before);

    expect($after)->toBeFalse();
});

/* ---------------- nothing else parses ---------------- */

/** @return list<string> "file: what" for each way past the gate in a PHP source tree */
function pxBypasses(string $root): array
{
    $found = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if ($file->getExtension() !== 'php' || $file->getFilename() === 'PartXml.php') {
            continue;
        }
        $tokens = token_get_all((string) file_get_contents($file->getPathname()));
        foreach ($tokens as $i => $token) {
            if (! is_array($token) || $token[0] !== T_STRING && $token[0] !== T_NAME_FULLY_QUALIFIED) {
                continue;
            }
            $name = strtolower(ltrim($token[1], chr(92)));
            $next = $tokens[$i + 1] ?? null;
            $prev = $tokens[$i - 2] ?? null;
            $called = $next === '(' || (is_array($next) && $next[0] === T_DOUBLE_COLON);
            if (in_array($name, ['loadxml', 'loadhtml', 'simplexml_load_string', 'simplexml_load_file', 'simplexml_import_dom', 'xml_parser_create', 'xml_parser_create_ns', 'xmlreader'], true) && $called) {
                $found[] = $file->getFilename() . ': ' . $token[1];
            }
            if (in_array($name, ['domdocument', 'xmlreader', 'simplexmlelement'], true) && is_array($prev) && $prev[0] === T_NEW) {
                $found[] = $file->getFilename() . ': new ' . $token[1];
            }
        }
    }

    return $found;
}

it('leaves no way to a part except through the gate', function (): void {
    expect(pxBypasses(dirname(__DIR__, 2) . '/src'))->toBe([]);
});

it('whose check can see one', function (): void {
    $dir = lwTempDir('lwpx');
    file_put_contents($dir . '/Sneak.php', '<?php $d = new DOMDocument(); $d->loadXML($x); $s = simplexml_load_string($x);');

    expect(pxBypasses($dir))->toBe(['Sneak.php: new DOMDocument', 'Sneak.php: loadXML', 'Sneak.php: simplexml_load_string']);
});
