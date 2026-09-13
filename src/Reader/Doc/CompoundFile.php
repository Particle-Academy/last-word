<?php

declare(strict_types=1);

namespace LastWord\Reader\Doc;

use RuntimeException;

/**
 * A read-only Compound File Binary (MS-CFB) container: the "OLE2" file a Word
 * 97-2003 `.doc` is stored in, along with `.xls`, `.ppt`, `.msg` and others.
 *
 * It exposes the streams that sit directly under the root storage, which is all
 * a `.doc` needs (`WordDocument`, `0Table` / `1Table`). Nested storages such as
 * `ObjectPool` hold embedded objects, and an embedded Word document carries its
 * own `WordDocument` stream there. Walking only the root's children is what keeps
 * that one from being mistaken for the document itself.
 *
 * ## Hostile input
 *
 * An upload is untrusted, and every structure here is an offset or a chain that
 * a crafted file can point anywhere. So:
 *
 * - every read is bounds-checked against the file, and a short read is an error,
 *   never a silently truncated stream;
 * - a sector chain is followed at most once per sector, so a FAT loop fails
 *   instead of spinning forever;
 * - a stream's declared size is capped by what its chain can actually hold, so a
 *   size field of four gigabytes cannot make the reader allocate four gigabytes,
 *   and a stream over 256 MB is refused outright;
 * - the allocation table may not list more sectors than the file holds, so a
 *   DIFAT naming the same sector a million times cannot inflate it;
 * - the directory tree walk tracks visited entries for the same reason.
 *
 * Nothing here is recovered from: a damaged container is refused with a message
 * naming what was wrong.
 */
final class CompoundFile
{
    public const SIGNATURE = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";

    private const FREESECT = 0xFFFFFFFF;

    private const ENDOFCHAIN = 0xFFFFFFFE;

    private const NOSTREAM = 0xFFFFFFFF;

    /** No stream a document reader needs is larger than this; a bigger size field is refused. */
    private const MAX_STREAM_BYTES = 256 * 1024 * 1024;

    /** @var list<int> */
    private array $fat = [];

    /** @var list<int> */
    private array $miniFat = [];

    private int $sectorSize;

    private int $miniSectorSize;

    private int $miniStreamCutoff;

    private string $miniStream = '';

    /** @var array<string, array{start: int, size: int}> root-level streams by name */
    private array $streams = [];

    private function __construct(private readonly string $bytes)
    {
    }

    public static function fromBytes(string $bytes): self
    {
        $file = new self($bytes);
        $file->parse();

        return $file;
    }

    /** @return list<string> the names of the streams directly under the root storage */
    public function streamNames(): array
    {
        return array_keys($this->streams);
    }

    public function hasStream(string $name): bool
    {
        return isset($this->streams[$name]);
    }

    /** The whole stream, or null when the root storage has no stream by that name. */
    public function stream(string $name): ?string
    {
        if (! isset($this->streams[$name])) {
            return null;
        }

        ['start' => $start, 'size' => $size] = $this->streams[$name];

        return $size < $this->miniStreamCutoff
            ? $this->readChain($this->miniStream, $this->miniFat, $start, $this->miniSectorSize, $size, 'mini stream', 0)
            : $this->readChain($this->bytes, $this->fat, $start, $this->sectorSize, $size, "stream {$name}", $this->sectorSize);
    }

