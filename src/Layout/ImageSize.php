<?php

declare(strict_types=1);

namespace LastWord\Layout;

use LastWord\Helpers\ImageSize as Sniff;

/**
 * The size last-word-ui draws an image block at (`image-size.ts`
 * `resolveImageSize`): the writer's rules, capped at the text column, rounded
 * to whole px. Only a PNG or JPEG data URL has an intrinsic size; anything
 * else is 300 x 200, or 3:2 to a given side.
 */
final class ImageSize
{
    private const DATA_URL = '/^data:image\/(png|jpe?g);base64,([A-Za-z0-9+\/=\s]+)$/i';

    /** @return array{width: int, height: int} */
    public static function resolve(string $src, mixed $widthPx, mixed $heightPx, float $maxWidthPx): array
    {
        $intrinsic = null;
        if (preg_match(self::DATA_URL, $src, $m) === 1) {
            $bytes = base64_decode((string) preg_replace('/\s+/', '', $m[2]), true);
            if ($bytes !== false) {
                $intrinsic = Sniff::sniff($bytes);
            }
        }
        $given = static fn (mixed $v): ?float => (is_int($v) || is_float($v)) && is_finite((float) $v) && $v > 0 ? (float) $v : null;
        $width = $given($widthPx);
        $height = $given($heightPx);
        $aspect = $intrinsic !== null ? $intrinsic['width'] / $intrinsic['height'] : 1.5;

        if ($width === null && $height === null) {
            $width = (float) ($intrinsic['width'] ?? 300);
            $height = (float) ($intrinsic['height'] ?? 200);
        } elseif ($width === null) {
            $width = $height * $aspect;
        } elseif ($height === null) {
            $height = $width / $aspect;
        }

        if ($width > $maxWidthPx) {
            $height = $height * $maxWidthPx / $width;
            $width = $maxWidthPx;
        }

        // JavaScript's Math.round: halves round up, also for negatives.
        return ['width' => max(1, (int) floor($width + 0.5)), 'height' => max(1, (int) floor($height + 0.5))];
    }
}
