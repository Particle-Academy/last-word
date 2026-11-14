<?php

declare(strict_types=1);

namespace LastWord\Writer;

use LastWord\Exceptions\ImageRefused;
use LastWord\Exceptions\SchemaException;
use LastWord\Helpers\ImageSize;

/**
 * Images the HOST registers with the writer, by name (CC-fancy-0039), so a
 * model places `{"type": "image", "src": "image:logo"}` instead of carrying the
 * bytes -- or inventing an image -- and a letterhead's logo goes in a header
 * part as easily as in the body.
 *
 * The write options:
 *
 *   images       {name: image}, an image being PNG or JPEG bytes, a PNG or JPEG
 *                data URL, or {data, widthPx?, heightPx?, alt?} with one of
 *                those as `data`. The size and alt are what a block that names
 *                the image gets unless it sets its own: a block setting either
 *                `widthPx` or `heightPx` takes neither registered size, so the
 *                image keeps its shape.
 *   imageLimits  {maxBytes?, maxWidthPx?, maxHeightPx?, maxImages?}, positive
 *                integers; each absent one is the default in {@see DEFAULT_LIMITS}.
 *
 * Every image is checked as it is registered -- its type from its leading
 * bytes, its size before it is decoded, its pixel size from its header -- and a
 * bad one throws {@see ImageRefused} with a stable reason. The same checks, in
 * the same order, as the Node package; the shared fixture pins them.
 *
 * A name is resolved when the document is written. The writer never sees a
 * name: it is given the data URL, so a named image writes exactly what the same
 * image as a data URL writes, and nothing else in the package can tell them apart.
 */
final class NamedImages
{
    /** What a block's `src` starts with to name a registered image. */
    public const PREFIX = 'image:';

    /** A letter, then up to 63 letters, digits, `-` or `_`. */
    public const NAME_PATTERN = '/^[A-Za-z][A-Za-z0-9_-]{0,63}$/D';

    /** Secure defaults; a host raises or lowers each through `imageLimits`. */
    public const DEFAULT_LIMITS = [
        'maxBytes' => 5242880,
        'maxWidthPx' => 4096,
        'maxHeightPx' => 4096,
        'maxImages' => 32,
    ];

    /** The keys an image object takes. */
    private const IMAGE_KEYS = ['data', 'widthPx', 'heightPx', 'alt'];

    /**
     * @param  array<string, array{type: string, bytes: string, width: int, height: int, widthPx?: int, heightPx?: int, alt?: string}>  $images
     */
    private function __construct(private readonly array $images)
    {
    }

    /**
     * The `images` / `imageLimits` options as registered images, or null when
     * no image is registered (absent, null or empty) -- which is then exactly
     * the behaviour from before this existed.
     *
     * @param  array<string, mixed>  $options
     *
     * @throws ImageRefused
     */
    public static function fromOptions(array $options): ?self
    {
        $limits = self::limits($options['imageLimits'] ?? null);
        $images = $options['images'] ?? null;
        if ($images === null || $images === []) {
            return null;
        }
        if (! is_array($images)) {
            throw new ImageRefused('image_option_invalid', 'images must be an object of name => image.');
        }
        if (count($images) > $limits['maxImages']) {
            throw new ImageRefused('image_count_exceeded', 'at most ' . $limits['maxImages'] . ' images may be registered (imageLimits.maxImages).');
        }

        $registered = [];
        foreach ($images as $name => $image) {
            $name = (string) $name;
            if (preg_match(self::NAME_PATTERN, $name) !== 1) {
                throw new ImageRefused('image_name_invalid', 'an image name is a letter followed by up to 63 letters, digits, - or _.');
            }
            $registered[$name] = self::register($name, $image, $limits);
        }

        return new self($registered);
    }

    /** @return list<string> the registered names, in registration order */
    public function names(): array
    {
        return array_keys($this->images);
    }

