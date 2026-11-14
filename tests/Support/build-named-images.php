<?php

declare(strict_types=1);

/*
 * Regenerates tests/fixtures/named-images.json, the fixture this package and
 * @particle-academy/last-word share for named images (CC-fancy-0039): images a
 * host registers with the writer, placed by the model as
 * {"type": "image", "src": "image:logo"}.
 *
 *   php tests/Support/build-named-images.php
 *
 * Everything in it is AUTHORED here -- the images, the documents, the guide
 * section and schema text both engines must produce, the refusals and their
 * codes -- except `parts`: the sha256 of every part each case writes, taken
 * from THIS engine, which is the reference for the pair. Run it only when the
 * writer is right. It writes the Node package's copy too when the envelope has
 * it checked out beside this one; both copies must be byte-identical, and each
 * package pins the file's hash.
 */

use LastWord\Agent;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

const BOILERPLATE = ['[Content_Types].xml', 'word/styles.xml', 'word/numbering.xml'];

// 600 x 200 PNG (palette), 64 x 48 JPEG, 300 x 100 PNG. Made once with GD.
$logo = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAlgAAADIAgMAAABJ8XclAAAACVBMVEX///8fOl+wjVdKUnqBAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAnklEQVR42u3aMREAMAzEsEAriZIsymL4IbkMMgIBcN2kU1NhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFh7WW93rCwsLCwsLCwsLCwsLCwsLCwsLCwsLCwsLCwsLCwsLCwsLCwelhGKSwsLCwsLCwsLCwsLCwsLCwsLCwsLCwsLCwsLCwsLCwsLCwsLCwsrKAPzELoDcsn8x8AAAAASUVORK5CYII=';
$seal = 'data:image/jpeg;base64,/9j/4AAQSkZJRgABAQEAYABgAAD//gA7Q1JFQVRPUjogZ2QtanBlZyB2MS4wICh1c2luZyBJSkcgSlBFRyB2ODApLCBxdWFsaXR5ID0gNzAK/9sAQwAKBwcIBwYKCAgICwoKCw4YEA4NDQ4dFRYRGCMfJSQiHyIhJis3LyYpNCkhIjBBMTQ5Oz4+PiUuRElDPEg3PT47/9sAQwEKCwsODQ4cEBAcOygiKDs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7/8AAEQgAMABAAwEiAAIRAQMRAf/EAB8AAAEFAQEBAQEBAAAAAAAAAAABAgMEBQYHCAkKC//EALUQAAIBAwMCBAMFBQQEAAABfQECAwAEEQUSITFBBhNRYQcicRQygZGhCCNCscEVUtHwJDNicoIJChYXGBkaJSYnKCkqNDU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6g4SFhoeIiYqSk5SVlpeYmZqio6Slpqeoqaqys7S1tre4ubrCw8TFxsfIycrS09TV1tfY2drh4uPk5ebn6Onq8fLz9PX29/j5+v/EAB8BAAMBAQEBAQEBAQEAAAAAAAABAgMEBQYHCAkKC//EALURAAIBAgQEAwQHBQQEAAECdwABAgMRBAUhMQYSQVEHYXETIjKBCBRCkaGxwQkjM1LwFWJy0QoWJDThJfEXGBkaJicoKSo1Njc4OTpDREVGR0hJSlNUVVZXWFlaY2RlZmdoaWpzdHV2d3h5eoKDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uLj5OXm5+jp6vLz9PX29/j5+v/aAAwDAQACEQMRAD8AvUU1mVFLuwVVGSScACuS1jxBJds8FqxS2xtJxgyf4D2/P0rzKFCdaVonZVqxpq7N+81ywsiyPNvkXqkY3HrjHoD7E1nS+LoVYCG0d1xyXcKc/QZrl6K9eGAox+LU4JYuo9tDpo/F0ZkAls2VO5WTcfywP51ftfEOnXJ2mUwsTgCUY/HPT9a4qiiWAovbQUcVUW+p6TRXE6Vrc+nMsbkyW2TmPuM9x/h06/WuyhmjuIVmhcPG4yGFeTiMNOi9du56FKtGotNzI8T3rW9itumQ1wSCR/dHX88j8M1yNdX4rtXls4rlTxAxDD2bHP5gfnXKV6+AUfYq3zPPxV/aahRRRXacoUUUUAFdL4UvWYS2LZIUeYh9B0I/UfrXNV0XhO1fzZrwnCBfLHueCfywPzrkxij7B3OjDX9qrHSSxpNE8UgyjqVYeoNcXq2jTabKWUNJbnlZMfd9m9D/ADrt6ayq6lHUMrDBBGQRXj4fESoy02PRrUVUWu55xRXYXnhiyuCzwlrdz2Xlc59P8CKzpfCVyrAQ3MTrjkuCpz9BmvXhjaMutjz5YaoulzAordj8J3ZkAluIVTuVyx/LA/nV+18K2kR3XErzkHoPkUj+f605Y2hHrcUcNVfQ5/TtLuNSmCxqVjz80pHyr/ifau3tLWOztkt4QdiDAyck+pp8cUcMYjiRUQdFUYA/Cn15GJxMqztsj0KNBU15n//Z';
$signature = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAASwAAABkAgMAAABBb+VTAAAACVBMVEX///8UFBRaWloGzL4IAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAQ0lEQVRYw+3WsQ0AIAgAQWrm0yWdkgVMiLbcD3D1x7624icWi8VisVgsFqux8rzFYrFYLBaLNdHymCwWi8VisVhzrAKv4MRjIatEtAAAAABJRU5ErkJggg==';
$sealBase64 = substr($seal, strlen('data:image/jpeg;base64,'));

