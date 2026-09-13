<?php

declare(strict_types=1);

namespace LastWord\Tests\Support;

use ZipArchive;

/**
 * Hand-built legacy files for the reader's guard tests.
 *
 * The converted fixtures in `tests/fixtures/formats/` prove the readers get a
 * real document right. They cannot prove the readers survive a damaged or
 * hostile one, because no converter writes those. These builders do, byte by
 * byte, so each test can break exactly one structure and nothing else.
 */
final class LegacyFiles
{
    public const ENDOFCHAIN = 0xFFFFFFFE;

    public const FREESECT = 0xFFFFFFFF;

    public const FATSECT = 0xFFFFFFFD;

    public const NOSTREAM = 0xFFFFFFFF;

    /** File offset of sector N in a version-3 compound file. */
    public static function sectorOffset(int $sector): int
    {
        return 512 + $sector * 512;
    }

    /** File offset of directory entry N in a file built by {@see cfb()} (the directory is sector 1). */
    public static function entryOffset(int $entry): int
    {
        return self::sectorOffset(1) + $entry * 128;
    }

    /**
     * A version-3 compound file with up to three streams under the root.
     *
     * Sector 0 is the FAT, sector 1 the directory, and the streams follow. Every
     * stream is padded to at least 4096 bytes so it lives in regular sectors
     * rather than the mini stream, which keeps the offsets a test patches simple.
     *
     * @param  array<string, string>  $streams  name => content
     */
    public static function cfb(array $streams): string
    {
        $names = array_keys($streams);
        $fat = [self::FATSECT, self::ENDOFCHAIN];
        $data = '';
        $entries = [self::entry('Root Entry', 5, self::NOSTREAM, self::NOSTREAM, $names === [] ? self::NOSTREAM : 1, self::ENDOFCHAIN, 0)];

        foreach ($names as $i => $name) {
            $content = $streams[$name];
            $size = max(4096, strlen($content));
            $sectors = intdiv($size + 511, 512);
            $padded = str_pad($content, $sectors * 512, "\0");
            $start = count($fat);
            for ($s = 0; $s < $sectors; $s++) {
                $fat[] = $s === $sectors - 1 ? self::ENDOFCHAIN : $start + $s + 1;
            }
            $data .= $padded;
            $right = $i + 1 < count($names) ? $i + 2 : self::NOSTREAM;
            $entries[] = self::entry($name, 2, self::NOSTREAM, $right, self::NOSTREAM, $start, $size);
        }

        $directory = str_pad(implode('', $entries), 512, self::entry('', 0, self::NOSTREAM, self::NOSTREAM, self::NOSTREAM, 0, 0));
        $fatSector = '';
        for ($i = 0; $i < 128; $i++) {
            $fatSector .= pack('V', $fat[$i] ?? self::FREESECT);
        }

        $header = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".str_repeat("\0", 16)
            .pack('v', 0x003E).pack('v', 3).pack('v', 0xFFFE).pack('v', 9).pack('v', 6)
            .str_repeat("\0", 6)
            .pack('V', 0)                 // directory sectors (always 0 in version 3)
            .pack('V', 1)                 // FAT sectors
            .pack('V', 1)                 // first directory sector
            .pack('V', 0)                 // transaction signature
            .pack('V', 4096)              // mini stream cutoff
            .pack('V', self::ENDOFCHAIN)  // first mini FAT sector
            .pack('V', 0)                 // mini FAT sectors
            .pack('V', self::ENDOFCHAIN)  // first DIFAT sector
            .pack('V', 0)                 // DIFAT sectors
            .pack('V', 0);                // DIFAT[0]: the FAT is sector 0
        $header .= str_repeat(pack('V', self::FREESECT), 108);

        return $header.$fatSector.$directory.$data;
    }

