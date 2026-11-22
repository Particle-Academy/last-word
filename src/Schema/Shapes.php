<?php

declare(strict_types=1);

namespace LastWord\Schema;

use LastWord\Exchange\Portable;

/**
 * Shapes and text boxes: the `shape` block. A DrawingML shape (`wps:wsp`) with a
 * preset geometry (`a:prstGeom`), a fill and an outline; with `blocks`, it is a
 * text box (`wps:txbx` holding `w:txbxContent`), and its text is ordinary blocks.
 * Inline by default (`wp:inline`); with an {@see Anchor}, floating (`wp:anchor`).
 *
 *   {"type": "shape", "geometry": "roundRect", "widthPx": 240, "heightPx": 96,
 *    "fill": "#FFF4D6", "outline": {"color": "#B7791F", "width": 1},
 *    "anchor": {"horizontal": {"relativeTo": "margin", "align": "right"}, "wrap": "square"},
 *    "blocks": [{"type": "paragraph", "runs": [{"text": "Pull quote."}]}]}
 *
 * Body only: a header, a footer and a footnote refuse it. A text box holds the
 * body's blocks less chart, pageBreak, toc and another shape, and its runs carry
 * no footnote, bookmark or tracked change (review comments are body text only,
 * as everywhere). Caps keep one document from asking for unbounded work.
 */
final class Shapes
{
    /**
     * The preset geometries the model names (`a:prstGeom/@prst`). A closed list:
     * each is a value of DrawingML's preset shape type, chosen for what documents
     * draw -- boxes, callouts, arrows, stars, flowchart symbols. The reader maps
     * any other preset to `rect` and reports it.
     */
    public const GEOMETRIES = [
        'rect', 'roundRect', 'ellipse', 'triangle', 'rtTriangle', 'diamond',
        'parallelogram', 'trapezoid', 'pentagon', 'hexagon', 'octagon', 'plus',
        'star5', 'heart', 'cloud', 'can', 'cube', 'plaque', 'snip1Rect', 'round1Rect',
        'line', 'straightConnector1',
        'rightArrow', 'leftArrow', 'upArrow', 'downArrow', 'leftRightArrow', 'upDownArrow',
        'chevron', 'homePlate',
        'wedgeRectCallout', 'wedgeRoundRectCallout', 'wedgeEllipseCallout', 'cloudCallout',
        'flowChartProcess', 'flowChartDecision', 'flowChartTerminator', 'flowChartDocument',
        'flowChartAlternateProcess', 'flowChartInputOutput',
    ];

    /** Geometries that are a stroke, not an area: one dimension may be 0, and they hold no text. */
    public const LINES = ['line', 'straightConnector1'];

    /** Arrowheads at either end of an outline (`a:headEnd` / `a:tailEnd` `@type`). */
    public const LINE_ENDS = ['none', 'triangle', 'stealth', 'diamond', 'oval', 'arrow'];

    /** Outline styles; `none` removes the outline, as a border's does. */
    public const OUTLINE_STYLES = ['solid', 'dashed', 'dotted', 'none'];

    public const VERTICAL_ALIGNS = ['top', 'middle', 'bottom'];

    /** Block types a text box refuses, at any depth. `shape` is refused as `shape_nested`. */
    public const TEXTBOX_REFUSED_TYPES = ['chart', 'pageBreak', 'toc', 'columnBreak', 'portable'];

    /** At most this many shapes in one document, wherever they sit. */
    public const MAX_SHAPES = 1000;

    /** At most this many blocks in one text box, counted at every depth (list items and cells' blocks too). */
    public const MAX_BOX_BLOCKS = 500;

    /** At most this many characters of run and code text in one text box. */
    public const MAX_BOX_TEXT = 32768;

    /** The largest width or height, in px at 96 dpi: 22 inches. This package's cap. */
    public const MAX_EXTENT_PX = 2112;

    /** The widest outline, in points. This package's cap. */
    public const MAX_OUTLINE_WIDTH = 100;

    /** The outline a shape has when the model gives none: 0.75pt, solid, black. */
    public const DEFAULT_OUTLINE_WIDTH = 0.75;

