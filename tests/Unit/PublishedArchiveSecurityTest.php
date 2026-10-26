<?php

declare(strict_types=1);

use LastWord\Agent;
use LastWord\Exceptions\ArchiveRefused;
use LastWord\Exceptions\TemplateException;
use LastWord\Reader\DocxReader;
use LastWord\Writer\DocxTemplate;

/** A small deterministic ZIP fixture builder, independent of production readers. */
function security_source_zip(array $entries, string $comment = ''): string
{
    $locals = '';
    $central = '';
    foreach ($entries as $entry) {
        $name = $entry['name'];
        $data = $entry['data'];
        $method = $entry['method'] ?? 0;
        $descriptor = $entry['descriptor'] ?? false;
        $flags = $entry['flags'] ?? ($descriptor ? 0x0808 : 0x0800);
        $compressed = $method === 8 ? gzdeflate($data) : $data;
        $crc = $entry['crc'] ?? crc32($data);
        $size = $entry['size'] ?? strlen($data);
        $offset = strlen($locals);
        $locals .= pack('VvvvvvVVVvv', 0x04034B50, 20, $flags, $method, 0, 33,
            $descriptor ? 0 : $crc, $descriptor ? 0 : strlen($compressed), $descriptor ? 0 : $size, strlen($name), 0).$name.$compressed;
        if ($descriptor) {
            $locals .= pack('VVVV', 0x08074B50, $crc, strlen($compressed), $size);
        }
        $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014B50, 20, 20, $flags, $method, 0, 33,
            $crc, strlen($compressed), $size, strlen($name), 0, 0, 0, 0, 0, $offset).$name;
    }

    return $locals.$central.pack('VvvvvVVv', 0x06054B50, 0, 0, count($entries), count($entries), strlen($central), strlen($locals), strlen($comment)).$comment;
}

function security_read_attack(string $attack, bool $odt = false): string
{
    $entries = [
        ['name' => 'word/document.xml', 'data' => '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>ok</w:t></w:r></w:p></w:body></w:document>'],
        ['name' => 'word/styles.xml', 'data' => '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"/>'],
    ];
    if ($odt) {
        $entries = [
            ['name' => 'mimetype', 'data' => 'application/vnd.oasis.opendocument.text'],
            ['name' => 'content.xml', 'data' => '<office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"><office:body><office:text/></office:body></office:document-content>'],
        ];
    }
    $entries[] = match ($attack) {
        'bomb' => ['name' => 'unknown/bomb.bin', 'data' => str_repeat('a', 2097152), 'method' => 8],
        'duplicate' => $entries[0],
        'crc' => ['name' => 'unknown/corrupt.bin', 'data' => 'opaque', 'crc' => 123],
        'path' => ['name' => '../outside.xml', 'data' => 'opaque'],
        default => ['name' => 'unknown/valid.bin', 'data' => 'opaque'],
    };
    $bytes = security_source_zip($entries);

    return $attack === 'truncated' ? substr($bytes, 0, -4) : $bytes;
}

function security_refused(callable $action, string $reason): void
{
    $caught = null;
    try {
        $action();
    } catch (Throwable $error) {
        $caught = $error;
    }
    expect($caught)->toBeInstanceOf('\LastWord\Exceptions\ArchiveRefused');
    expect($caught->reason)->toBe($reason);
}

it('rejects unsafe packages at the published read boundary', function (string $attack, string $reason) {
    $path = tempnam(sys_get_temp_dir(), 'sibling_archive_');
    file_put_contents($path, security_read_attack($attack));
    try {
        security_refused(fn () => Agent::read($path), $reason);
    } finally {
        unlink($path);
    }
})->with([['bomb', 'archive_limit'], ['duplicate', 'archive_duplicate'], ['crc', 'archive_corrupt'], ['truncated', 'archive_corrupt'], ['path', 'archive_path']]);

it('accepts the valid package control', function () {
    $bytes = security_read_attack('valid');
    expect(Agent::read($bytes)['blocks'][0]['runs'][0]['text'])->toBe('ok');
});

it('rejects unsafe ODT packages with a typed archive refusal', function (string $attack, string $reason) {
    security_refused(fn () => Agent::read(security_read_attack($attack, true)), $reason);
})->with([['bomb', 'archive_limit'], ['duplicate', 'archive_duplicate'], ['crc', 'archive_corrupt'], ['truncated', 'archive_corrupt'], ['path', 'archive_path']]);

it('retains the typed archive cause when refusing a template', function (string $attack, string $reason) {
    $caught = null;
    try {
        DocxTemplate::open(security_read_attack($attack));
    } catch (Throwable $error) {
        $caught = $error;
    }
    expect($caught)->toBeInstanceOf(TemplateException::class);
    expect($caught->getPrevious())->toBeInstanceOf(ArchiveRefused::class);
    expect($caught->getPrevious()->reason)->toBe($reason);
})->with([['bomb', 'archive_limit'], ['duplicate', 'archive_duplicate'], ['crc', 'archive_corrupt'], ['truncated', 'archive_corrupt'], ['path', 'archive_path']]);

it('bounds template paths before allocating their contents', function () {
    $path = tempnam(sys_get_temp_dir(), 'sibling_template_');
    $stream = fopen($path, 'r+b');
    ftruncate($stream, 67108865);
    fclose($stream);
    try {
        $caught = null;
        try {
            DocxTemplate::open($path);
        } catch (Throwable $error) {
            $caught = $error;
        }
        expect($caught)->toBeInstanceOf(TemplateException::class);
        expect($caught->getPrevious())->toBeInstanceOf(ArchiveRefused::class);
        expect($caught->getPrevious()->reason)->toBe('archive_limit');
    } finally {
        unlink($path);
    }
});

it('honours the host reader temporary directory instead of silently using the system directory', function () {
    $file = tempnam(sys_get_temp_dir(), 'sibling_not_a_directory_');
    try {
        security_refused(fn () => (new DocxReader($file))->read(security_read_attack('valid')), 'archive_io');
    } finally {
        unlink($file);
    }
});
