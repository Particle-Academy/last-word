<?php

declare(strict_types=1);

namespace LastWord\Archive;

use LastWord\Exceptions\ArchiveRefused;
use ZipArchive;

/** @internal Immutable, bounded reader input; retains opaque bytes and never extracts files. */
final class SourceArchive
{
    private const DEFAULTS = [
        'maxArchiveBytes' => 67108864, 'maxEntries' => 16384, 'maxPartBytes' => 67108864,
        'maxExpandedBytes' => 268435456, 'maxCompressionRatio' => 1000,
    ];

    private function __construct(private readonly string $original, private readonly array $parts) {}

    public static function fromPath(string $path, array $limits = []): self
    {
        $limits = self::limits($limits);
        $windowsDrive = strlen($path) >= 3 && preg_match('/^[a-zA-Z]$/', $path[0]) === 1 && $path[1] === ':' && in_array($path[2], ['/', chr(92)], true);
        if (str_contains($path, "\0") || (preg_match('/^[a-zA-Z][a-zA-Z0-9+.-]*:/', $path) === 1 && ! $windowsDrive)) {
            throw new ArchiveRefused('archive_path');
        }
        if (! is_file($path)) {
            throw new ArchiveRefused('archive_path');
        }
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            throw new ArchiveRefused('archive_io');
        }
        try {
            $stat = fstat($stream);
            if ($stat === false || ($stat['mode'] & 0170000) !== 0100000) {
                throw new ArchiveRefused('archive_path');
            }
            if ($stat['size'] > $limits['maxArchiveBytes']) {
                throw new ArchiveRefused('archive_limit');
            }
            $bytes = stream_get_contents($stream, $limits['maxArchiveBytes'] + 1);
            if (! is_string($bytes)) {
                throw new ArchiveRefused('archive_io');
            }

            return self::open($bytes, $limits);
        } finally {
            fclose($stream);
        }
    }

    public static function open(string $bytes, array $limits = [], ?string $tempDir = null): self
    {
        $limits = self::limits($limits);
        if (strlen($bytes) > $limits['maxArchiveBytes']) {
            throw new ArchiveRefused('archive_limit');
        }
        $records = self::index($bytes, $limits);

        return self::decode($bytes, $records, $tempDir);
    }

    private static function limits(array $limits): array
    {
        foreach ($limits as $key => $value) {
            if (! array_key_exists($key, self::DEFAULTS) || ! is_int($value) || $value < 1) {
                throw new ArchiveRefused('archive_limits');
            }
        }

        return $limits + self::DEFAULTS;
    }

    private static function decode(string $bytes, array $records, ?string $tempDir): self
    {
        $directory = $tempDir ?? sys_get_temp_dir();
        if (! is_dir($directory) || ! is_writable($directory)) {
            throw new ArchiveRefused('archive_io');
        }
        $path = tempnam($directory, 'office_source_');
        if ($path === false) {
            throw new ArchiveRefused('archive_io');
        }
        $zip = new ZipArchive;
        $opened = false;
        try {
            if (file_put_contents($path, $bytes) !== strlen($bytes)) {
                throw new ArchiveRefused('archive_io');
            }
            if (@$zip->open($path, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
                throw new ArchiveRefused('archive_corrupt');
            }
            $opened = true;
            if ($zip->numFiles !== count($records)) {
                throw new ArchiveRefused('archive_corrupt');
            }
            $parts = [];
            foreach ($records as $i => $record) {
                // The extra byte detects forged sizes without an unbounded native allocation.
                $part = @$zip->getFromIndex($i, $record['size'] + 1);
                if (! is_string($part) || strlen($part) !== $record['size'] || crc32($part) !== $record['crc']) {
                    throw new ArchiveRefused('archive_corrupt');
                }
                $parts[$record['name']] = $part;
            }

            return new self($bytes, $parts);
        } finally {
            if ($opened) {
                $zip->close();
            }
            unlink($path);
        }
    }

    public function names(): array
    {
        return array_map(static fn ($name): string => (string) $name, array_keys($this->parts));
    }

    public function read(string $name): ?string
    {
        return $this->parts[$name] ?? null;
    }

    public function toBytes(): string
    {
        return $this->original;
    }

    private static function index(string $bytes, array $limits): array
    {
        $length = strlen($bytes);
        $end = null;
        for ($at = $length - 22; $at >= max(0, $length - 65557); $at--) {
            if (substr($bytes, $at, 4) === "PK\x05\x06" && $at + 22 + self::u16($bytes, $at + 20) === $length
                && self::u32($bytes, $at + 16) + self::u32($bytes, $at + 12) === $at) {
                $end = $at;
                break;
            }
        }
        if ($end === null) {
            throw new ArchiveRefused('archive_corrupt');
        }
        $count = self::u16($bytes, $end + 10);
        if (self::u16($bytes, $end + 4) !== 0 || self::u16($bytes, $end + 6) !== 0
            || self::u16($bytes, $end + 8) !== $count || $count === 65535) {
            throw new ArchiveRefused('archive_unsupported');
        }
        if ($count > $limits['maxEntries']) {
            throw new ArchiveRefused('archive_limit');
        }
        $at = self::u32($bytes, $end + 16);
        if ($end - $at < $count * 46) {
            throw new ArchiveRefused('archive_corrupt');
        }
        $records = $seen = $regions = [];
        $expanded = 0;
        for ($i = 0; $i < $count; $i++) {
            self::bounds($bytes, $at, 46, $end);
            if (self::u32($bytes, $at) !== 0x02014B50) {
                throw new ArchiveRefused('archive_corrupt');
            }
            $flags = self::u16($bytes, $at + 8);
            $method = self::u16($bytes, $at + 10);
            $crc = self::u32($bytes, $at + 16);
            $compressed = self::u32($bytes, $at + 20);
            $size = self::u32($bytes, $at + 24);
            $nameLength = self::u16($bytes, $at + 28);
            $extraLength = self::u16($bytes, $at + 30);
            $commentLength = self::u16($bytes, $at + 32);
            $local = self::u32($bytes, $at + 42);
            if (($flags & ~0x080E) !== 0 || ! in_array($method, [0, 8], true)
                || ($method === 0 && ($flags & 6) !== 0) || self::u16($bytes, $at + 34) !== 0
                || in_array(0xFFFFFFFF, [$compressed, $size, $local], true) || self::u16($bytes, $at + 6) > 20) {
                throw new ArchiveRefused('archive_unsupported');
            }
            self::bounds($bytes, $at + 46, $nameLength + $extraLength + $commentLength, $end);
            $name = substr($bytes, $at + 46, $nameLength);
            self::name($name, $flags);
            if (isset($seen[$name])) {
                throw new ArchiveRefused('archive_duplicate');
            }
            $seen[$name] = true;
            $expanded += $size;
            if ($size > $limits['maxPartBytes'] || $expanded > $limits['maxExpandedBytes']
                || $size > max(1, $compressed) * $limits['maxCompressionRatio']) {
                throw new ArchiveRefused('archive_limit');
            }
            if ($method === 0 && $compressed !== $size) {
                throw new ArchiveRefused('archive_corrupt');
            }
            self::extra($bytes, $at + 46 + $nameLength, $extraLength);
            self::bounds($bytes, $local, 30, self::u32($bytes, $end + 16));
            if (self::u32($bytes, $local) !== 0x04034B50 || self::u16($bytes, $local + 4) !== self::u16($bytes, $at + 6) || self::u16($bytes, $local + 6) !== $flags
                || self::u16($bytes, $local + 8) !== $method) {
                throw new ArchiveRefused('archive_corrupt');
            }
            $localName = self::u16($bytes, $local + 26);
            $localExtra = self::u16($bytes, $local + 28);
            $start = $local + 30 + $localName + $localExtra;
            self::bounds($bytes, $local + 30, $localName + $localExtra + $compressed, self::u32($bytes, $end + 16));
            if (substr($bytes, $local + 30, $localName) !== $name) {
                throw new ArchiveRefused('archive_corrupt');
            }
            self::extra($bytes, $local + 30 + $localName, $localExtra);
            foreach ([14 => $crc, 18 => $compressed, 22 => $size] as $offset => $expected) {
                $value = self::u32($bytes, $local + $offset);
                if ($value !== $expected && ! (($flags & 8) !== 0 && $value === 0)) {
                    throw new ArchiveRefused('archive_corrupt');
                }
            }
            $finish = $start + $compressed;
            if (($flags & 8) !== 0) {
                $finish = self::descriptor($bytes, $finish, self::u32($bytes, $end + 16), $crc, $compressed, $size);
            }
            $records[] = ['name' => $name, 'size' => $size, 'crc' => $crc];
            $regions[] = [$local, $finish];
            $at += 46 + $nameLength + $extraLength + $commentLength;
        }
        if ($at !== $end) {
            throw new ArchiveRefused('archive_corrupt');
        }
        usort($regions, static fn ($a, $b): int => $a[0] <=> $b[0]);
        $previous = 0;
        foreach ($regions as [$start, $finish]) {
            if ($start < $previous) {
                throw new ArchiveRefused('archive_corrupt');
            }
            $previous = $finish;
        }

        return $records;
    }

    private static function name(string $name, int $flags): void
    {
        if ($name === '' || str_starts_with($name, '/') || str_contains($name, chr(92)) || str_contains($name, ':') || preg_match('//u', $name) !== 1) {
            throw new ArchiveRefused('archive_path');
        }
        for ($i = 0; $i < strlen($name); $i++) {
            $byte = ord($name[$i]);
            if ($byte < 32 || $byte === 127) {
                throw new ArchiveRefused('archive_path');
            }
            if ($byte > 127 && ($flags & 0x0800) === 0) {
                throw new ArchiveRefused('archive_unsupported');
            }
        }
        $parts = explode('/', str_ends_with($name, '/') ? substr($name, 0, -1) : $name);
        foreach ($parts as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                throw new ArchiveRefused('archive_path');
            }
        }
    }

    private static function extra(string $bytes, int $start, int $length): void
    {
        $end = $start + $length;
        while ($start < $end) {
            self::bounds($bytes, $start, 4, $end);
            $id = self::u16($bytes, $start);
            $size = self::u16($bytes, $start + 2);
            self::bounds($bytes, $start + 4, $size, $end);
            if ($id === 1 || $id === 0x9901) {
                throw new ArchiveRefused('archive_unsupported');
            }
            $start += 4 + $size;
        }
    }

    private static function descriptor(string $bytes, int $at, int $end, int $crc, int $compressed, int $size): int
    {
        foreach ([0, 4] as $prefix) {
            if ($at + $prefix + 12 <= $end && ($prefix === 0 || self::u32($bytes, $at) === 0x08074B50)
                && self::u32($bytes, $at + $prefix) === $crc && self::u32($bytes, $at + $prefix + 4) === $compressed
                && self::u32($bytes, $at + $prefix + 8) === $size) {
                return $at + $prefix + 12;
            }
        }
        throw new ArchiveRefused('archive_corrupt');
    }

    private static function bounds(string $bytes, int $at, int $size, ?int $end = null): void
    {
        if ($at < 0 || $size < 0 || $at + $size > ($end ?? strlen($bytes)) || $at + $size > strlen($bytes)) {
            throw new ArchiveRefused('archive_corrupt');
        }
    }

    private static function u16(string $bytes, int $at): int
    {
        self::bounds($bytes, $at, 2);

        return unpack('v', substr($bytes, $at, 2))[1];
    }

    private static function u32(string $bytes, int $at): int
    {
        self::bounds($bytes, $at, 4);

        return unpack('V', substr($bytes, $at, 4))[1];
    }
}
