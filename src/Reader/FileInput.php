<?php

declare(strict_types=1);

namespace LastWord\Reader;

use LastWord\Exceptions\ArchiveRefused;

/** @internal Bounded local input for ZIP and legacy document formats. */
final class FileInput
{
    private const MAX_BYTES = 67108864;

    public static function read(string $path): string
    {
        $windowsDrive = strlen($path) >= 3 && preg_match('/^[a-zA-Z]$/', $path[0]) === 1 && $path[1] === ':' && in_array($path[2], ['/', chr(92)], true);
        if (str_contains($path, "\0") || (preg_match('/^[a-zA-Z][a-zA-Z0-9+.-]*:/', $path) === 1 && ! $windowsDrive) || ! is_file($path)) {
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
            if ($stat['size'] > self::MAX_BYTES) {
                throw new ArchiveRefused('archive_limit');
            }
            $bytes = stream_get_contents($stream, self::MAX_BYTES + 1);
            if (! is_string($bytes)) {
                throw new ArchiveRefused('archive_io');
            }
            if (strlen($bytes) > self::MAX_BYTES) {
                throw new ArchiveRefused('archive_limit');
            }

            return $bytes;
        } finally {
            fclose($stream);
        }
    }
}
