<?php

declare(strict_types=1);

namespace LastWord\Schema;

use LastWord\Exchange\Content;
use LastWord\Exchange\Portable;
use LastWord\Helpers\Chart;

/**
 * Structural validation of the Doc model. Returns a flat error list —
 * `{path, message}` pairs, empty when the document is valid — designed to
 * hand straight back to an agent so it can correct its next emission.
 *
 * Lenient about unknown extra keys (agents decorate), strict about the
 * keys the writer actually consumes.
 */
final class Validator
{
    private const BORDER_STYLES = ['single', 'double', 'dashed', 'dotted', 'none'];

    private const BOX_EDGES = ['top', 'right', 'bottom', 'left'];

    private const TABLE_EDGES = ['top', 'right', 'bottom', 'left', 'insideH', 'insideV'];

    /**
     * @param  array<string, mixed>  $doc
     * @return list<array{path: string, message: string}>
     */
    public function validate(array $doc): array
    {
        $errors = [];

        if (array_key_exists('title', $doc) && !is_string($doc['title'])) {
            $errors[] = self::error('title', 'title must be a string when present');
        }

        if (isset($doc['defaultFont']) && !is_string($doc['defaultFont'])) {
            $errors[] = self::error('defaultFont', 'defaultFont must be a font family name');
        }
        $this->validateNumber($doc['defaultSize'] ?? null, 'defaultSize', 'defaultSize', $errors, true);

        if (isset($doc['page'])) {
            if (!is_array($doc['page'])) {
                $errors[] = self::error('page', 'page must be an object ({size?, orientation?, margins?})');
            } else {
                $this->validateEnum($doc['page']['size'] ?? null, 'page.size', 'page size', ['letter', 'legal', 'a4'], $errors);
                $this->validateEnum($doc['page']['orientation'] ?? null, 'page.orientation', 'page orientation', ['portrait', 'landscape'], $errors);
                $this->validateSides($doc['page']['margins'] ?? null, 'page.margins', 'page margins', $errors);
                foreach (['header', 'footer'] as $kind) {
                    $band = $doc['page'][$kind] ?? null;
                    if ($band === null) {
                        continue;
                    }
                    if (!is_array($band)) {
                        $errors[] = self::error("page.{$kind}", "page {$kind} must be an object ({left?, center?, right?, size?, color?})");

                        continue;
                    }
                    foreach (['left', 'center', 'right'] as $slot) {
                        if (isset($band[$slot]) && !is_string($band[$slot])) {
                            $errors[] = self::error("page.{$kind}.{$slot}", "page {$kind} {$slot} must be text");
                        }
                    }
                    if (isset($band['size']) && (!is_numeric($band['size']) || (float) $band['size'] <= 0)) {
                        $errors[] = self::error("page.{$kind}.size", "page {$kind} size must be a positive number of points");
                    }
                    if (isset($band['color']) && (!is_string($band['color']) || preg_match('/^#[0-9A-Fa-f]{6}$/', $band['color']) !== 1)) {
                        $errors[] = self::error("page.{$kind}.color", "page {$kind} color must be #RRGGBB");
                    }
                }
                $this->validatePageDecoration($doc, $errors);
            }
        }

        if (!array_key_exists('blocks', $doc)) {
            $errors[] = self::error('blocks', 'missing required key "blocks" (array of block objects)');

            return $errors;
        }
        if (!is_array($doc['blocks']) || !array_is_list($doc['blocks'])) {
            $errors[] = self::error('blocks', 'blocks must be a list of block objects');

            return $errors;
        }

        $this->validateBlocks($doc['blocks'], 'blocks', $errors);

        return $errors;
    }

