<?php

declare(strict_types=1);

use LastWord\Agent;

/*
 * page.background and page.border (owner decision 11, document-exchange plan):
 * the same keys and the same meaning as fine-print 0.3.0, mapped to Word's
 * w:background (with w:displayBackgroundShape in settings, without which Word
 * and LibreOffice ignore it) and w:pgBorders.
 *
 * fine-print's semantics: `background` is #RRGGBB, full-bleed. `border` is a
 * frame measured from the PAGE EDGE: style single|double|dashed|dotted|none
 * (single by default), width = the whole frame in points (0.5 by default; a
 * double is two rules of a third each, with a third between), color (black),
 * inset = points from the page edge to the OUTSIDE of the frame (24).
 */

/** @return array<string, string> part name => xml */
function lwPfParts(array $doc): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'lw-pf-');
    file_put_contents($tmp, Agent::toBytes($doc));
    $zip = new ZipArchive;
    expect($zip->open($tmp))->toBeTrue();
    $parts = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);
        $parts[$name] = (string) $zip->getFromIndex($i);
    }
    $zip->close();
    @unlink($tmp);

    return $parts;
}

function lwPfDoc(array $page): array
{
    return ['page' => $page, 'blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'Certificate of completion']]]]];
}

it('declares page.background and page.border in the JSON schema, with fine-print\'s field set', function (): void {
    $page = Agent::jsonSchema()['properties']['page'];
    $border = $page['properties']['border'];

    expect($page['properties']['background']['pattern'])->toBe('^#[0-9A-Fa-f]{6}$')
        ->and($border['additionalProperties'])->toBeFalse()
        ->and(array_keys($border['properties']))->toEqualCanonicalizing(['style', 'width', 'color', 'inset'])
        ->and($border['properties']['style']['enum'])->toBe(['single', 'double', 'dashed', 'dotted', 'none'])
        ->and($border['properties']['width']['exclusiveMinimum'])->toBe(0)
        ->and($border['properties']['inset']['minimum'])->toBe(0);
});

it('accepts a valid background and frame', function (): void {
    expect(Agent::validate(lwPfDoc(['background' => '#FDFAF3', 'border' => ['style' => 'double', 'width' => 6, 'color' => '#B08D57', 'inset' => 18]])))->toBe([]);
});

it('refuses malformed values, each at its own path', function (array $page, string $path): void {
    $paths = array_column(Agent::validate(lwPfDoc($page)), 'path');

    expect($paths)->toContain($path);
})->with([
    'background not hex' => [['background' => 'cream'], 'page.background'],
    'border not an object' => [['border' => 'thick'], 'page.border'],
    'unknown style' => [['border' => ['style' => 'groove']], 'page.border.style'],
    'zero width' => [['border' => ['width' => 0]], 'page.border.width'],
    'negative inset' => [['border' => ['inset' => -1]], 'page.border.inset'],
    'colour not hex' => [['border' => ['color' => 'gold']], 'page.border.color'],
    'no room inside the frame' => [['border' => ['inset' => 300, 'width' => 10]], 'page.border'],
]);

it('repairs an invalid background or frame away, and says so', function (): void {
    $r = Agent::validateAndRepair(lwPfDoc(['background' => 'cream', 'border' => ['style' => 'groove'], 'size' => 'a4']));

    expect($r['ok'])->toBeTrue()
        ->and($r['schema']['page'])->toBe(['size' => 'a4'])
        ->and(array_column($r['errors'], 'path'))->toContain('page.background', 'page.border');
});

it('writes the background as w:background and turns on displayBackgroundShape', function (): void {
    $parts = lwPfParts(lwPfDoc(['background' => '#fdfaf3']));

    expect($parts['word/document.xml'])->toContain('<w:background w:color="FDFAF3"/><w:body>')
        ->and($parts['word/settings.xml'] ?? '')->toContain('<w:displayBackgroundShape/>')
        ->and($parts['[Content_Types].xml'])->toContain('PartName="/word/settings.xml"')
        ->and($parts['word/_rels/document.xml.rels'])->toContain('relationships/settings" Target="settings.xml"');
});

it('writes the frame as w:pgBorders measured from the page, after w:pgMar', function (): void {
    $xml = lwPfParts(lwPfDoc(['border' => ['style' => 'single', 'width' => 2, 'color' => '#1F3A5F', 'inset' => 18]]))['word/document.xml'];

    $edge = 'w:val="single" w:sz="16" w:space="18" w:color="1F3A5F"/>';
    expect($xml)->toContain(
        '<w:pgBorders w:offsetFrom="page"><w:top '.$edge.'<w:left '.$edge.'<w:bottom '.$edge.'<w:right '.$edge.'</w:pgBorders>',
    )
        ->and(strpos($xml, '<w:pgBorders'))->toBeGreaterThan(strpos($xml, '<w:pgMar'));
});

it('fills fine-print\'s defaults: single, 0.5pt, black, 24pt from the edge', function (): void {
    $xml = lwPfParts(lwPfDoc(['border' => []]))['word/document.xml'];

    expect($xml)->toContain('<w:top w:val="single" w:sz="4" w:space="24" w:color="000000"/>');
});

it('draws a double frame as two rules of a third of the width each', function (): void {
    $xml = lwPfParts(lwPfDoc(['border' => ['style' => 'double', 'width' => 6]]))['word/document.xml'];

    // Word's double: two lines of w:sz with a gap of the same width, so the
    // whole frame is three times w:sz, which is fine-print's double exactly.
    expect($xml)->toContain('<w:top w:val="double" w:sz="16" w:space="24"');
});

it('measures from the text when the inset is past Word\'s 31pt page offset', function (): void {
    // 1in margins (72pt), inset 40, width 2: the frame's inside edge is 42pt
    // from the page, so 72 - 42 = 30pt from the text.
    $xml = lwPfParts(lwPfDoc(['border' => ['inset' => 40, 'width' => 2]]))['word/document.xml'];

    expect($xml)->toContain('<w:pgBorders w:offsetFrom="text"><w:top w:val="single" w:sz="16" w:space="30"');
});

it('writes nothing for style none, and nothing at all for a document without either key', function (): void {
    expect(lwPfParts(lwPfDoc(['border' => ['style' => 'none']]))['word/document.xml'])->not->toContain('pgBorders');

    $plain = lwPfParts(lwPfDoc([]));
    expect($plain)->not->toHaveKey('word/settings.xml')
        ->and($plain['word/document.xml'])->not->toContain('w:background')
        ->and($plain['word/document.xml'])->not->toContain('pgBorders');
});

it('reads the background and the frame back', function (): void {
    $page = ['background' => '#FDFAF3', 'border' => ['style' => 'double', 'width' => 6, 'color' => '#B08D57', 'inset' => 18]];
    $doc = Agent::read(Agent::toBytes(lwPfDoc($page)));

    expect($doc['page'])->toBe($page);
});

it('reads a text-measured frame back to the same inset', function (): void {
    $page = ['border' => ['style' => 'dashed', 'width' => 2, 'color' => '#1F3A5F', 'inset' => 40]];

    expect(Agent::read(Agent::toBytes(lwPfDoc($page)))['page'])->toBe($page);
});

/*
 * The oracle: LibreOffice, when it is installed, headless, with a short
 * throwaway profile (a long profile path crashes it on Windows). It converts
 * the .docx to .odt, and the ODT's page layout must carry the colour and the
 * frame, so the XML above is not just well-formed but understood.
 */
it('is understood by LibreOffice as a page colour and a page frame', function (): void {
    $soffice = null;
    foreach (['C:/Program Files/LibreOffice/program/soffice.com', '/usr/bin/soffice', '/usr/bin/libreoffice', '/Applications/LibreOffice.app/Contents/MacOS/soffice'] as $candidate) {
        if (is_file($candidate)) {
            $soffice = $candidate;

            break;
        }
    }
    if ($soffice === null) {
        $this->markTestSkipped('LibreOffice is not installed; the XML assertions above still run.');
    }

    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'lwpf'.bin2hex(random_bytes(3));
    mkdir($dir);
    $profile = $dir.DIRECTORY_SEPARATOR.'p';
    $in = $dir.DIRECTORY_SEPARATOR.'frame.docx';
    file_put_contents($in, Agent::toBytes(lwPfDoc([
        'background' => '#FDFAF3',
        'border' => ['style' => 'single', 'width' => 3, 'color' => '#B08D57', 'inset' => 18],
    ])));

    try {
        $cmd = escapeshellarg($soffice).' -env:UserInstallation=file:///'.str_replace('\\', '/', ltrim($profile, '/'))
            .' --headless --norestore --convert-to odt --outdir '.escapeshellarg($dir).' '.escapeshellarg($in).' 2>&1';
        exec($cmd, $output, $code);
        expect($code)->toBe(0, implode("\n", $output));

        $zip = new ZipArchive;
        expect($zip->open($dir.DIRECTORY_SEPARATOR.'frame.odt'))->toBeTrue();
        $styles = (string) $zip->getFromName('styles.xml');
        $zip->close();

        // The layout the body's master page uses, not merely the first one.
        expect(preg_match('/<style:master-page [^>]*style:page-layout-name="([^"]+)"/', $styles, $master))->toBe(1);
        expect(preg_match('/<style:page-layout style:name="'.preg_quote($master[1], '/').'"><style:page-layout-properties[^>]*>/', $styles, $m))->toBe(1);
        $props = strtolower($m[0]);
        // Measured 2026-10-27 with LibreOffice 26.2: the frame is the page
        // border, the inset is the page margin (18pt = 0.25in), and the
        // padding keeps the text where the margins put it (72 - 18 - 3 = 51pt).
        expect($props)->toContain('fo:background-color="#fdfaf3"')
            ->and($props)->toContain('fo:border="3pt solid #b08d57"')
            ->and($props)->toContain('fo:margin-top="0.25in"')
            ->and($props)->toContain('fo:padding="0.7083in"');
    } finally {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
})->group('local');