    private static function entry(string $name, int $type, int $left, int $right, int $child, int $start, int $size): string
    {
        $utf16 = '';
        foreach (str_split($name) as $char) { // ASCII names only, which is all the tests use
            $utf16 .= pack('v', ord($char));
        }
        $nameField = str_pad($utf16, 64, "\0");
        $nameLength = $name === '' ? 0 : strlen($utf16) + 2;

        return $nameField
            .pack('v', $nameLength)
            .chr($type).chr(1)
            .pack('V', $left).pack('V', $right).pack('V', $child)
            .str_repeat("\0", 16 + 4 + 16)
            .pack('V', $start).pack('V', $size).pack('V', 0);
    }

    /**
     * A Word 97 binary document: the `WordDocument` and `0Table` streams.
     *
     * The FIB carries only what the reader looks at: the identifier, `nFib`, the
     * flags, `ccpText`, and the `Clx` location. The text sits at offset 1024 of the
     * WordDocument stream, in one 8-bit piece unless `pieces` says otherwise.
     *
     * @param  array{nFib?: int, flags?: int, ccpText?: int, clx?: string, unicode?: bool}  $options
     * @return array<string, string>
     */
    public static function word(string $text, array $options = []): array
    {
        $unicode = $options['unicode'] ?? false;
        $characters = $unicode ? intdiv(strlen($text), 2) : strlen($text);

        $fib = pack('v', 0xA5EC)
            .pack('v', $options['nFib'] ?? 0x00C1)
            .str_repeat("\0", 6)
            .pack('v', $options['flags'] ?? 0)
            .str_repeat("\0", 20);
        $fib .= pack('v', 14).str_repeat("\0", 28);                    // csw, fibRgW
        $rgLw = str_repeat("\0", 88);
        $rgLw = substr_replace($rgLw, pack('V', $options['ccpText'] ?? $characters), 12, 4);
        $fib .= pack('v', 22).$rgLw;                                    // cslw, fibRgLw
        $clx = $options['clx'] ?? self::clx([[0, $characters, 1024, ! $unicode]]);
        $rgFcLcb = str_repeat("\0", 93 * 8);
        $rgFcLcb = substr_replace($rgFcLcb, pack('VV', 0, strlen($clx)), 33 * 8, 8);
        $fib .= pack('v', 93).$rgFcLcb;

        $document = str_pad($fib, 1024, "\0").$text;

        return ['WordDocument' => $document, '0Table' => $clx];
    }

    /**
     * A Clx holding one piece table.
     *
     * @param  list<array{0: int, 1: int, 2: int, 3: bool}>  $pieces  [cpStart, cpEnd, byte offset, compressed]
     */
    public static function clx(array $pieces, string $prc = ''): string
    {
        $cps = '';
        $pcds = '';
        foreach ($pieces as $i => [$cpStart, $cpEnd, $offset, $compressed]) {
            $cps .= pack('V', $cpStart);
            $pcds .= pack('v', 0).pack('V', $compressed ? ($offset * 2) | 0x40000000 : $offset).pack('v', 0);
        }
        $cps .= pack('V', $pieces === [] ? 0 : $pieces[count($pieces) - 1][1]);
        $plc = $cps.$pcds;

        return $prc."\x02".pack('V', strlen($plc)).$plc;
    }

    /** An ODT whose content.xml is the given body markup, or the given whole part. */
    public static function odt(string $body, ?string $contentXml = null): string
    {
        $contentXml ??= '<?xml version="1.0" encoding="UTF-8"?>'
            .'<office:document-content'
            .' xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"'
            .' xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"'
            .' xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0">'
            .'<office:body><office:text>'.$body.'</office:text></office:body>'
            .'</office:document-content>';

        $path = tempnam(sys_get_temp_dir(), 'lw_odt_');
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('mimetype', 'application/vnd.oasis.opendocument.text');
        $zip->setCompressionName('mimetype', ZipArchive::CM_STORE);
        $zip->addFromString('content.xml', $contentXml);
        $zip->close();
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    /** A zip holding the given entries, for zips that are not documents. */
    public static function zip(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'lw_zip_');
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
            if ($name === 'mimetype') {
                $zip->setCompressionName('mimetype', ZipArchive::CM_STORE); // as ODF requires
            }
        }
        $zip->close();
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }
}
