<?php

declare(strict_types=1);

namespace LastWord\Schema;

/**
 * Where a floating drawing sits, and how body text flows round it: the model's
 * `anchor` object, written as `wp:anchor` (`wp:positionH`, `wp:positionV`, one
 * of the `wp:wrap*` elements and its `behindDoc` attribute). A drawing without
 * an `anchor` is inline (`wp:inline`) and moves with the text.
 *
 * ONE vocabulary for every drawing that can float. Shapes and text boxes use it
 * first; it is meant to be the same object an anchored picture takes
 * (CC-fancy-0055), so nothing here knows what kind of drawing it positions.
 *
 *   {
 *     "horizontal": {"relativeTo": "margin", "offset": 36}      // or "align": "right"
 *     "vertical":   {"relativeTo": "paragraph", "offset": 0}    // or "align": "top"
 *     "wrap": "square",
 *     "allowOverlap": true
 *   }
 *
 * - `relativeTo` is `wp:positionH/@relativeFrom` / `wp:positionV/@relativeFrom`.
 *   Horizontal: page, margin, column, character. Vertical: page, margin,
 *   paragraph, line. The format has more (leftMargin, insideMargin ...); the
 *   reader maps those to `page` and reports the position as approximated.
 * - `offset` is `wp:posOffset`, in points (the model's page unit), from the
 *   reference's top-left corner. `align` is `wp:align`. Never both.
 * - `wrap`: square, tight, through, topAndBottom (`wp:wrapSquare`,
 *   `wp:wrapTight`, `wp:wrapThrough`, `wp:wrapTopAndBottom`), or behind / inFront,
 *   which are Word's "Behind Text" and "In Front of Text": `wp:wrapNone` with
 *   `behindDoc` 1 or 0. That is the only layer the model has; within a layer, a
 *   later floating drawing is drawn over an earlier one (`relativeHeight`).
 * - `allowOverlap` is `@allowOverlap`: true (the default) lets other floating
 *   drawings overlap this one; false asks Word to move them clear.
 *
 * The contract for a renderer that cannot place a floating drawing: draw it
 * inline at its paragraph and report `layout-not-preserved`; never drop it.
 *
 * Everything is closed: an unknown key is refused (and repair drops it), and no
 * message ever repeats a key the document made up.
 */
final class Anchor
{
    public const HORIZONTAL_FROM = ['page', 'margin', 'column', 'character'];

    public const VERTICAL_FROM = ['page', 'margin', 'paragraph', 'line'];

    public const HORIZONTAL_ALIGN = ['left', 'center', 'right', 'inside', 'outside'];

    public const VERTICAL_ALIGN = ['top', 'center', 'bottom', 'inside', 'outside'];

    public const WRAPS = ['square', 'tight', 'through', 'topAndBottom', 'behind', 'inFront'];

    /** The defaults an omitted part takes: Word's own for a newly floated object. */
    public const DEFAULT_HORIZONTAL = ['relativeTo' => 'column', 'offset' => 0];

    public const DEFAULT_VERTICAL = ['relativeTo' => 'paragraph', 'offset' => 0];

    public const DEFAULT_WRAP = 'square';

    public const DEFAULT_ALLOW_OVERLAP = true;

    /** The keys an anchor may have. */
    public const KEYS = ['horizontal', 'vertical', 'wrap', 'allowOverlap'];

    /**
     * The furthest an offset may go, either way, in points: 22 inches. This is
     * this package's cap, the largest page dimension a document may need plus
     * room to bleed; it keeps every written `wp:posOffset` far inside xsd:int.
     */
    public const MAX_OFFSET = 1584;

    /** EMUs per point (DrawingML's unit: 914400 to the inch). */
    public const EMU_PER_PT = 12700;

