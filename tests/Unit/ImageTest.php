<?php

declare(strict_types=1);

use LastWord\Agent;
use LastWord\Helpers\ImageSize;

/*
 * Spec vector 7: dimension sniffing (PNG IHDR, JPEG SOF0) drives the
 * drawing extents when widthPx/heightPx are absent, and the 6.5in width
 * cap keeps aspect.
 */

/** A structurally valid 1000x400 red PNG (large enough to trip the width cap). */
function lwWidePngDataUrl(): string
{
    $chunk = function (string $type, string $data): string {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    };
    $row = "\x00" . str_repeat("\xff\x00\x00", 1000);
    $png = "\x89PNG\r\n\x1a\n"
        . $chunk('IHDR', pack('N', 1000) . pack('N', 400) . "\x08\x02\x00\x00\x00")
        . $chunk('IDAT', gzcompress(str_repeat($row, 400), 9))
        . $chunk('IEND', '');

    return 'data:image/png;base64,' . base64_encode($png);
}

/** A minimal JPEG whose SOF0 frame declares 4x3. */
function lwTinyJpegDataUrl(): string
{
    return 'data:image/jpeg;base64,/9j/4AAQSkZJRgABAQAAAQABAAD/wAALCAADAAQBEQD/2Q==';
}

it('sniffs PNG dimensions from the IHDR chunk', function () {
    $bytes = base64_decode(explode(',', lwRedPngDataUrl(), 2)[1], true);

    expect(ImageSize::sniff($bytes))->toBe(['width' => 2, 'height' => 2])
        ->and(ImageSize::png($bytes))->toBe(['width' => 2, 'height' => 2])
        ->and(ImageSize::jpeg($bytes))->toBeNull();
});

it('sniffs JPEG dimensions from the SOF0 frame header', function () {
    $bytes = base64_decode(explode(',', lwTinyJpegDataUrl(), 2)[1], true);

    expect(ImageSize::sniff($bytes))->toBe(['width' => 4, 'height' => 3])
        ->and(ImageSize::jpeg($bytes))->toBe(['width' => 4, 'height' => 3])
        ->and(ImageSize::png($bytes))->toBeNull();
});

it('returns null for unrecognised bytes', function () {
    expect(ImageSize::sniff('definitely not an image'))->toBeNull();
});

it('uses sniffed PNG dimensions for the extents when px are absent', function () {
    $doc = ['blocks' => [
        ['type' => 'image', 'src' => lwRedPngDataUrl()],
    ]];

    $readBack = Agent::read(Agent::toBytes($doc));

    expect($readBack['blocks'][0]['widthPx'])->toBe(2)
        ->and($readBack['blocks'][0]['heightPx'])->toBe(2);
});

it('uses sniffed JPEG dimensions for the extents when px are absent', function () {
    $doc = ['blocks' => [
        ['type' => 'image', 'src' => lwTinyJpegDataUrl()],
    ]];

    $readBack = Agent::read(Agent::toBytes($doc));

    expect($readBack['blocks'][0]['widthPx'])->toBe(4)
        ->and($readBack['blocks'][0]['heightPx'])->toBe(3)
        ->and($readBack['blocks'][0]['src'])->toBe(lwTinyJpegDataUrl());
});

it('caps images at 6.5in width while keeping aspect', function () {
    $doc = ['blocks' => [
        ['type' => 'image', 'src' => lwWidePngDataUrl()],
    ]];

    $readBack = Agent::read(Agent::toBytes($doc));

    // 6.5in at 96dpi = 624px; 400 * (624/1000) = 249.6 → 250
    expect($readBack['blocks'][0]['widthPx'])->toBe(624)
        ->and($readBack['blocks'][0]['heightPx'])->toBe(250);
});

it('respects explicit widthPx/heightPx over sniffed dimensions', function () {
    $doc = ['blocks' => [
        ['type' => 'image', 'src' => lwRedPngDataUrl(), 'widthPx' => 120, 'heightPx' => 60],
    ]];

    $readBack = Agent::read(Agent::toBytes($doc));

    expect($readBack['blocks'][0]['widthPx'])->toBe(120)
        ->and($readBack['blocks'][0]['heightPx'])->toBe(60);
});

it('keeps the sniffed aspect when only one dimension is given', function () {
    $doc = ['blocks' => [
        // wide png is 1000x400 → aspect 0.4; width 500 → height 200
        ['type' => 'image', 'src' => lwWidePngDataUrl(), 'widthPx' => 500],
    ]];

    $readBack = Agent::read(Agent::toBytes($doc));

    expect($readBack['blocks'][0]['widthPx'])->toBe(500)
        ->and($readBack['blocks'][0]['heightPx'])->toBe(200);
});