    /**
     * The document with every `image:<name>` src replaced by its data URL, and
     * the registered size and alt filled in where the block sets none.
     *
     * @param  array<string, mixed>  $doc
     * @return array<string, mixed>
     *
     * @throws SchemaException for a name that is not registered (validation reports it first)
     */
    public function resolve(array $doc): array
    {
        return $this->walk($doc, '');
    }

    /**
     * The section {@see \LastWord\Agent::guide()} appends: which names exist,
     * their type, pixel size, placed size and alt. Identical in the Node package.
     */
    public function guideSection(): string
    {
        $lines = '';
        foreach ($this->images as $name => $image) {
            $line = '- `' . self::PREFIX . $name . '`: ' . strtoupper($image['type']) . ', ' . $image['width'] . ' x ' . $image['height'] . ' px';
            if (isset($image['widthPx'], $image['heightPx'])) {
                $line .= '; placed at ' . $image['widthPx'] . ' x ' . $image['heightPx'] . ' px';
            } elseif (isset($image['widthPx'])) {
                $line .= '; placed ' . $image['widthPx'] . ' px wide';
            } elseif (isset($image['heightPx'])) {
                $line .= '; placed ' . $image['heightPx'] . ' px high';
            }
            if (isset($image['alt'])) {
                $line .= '; alt ' . json_encode($image['alt'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR);
            }
            $lines .= $line . "\n";
        }

        return "\n## Images you can place\n\n"
            . 'The host registered these images for this document. Place one with `{"type": "image", "src": "image:NAME"}`, '
            . 'in the body or in a header or footer that holds `blocks` (a letterhead puts its logo in `page.header` or `page.firstHeader`). '
            . 'Use only these names: never invent an image, and never write out a data URL for one of them. '
            . 'Each is placed at the size and with the alt text listed; set `widthPx` or `heightPx` on the block to resize it '
            . "(the other follows the image's shape), or `alt` to describe it differently. "
            . "Any other `image:` name is refused with `image_unknown`.\n\n"
            . $lines;
    }

    /** The image block's `src` description in {@see \LastWord\Agent::jsonSchema()}. Identical in the Node package. */
    public function srcDescription(): string
    {
        return 'PNG or JPEG data URL, or a registered image by name: '
            . implode(', ', array_map(static fn (string $n): string => self::PREFIX . $n, $this->names())) . '. '
            . 'Use a registered name rather than inventing an image; any other image: name is refused (image_unknown).';
    }

    /** The image block's `src` pattern: a data URL, or exactly one of the registered names. */
    public function srcPattern(): string
    {
        return '^(data:image/(png|jpe?g);base64,|' . self::PREFIX . '(' . implode('|', $this->names()) . ')$)';
    }

    /**
     * The JSON Schema with the image block's `src` naming the registered images.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public function patchSchema(array $schema): array
    {
        $walk = function (mixed $node) use (&$walk): mixed {
            if (! is_array($node)) {
                return $node;
            }
            if (($node['properties']['type']['const'] ?? null) === 'image' && is_array($node['properties']['src'] ?? null)) {
                $node['properties']['src']['pattern'] = $this->srcPattern();
                $node['properties']['src']['description'] = $this->srcDescription();

                return $node;
            }
            foreach ($node as $key => $value) {
                $node[$key] = $walk($value);
            }

            return $node;
        };

        return $walk($schema);
    }

    /**
     * @param  array<mixed>  $node
     * @return array<mixed>
     */
    private function walk(array $node, string $path): array
    {
        if (($node['type'] ?? null) === 'image' && is_string($node['src'] ?? null) && str_starts_with($node['src'], self::PREFIX)) {
            $name = substr($node['src'], strlen(self::PREFIX));
            $image = $this->images[$name] ?? null;
            if ($image === null) {
                throw new SchemaException('Document names an image that is not registered.', [
                    self::unknownError($path . '.src', $name, $this->names()),
                ]);
            }
            $node['src'] = 'data:image/' . $image['type'] . ';base64,' . base64_encode($image['bytes']);
            if (! array_key_exists('widthPx', $node) && ! array_key_exists('heightPx', $node)) {
                if (isset($image['widthPx'])) {
                    $node['widthPx'] = $image['widthPx'];
                }
                if (isset($image['heightPx'])) {
                    $node['heightPx'] = $image['heightPx'];
                }
            }
            if (! array_key_exists('alt', $node) && isset($image['alt'])) {
                $node['alt'] = $image['alt'];
            }

            return $node;
        }
        foreach ($node as $key => $value) {
            // A chart's ECharts option is not document content.
            if (is_array($value) && $key !== 'option') {
                $node[$key] = $this->walk($value, $path === '' ? (string) $key : (is_int($key) ? "{$path}[{$key}]" : "{$path}.{$key}"));
            }
        }

        return $node;
    }

    /**
     * The validation error for `image:<name>` naming nothing registered.
     *
     * @param  list<string>  $names
     * @return array{path: string, message: string, code: string}
     */
    public static function unknownError(string $path, string $name, array $names): array
    {
        return [
            'path' => $path,
            'message' => 'no image named "' . $name . '" is registered'
                . ($names === []
                    ? '; none are, so use a PNG or JPEG data URL'
                    : '; the registered ones are ' . implode(', ', array_map(static fn (string $n): string => self::PREFIX . $n, $names))),
            'code' => 'image_unknown',
        ];
    }

    /**
     * @return array{maxBytes: int, maxWidthPx: int, maxHeightPx: int, maxImages: int}
     *
     * @throws ImageRefused
     */
    private static function limits(mixed $limits): array
    {
        if ($limits === null) {
            return self::DEFAULT_LIMITS;
        }
        if (! is_array($limits) || ($limits !== [] && array_is_list($limits))) {
            throw new ImageRefused('image_limits_invalid', 'imageLimits must be an object of {maxBytes?, maxWidthPx?, maxHeightPx?, maxImages?}.');
        }
        $out = self::DEFAULT_LIMITS;
        foreach ($limits as $key => $value) {
            if (! array_key_exists((string) $key, self::DEFAULT_LIMITS)) {
                throw new ImageRefused('image_limits_invalid', 'imageLimits takes maxBytes, maxWidthPx, maxHeightPx and maxImages only.');
            }
            if (! is_int($value) || $value < 1) {
                throw new ImageRefused('image_limits_invalid', "imageLimits.{$key} must be a positive integer.");
            }
            $out[(string) $key] = $value;
        }

        return $out;
    }

    /**
     * One image, checked: its options, its bytes, its type, its pixel size.
     *
     * @param  array{maxBytes: int, maxWidthPx: int, maxHeightPx: int, maxImages: int}  $limits
     * @return array{type: string, bytes: string, width: int, height: int, widthPx?: int, heightPx?: int, alt?: string}
     *
     * @throws ImageRefused
     */
    private static function register(string $name, mixed $image, array $limits): array
    {
        $options = [];
        if (is_array($image)) {
            foreach (array_keys($image) as $key) {
                if (! in_array($key, self::IMAGE_KEYS, true)) {
                    throw new ImageRefused('image_option_invalid', "image {$name} takes data, widthPx, heightPx and alt only.", $name);
                }
            }
            if (! is_string($image['data'] ?? null)) {
                throw new ImageRefused('image_data_invalid', "image {$name} needs its data: PNG or JPEG bytes, or a data URL.", $name);
            }
            foreach (['widthPx', 'heightPx'] as $dim) {
                if (array_key_exists($dim, $image)) {
                    if (! is_int($image[$dim]) || $image[$dim] < 1) {
                        throw new ImageRefused('image_option_invalid', "image {$name} {$dim} must be a positive integer.", $name);
                    }
                    $options[$dim] = $image[$dim];
                }
            }
            if (array_key_exists('alt', $image)) {
                if (! is_string($image['alt']) || ! mb_check_encoding($image['alt'], 'UTF-8')) {
                    throw new ImageRefused('image_option_invalid', "image {$name} alt must be text.", $name);
                }
                $options['alt'] = $image['alt'];
            }
            $image = $image['data'];
        }
        if (! is_string($image)) {
            throw new ImageRefused('image_data_invalid', "image {$name} must be PNG or JPEG bytes, a data URL, or {data, widthPx?, heightPx?, alt?}.", $name);
        }

        [$declared, $bytes] = self::bytes($name, $image, $limits['maxBytes']);
        $type = match (true) {
            str_starts_with($bytes, "\x89PNG\r\n\x1a\n") => 'png',
            str_starts_with($bytes, "\xFF\xD8\xFF") => 'jpeg',
            default => null,
        };
        if ($type === null || ($declared !== null && $declared !== $type)) {
            throw new ImageRefused('image_type_unsupported', "image {$name} must be a PNG or JPEG" . ($type !== null ? ' whose bytes match its data URL type.' : '.'), $name);
        }
        $size = $type === 'png' ? ImageSize::png($bytes) : ImageSize::jpeg($bytes);
        if ($size === null) {
            throw new ImageRefused('image_dimensions_unreadable', "image {$name}: its pixel size cannot be read.", $name);
        }
        if ($size['width'] > $limits['maxWidthPx'] || $size['height'] > $limits['maxHeightPx']) {
            throw new ImageRefused(
                'image_dimensions_too_large',
                "image {$name} is {$size['width']} x {$size['height']} px; at most {$limits['maxWidthPx']} x {$limits['maxHeightPx']} (imageLimits.maxWidthPx / maxHeightPx).",
                $name,
            );
        }

        return ['type' => $type, 'bytes' => $bytes, 'width' => $size['width'], 'height' => $size['height']] + $options;
    }

    /**
     * The bytes, and the type a data URL declares (null for raw bytes). A data
     * URL is measured before it is decoded, so an oversized one costs nothing.
     *
     * @return array{0: ?string, 1: string}
     *
     * @throws ImageRefused
     */
    private static function bytes(string $name, string $image, int $maxBytes): array
    {
        $tooLarge = static fn (): ImageRefused => new ImageRefused('image_too_large', "image {$name} is over {$maxBytes} bytes (imageLimits.maxBytes).", $name);

        if (! str_starts_with($image, 'data:')) {
            if ($image === '') {
                throw new ImageRefused('image_data_invalid', "image {$name} is empty.", $name);
            }
            if (strlen($image) > $maxBytes) {
                throw $tooLarge();
            }

            return [null, $image];
        }
        if (preg_match('#^data:image/(png|jpe?g);base64,(.*)$#sD', $image, $m) !== 1) {
            throw new ImageRefused('image_type_unsupported', "image {$name} must be a base64 PNG or JPEG data URL.", $name);
        }
        $payload = $m[2];
        if ($payload === '' || strlen($payload) % 4 !== 0 || preg_match('#^[A-Za-z0-9+/]*={0,2}$#D', $payload) !== 1) {
            throw new ImageRefused('image_data_invalid', "image {$name} is not valid base64.", $name);
        }
        $padding = str_ends_with($payload, '==') ? 2 : (str_ends_with($payload, '=') ? 1 : 0);
        if (intdiv(strlen($payload), 4) * 3 - $padding > $maxBytes) {
            throw $tooLarge();
        }
        $bytes = base64_decode($payload, true);
        if ($bytes === false || $bytes === '') {
            throw new ImageRefused('image_data_invalid', "image {$name} is not valid base64.", $name);
        }

        return [$m[1] === 'png' ? 'png' : 'jpeg', $bytes];
    }
}