    private function parse(): void
    {
        if (strlen($this->bytes) < 512 || ! str_starts_with($this->bytes, self::SIGNATURE)) {
            throw new RuntimeException('Not a Compound File Binary document: the header is missing or truncated.');
        }
        if (self::u16($this->bytes, 28) !== 0xFFFE) {
            throw new RuntimeException('Compound File Binary header has an invalid byte-order mark.');
        }

        $major = self::u16($this->bytes, 26);
        $sectorShift = self::u16($this->bytes, 30);
        $miniShift = self::u16($this->bytes, 32);
        if (! (($major === 3 && $sectorShift === 9) || ($major === 4 && $sectorShift === 12)) || $miniShift !== 6) {
            throw new RuntimeException('Compound File Binary header declares an unsupported sector size.');
        }
        $this->sectorSize = 1 << $sectorShift;
        $this->miniSectorSize = 1 << $miniShift;
        $this->miniStreamCutoff = self::u32($this->bytes, 56);
        if ($this->miniStreamCutoff !== 4096) {
            throw new RuntimeException('Compound File Binary header declares an invalid mini stream cutoff.');
        }

        $this->fat = $this->readFat();

        $directory = $this->readChain($this->bytes, $this->fat, self::u32($this->bytes, 48), $this->sectorSize, null, 'directory', $this->sectorSize);
        $entries = str_split($directory, 128);
        if ($entries === [] || strlen($entries[0]) < 128) {
            throw new RuntimeException('Compound File Binary directory is empty.');
        }

        $root = $entries[0];
        if (ord($root[66]) !== 5) {
            throw new RuntimeException('Compound File Binary directory does not start with the root storage.');
        }

        $miniFatStart = self::u32($this->bytes, 60);
        $this->miniFat = $miniFatStart === self::ENDOFCHAIN || $miniFatStart === self::FREESECT
            ? []
            : array_values(unpack('V*', $this->readChain($this->bytes, $this->fat, $miniFatStart, $this->sectorSize, null, 'mini FAT', $this->sectorSize)) ?: []);

        $rootStart = self::u32($root, 116);
        $rootSize = $this->streamSize($root, $major);
        $this->miniStream = $rootSize === 0 || $rootStart === self::ENDOFCHAIN
            ? ''
            : $this->readChain($this->bytes, $this->fat, $rootStart, $this->sectorSize, $rootSize, 'mini stream container', $this->sectorSize);

        // The root's children are a red-black tree threaded through left/right
        // sibling ids. Its shape does not matter to a reader; visiting every node
        // does, and a crafted file can make the ids a cycle.
        $pending = [self::u32($root, 76)];
        $visited = [];
        while ($pending !== []) {
            $id = array_pop($pending);
            if ($id === self::NOSTREAM || isset($visited[$id])) {
                continue;
            }
            if (! isset($entries[$id]) || strlen($entries[$id]) < 128) {
                throw new RuntimeException('Compound File Binary directory points at an entry that does not exist.');
            }
            $visited[$id] = true;
            $entry = $entries[$id];
            $pending[] = self::u32($entry, 68);
            $pending[] = self::u32($entry, 72);

            if (ord($entry[66]) !== 2) {
                continue; // a storage (or unused slot); its contents are not the document
            }
            $nameLength = min(64, self::u16($entry, 64));
            $name = self::utf16ToUtf8(substr($entry, 0, max(0, $nameLength - 2)));
            $this->streams[$name] = ['start' => self::u32($entry, 116), 'size' => $this->streamSize($entry, $major)];
        }
    }

    /** @return list<int> */
    private function readFat(): array
    {
        $fatSectors = [];
        for ($i = 0; $i < 109; $i++) {
            $sector = self::u32($this->bytes, 76 + $i * 4);
            if ($sector !== self::FREESECT) {
                $fatSectors[] = $sector;
            }
        }

        $difat = self::u32($this->bytes, 68);
        $perDifat = intdiv($this->sectorSize, 4) - 1;
        $seen = [];
        while ($difat !== self::ENDOFCHAIN && $difat !== self::FREESECT) {
            if (isset($seen[$difat])) {
                throw new RuntimeException('Compound File Binary DIFAT chain loops.');
            }
            $seen[$difat] = true;
            $sector = $this->sector($this->bytes, $difat, $this->sectorSize, $this->sectorSize, 'DIFAT');
            for ($i = 0; $i < $perDifat; $i++) {
                $entry = self::u32($sector, $i * 4);
                if ($entry !== self::FREESECT) {
                    $fatSectors[] = $entry;
                }
            }
            $difat = self::u32($sector, $perDifat * 4);
        }

        if (count($fatSectors) > intdiv(strlen($this->bytes), $this->sectorSize) + 1) {
            throw new RuntimeException('Compound File Binary allocation table lists more sectors than the file holds.');
        }

        $fat = [];
        foreach ($fatSectors as $sector) {
            foreach (unpack('V*', $this->sector($this->bytes, $sector, $this->sectorSize, $this->sectorSize, 'FAT')) ?: [] as $next) {
                $fat[] = $next;
            }
        }

        return $fat;
    }