    public const DEFAULT_OUTLINE_COLOR = '#000000';

    /** What repair gives a shape whose size it cannot use. */
    public const DEFAULT_WIDTH_PX = 240;

    public const DEFAULT_HEIGHT_PX = 120;

    /** Model keys a shape has, in the order the reader writes them. */
    public const KEYS = ['type', 'geometry', 'widthPx', 'heightPx', 'fill', 'outline', 'flipH', 'flipV', 'anchor', 'verticalAlign', 'alt', 'blocks'];

    /** @param  array<string, mixed>  $blockRef  the schema of one block a text box may hold */
    public static function jsonSchema(array $blockRef): array
    {
        $hex = ['type' => 'string', 'pattern' => '^#[0-9A-Fa-f]{6}$'];

        return [
            'type' => 'object',
            'required' => ['type', 'widthPx', 'heightPx'],
            'description' => 'A shape, or a text box when it has blocks: a callout, a sidebar, a pull quote, a boxed note, an arrow, a rule. Inline by default, like an image; give it an anchor to float it beside the text. Body only (not in a header, footer or footnote) and never inside another text box; at most ' . self::MAX_SHAPES . ' in a document. Word: a drawing shape (wps:wsp), its text in w:txbxContent.',
            'properties' => [
                'type' => ['const' => 'shape'],
                'geometry' => ['enum' => self::GEOMETRIES, 'description' => 'The outline drawn: rect by default. line and straightConnector1 are strokes: one dimension may be 0 and they hold no blocks; set flipV for a line that rises left to right.'],
                'widthPx' => ['type' => 'number', 'minimum' => 0, 'maximum' => self::MAX_EXTENT_PX, 'description' => 'Width in px at 96 dpi (96 = one inch).'],
                'heightPx' => ['type' => 'number', 'minimum' => 0, 'maximum' => self::MAX_EXTENT_PX, 'description' => 'Height in px at 96 dpi. 0 only for a line.'],
                'fill' => $hex + ['description' => 'Fill colour. Absent, no fill: the page shows through.'],
                'outline' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'description' => 'The outline. Absent, 0.75pt solid black. {"style": "none"} removes it.',
                    'properties' => [
                        'color' => $hex + ['description' => 'Black by default.'],
                        'width' => ['type' => 'number', 'exclusiveMinimum' => 0, 'maximum' => self::MAX_OUTLINE_WIDTH, 'description' => 'Points; 0.75 by default.'],
                        'style' => ['enum' => self::OUTLINE_STYLES],
                        'headEnd' => ['enum' => self::LINE_ENDS, 'description' => 'Arrowhead where the line starts.'],
                        'tailEnd' => ['enum' => self::LINE_ENDS, 'description' => 'Arrowhead where the line ends.'],
                    ],
                ],
                'flipH' => ['type' => 'boolean', 'description' => 'Mirror left to right.'],
                'flipV' => ['type' => 'boolean', 'description' => 'Mirror top to bottom.'],
                'anchor' => ['$ref' => '#/$defs/anchor'],
                'verticalAlign' => ['enum' => self::VERTICAL_ALIGNS, 'description' => 'Where a text box\'s text sits: top by default.'],
                'alt' => ['type' => 'string', 'description' => 'Alternative text, for a screen reader.'],
                'blocks' => [
                    'type' => 'array',
                    'description' => 'The text box\'s content: the body\'s blocks less chart, pageBreak, toc, columnBreak and shape. Runs carry no footnote, bookmark or revision. At most ' . self::MAX_BOX_BLOCKS . ' blocks and ' . self::MAX_BOX_TEXT . ' characters.',
                    'items' => [
                        'allOf' => [
                            $blockRef,
                            ['not' => ['properties' => ['type' => ['enum' => ['chart', 'pageBreak', 'toc', 'columnBreak', 'shape']]], 'required' => ['type']]],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Errors for a shape's own keys (not its blocks, which the validator walks).
     *
     * @param  array<string, mixed>  $shape
     * @return list<array{path: string, message: string, code: string}>
     */
    public static function validateOwn(array $shape, string $path): array
    {
        $errors = [];
        $geometry = $shape['geometry'] ?? 'rect';
        if (!in_array($geometry, self::GEOMETRIES, true)) {
            $errors[] = self::error("{$path}.geometry", 'shape geometry must be one of the preset geometries in jsonSchema() (rect, roundRect, ellipse, line, rightArrow, wedgeRectCallout ...)', 'shape_geometry_unknown');
            $geometry = 'rect';
        }
        $isLine = in_array($geometry, self::LINES, true);
        $dims = [];
        foreach (['widthPx', 'heightPx'] as $dim) {
            $value = $shape[$dim] ?? null;
            if (!array_key_exists($dim, $shape)) {
                $errors[] = self::error("{$path}.{$dim}", "shape requires {$dim} (px at 96 dpi)", 'shape_size_invalid');
            } elseif ((!is_int($value) && !is_float($value)) || !is_finite((float) $value)) {
                $errors[] = self::error("{$path}.{$dim}", "shape {$dim} must be a number of px", 'shape_size_invalid');
            } elseif ($value < 0 || $value > self::MAX_EXTENT_PX) {
                $errors[] = self::error("{$path}.{$dim}", "shape {$dim} must be between 0 and " . self::MAX_EXTENT_PX . ' px', 'shape_size_invalid');
            } else {
                $dims[$dim] = (float) $value;
            }
        }
        if (count($dims) === 2) {
            if ($dims['widthPx'] == 0 && $dims['heightPx'] == 0) {
                $errors[] = self::error($path, 'a shape needs a width or a height', 'shape_size_invalid');
            } elseif (!$isLine && ($dims['widthPx'] == 0 || $dims['heightPx'] == 0)) {
                $errors[] = self::error($path, 'only a line may be 0 wide or 0 high', 'shape_size_invalid');
            }
        }
        if (array_key_exists('fill', $shape) && !self::isHex($shape['fill'])) {
            $errors[] = self::error("{$path}.fill", 'shape fill must be a #RRGGBB hex string; leave it out for no fill', 'shape_invalid');
        }
        if (array_key_exists('outline', $shape)) {
            array_push($errors, ...self::validateOutline($shape['outline'], "{$path}.outline"));
        }
        foreach (['flipH', 'flipV'] as $flag) {
            if (array_key_exists($flag, $shape) && !is_bool($shape[$flag])) {
                $errors[] = self::error("{$path}.{$flag}", "shape {$flag} must be a boolean", 'shape_invalid');
            }
        }
        if (array_key_exists('alt', $shape) && !is_string($shape['alt'])) {
            $errors[] = self::error("{$path}.alt", 'alt must be a string when present', 'shape_invalid');
        }
        if (array_key_exists('anchor', $shape)) {
            array_push($errors, ...Anchor::validate($shape['anchor'], "{$path}.anchor"));
        }
        $hasBlocks = array_key_exists('blocks', $shape);
        if ($hasBlocks && (!is_array($shape['blocks']) || !array_is_list($shape['blocks']))) {
            $errors[] = self::error("{$path}.blocks", 'a text box\'s blocks must be a list of blocks', 'shape_invalid');
        } elseif ($hasBlocks && $isLine) {
            $errors[] = self::error("{$path}.blocks", 'a line holds no text; use a rect (or another area) for a text box', 'shape_text_on_line');
        }
        if (array_key_exists('verticalAlign', $shape)) {
            if (!in_array($shape['verticalAlign'], self::VERTICAL_ALIGNS, true)) {
                $errors[] = self::error("{$path}.verticalAlign", 'verticalAlign must be top, middle or bottom', 'shape_invalid');
            } elseif (!$hasBlocks) {
                $errors[] = self::error("{$path}.verticalAlign", 'verticalAlign places a text box\'s text; this shape has no blocks', 'shape_invalid');
            }
        }

        return $errors;
    }

    /** @return list<array{path: string, message: string, code: string}> */
    private static function validateOutline(mixed $outline, string $path): array
    {
        if (!is_array($outline) || ($outline !== [] && array_is_list($outline))) {
            return [self::error($path, 'shape outline must be an object ({color?, width?, style?, headEnd?, tailEnd?})', 'shape_invalid')];
        }
        $errors = [];
        $unknown = count(array_diff(array_keys($outline), ['color', 'width', 'style', 'headEnd', 'tailEnd']));
        if ($unknown > 0) {
            $errors[] = self::error($path, "shape outline takes color, width, style, headEnd and tailEnd only ({$unknown} other key" . ($unknown === 1 ? '' : 's') . ')', 'shape_invalid');
        }
        if (array_key_exists('color', $outline) && !self::isHex($outline['color'])) {
            $errors[] = self::error("{$path}.color", 'outline color must be a #RRGGBB hex string', 'shape_invalid');
        }
        if (array_key_exists('width', $outline)) {
            $w = $outline['width'];
            if ((!is_int($w) && !is_float($w)) || !is_finite((float) $w) || $w <= 0 || $w > self::MAX_OUTLINE_WIDTH) {
                $errors[] = self::error("{$path}.width", 'outline width must be a number of points above 0 and at most ' . self::MAX_OUTLINE_WIDTH, 'shape_invalid');
            }
        }
        if (array_key_exists('style', $outline) && !in_array($outline['style'], self::OUTLINE_STYLES, true)) {
            $errors[] = self::error("{$path}.style", 'outline style must be solid, dashed, dotted or none', 'shape_invalid');
        }
        foreach (['headEnd', 'tailEnd'] as $end) {
            if (array_key_exists($end, $outline) && !in_array($outline[$end], self::LINE_ENDS, true)) {
                $errors[] = self::error("{$path}.{$end}", "outline {$end} must be one of: " . implode(', ', self::LINE_ENDS), 'shape_invalid');
            }
        }

        return $errors;
    }

    /**
     * Errors for what a text box's content may not hold, and how much of it there
     * is. Its blocks' own rules are the validator's; this is only what is
     * different inside a text box.
     *
     * @param  list<mixed>  $blocks
     * @return list<array{path: string, message: string, code: string}>
     */
    public static function validateContent(array $blocks, string $path): array
    {
        $errors = [];
        self::refuseInBox($blocks, $path, $errors, 0);
        [$count, $text] = self::measure($blocks);
        if ($count > self::MAX_BOX_BLOCKS) {
            $errors[] = self::error($path, 'a text box holds at most ' . self::MAX_BOX_BLOCKS . " blocks, counted at every depth (found {$count}); continue the text after it", 'shape_content_too_large');
        }
        if ($text > self::MAX_BOX_TEXT) {
            $errors[] = self::error($path, 'a text box holds at most ' . self::MAX_BOX_TEXT . " characters (found {$text}); continue the text after it", 'shape_content_too_large');
        }

        return $errors;
    }

    /**
     * @param  list<mixed>  $blocks
     * @param  list<array{path: string, message: string, code: string}>  $errors
     */
    private static function refuseInBox(array $blocks, string $path, array &$errors, int $depth): void
    {
        if ($depth > Portable::MAX_DEPTH) {
            return;
        }
        foreach ($blocks as $i => $block) {
            if (!is_array($block)) {
                continue;
            }
            $at = "{$path}[{$i}]";
            $type = $block['type'] ?? null;
            if (in_array($type, self::TEXTBOX_REFUSED_TYPES, true)) {
                $errors[] = self::error("{$at}.type", "a text box cannot hold a {$type} block", 'shape_content_refused');

                continue;
            }
            if (array_key_exists('revision', $block)) {
                $errors[] = self::error("{$at}.revision", 'a text box carries no tracked changes; accept them first', 'shape_content_refused');
            }
            self::refuseInRuns($block['runs'] ?? null, "{$at}.runs", $errors);
            self::refuseInItems($block['items'] ?? null, "{$at}.items", $errors, $depth);
            foreach (self::children($block) as [$childPath, $children]) {
                self::refuseInBox($children, "{$at}.{$childPath}", $errors, $depth + 1);
            }
        }
    }

    /** @param  list<array{path: string, message: string, code: string}>  $errors */
    private static function refuseInItems(mixed $items, string $path, array &$errors, int $depth): void
    {
        if (!is_array($items) || !array_is_list($items) || $depth > Portable::MAX_DEPTH) {
            return;
        }
        foreach ($items as $i => $item) {
            if (!is_array($item)) {
                continue;
            }
            if (array_key_exists('revision', $item)) {
                $errors[] = self::error("{$path}[{$i}].revision", 'a text box carries no tracked changes; accept them first', 'shape_content_refused');
            }
            self::refuseInRuns($item['runs'] ?? null, "{$path}[{$i}].runs", $errors);
            self::refuseInItems($item['children'] ?? null, "{$path}[{$i}].children", $errors, $depth + 1);
        }
    }

    /** @param  list<array{path: string, message: string, code: string}>  $errors */
    private static function refuseInRuns(mixed $runs, string $path, array &$errors): void
    {
        if (!is_array($runs) || !array_is_list($runs)) {
            return;
        }
        foreach ($runs as $j => $run) {
            if (!is_array($run)) {
                continue;
            }
            foreach (['footnote' => 'a footnote', 'bookmark' => 'a bookmark', 'revision' => 'a tracked change'] as $key => $what) {
                if (array_key_exists($key, $run)) {
                    $errors[] = self::error("{$path}[{$j}].{$key}", "a text box cannot hold {$what}; put it in the body text", 'shape_content_refused');
                }
            }
        }
    }

    /**
     * The block lists inside a block, as [path suffix, blocks]: a quote's, a
     * table's cells', a shape's.
     *
     * @param  array<string, mixed>  $block
     * @return list<array{0: string, 1: list<mixed>}>
     */
    public static function children(array $block): array
    {
        $out = [];
        $type = $block['type'] ?? null;
        if (($type === 'quote' || $type === 'shape') && is_array($block['blocks'] ?? null) && array_is_list($block['blocks'])) {
            $out[] = ['blocks', $block['blocks']];
        }
        if ($type === 'table' && is_array($block['rows'] ?? null)) {
            foreach ($block['rows'] as $r => $row) {
                foreach (is_array($row) && is_array($row['cells'] ?? null) ? $row['cells'] : [] as $c => $cell) {
                    if (is_array($cell) && is_array($cell['blocks'] ?? null) && array_is_list($cell['blocks'])) {
                        $out[] = ["rows[{$r}].cells[{$c}].blocks", $cell['blocks']];
                    }
                }
            }
        }

        return $out;
    }

    /**
     * How much a list of blocks holds: blocks at every depth (a list's items and
     * a table's cells count as blocks), and characters of run and code text.
     *
     * @param  list<mixed>  $blocks
     * @return array{0: int, 1: int}
     */
    public static function measure(array $blocks, int $depth = 0): array
    {
        $count = 0;
        $text = 0;
        if ($depth > Portable::MAX_DEPTH) {
            return [0, 0];
        }
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            $count++;
            $text += self::runText($block['runs'] ?? null);
            if (is_string($block['text'] ?? null)) {
                $text += mb_strlen($block['text']);
            }
            [$c, $t] = self::measureItems($block['items'] ?? null, $depth);
            $count += $c;
            $text += $t;
            foreach (self::children($block) as [, $children]) {
                [$c, $t] = self::measure($children, $depth + 1);
                $count += $c + 1;
                $text += $t;
            }
        }

        return [$count, $text];
    }

    /** @return array{0: int, 1: int} */
    private static function measureItems(mixed $items, int $depth): array
    {
        if (!is_array($items) || $depth > Portable::MAX_DEPTH) {
            return [0, 0];
        }
        $count = 0;
        $text = 0;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $count++;
            $text += self::runText($item['runs'] ?? null);
            [$c, $t] = self::measureItems($item['children'] ?? null, $depth + 1);
            $count += $c;
            $text += $t;
        }

        return [$count, $text];
    }

    private static function runText(mixed $runs): int
    {
        $n = 0;
        foreach (is_array($runs) ? $runs : [] as $run) {
            if (is_array($run) && is_string($run['text'] ?? null)) {
                $n += mb_strlen($run['text']);
            }
        }

        return $n;
    }

    /**
     * Every shape in a document's body, at any depth (text boxes included).
     *
     * @param  list<mixed>  $blocks
     */
    public static function count(array $blocks, int $depth = 0): int
    {
        if ($depth > Portable::MAX_DEPTH) {
            return 0;
        }
        $n = 0;
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            if (($block['type'] ?? null) === 'shape') {
                $n++;
            }
            foreach (self::children($block) as [, $children]) {
                $n += self::count($children, $depth + 1);
            }
        }

        return $n;
    }

    /**
     * A text box's blocks split where they stop fitting: what stays inside, and
     * what continues after the box. Whole blocks only, in order; a single block
     * too big on its own goes after the box too. Shared by repair and the reader,
     * so a hostile file and a hostile agent end with the same document.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    public static function fit(array $blocks): array
    {
        $kept = [];
        $count = 0;
        $text = 0;
        foreach ($blocks as $i => $block) {
            [$c, $t] = self::measure([$block]);
            if ($count + $c > self::MAX_BOX_BLOCKS || $text + $t > self::MAX_BOX_TEXT) {
                return [$kept, array_values(array_slice($blocks, $i))];
            }
            $kept[] = $block;
            $count += $c;
            $text += $t;
        }

        return [$kept, []];
    }

    /**
     * A shape as only its text: the blocks of a text box, with every shape inside
     * them replaced by its own blocks too. What a shape becomes where the model
     * cannot hold one (a header, a footer, a footnote, another text box, past the
     * cap): its text stays in the document.
     *
     * @param  array<string, mixed>  $shape
     * @return list<array<string, mixed>>
     */
    public static function flatten(array $shape, int $depth = 0): array
    {
        $blocks = is_array($shape['blocks'] ?? null) && array_is_list($shape['blocks']) ? $shape['blocks'] : [];

        return self::flattenList($blocks, $depth);
    }

    /**
     * @param  list<mixed>  $blocks
     * @return list<array<string, mixed>>
     */
    public static function flattenList(array $blocks, int $depth = 0): array
    {
        $out = [];
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            if (($block['type'] ?? null) === 'shape') {
                if ($depth <= Portable::MAX_DEPTH) {
                    array_push($out, ...self::flatten($block, $depth + 1));
                }

                continue;
            }
            $out[] = $block;
        }

        return $out;
    }

    /**
     * describe()'s line: "Shapes: 3 (2 text boxes, 1 floating)", or null for none.
     *
     * @param  list<mixed>  $blocks
     */
    public static function describe(array $blocks): ?string
    {
        $all = 0;
        $boxes = 0;
        $floating = 0;
        $walk = static function (array $list, int $depth) use (&$walk, &$all, &$boxes, &$floating): void {
            if ($depth > Portable::MAX_DEPTH) {
                return;
            }
            foreach ($list as $block) {
                if (!is_array($block)) {
                    continue;
                }
                if (($block['type'] ?? null) === 'shape') {
                    $all++;
                    $boxes += array_key_exists('blocks', $block) ? 1 : 0;
                    $floating += is_array($block['anchor'] ?? null) ? 1 : 0;
                }
                foreach (self::children($block) as [, $children]) {
                    $walk($children, $depth + 1);
                }
            }
        };
        $walk($blocks, 0);
        if ($all === 0) {
            return null;
        }

        return "Shapes: {$all} ({$boxes} text box" . ($boxes === 1 ? '' : 'es') . ", {$floating} floating)";
    }

    public static function isHex(mixed $value): bool
    {
        return is_string($value) && preg_match('/^#[0-9A-Fa-f]{6}$/D', $value) === 1;
    }

    /** @return array{path: string, message: string, code: string} */
    private static function error(string $path, string $message, string $code): array
    {
        return ['path' => $path, 'message' => $message, 'code' => $code];
    }
}
