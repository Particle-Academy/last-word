<?php

declare(strict_types=1);

use LastWord\Agent;
use LastWord\Exceptions\ImageRefused;
use LastWord\Exceptions\SchemaException;

/*
 * Named images (CC-fancy-0039): the HOST registers images with the writer --
 * `images: {logo: <bytes or data URL>, ...}`, with an optional size and alt each
 * -- and the model places one with {"type": "image", "src": "image:logo"},
 * resolved at write time, in the body and in header and footer parts alike, so
 * a letterhead can carry the logo without the model ever holding its bytes.
 *
 * tests/fixtures/named-images.json is shared with the Node package, byte for
 * byte: the same images, documents, guide text, schema text and refusal codes,
 * and the sha256 of every part each document writes. Both engines assert the
 * same hashes, so the two writers agree on the parts without either needing the
 * other installed. Regenerate it with tests/Support/build-named-images.php.
 */

const NAMED_IMAGES_FIXTURE_SHA256 = 'fdeebc4e1fca2c53d89a54bf4c98fbe95d1656cabc354d2a03803b44428679c6';

/** @return array<string, mixed> */
function lwNi(): array
{
    return json_decode((string) file_get_contents(__DIR__ . '/../fixtures/named-images.json'), true, 512, JSON_THROW_ON_ERROR);
}

/** @return array<string, mixed> */
function lwNiOptions(): array
{
    return ['images' => lwNi()['images']];
}

/** @return array<string, string> */
function lwNiParts(string $bytes): array
{
    $path = lwTemp('lwni', '.docx');
    file_put_contents($path, $bytes);
    $zip = new ZipArchive();
    expect($zip->open($path))->toBeTrue();
    $parts = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $parts[(string) $zip->getNameIndex($i)] = (string) $zip->getFromIndex($i);
    }
    $zip->close();
    @unlink($path);

    return $parts;
}

