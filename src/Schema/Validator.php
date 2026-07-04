<?php

declare(strict_types=1);

namespace LastWord\Schema;

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
        if (!in_array($type, Schema::BLOCK_TYPES, true)) {
            $errors[] = self::error("{$path}.type", "unknown block type \"{$type}\" (expected one of: " . implode(', ', Schema::BLOCK_TYPES) . ')');

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
            'pageBreak', 'hr' => null,
        };
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
    }

    /** @param  array<string, mixed>  $block */
    private function validateParagraph(array $block, string $path, array &$errors): void
    {
        $this->validateRuns($block['runs'] ?? null, "{$path}.runs", $errors);
        if (isset($block['align']) && !in_array($block['align'], Schema::ALIGNMENTS, true)) {
            $errors[] = self::error("{$path}.align", 'align must be one of: ' . implode(', ', Schema::ALIGNMENTS));
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