$images = [
    'logo' => ['data' => $logo, 'widthPx' => 150, 'heightPx' => 50, 'alt' => 'Acme Ltd'],
    'seal' => $seal,
    'signature' => ['data' => $signature, 'widthPx' => 120, 'alt' => 'Signature of J. Smith'],
];

$p = static fn (string $text, array $extra = []): array => ['type' => 'paragraph'] + $extra + ['runs' => [['text' => $text]]];

$contact = [
    ['type' => 'paragraph', 'align' => 'right', 'runs' => [['text' => 'Acme Ltd', 'bold' => true, 'size' => 12]]],
    ['type' => 'paragraph', 'align' => 'right', 'runs' => [['text' => "1 Example Street, Springfield\nhello@example.com | +1 555 0100", 'size' => 9, 'color' => '#6B7280']]],
];

$letter = [
    $p('14 November 2026'),
    $p("Northwind Traders\n2 Sample Road\nRiverton"),
    $p('Dear Ms Rivera,'),
    $p('Thank you for your order of 3 November. This letter confirms the delivery dates we agreed and the terms that apply to them.'),
    $p('Yours sincerely,'),
    ['type' => 'image', 'src' => 'image:signature'],
    $p('J. Smith, Director'),
];

$cases = [
    // The registered size and alt, a block's own size and alt winning, a
    // plain data URL beside a named one, and a named image inside a quote.
    'body' => ['doc' => [
        'title' => 'Named images in the body',
        'blocks' => [
            ['type' => 'heading', 'level' => 1, 'runs' => [['text' => 'Seal of approval']]],
            ['type' => 'image', 'src' => 'image:seal'],
            ['type' => 'image', 'src' => 'image:logo', 'widthPx' => 300, 'alt' => 'Acme Ltd, large'],
            ['type' => 'image', 'src' => $seal, 'alt' => 'The same seal, as a data URL'],
            ['type' => 'quote', 'blocks' => [
                ['type' => 'image', 'src' => 'image:logo'],
                $p('Quoted under the logo.'),
            ]],
        ],
    ]],

    // The letterhead: the logo, name and contact block in the header, a
    // footer with the company line and page numbers, on every page.
    'letterhead' => ['doc' => [
        'title' => 'Letter to Northwind Traders',
        'meta' => ['author' => 'J. Smith'],
        'page' => [
            'margins' => ['top' => 126, 'header' => 30],
            'header' => ['blocks' => array_merge([['type' => 'image', 'src' => 'image:logo']], $contact)],
            'footer' => ['blocks' => [
                ['type' => 'paragraph', 'align' => 'center', 'runs' => [['text' => 'Acme Ltd | Registered in England 01234567 | Page {page} of {pages}', 'size' => 8, 'color' => '#6B7280']]],
            ]],
        ],
        'blocks' => $letter,
    ]],

    // The letterhead on the first page only, a plain running header and a
    // footer after it.
    'letterheadFirstPage' => ['doc' => [
        'title' => 'Letter, first-page letterhead',
        'page' => [
            'firstHeader' => ['blocks' => array_merge([['type' => 'image', 'src' => 'image:logo']], $contact)],
            'header' => ['right' => 'Acme Ltd | continued'],
            'footer' => ['center' => 'Page {page} of {pages}'],
            'firstFooter' => ['blocks' => [
                ['type' => 'paragraph', 'align' => 'center', 'runs' => [['text' => 'Acme Ltd | 1 Example Street, Springfield', 'size' => 8]]],
            ]],
        ],
        'blocks' => $letter,
    ]],

    // A named image in a footnote, and in a footer, in one document.
    'footnoteAndFooter' => ['doc' => [
        'title' => 'Footnote and footer',
        'page' => ['footer' => ['blocks' => [
            ['type' => 'image', 'src' => 'image:seal', 'widthPx' => 24],
        ]]],
        'blocks' => [
            ['type' => 'paragraph', 'runs' => [
                ['text' => 'Certified'],
                ['text' => '', 'footnote' => ['blocks' => [['type' => 'image', 'src' => 'image:seal', 'alt' => 'Seal in a note']]]],
                ['text' => ' by the board.'],
            ]],
        ],
    ]],
];