/** The refusal reason a registration is refused with, or null when it is taken. */
function lwNiRefusal(array $options): ?string
{
    try {
        Agent::toBytes(['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'x']]]]], $options);
    } catch (ImageRefused $e) {
        return $e->reason;
    }

    return null;
}

it('reads the shared fixture, and it is the shared one', function () {
    $bytes = str_replace("\r\n", "\n", (string) file_get_contents(__DIR__ . '/../fixtures/named-images.json'));

    expect(hash('sha256', $bytes))->toBe(NAMED_IMAGES_FIXTURE_SHA256);
});

it('writes every fixture case part for part as the shared hashes say', function (string $name) {
    $case = lwNi()['cases'][$name];
    $parts = lwNiParts(Agent::toBytes($case['doc'], lwNiOptions()));

    $names = array_keys($parts);
    sort($names);
    expect($names)->toBe($case['partNames']);
    foreach ($case['parts'] as $part => $sha) {
        expect(hash('sha256', $parts[$part]))->toBe($sha, "{$name}: {$part}");
    }
})->with(['body', 'letterhead', 'letterheadFirstPage', 'footnoteAndFooter']);

it('resolves a name to exactly what writing its data URL by hand writes', function () {
    $images = lwNi()['images'];
    $doc = lwNi()['cases']['letterhead']['doc'];
    $byHand = $doc;
    $byHand['page']['header']['blocks'][0] = ['type' => 'image', 'src' => $images['logo']['data'], 'widthPx' => 150, 'heightPx' => 50, 'alt' => 'Acme Ltd'];
    $byHand['blocks'][5] = ['type' => 'image', 'src' => $images['signature']['data'], 'widthPx' => 120, 'alt' => 'Signature of J. Smith'];

    expect(Agent::toBytes($doc, lwNiOptions()))->toBe(Agent::toBytes($byHand));
});

it('puts the logo in the header part, through the header part\'s own relationships', function () {
    $parts = lwNiParts(Agent::toBytes(lwNi()['cases']['letterhead']['doc'], lwNiOptions()));

    $header = $parts['word/header1.xml'];
    expect($header)->toContain('<w:drawing>')
        // 150 x 50 px at 9525 EMU a pixel: the registered size, not the 600 x 200 bytes.
        ->and($header)->toContain('<wp:extent cx="1428750" cy="476250"/>')
        ->and($header)->toContain('descr="Acme Ltd"')
        ->and($header)->toContain('r:embed="rId1"')
        ->and($header)->toContain('Acme Ltd')
        ->and($header)->toContain('hello@example.com')
        // The body renders before the bands, so the signature is image1 and the
        // logo image2; one counter across the package, so no name repeats.
        ->and($parts['word/_rels/header1.xml.rels'])->toContain('Target="media/image2.png"')
        ->and($parts['word/media/image2.png'])->toBe(base64_decode(substr(lwNi()['images']['logo']['data'], 22)))
        ->and($parts['word/footer1.xml'])->toContain(' PAGE ')
        ->and($parts['word/footer1.xml'])->toContain('Registered in England')
        ->and($parts['word/_rels/document.xml.rels'])->toContain('Target="media/image1.png"')
        ->and($parts['word/media/image1.png'])->toBe(base64_decode(substr(lwNi()['images']['signature']['data'], 22)));
});

it('lets a block set its own size and alt over the registered ones', function () {
    $parts = lwNiParts(Agent::toBytes(lwNi()['cases']['body']['doc'], lwNiOptions()));
    $xml = $parts['word/document.xml'];

    // seal at its own 64 x 48; logo at the block's 300 px wide, its height from the shape (100).
    expect($xml)->toContain('<wp:extent cx="609600" cy="457200"/>')
        ->and($xml)->toContain('<wp:extent cx="2857500" cy="952500"/>')
        ->and($xml)->toContain('descr="Acme Ltd, large"')
        ->and($xml)->toContain('descr="The same seal, as a data URL"');
});

it('refuses an unknown name with image_unknown, in the body and in a header', function (string $key) {
    $fixture = lwNi();
    $doc = $fixture['unknown'][$key];

    $errors = Agent::validate($doc, lwNiOptions());
    expect(array_column($errors, 'code'))->toBe([$fixture['unknown']['code']]);

    try {
        Agent::toBytes($doc, lwNiOptions());
        $this->fail('an unknown image name was written');
    } catch (SchemaException $e) {
        expect(array_column($e->errors, 'code'))->toBe(['image_unknown'])
            ->and($e->errors[0]['message'])->toContain('image:logo');
    }
})->with(['doc', 'bandDoc']);

it('refuses an image name when no images are registered at all', function () {
    $errors = Agent::validate(['blocks' => [['type' => 'image', 'src' => 'image:logo']]]);

    expect($errors)->toHaveCount(1)
        ->and($errors[0]['code'])->toBe('image_unknown')
        ->and($errors[0]['path'])->toBe('blocks[0].src');
});

it('validates and repairs a document that uses names, leaving the names in it', function () {
    $doc = lwNi()['cases']['letterhead']['doc'];
    $doc['blocks'][] = ['type' => 'heading', 'level' => 9, 'runs' => [['text' => 'Clamped']]];

    $result = Agent::validateAndRepair($doc, lwNiOptions());
    expect($result['ok'])->toBeTrue()
        ->and($result['schema']['page']['header']['blocks'][0]['src'])->toBe('image:logo');

    $unknown = Agent::validateAndRepair(lwNi()['unknown']['doc'], lwNiOptions());
    expect($unknown['ok'])->toBeFalse()
        ->and(array_column($unknown['errors'], 'code'))->toContain('image_unknown');
});

it('refuses each bad registration with its stable reason', function (array $row) {
    $options = ['images' => $row['images']] + (isset($row['imageLimits']) ? ['imageLimits' => $row['imageLimits']] : []);

    expect(lwNiRefusal($options))->toBe($row['reason'], $row['title']);
})->with(function () {
    foreach (lwNi()['refusals'] as $row) {
        yield $row['title'] => [$row];
    }
});

it('takes a registration exactly at its limits', function (array $row) {
    $options = ['images' => $row['images']] + (isset($row['imageLimits']) ? ['imageLimits' => $row['imageLimits']] : []);

    expect(lwNiRefusal($options))->toBeNull();
})->with(function () {
    foreach (lwNi()['accepted'] as $row) {
        yield $row['title'] => [$row];
    }
});

it('takes raw PNG and JPEG bytes as well as data URLs', function () {
    $images = lwNi()['images'];
    $logo = base64_decode(substr($images['logo']['data'], 22));
    $seal = base64_decode(substr($images['seal'], 23));
    $doc = ['blocks' => [['type' => 'image', 'src' => 'image:logo'], ['type' => 'image', 'src' => 'image:seal']]];

    expect(Agent::toBytes($doc, ['images' => ['logo' => ['data' => $logo, 'widthPx' => 150, 'heightPx' => 50, 'alt' => 'Acme Ltd'], 'seal' => $seal]]))
        ->toBe(Agent::toBytes($doc, lwNiOptions()));
});

it('has secure default limits: 5 MiB, 4096 px a side, 32 images', function () {
    // A PNG header claiming 4097 x 10: the size is read from IHDR, nothing is decoded.
    $wide = "\x89PNG\r\n\x1a\n\x00\x00\x00\x0DIHDR" . pack('NN', 4097, 10) . "\x08\x02\x00\x00\x00";
    $fine = "\x89PNG\r\n\x1a\n\x00\x00\x00\x0DIHDR" . pack('NN', 4096, 4096) . "\x08\x02\x00\x00\x00";
    $big = $fine . str_repeat("\0", 5 * 1024 * 1024);
    $many = [];
    for ($i = 0; $i < 33; $i++) {
        $many["i{$i}"] = $fine;
    }

    expect(lwNiRefusal(['images' => ['wide' => $wide]]))->toBe('image_dimensions_too_large')
        ->and(lwNiRefusal(['images' => ['fine' => $fine]]))->toBeNull()
        ->and(lwNiRefusal(['images' => ['big' => $big]]))->toBe('image_too_large')
        ->and(lwNiRefusal(['images' => $many]))->toBe('image_count_exceeded')
        ->and(lwNiRefusal(['images' => ['wide' => $wide], 'imageLimits' => ['maxWidthPx' => 5000]]))->toBeNull();
});

it('tells the model which names exist, in the guide, and says nothing new without them', function () {
    $fixture = lwNi();

    expect(Agent::guide(lwNiOptions()))->toBe(Agent::guide() . $fixture['guideSection'])
        ->and(Agent::guide([]))->toBe(Agent::guide())
        ->and(Agent::guide(['images' => []]))->toBe(Agent::guide());
});

it('lists the names in the schema, and leaves the schema alone without them', function () {
    $find = static function (array $schema): array {
        foreach ($schema['$defs']['block']['oneOf'] as $def) {
            if (($def['properties']['type']['const'] ?? null) === 'image') {
                return $def['properties']['src'];
            }
        }
        throw new RuntimeException('no image block in the schema');
    };
    $src = $find(Agent::jsonSchema(lwNiOptions()));

    expect($src['description'])->toBe(lwNi()['schemaSrcDescription'])
        ->and(preg_match('#' . $src['pattern'] . '#', 'image:logo'))->toBe(1)
        ->and(preg_match('#' . $src['pattern'] . '#', 'image:other'))->toBe(0)
        ->and(preg_match('#' . $src['pattern'] . '#', 'data:image/png;base64,AAAA'))->toBe(1)
        ->and(Agent::jsonSchema([]))->toBe(Agent::jsonSchema())
        ->and($find(Agent::jsonSchema()))->toBe(['type' => 'string', 'pattern' => '^data:image/(png|jpe?g);base64,', 'description' => 'PNG or JPEG data URL.']);
});

it('writes a document whose parts are all well-formed and fully wired', function (string $name) {
    $parts = lwNiParts(Agent::toBytes(lwNi()['cases'][$name]['doc'], lwNiOptions()));
    $types = new DOMDocument();
    expect($types->loadXML($parts['[Content_Types].xml']))->toBeTrue();
    $defaults = [];
    $overrides = [];
    foreach ($types->documentElement->childNodes as $node) {
        if ($node instanceof DOMElement) {
            $node->localName === 'Default'
                ? $defaults[strtolower($node->getAttribute('Extension'))] = true
                : $overrides[ltrim($node->getAttribute('PartName'), '/')] = true;
        }
    }

    foreach ($parts as $part => $bytes) {
        if (str_ends_with($part, '.xml') || str_ends_with($part, '.rels')) {
            $dom = new DOMDocument();
            expect(@$dom->loadXML($bytes))->toBeTrue("{$part} is not well-formed");
        }
        $ext = strtolower(pathinfo($part, PATHINFO_EXTENSION));
        expect(isset($overrides[$part]) || isset($defaults[$ext]))->toBeTrue("{$part} has no content type");
        if (str_ends_with($part, '.rels')) {
            // Every internal target resolves to a part in the package.
            $base = str_starts_with($part, '_rels/') ? '' : dirname(dirname($part)) . '/';
            preg_match_all('#<Relationship [^>]*Target="([^"]+)"(?! TargetMode="External")#', $bytes, $m);
            foreach ($m[1] as $target) {
                expect($parts)->toHaveKey($base . $target);
            }
        }
    }
    // And every r:embed in a part is a relationship of that part.
    foreach ($parts as $part => $bytes) {
        if (preg_match_all('#r:embed="([^"]+)"#', $bytes, $m) > 0) {
            $rels = $parts[dirname($part) . '/_rels/' . basename($part) . '.rels'];
            foreach ($m[1] as $rId) {
                expect($rels)->toContain('Id="' . $rId . '"');
            }
        }
    }
})->with(['body', 'letterhead', 'letterheadFirstPage', 'footnoteAndFooter']);

/*
 * The writer's own reading of its output proves nothing about Word; LibreOffice
 * opening it does. Converted to .odt, the letterhead's header must hold the logo
 * as an image, the footer its page field, and the body the signature.
 */
it('is opened by LibreOffice with the logo in the header and the signature in the body', function () {
    $soffice = null;
    foreach (['C:/Program Files/LibreOffice/program/soffice.com', '/usr/bin/soffice', '/usr/bin/libreoffice', '/Applications/LibreOffice.app/Contents/MacOS/soffice'] as $candidate) {
        if (is_file($candidate)) {
            $soffice = $candidate;

            break;
        }
    }
    if ($soffice === null) {
        $this->markTestSkipped('LibreOffice is not installed; the OOXML checks above still run.');
    }

    $dir = lwTempDir('lwni');
    $in = $dir . DIRECTORY_SEPARATOR . 'letter.docx';
    file_put_contents($in, Agent::toBytes(lwNi()['cases']['letterhead']['doc'], lwNiOptions()));
    $cmd = escapeshellarg($soffice) . ' -env:UserInstallation=file:///' . str_replace('\\', '/', ltrim($dir . '/p', '/'))
        . ' --headless --norestore --convert-to odt --outdir ' . escapeshellarg($dir) . ' ' . escapeshellarg($in) . ' 2>&1';
    exec($cmd, $output, $code);
    expect($code)->toBe(0, implode("\n", $output));

    $zip = new ZipArchive();
    expect($zip->open($dir . DIRECTORY_SEPARATOR . 'letter.odt'))->toBeTrue();
    $styles = (string) $zip->getFromName('styles.xml');
    $content = (string) $zip->getFromName('content.xml');
    $pictures = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entry = (string) $zip->getNameIndex($i);
        if (str_starts_with($entry, 'Pictures/')) {
            $pictures[] = (string) $zip->getFromIndex($i);
        }
    }
    $zip->close();

    expect(preg_match('#<style:header>.*?<draw:image .*?</style:header>#s', $styles))->toBe(1)
        ->and(preg_match('#<style:header>.*?Acme Ltd.*?</style:header>#s', $styles))->toBe(1)
        ->and(preg_match('#<style:footer>.*?<text:page-number.*?</style:footer>#s', $styles))->toBe(1)
        ->and($content)->toContain('<draw:image ')
        ->and($content)->toContain('Dear Ms Rivera,')
        ->and($pictures)->toContain(base64_decode(substr(lwNi()['images']['logo']['data'], 22)));
})->group('local');
