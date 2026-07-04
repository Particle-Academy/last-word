<?php

declare(strict_types=1);

namespace LastWord\Helpers;

/**
 * Intrinsic image-dimension sniffing straight from the bytes — no GD, no
 * exif extension. Reads the PNG IHDR chunk and the JPEG SOF frame header,
 * which is all the writer needs to compute drawing extents when the model
 * omits `widthPx` / `heightPx`.
 */
final class ImageSize
{
    /**
     * Sniff `{width, height}` in pixels from PNG or JPEG bytes.
     * Returns null when the format isn't recognised.
     *
     * @return array{width: int, height: int}|null
     */
    public static function sniff(string $bytes): ?array
    {
        return self::png($bytes) ?? self::jpeg($bytes);
    }

    /**
     * PNG: 8-byte signature, then the IHDR chunk — width and height are
     * big-endian uint32 at byte offsets 16 and 20.
     *
     * @return array{width: int, height: int}|null
     */
    public static function png(string $bytes): ?array
    {
        if (strlen($bytes) < 24 || !str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            return null;
        }
        if (substr($bytes, 12, 4) !== 'IHDR') {
            return null;
        }
        $parts = unpack('Nwidth/Nheight', substr($bytes, 16, 8));
        if ($parts === false || $parts['width'] < 1 || $parts['height'] < 1) {
            return null;
        }

        return ['width' => $parts['width'], 'height' => $parts['height']];
    }

    /**
     * JPEG: walk the marker segments until a start-of-frame (SOF0–SOF15,
     * excluding DHT/JPG/DAC) — height and width are big-endian uint16 right
     * after the frame header's precision byte.
     *
     * @return array{width: int, height: int}|null
     */
    public static function jpeg(string $bytes): ?array
    {
        $len = strlen($bytes);
        if ($len < 4 || !str_starts_with($bytes, "\xFF\xD8")) {
            return null;
        }

        $offset = 2;
        while ($offset + 4 <= $len) {
            // Markers may be padded with fill bytes (0xFF).
            if (ord($bytes[$offset]) !== 0xFF) {
                $offset++;

                continue;
            }
            $marker = ord($bytes[$offset + 1]);
            if ($marker === 0xFF) {
                $offset++;

                continue;
            }
            // Standalone markers without a length field.
            if ($marker === 0xD8 || ($marker >= 0xD0 && $marker <= 0xD7) || $marker === 0x01) {
                $offset += 2;

                continue;
            }
            if ($marker === 0xD9) { // EOI
                return null;
            }
            if ($offset + 4 > $len) {
                return null;
            }
            $segLen = (ord($bytes[$offset + 2]) << 8) | ord($bytes[$offset + 3]);
            if ($segLen < 2) {
                return null;
            }
            $isSof = $marker >= 0xC0 && $marker <= 0xCF
                && $marker !== 0xC4 && $marker !== 0xC8 && $marker !== 0xCC;
            if ($isSof) {
                if ($offset + 9 > $len) {
                    return null;
                }
                $height = (ord($bytes[$offset + 5]) << 8) | ord($bytes[$offset + 6]);
                $width = (ord($bytes[$offset + 7]) << 8) | ord($bytes[$offset + 8]);
                if ($width < 1 || $height < 1) {
                    return null;
                }

                return ['width' => $width, 'height' => $height];
            }
            $offset += 2 + $segLen;
        }

        return null;
    }
}