    /**
     * `page.background` and `page.border`, with fine-print's rules: hex
     * colours, a known style, a positive width, a non-negative inset, and a
     * frame that leaves room inside it on both axes.
     *
     * @param  array<string, mixed>  $doc
     * @param  list<array{path: string, message: string}>  $errors
     */
    private function validatePageDecoration(array $doc, array &$errors): void
    {
        $page = $doc['page'];
        $this->validateHex($page['background'] ?? null, 'page.background', 'page background', $errors);
        if (!isset($page['border'])) {
            return;
        }
        $border = $page['border'];
        if (!is_array($border) || ($border !== [] && array_is_list($border))) {
            $errors[] = self::error('page.border', 'page border must be an object ({style?, width?, color?, inset?})');

            return;
        }
        $before = count($errors);
        $this->validateEnum($border['style'] ?? null, 'page.border.style', 'page border style', self::BORDER_STYLES, $errors);
        $this->validateFrameNumber($border['width'] ?? null, 'page.border.width', 'page border width', true, $errors);
        $this->validateHex($border['color'] ?? null, 'page.border.color', 'page border color', $errors);
        $this->validateFrameNumber($border['inset'] ?? null, 'page.border.inset', 'page border inset', false, $errors);
        $frame = Schema::pageFrame($doc);
        if (count($errors) === $before && $frame !== null) {
            [$w, $h] = Schema::pageSize($doc);
            if (2 * ($frame['inset'] + $frame['width']) >= min($w, $h)) {
                $errors[] = self::error('page.border', 'page border inset + width must leave room inside the frame on both axes');
            }
        }
    }

