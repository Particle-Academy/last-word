<?php

declare(strict_types=1);

namespace LastWord\Exceptions;

use InvalidArgumentException;

/**
 * A named image the HOST registered (the `images` / `imageLimits` write
 * options) was refused. Branch on `reason`, a stable code; the message is for
 * people:
 *
 *   - `image_option_invalid`        `images` is not an object of name => image,
 *                                   or an image object carries a bad `widthPx`,
 *                                   `heightPx` or `alt`, or a key it does not take
 *   - `image_name_invalid`          a name is not a letter followed by up to 63
 *                                   letters, digits, `-` or `_`
 *   - `image_data_invalid`          no bytes: not a string or `{data}`, bad
 *                                   base64, or empty
 *   - `image_type_unsupported`      not PNG or JPEG, or the bytes are not the
 *                                   type the data URL declares
 *   - `image_too_large`             over `imageLimits.maxBytes`
 *   - `image_dimensions_unreadable` the pixel size cannot be read from the bytes
 *   - `image_dimensions_too_large`  wider than `maxWidthPx` or taller than `maxHeightPx`
 *   - `image_count_exceeded`        more than `maxImages`
 *   - `image_limits_invalid`        `imageLimits` is not an object of positive
 *                                   integers under the four known keys
 *
 * The same codes, in the same order of checking, as the Node package.
 */
final class ImageRefused extends InvalidArgumentException
{
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly ?string $image = null,
    ) {
        parent::__construct('Image refused (' . $reason . '): ' . $message);
    }
}