    /** @return array<string, mixed> the JSON Schema of `anchor` */
    public static function jsonSchema(): array
    {
        $axis = static fn (array $from, array $align, string $default): array => [
            'type' => 'object',
            'additionalProperties' => false,
            'description' => "Measured from relativeTo's top-left corner: offset in points (negative goes left or up), or align. Never both. Absent, {$default}.",
            'properties' => [
                'relativeTo' => ['enum' => $from],
                'offset' => ['type' => 'number', 'minimum' => -self::MAX_OFFSET, 'maximum' => self::MAX_OFFSET, 'description' => 'Points. 0 by default.'],
                'align' => ['enum' => $align],
            ],
        ];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'description' => 'Float the drawing instead of setting it in the line of text: where it sits and how text flows round it. e.g. {"horizontal": {"relativeTo": "margin", "align": "right"}, "vertical": {"relativeTo": "paragraph", "offset": 0}, "wrap": "square"}. Offsets are points; the drawing\'s size is its own widthPx / heightPx. Absent, the drawing is inline and moves with the text. Word: wp:anchor.',
            'properties' => [
                'horizontal' => $axis(self::HORIZONTAL_FROM, self::HORIZONTAL_ALIGN, '{"relativeTo": "column", "offset": 0}'),
                'vertical' => $axis(self::VERTICAL_FROM, self::VERTICAL_ALIGN, '{"relativeTo": "paragraph", "offset": 0}'),
                'wrap' => ['enum' => self::WRAPS, 'description' => 'How text flows round it: square (the default), tight, through, topAndBottom (text above and below only), or none at all, the drawing behind the text or inFront of it.'],
                'allowOverlap' => ['type' => 'boolean', 'description' => 'Whether other floating drawings may overlap this one. true by default.'],
            ],
        ];
    }

    /**
     * Errors for one `anchor` value, at `$path`.
     *
     * @return list<array{path: string, message: string, code: string}>
     */
    public static function validate(mixed $anchor, string $path): array
    {
        if (!is_array($anchor) || ($anchor !== [] && array_is_list($anchor))) {
            return [self::error($path, 'anchor must be an object ({horizontal?, vertical?, wrap?}); leave it out for an inline drawing', 'anchor_invalid')];
        }
        $errors = [];
        $unknown = count(array_diff(array_keys($anchor), self::KEYS));
        if ($unknown > 0) {
            $errors[] = self::error($path, "anchor takes horizontal, vertical, wrap and allowOverlap only ({$unknown} other key" . ($unknown === 1 ? '' : 's') . ')', 'anchor_invalid');
        }
        foreach (['horizontal' => [self::HORIZONTAL_FROM, self::HORIZONTAL_ALIGN], 'vertical' => [self::VERTICAL_FROM, self::VERTICAL_ALIGN]] as $axis => [$from, $align]) {
            if (array_key_exists($axis, $anchor)) {
                array_push($errors, ...self::validateAxis($anchor[$axis], "{$path}.{$axis}", $axis, $from, $align));
            }
        }
        if (array_key_exists('wrap', $anchor) && !in_array($anchor['wrap'], self::WRAPS, true)) {
            $errors[] = self::error("{$path}.wrap", 'anchor wrap must be one of: ' . implode(', ', self::WRAPS), 'anchor_wrap_invalid');
        }
        if (array_key_exists('allowOverlap', $anchor) && !is_bool($anchor['allowOverlap'])) {
            $errors[] = self::error("{$path}.allowOverlap", 'anchor allowOverlap must be a boolean', 'anchor_overlap_invalid');
        }

        return $errors;
    }

    /**
     * @param  list<string>  $from
     * @param  list<string>  $align
     * @return list<array{path: string, message: string, code: string}>
     */
    private static function validateAxis(mixed $value, string $path, string $axis, array $from, array $align): array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            return [self::error($path, "anchor {$axis} must be an object ({relativeTo?, offset?} or {relativeTo?, align})", 'anchor_invalid')];
        }
        $errors = [];
        $unknown = count(array_diff(array_keys($value), ['relativeTo', 'offset', 'align']));
        if ($unknown > 0) {
            $errors[] = self::error($path, "anchor {$axis} takes relativeTo, offset and align only ({$unknown} other key" . ($unknown === 1 ? '' : 's') . ')', 'anchor_invalid');
        }
        if (array_key_exists('relativeTo', $value) && !in_array($value['relativeTo'], $from, true)) {
            $errors[] = self::error("{$path}.relativeTo", "anchor {$axis} relativeTo must be one of: " . implode(', ', $from), 'anchor_invalid');
        }
        if (array_key_exists('offset', $value) && array_key_exists('align', $value)) {
            $errors[] = self::error($path, "anchor {$axis} takes offset or align, not both", 'anchor_invalid');
        }
        if (array_key_exists('align', $value) && !in_array($value['align'], $align, true)) {
            $errors[] = self::error("{$path}.align", "anchor {$axis} align must be one of: " . implode(', ', $align), 'anchor_invalid');
        }
        if (array_key_exists('offset', $value)) {
            $offset = $value['offset'];
            if ((!is_int($offset) && !is_float($offset)) || !is_finite((float) $offset)) {
                $errors[] = self::error("{$path}.offset", "anchor {$axis} offset must be a number of points", 'anchor_offset_invalid');
            } elseif (abs((float) $offset) > self::MAX_OFFSET) {
                $errors[] = self::error("{$path}.offset", "anchor {$axis} offset must be between -" . self::MAX_OFFSET . ' and ' . self::MAX_OFFSET . ' points', 'anchor_offset_invalid');
            }
        }

        return $errors;
    }

    /**
     * The nearest valid anchor, or null (inline) when the value is not an object
     * at all. Each change is a note; no note names a key the document made up.
     *
     * @param  list<array{path: string, message: string}>  $notes
     * @return array<string, mixed>|null
     */
    public static function repair(mixed $anchor, string $path, array &$notes): ?array
    {
        if (!is_array($anchor) || ($anchor !== [] && array_is_list($anchor))) {
            $notes[] = ['path' => $path, 'message' => 'removed an anchor that is not an object: the drawing is inline'];

            return null;
        }
        $out = [];
        $unknown = count(array_diff(array_keys($anchor), self::KEYS));
        if ($unknown > 0) {
            $notes[] = ['path' => $path, 'message' => "dropped {$unknown} unknown anchor key" . ($unknown === 1 ? '' : 's')];
        }
        foreach (['horizontal' => [self::HORIZONTAL_FROM, self::HORIZONTAL_ALIGN, self::DEFAULT_HORIZONTAL], 'vertical' => [self::VERTICAL_FROM, self::VERTICAL_ALIGN, self::DEFAULT_VERTICAL]] as $axis => [$from, $align, $default]) {
            if (!array_key_exists($axis, $anchor)) {
                continue;
            }
            $value = $anchor[$axis];
            if (!is_array($value) || ($value !== [] && array_is_list($value))) {
                $notes[] = ['path' => "{$path}.{$axis}", 'message' => "reset the anchor's {$axis} position to its default: it was not an object"];
                $out[$axis] = $default;

                continue;
            }
            $fixed = [];
            if (array_key_exists('relativeTo', $value)) {
                if (in_array($value['relativeTo'], $from, true)) {
                    $fixed['relativeTo'] = $value['relativeTo'];
                } else {
                    $notes[] = ['path' => "{$path}.{$axis}.relativeTo", 'message' => "reset relativeTo to {$default['relativeTo']}: it must be one of " . implode(', ', $from)];
                    $fixed['relativeTo'] = $default['relativeTo'];
                }
            }
            $offset = $value['offset'] ?? null;
            if (is_string($offset) && is_numeric($offset)) {
                $offset = (float) $offset;
            }
            $hasOffset = array_key_exists('offset', $value);
            if ($hasOffset && ((!is_int($offset) && !is_float($offset)) || !is_finite((float) $offset))) {
                $notes[] = ['path' => "{$path}.{$axis}.offset", 'message' => 'set the offset to 0: it must be a number of points'];
                $offset = 0;
            }
            if ($hasOffset && abs((float) $offset) > self::MAX_OFFSET) {
                $notes[] = ['path' => "{$path}.{$axis}.offset", 'message' => 'clamped the offset to ' . self::MAX_OFFSET . ' points either way'];
                $offset = $offset < 0 ? -self::MAX_OFFSET : self::MAX_OFFSET;
            }
            if ($hasOffset) {
                $fixed['offset'] = $offset;
                if (array_key_exists('align', $value)) {
                    $notes[] = ['path' => "{$path}.{$axis}.align", 'message' => 'dropped align: the position has an offset'];
                }
            } elseif (array_key_exists('align', $value)) {
                if (in_array($value['align'], $align, true)) {
                    $fixed['align'] = $value['align'];
                } else {
                    $notes[] = ['path' => "{$path}.{$axis}.align", 'message' => 'set the offset to 0: align must be one of ' . implode(', ', $align)];
                    $fixed['offset'] = 0;
                }
            }
            $dropped = count(array_diff(array_keys($value), ['relativeTo', 'offset', 'align']));
            if ($dropped > 0) {
                $notes[] = ['path' => "{$path}.{$axis}", 'message' => "dropped {$dropped} unknown {$axis} key" . ($dropped === 1 ? '' : 's')];
            }
            $out[$axis] = $fixed;
        }
        if (array_key_exists('wrap', $anchor)) {
            if (in_array($anchor['wrap'], self::WRAPS, true)) {
                $out['wrap'] = $anchor['wrap'];
            } else {
                $notes[] = ['path' => "{$path}.wrap", 'message' => 'set wrap to square: it must be one of ' . implode(', ', self::WRAPS)];
                $out['wrap'] = self::DEFAULT_WRAP;
            }
        }
        if (array_key_exists('allowOverlap', $anchor)) {
            if (is_bool($anchor['allowOverlap'])) {
                $out['allowOverlap'] = $anchor['allowOverlap'];
            } else {
                $notes[] = ['path' => "{$path}.allowOverlap", 'message' => 'dropped allowOverlap: it must be a boolean (true by default)'];
            }
        }

        return $out;
    }

    /**
     * A valid anchor in full: both positions, the wrap and allowOverlap, every default filled
     * in. The form the reader returns, so what it reads writes the same bytes.
     *
     * @param  array<string, mixed>  $anchor
     * @return array{horizontal: array<string, mixed>, vertical: array<string, mixed>, wrap: string, allowOverlap: bool}
     */
    public static function full(array $anchor): array
    {
        $axis = static function (mixed $value, array $default): array {
            $value = is_array($value) ? $value : [];
            $out = ['relativeTo' => $value['relativeTo'] ?? $default['relativeTo']];
            if (array_key_exists('align', $value) && !array_key_exists('offset', $value)) {
                $out['align'] = $value['align'];
            } else {
                $out['offset'] = $value['offset'] ?? 0;
            }

            return $out;
        };

        return [
            'horizontal' => $axis($anchor['horizontal'] ?? null, self::DEFAULT_HORIZONTAL),
            'vertical' => $axis($anchor['vertical'] ?? null, self::DEFAULT_VERTICAL),
            'wrap' => is_string($anchor['wrap'] ?? null) ? $anchor['wrap'] : self::DEFAULT_WRAP,
            'allowOverlap' => is_bool($anchor['allowOverlap'] ?? null) ? $anchor['allowOverlap'] : self::DEFAULT_ALLOW_OVERLAP,
        ];
    }

    /** @return array{path: string, message: string, code: string} */
    private static function error(string $path, string $message, string $code): array
    {
        return ['path' => $path, 'message' => $message, 'code' => $code];
    }
}