    /**
     * Follow a chain and concatenate its sectors.
     *
     * @param  list<int>  $table  the FAT or mini FAT the chain lives in
     * @param  int|null  $size  bytes wanted; null means the whole chain
     * @param  int  $headerOffset  512 for regular sectors (the header is sector -1), 0 inside the mini stream
     */
    private function readChain(string $source, array $table, int $start, int $unit, ?int $size, string $what, int $headerOffset): string
    {
        if ($size === 0) {
            return '';
        }

        $out = '';
        $seen = [];
        $sector = $start;
        $limit = intdiv(strlen($source), $unit) + 1;

        while ($sector !== self::ENDOFCHAIN) {
            if ($sector === self::FREESECT || $sector >= 0xFFFFFFFA) {
                throw new RuntimeException("Compound File Binary {$what} chain is broken.");
            }
            if (isset($seen[$sector]) || count($seen) > $limit) {
                throw new RuntimeException("Compound File Binary {$what} chain loops.");
            }
            $seen[$sector] = true;
            $out .= $this->sector($source, $sector, $unit, $headerOffset, $what);
            if ($size !== null && strlen($out) >= $size) {
                return substr($out, 0, $size);
            }
            if (! isset($table[$sector])) {
                throw new RuntimeException("Compound File Binary {$what} chain runs past the allocation table.");
            }
            $sector = $table[$sector];
        }

        if ($size !== null && strlen($out) < $size) {
            throw new RuntimeException("Compound File Binary {$what} is shorter than its declared size.");
        }

        return $out;
    }

    private function sector(string $source, int $index, int $unit, int $headerOffset, string $what): string
    {
        $offset = $headerOffset + $index * $unit;
        if ($offset < 0 || $offset + $unit > strlen($source)) {
            throw new RuntimeException("Compound File Binary {$what} points outside the file.");
        }

        return substr($source, $offset, $unit);
    }

    private function streamSize(string $entry, int $major): int
    {
        $low = self::u32($entry, 120);
        // Version 3 files may leave garbage in the high half; only version 4
        // can hold a stream over 4GB, and nothing here reads one that large.
        $high = $major === 4 ? self::u32($entry, 124) : 0;
        if ($high !== 0 || $low > self::MAX_STREAM_BYTES) {
            throw new RuntimeException('Compound File Binary stream is too large to read.');
        }

        return $low;
    }

    private static function utf16ToUtf8(string $raw): string
    {
        $out = '';
        $length = strlen($raw) - (strlen($raw) % 2);
        for ($i = 0; $i < $length; $i += 2) {
            $out .= self::codePoint(self::u16($raw, $i));
        }

        return $out;
    }

    private static function codePoint(int $cp): string
    {
        return match (true) {
            $cp < 0x80 => chr($cp),
            $cp < 0x800 => chr(0xC0 | ($cp >> 6)).chr(0x80 | ($cp & 0x3F)),
            $cp >= 0xD800 && $cp <= 0xDFFF => "\u{FFFD}", // half a surrogate pair is not a character
            default => chr(0xE0 | ($cp >> 12)).chr(0x80 | (($cp >> 6) & 0x3F)).chr(0x80 | ($cp & 0x3F)),
        };
    }

    private static function u16(string $b, int $o): int
    {
        return $o + 2 <= strlen($b) ? (ord($b[$o]) | (ord($b[$o + 1]) << 8)) : 0;
    }

    private static function u32(string $b, int $o): int
    {
        return $o + 4 <= strlen($b)
            ? (ord($b[$o]) | (ord($b[$o + 1]) << 8) | (ord($b[$o + 2]) << 16) | (ord($b[$o + 3]) << 24))
            : 0;
    }
}
