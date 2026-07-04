<?php

declare(strict_types=1);

use LastWord\Agent;

/*
 * Spec vector 8: toBytes is deterministic — identical bytes on every call,
 * fixed zip entry order, no timestamps in the XML content.
 */

it('produces identical bytes across repeated toBytes calls', function () {
    $doc = lwCanonical();

    $a = Agent::toBytes($doc);
    $b = Agent::toBytes($doc);

    expect(strlen($a))->toBeGreaterThan(0)
        ->and(hash('sha256', $b))->toBe(hash('sha256', $a))
        ->and($b)->toBe($a);
});

it('writes zip entries in the fixed documented order', function () {
    $bytes = Agent::toBytes(lwCanonical());

    $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lw-order-' . bin2hex(random_bytes(6)) . '.docx';
    file_put_contents($tmp, $bytes);

    try {
        $zip = new ZipArchive();
        expect($zip->open($tmp, ZipArchive::RDONLY))->toBeTrue();
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->close();
    } finally {
        @unlink($tmp);
    }

    expect($names)->toBe([
        '[Content_Types].xml',
        '_rels/.rels',
        'docProps/core.xml',
        'word/document.xml',
        'word/styles.xml',
        'word/numbering.xml',
        'word/_rels/document.xml.rels',
        'word/media/image1.png',
    ]);
});

it('emits no timestamps in the XML parts', function () {
    $bytes = Agent::toBytes(lwCanonical());

    $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lw-ts-' . bin2hex(random_bytes(6)) . '.docx';
    file_put_contents($tmp, $bytes);

    try {
        $zip = new ZipArchive();
        expect($zip->open($tmp, ZipArchive::RDONLY))->toBeTrue();
        $document = $zip->getFromName('word/document.xml');
        $zip->close();
    } finally {
        @unlink($tmp);
    }

    // No dcterms/date-like content anywhere in the document part.
    expect($document)->not->toMatch('/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/');
});