    /** @param  list<array{path: string, message: string}>  $errors */
    private function validateFrameNumber(mixed $value, string $path, string $label, bool $positive, array &$errors): void
    {
        if ($value === null) {
            return;
        }
        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value)) {
            $errors[] = self::error($path, "{$label} must be a number of points");
        } elseif ($positive && $value <= 0) {
            $errors[] = self::error($path, "{$label} must be greater than zero");
        } elseif (!$positive && $value < 0) {
            $errors[] = self::error($path, "{$label} must not be negative");
        } elseif (abs((float) $value) > 10000) {
            $errors[] = self::error($path, "{$label} is out of range");
        }
    }

    /**
     * @param  list<mixed>  $blocks
     * @param  list<array{path: string, message: string}>  $errors
     */
    private function validateBlocks(array $blocks, string $path, array &$errors): void
    {
        foreach ($blocks as $i => $block) {
            $this->validateBlock($block, "{$path}[{$i}]", $errors);
        }
    }

    /**
     * @param  list<array{path: string, message: string}>  $errors
     */
    private function validateBlock(mixed $block, string $path, array &$errors): void
    {
        if (!is_array($block)) {
            $errors[] = self::error($path, 'block must be an object, got ' . get_debug_type($block));

            return;
        }
        $type = $block['type'] ?? null;
        if (!is_string($type)) {
            $errors[] = self::error("{$path}.type", 'block is missing its "type" discriminator');

            return;
        }
        if ($type === Portable::TYPE) {
            // Portable content from another package: checked closed and against
            // the exchange limits; expanded by validateAndRepair and on write.
            $check = Content::validate($block['content'] ?? null);
            if (!$check['ok']) {
                $errors[] = self::error("{$path}.content", "portable content is invalid: {$check['code']} at \"{$check['path']}\" (fancyContent 1; see the exchange spec)");
            }

            return;
        }
        if (!in_array($type, Schema::BLOCK_TYPES, true)) {
            $errors[] = self::error("{$path}.type", "unknown block type \"{$type}\" (expected one of: " . implode(', ', Schema::BLOCK_TYPES) . ', or portable)');

            return;
        }

        match ($type) {
            'heading' => $this->validateHeading($block, $path, $errors),
            'paragraph' => $this->validateParagraph($block, $path, $errors),
            'list' => $this->validateList($block, $path, $errors),
            'table' => $this->validateTable($block, $path, $errors),
            'code' => $this->validateCode($block, $path, $errors),
            'quote' => $this->validateQuote($block, $path, $errors),
            'image' => $this->validateImage($block, $path, $errors),
            'chart' => $this->validateChart($block, $path, $errors),
            'pageBreak', 'hr' => null,
        };
    }

    /**
     * A chart block. Everything {@see Chart::spec()} needs to draw is checked
     * here, so a valid chart is always a drawable one: the writer's fallback text
     * is for unvalidated input, never for an agent's document.
     *
     * @param  array<string, mixed>  $block
     * @param  list<array{path: string, message: string}>  $errors
     */
    private function validateChart(array $block, string $path, array &$errors): void
    {
        foreach (['widthPx', 'heightPx'] as $dim) {
            $value = $block[$dim] ?? null;
            if ($value !== null && (!(is_int($value) || is_float($value)) || $value <= 0)) {
                $errors[] = self::error("{$path}.{$dim}", "{$dim} must be a positive number of px when present");
            }
        }
        $width = $block['width'] ?? null;
        if ($width !== null && (!(is_int($width) || is_float($width)) || $width <= 0 || $width > 100)) {
            $errors[] = self::error("{$path}.width", 'chart width must be a percentage of the text column, above 0 and at most 100');
        }
        if (isset($block['numberFormat']) && (!is_string($block['numberFormat']) || $block['numberFormat'] === '')) {
            $errors[] = self::error("{$path}.numberFormat", 'numberFormat must be an Excel number format string, e.g. "#,##0" or "0.0%"');
        }
        if (isset($block['alt']) && !is_string($block['alt'])) {
            $errors[] = self::error("{$path}.alt", 'alt must be a string when present');
        }

        $option = $block['option'] ?? null;
        if (!is_array($option) || ($option !== [] && array_is_list($option))) {
            $errors[] = self::error("{$path}.option", 'chart requires an "option" object: an Apache ECharts option, e.g. {"xAxis": {"type": "category", "data": ["Q1", "Q2"]}, "yAxis": {"type": "value"}, "series": [{"type": "bar", "name": "Revenue", "data": [120, 135]}]}');

            return;
        }
        $optPath = "{$path}.option";

        $series = $option['series'] ?? null;
        if (is_array($series) && $series !== [] && !array_is_list($series)) {
            $series = [$series];
        }
        if (!is_array($series) || $series === []) {
            $errors[] = self::error("{$optPath}.series", 'chart option requires "series": a list of {type, name, data} objects');

            return;
        }

        $supported = implode(', ', Chart::SUPPORTED_TYPES);
        $kind = null;
        foreach ($series as $i => $one) {
            $serPath = "{$optPath}.series[{$i}]";
            if (!is_array($one) || ($one !== [] && array_is_list($one))) {
                $errors[] = self::error($serPath, 'a series must be an object ({type, name, data})');

                continue;
            }
            $type = $one['type'] ?? 'bar';
            if (!is_string($type) || !in_array($type, Chart::SUPPORTED_TYPES, true)) {
                $errors[] = self::error("{$serPath}.type", "series type must be one of: {$supported} (a document chart cannot draw " . (is_string($type) ? "\"{$type}\"" : 'that') . ')');

                continue;
            }
            if ($kind === null) {
                $kind = $type;
            } elseif ($type !== $kind) {
                $errors[] = self::error("{$serPath}.type", "every series in a chart must be the same type: this one is \"{$type}\", the first is \"{$kind}\"");
            }
            if (isset($one['name']) && !is_string($one['name']) && !is_int($one['name']) && !is_float($one['name'])) {
                $errors[] = self::error("{$serPath}.name", 'series name must be a string');
            }
            $this->validateHex(is_array($one['itemStyle'] ?? null) ? ($one['itemStyle']['color'] ?? null) : null, "{$serPath}.itemStyle.color", 'series colour', $errors);

            $data = $one['data'] ?? null;
            if (!is_array($data) || ($data !== [] && !array_is_list($data))) {
                $hint = isset($one['values']) ? ' (ECharts calls it "data", not "values")' : '';
                $errors[] = self::error("{$serPath}.data", 'series requires a "data" list of numbers' . $hint);

                continue;
            }
            foreach ($data as $p => $point) {
                if (Chart::pointValue($point) === null) {
                    $errors[] = self::error("{$serPath}.data[{$p}]", $type === 'pie'
                        ? 'a pie point must be {"name": string, "value": number}'
                        : 'a point must be a number, or {"value": number}');

                    continue;
                }
                if (is_array($point)) {
                    $this->validateHex(is_array($point['itemStyle'] ?? null) ? ($point['itemStyle']['color'] ?? null) : null, "{$serPath}.data[{$p}].itemStyle.color", 'point colour', $errors);
                }
            }
        }
        if ($kind === 'pie' && count($series) > 1) {
            $errors[] = self::error("{$optPath}.series", 'a pie chart takes exactly one series');
        }

        foreach (['xAxis', 'yAxis'] as $key) {
            $axis = $option[$key] ?? null;
            if ($axis === null) {
                continue;
            }
            if (is_array($axis) && $axis !== [] && array_is_list($axis)) {
                $axis = $axis[0];
            }
            if (!is_array($axis)) {
                $errors[] = self::error("{$optPath}.{$key}", "{$key} must be an object ({type, data, name})");

                continue;
            }
            $this->validateEnum($axis['type'] ?? null, "{$optPath}.{$key}.type", "{$key} type", ['category', 'value'], $errors);
            if (isset($axis['data']) && (!is_array($axis['data']) || ($axis['data'] !== [] && !array_is_list($axis['data'])))) {
                $errors[] = self::error("{$optPath}.{$key}.data", "{$key}.data must be a list of category labels");
            }
            if (isset($axis['name']) && !is_string($axis['name'])) {
                $errors[] = self::error("{$optPath}.{$key}.name", "{$key}.name (the axis title) must be a string");
            }
        }

        if (isset($option['color'])) {
            if (!is_array($option['color']) || ($option['color'] !== [] && !array_is_list($option['color']))) {
                $errors[] = self::error("{$optPath}.color", 'option.color must be a list of #RRGGBB colours');
            } else {
                foreach ($option['color'] as $c => $color) {
                    $this->validateHex($color, "{$optPath}.color[{$c}]", 'palette colour', $errors);
                }
            }
        }
    }

    /** @param  array<string, mixed>  $block */
    private function validateHeading(array $block, string $path, array &$errors): void
    {
        $level = $block['level'] ?? null;
        if (!is_int($level) && !(is_float($level) && floor($level) === $level)) {
            $errors[] = self::error("{$path}.level", 'heading requires an integer "level" (1-' . Schema::MAX_HEADING_LEVEL . ')');
        } elseif ($level < 1 || $level > Schema::MAX_HEADING_LEVEL) {
            $errors[] = self::error("{$path}.level", "heading level {$level} is out of range 1-" . Schema::MAX_HEADING_LEVEL);
        }
        $this->validateRuns($block['runs'] ?? null, "{$path}.runs", $errors);
        // A heading is a paragraph and takes the same properties — before
        // this, a section label that needed spacing had to be a bold paragraph
        // impersonating one, and so appeared in no navigation pane.
        $this->validateParagraphProps($block, $path, $errors);
    }

    /** @param  array<string, mixed>  $block */
    private function validateParagraph(array $block, string $path, array &$errors): void
    {
        $this->validateRuns($block['runs'] ?? null, "{$path}.runs", $errors);
        $this->validateParagraphProps($block, $path, $errors);
    }

    /**
     * The properties every paragraph-shaped block accepts — `paragraph`,
     * `heading` and a list item alike.
     *
     * @param  array<string, mixed>  $block
     * @param  list<array{path: string, message: string}>  $errors
     */
    private function validateParagraphProps(array $block, string $path, array &$errors): void
    {
        if (isset($block['align']) && !in_array($block['align'], Schema::ALIGNMENTS, true)) {
            $errors[] = self::error("{$path}.align", 'align must be one of: ' . implode(', ', Schema::ALIGNMENTS));
        }
        $this->validateNumber($block['spaceBefore'] ?? null, "{$path}.spaceBefore", 'spaceBefore', $errors);
        $this->validateNumber($block['spaceAfter'] ?? null, "{$path}.spaceAfter", 'spaceAfter', $errors);
        $this->validateNumber($block['lineHeight'] ?? null, "{$path}.lineHeight", 'lineHeight (a multiple of single spacing)', $errors, true);
        $this->validateNumber($block['indentLeft'] ?? null, "{$path}.indentLeft", 'indentLeft', $errors);
        $this->validateNumber($block['indentRight'] ?? null, "{$path}.indentRight", 'indentRight', $errors);
        if (isset($block['keepNext']) && !is_bool($block['keepNext'])) {
            $errors[] = self::error("{$path}.keepNext", 'keepNext must be a boolean');
        }
        $this->validateHex($block['shading'] ?? null, "{$path}.shading", 'shading', $errors);
        $this->validateBorders($block['borders'] ?? null, "{$path}.borders", 'borders', self::BOX_EDGES, $errors);
    }

    /**
     * A finite number of points, or absent.
     *
     * @param  list<array{path: string, message: string}>  $errors
     */
    private function validateNumber(mixed $value, string $path, string $label, array &$errors, bool $positive = false): void
    {
        if ($value === null) {
            return;
        }
        if (!is_int($value) && !is_float($value)) {
            $errors[] = self::error($path, "{$label} must be a number of points");

            return;
        }
        if ($positive && $value <= 0) {
            $errors[] = self::error($path, "{$label} must be greater than zero");
        }
    }

    /** @param  list<array{path: string, message: string}>  $errors */
    private function validateHex(mixed $value, string $path, string $label, array &$errors): void
    {
        if ($value !== null && (!is_string($value) || preg_match('/^#[0-9A-Fa-f]{6}$/', $value) !== 1)) {
            $errors[] = self::error($path, "{$label} must be a #RRGGBB hex string");
        }
    }

    /**
     * @param  list<string>  $allowed
     * @param  list<array{path: string, message: string}>  $errors
     */
    private function validateEnum(mixed $value, string $path, string $label, array $allowed, array &$errors): void
    {
        if ($value !== null && !in_array($value, $allowed, true)) {
            $errors[] = self::error($path, "{$label} must be one of: " . implode(', ', $allowed));
        }
    }

    /** @param  list<array{path: string, message: string}>  $errors */
    private function validateSides(mixed $value, string $path, string $label, array &$errors): void
    {
        if ($value === null) {
            return;
        }
        if (!is_array($value)) {
            $errors[] = self::error($path, "{$label} must be an object of sides in points");

            return;
        }
        foreach (self::BOX_EDGES as $side) {
            $this->validateNumber($value[$side] ?? null, "{$path}.{$side}", $side, $errors);
        }
    }

    /**
     * @param  list<string>  $edges
     * @param  list<array{path: string, message: string}>  $errors
     */
    private function validateBorders(mixed $value, string $path, string $label, array $edges, array &$errors): void
    {
        if ($value === null) {
            return;
        }
        if (!is_array($value)) {
            $errors[] = self::error($path, "{$label} must be an object keyed by edge");

            return;
        }
        foreach ($edges as $edge) {
            $border = $value[$edge] ?? null;
            if ($border === null) {
                continue;
            }
            $edgePath = "{$path}.{$edge}";
            if (!is_array($border)) {
                $errors[] = self::error($edgePath, 'a border must be an object ({style?, width?, color?})');

                continue;
            }
            $this->validateEnum($border['style'] ?? null, "{$edgePath}.style", 'border style', self::BORDER_STYLES, $errors);
            $this->validateNumber($border['width'] ?? null, "{$edgePath}.width", 'border width', $errors, true);
            $this->validateHex($border['color'] ?? null, "{$edgePath}.color", 'border color', $errors);
        }
    }

    /** @param  array<string, mixed>  $block */
    private function validateList(array $block, string $path, array &$errors): void
    {
        $items = $block['items'] ?? null;
        if (!is_array($items) || !array_is_list($items)) {
            $errors[] = self::error("{$path}.items", 'list requires an "items" array');

            return;
        }
        $this->validateListItems($items, "{$path}.items", $errors);
    }

    /**
     * @param  list<mixed>  $items
     */
    private function validateListItems(array $items, string $path, array &$errors, int $depth = 0): void
    {
        foreach ($items as $i => $item) {
            $itemPath = "{$path}[{$i}]";
            if (!is_array($item)) {
                $errors[] = self::error($itemPath, 'list item must be an object with "runs"');

                continue;
            }
            $this->validateRuns($item['runs'] ?? null, "{$itemPath}.runs", $errors);
            $this->validateParagraphProps($item, $itemPath, $errors);
            if (isset($item['children'])) {
                if (!is_array($item['children']) || !array_is_list($item['children'])) {
                    $errors[] = self::error("{$itemPath}.children", 'children must be a list of list items');
                } else {
                    $this->validateListItems($item['children'], "{$itemPath}.children", $errors, $depth + 1);
                }
            }
        }
    }

    /** @param  array<string, mixed>  $block */
    private function validateTable(array $block, string $path, array &$errors): void
    {
        $this->validateEnum($block['align'] ?? null, "{$path}.align", 'table align', ['left', 'center', 'right'], $errors);
        $this->validateBorders($block['borders'] ?? null, "{$path}.borders", 'table borders', self::TABLE_EDGES, $errors);
        $this->validateSides($block['cellPadding'] ?? null, "{$path}.cellPadding", 'table cellPadding', $errors);

        $width = $block['width'] ?? null;
        if ($width !== null && (!(is_int($width) || is_float($width)) || $width <= 0 || $width > 100)) {
            $errors[] = self::error("{$path}.width", 'table width must be a percentage of the text column, above 0 and at most 100');
        }

        $widths = $block['widths'] ?? null;
        if ($widths !== null) {
            if (!is_array($widths) || !array_is_list($widths) || $widths === []) {
                $errors[] = self::error("{$path}.widths", 'table widths must be a non-empty array of relative column weights');
            } else {
                foreach ($widths as $i => $w) {
                    if (!(is_int($w) || is_float($w)) || $w < 0) {
                        $errors[] = self::error("{$path}.widths[{$i}]", 'a column weight must be a non-negative number');
                    }
                }
            }
        }

        $rows = $block['rows'] ?? null;
        if (!is_array($rows) || !array_is_list($rows)) {
            $errors[] = self::error("{$path}.rows", 'table requires a "rows" array');

            return;
        }
        foreach ($rows as $r => $row) {
            $rowPath = "{$path}.rows[{$r}]";
            if (!is_array($row) || !is_array($row['cells'] ?? null) || !array_is_list($row['cells'])) {
                $errors[] = self::error("{$rowPath}.cells", 'table row requires a "cells" array');

                continue;
            }
            foreach ($row['cells'] as $c => $cell) {
                $cellPath = "{$rowPath}.cells[{$c}]";
                if (!is_array($cell) || !is_array($cell['blocks'] ?? null) || !array_is_list($cell['blocks'])) {
                    $errors[] = self::error("{$cellPath}.blocks", 'table cell requires a "blocks" array');

                    continue;
                }
                $this->validateHex($cell['shading'] ?? null, "{$cellPath}.shading", 'cell shading', $errors);
                $this->validateBorders($cell['borders'] ?? null, "{$cellPath}.borders", 'cell borders', self::BOX_EDGES, $errors);
                $this->validateSides($cell['padding'] ?? null, "{$cellPath}.padding", 'cell padding', $errors);
                $this->validateEnum($cell['valign'] ?? null, "{$cellPath}.valign", 'cell valign', ['top', 'center', 'bottom'], $errors);
                foreach (['colSpan', 'rowSpan'] as $span) {
                    $value = $cell[$span] ?? null;
                    if ($value !== null && (!is_int($value) || $value < 1)) {
                        $errors[] = self::error("{$cellPath}.{$span}", "{$span} must be an integer of 1 or more");
                    }
                }
                $this->validateBlocks($cell['blocks'], "{$cellPath}.blocks", $errors);
            }
        }
    }

    /** @param  array<string, mixed>  $block */
    private function validateCode(array $block, string $path, array &$errors): void
    {
        if (!is_string($block['text'] ?? null)) {
            $errors[] = self::error("{$path}.text", 'code block requires a string "text"');
        }
        if (isset($block['language']) && !is_string($block['language'])) {
            $errors[] = self::error("{$path}.language", 'code language must be a string when present');
        }
    }

    /** @param  array<string, mixed>  $block */
    private function validateQuote(array $block, string $path, array &$errors): void
    {
        $blocks = $block['blocks'] ?? null;
        if (!is_array($blocks) || !array_is_list($blocks)) {
            $errors[] = self::error("{$path}.blocks", 'quote requires a "blocks" array');

            return;
        }
        $this->validateBlocks($blocks, "{$path}.blocks", $errors);
    }

    /** @param  array<string, mixed>  $block */
    private function validateImage(array $block, string $path, array &$errors): void
    {
        $src = $block['src'] ?? null;
        if (!is_string($src)) {
            $errors[] = self::error("{$path}.src", 'image requires a string "src" data URL');
        } elseif (preg_match('#^data:image/(png|jpe?g);base64,#', $src) !== 1) {
            $errors[] = self::error("{$path}.src", 'image src must be a PNG or JPEG data URL (data:image/png;base64,… or data:image/jpeg;base64,…)');
        }
        foreach (['widthPx', 'heightPx'] as $dim) {
            if (isset($block[$dim]) && (!is_numeric($block[$dim]) || (float) $block[$dim] <= 0)) {
                $errors[] = self::error("{$path}.{$dim}", "{$dim} must be a positive number when present");
            }
        }
        if (isset($block['alt']) && !is_string($block['alt'])) {
            $errors[] = self::error("{$path}.alt", 'alt must be a string when present');
        }
    }

    /**
     * @param  list<array{path: string, message: string}>  $errors
     */
    private function validateRuns(mixed $runs, string $path, array &$errors): void
    {
        if (!is_array($runs) || !array_is_list($runs)) {
            $errors[] = self::error($path, 'missing "runs" (array of {text, …} run objects)');

            return;
        }
        foreach ($runs as $i => $run) {
            $runPath = "{$path}[{$i}]";
            if (!is_array($run)) {
                $errors[] = self::error($runPath, 'run must be an object with "text", got ' . get_debug_type($run));

                continue;
            }
            if (!is_string($run['text'] ?? null)) {
                $errors[] = self::error("{$runPath}.text", 'run requires a string "text"');
            }
            foreach (Schema::RUN_FLAGS as $flag) {
                if (isset($run[$flag]) && !is_bool($run[$flag])) {
                    $errors[] = self::error("{$runPath}.{$flag}", "run flag \"{$flag}\" must be a boolean");
                }
            }
            if (isset($run['link']) && !is_string($run['link'])) {
                $errors[] = self::error("{$runPath}.link", 'run link must be a string URL');
            }
            foreach (['color', 'highlight'] as $colorKey) {
                if (isset($run[$colorKey]) && (!is_string($run[$colorKey]) || preg_match('/^#[0-9A-Fa-f]{6}$/', $run[$colorKey]) !== 1)) {
                    $errors[] = self::error("{$runPath}.{$colorKey}", "run {$colorKey} must be a #RRGGBB hex string");
                }
            }
            if (isset($run['font']) && !is_string($run['font'])) {
                $errors[] = self::error("{$runPath}.font", 'run font must be a font family name');
            }
            $this->validateNumber($run['size'] ?? null, "{$runPath}.size", 'run size', $errors, true);
            $this->validateNumber($run['letterSpacing'] ?? null, "{$runPath}.letterSpacing", 'run letterSpacing', $errors);
        }
    }

    /**
     * @return array{path: string, message: string}
     */
    private static function error(string $path, string $message): array
    {
        return ['path' => $path, 'message' => $message];
    }
}