foreach ($cases as $name => $case) {
    $parts = partsOf(Agent::toBytes($case['doc'], ['images' => $images]));
    $hashes = [];
    foreach ($parts as $part => $bytes) {
        if (in_array($part, BOILERPLATE, true)) {
            continue;
        }
        $hashes[$part] = hash('sha256', $bytes);
    }
    ksort($hashes);
    $names = array_keys($parts);
    sort($names);
    $cases[$name]['partNames'] = $names;
    $cases[$name]['parts'] = $hashes;
}

$fixture = [
    '_comment' => [
        'Shared by particle-academy/last-word (PHP) and @particle-academy/last-word (Node): named images (CC-fancy-0039).',
        'Generated by last-word tests/Support/build-named-images.php. Edit the generator, not this file; both copies must be byte-identical and each package pins its sha256.',
        'images is the write option; parts holds the sha256 of every part a case writes except [Content_Types].xml, word/styles.xml and word/numbering.xml, whose differences between the engines are recorded in the Node parity suite. PHP is the reference.',
    ],
    'images' => $images,
    'guideSection' => "\n## Images you can place\n\n"
        . 'The host registered these images for this document. Place one with `{"type": "image", "src": "image:NAME"}`, '
        . 'in the body or in a header or footer that holds `blocks` (a letterhead puts its logo in `page.header` or `page.firstHeader`). '
        . 'Use only these names: never invent an image, and never write out a data URL for one of them. '
        . 'Each is placed at the size and with the alt text listed; set `widthPx` or `heightPx` on the block to resize it '
        . "(the other follows the image's shape), or `alt` to describe it differently. "
        . "Any other `image:` name is refused with `image_unknown`.\n\n"
        . "- `image:logo`: PNG, 600 x 200 px; placed at 150 x 50 px; alt \"Acme Ltd\"\n"
        . "- `image:seal`: JPEG, 64 x 48 px\n"
        . "- `image:signature`: PNG, 300 x 100 px; placed 120 px wide; alt \"Signature of J. Smith\"\n",
    'schemaSrcDescription' => 'PNG or JPEG data URL, or a registered image by name: image:logo, image:seal, image:signature. '
        . 'Use a registered name rather than inventing an image; any other image: name is refused (image_unknown).',
    'cases' => $cases,
    'unknown' => [
        'doc' => ['blocks' => [['type' => 'image', 'src' => 'image:watermark']]],
        'bandDoc' => ['page' => ['header' => ['blocks' => [['type' => 'image', 'src' => 'image:crest']]]], 'blocks' => []],
        'code' => 'image_unknown',
    ],
    // Registrations the writer refuses, each with exactly one fault, and the
    // stable code it is refused with.
    'refusals' => [
        ['title' => 'images is not an object of name => image', 'images' => 'logo', 'reason' => 'image_option_invalid'],
        ['title' => 'a name must start with a letter', 'images' => ['1logo' => $seal], 'reason' => 'image_name_invalid'],
        ['title' => 'a name holds letters, digits, - and _ only', 'images' => ['my logo' => $seal], 'reason' => 'image_name_invalid'],
        ['title' => 'a name is at most 64 characters', 'images' => [str_repeat('a', 65) => $seal], 'reason' => 'image_name_invalid'],
        ['title' => 'an image is a string or an object', 'images' => ['logo' => 5], 'reason' => 'image_data_invalid'],
        ['title' => 'an object needs data', 'images' => ['logo' => ['alt' => 'Acme']], 'reason' => 'image_data_invalid'],
        ['title' => 'an object takes data, widthPx, heightPx and alt only', 'images' => ['logo' => ['data' => $seal, 'href' => 'https://example.com']], 'reason' => 'image_option_invalid'],
        ['title' => 'widthPx is a positive integer', 'images' => ['logo' => ['data' => $seal, 'widthPx' => 0]], 'reason' => 'image_option_invalid'],
        ['title' => 'heightPx is an integer', 'images' => ['logo' => ['data' => $seal, 'heightPx' => 12.5]], 'reason' => 'image_option_invalid'],
        ['title' => 'alt is text', 'images' => ['logo' => ['data' => $seal, 'alt' => 7]], 'reason' => 'image_option_invalid'],
        ['title' => 'GIF is not taken', 'images' => ['logo' => 'data:image/gif;base64,R0lGODlhAQABAAAAADs='], 'reason' => 'image_type_unsupported'],
        ['title' => 'a data URL must be base64', 'images' => ['logo' => 'data:image/png,plain'], 'reason' => 'image_type_unsupported'],
        ['title' => 'not a data URL, and not PNG or JPEG bytes', 'images' => ['logo' => 'https://example.com/logo.png'], 'reason' => 'image_type_unsupported'],
        ['title' => 'JPEG bytes declared as PNG', 'images' => ['logo' => 'data:image/png;base64,' . $sealBase64], 'reason' => 'image_type_unsupported'],
        ['title' => 'base64 that is not base64', 'images' => ['logo' => 'data:image/png;base64,!!!!'], 'reason' => 'image_data_invalid'],
        ['title' => 'an empty data URL', 'images' => ['logo' => 'data:image/png;base64,'], 'reason' => 'image_data_invalid'],
        ['title' => 'a PNG whose size cannot be read', 'images' => ['logo' => 'data:image/png;base64,iVBORw0KGgoAAAAAAAAAAAAAAAAAAAAA'], 'reason' => 'image_dimensions_unreadable'],
        ['title' => 'over maxBytes', 'images' => ['seal' => $seal], 'imageLimits' => ['maxBytes' => 1000], 'reason' => 'image_too_large'],
        ['title' => 'wider than maxWidthPx', 'images' => ['logo' => $logo], 'imageLimits' => ['maxWidthPx' => 599], 'reason' => 'image_dimensions_too_large'],
        ['title' => 'taller than maxHeightPx', 'images' => ['logo' => $logo], 'imageLimits' => ['maxHeightPx' => 199], 'reason' => 'image_dimensions_too_large'],
        ['title' => 'more than maxImages', 'images' => ['logo' => $logo, 'seal' => $seal], 'imageLimits' => ['maxImages' => 1], 'reason' => 'image_count_exceeded'],
        ['title' => 'a limit is a positive integer', 'images' => ['seal' => $seal], 'imageLimits' => ['maxBytes' => 0], 'reason' => 'image_limits_invalid'],
        ['title' => 'an unknown limit', 'images' => ['seal' => $seal], 'imageLimits' => ['maxArea' => 10], 'reason' => 'image_limits_invalid'],
    ],
    // Registrations at exactly the limits are taken.
    'accepted' => [
        ['title' => 'exactly maxBytes, maxWidthPx and maxHeightPx', 'images' => ['logo' => $logo], 'imageLimits' => ['maxBytes' => 257, 'maxWidthPx' => 600, 'maxHeightPx' => 200, 'maxImages' => 1]],
        ['title' => 'no images at all', 'images' => []],
    ],
];

$json = json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
$targets = [dirname(__DIR__) . '/fixtures/named-images.json'];
$node = dirname(__DIR__, 3) . '/last-word-js/test/fixtures';
if (is_dir($node)) {
    $targets[] = $node . '/named-images.json';
}
foreach ($targets as $target) {
    file_put_contents($target, $json);
    fwrite(STDOUT, "wrote {$target}\n");
}


/** @return array<string, string> */
function partsOf(string $bytes): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'lwni');
    file_put_contents($tmp, $bytes);
    $zip = new ZipArchive();
    $zip->open($tmp);
    $parts = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $parts[(string) $zip->getNameIndex($i)] = (string) $zip->getFromIndex($i);
    }
    $zip->close();
    @unlink($tmp);

    return $parts;
}
