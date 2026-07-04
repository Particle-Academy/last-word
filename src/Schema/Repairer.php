<?php

declare(strict_types=1);

namespace LastWord\Schema;

/**
 * Heuristic repair of near-miss agent output. Applied by
 * Agent::validateAndRepair() when strict validation fails:
 *
 *   - missing / non-list `blocks` → defaults to []
 *   - bare string entries (in blocks, runs, list items) → wrapped
 *   - `"text"` shorthand on run-bearing blocks → coerced to runs
 *   - heading levels clamped to 1-6, numeric strings cast
 *   - unknown block types dropped — with the drop retained as an error
 *   - invalid align / color values dropped rather than failed
 *
 * repair() returns both the repaired document and the list of notes about
 * what changed, so validateAndRepair can hand the full story back to the
 * agent.
 */
final class Repairer
{
    /** @var list<array{path: string, message: string}> */
    private array $notes = [];

    /**
     * @param  array<string, mixed>  $doc
     * @return array{doc: array<string, mixed>, notes: list<array{path: string, message: string}>}
     */
    public function repair(array $doc): array
    {
        $this->notes = [];

        if (array_key_exists('title', $doc) && !is_string($doc['title'])) {
            if (is_scalar($doc['title'])) {
                $doc['title'] = (string) $doc['title'];
                $this->note('title', 'coerced non-string title to string');
            } else {
                unset($doc['title']);
                $this->note('title', 'dropped non-string title');
            }
        }

        if (!isset($doc['blocks']) || !is_array($doc['blocks']) || !array_is_list($doc['blocks'])) {
            $doc['blocks'] = [];
            $this->note('blocks', 'defaulted missing/invalid "blocks" to []');
        }

        $doc['blocks'] = $this->repairBlocks($doc['blocks'], 'blocks');

        return ['doc' => $doc, 'notes' => $this->notes];
    }

    /**
     * @param  list<mixed>  $blocks
     * @return list<array<string, mixed>>
     */
    private function repairBlocks(array $blocks, string $path): array
    {
        $out = [];
        foreach ($blocks as $i => $block) {
            $blockPath = "{$path}[{$i}]";
            $repaired = $this->repairBlock($block, $blockPath);
            if ($repaired !== null) {
                $out[] = $repaired;
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|null null = drop the block
     */
    private function repairBlock(mixed $block, string $path): ?array
    {
        if (is_string($block)) {
            $this->note($path, 'coerced bare string block to a paragraph');

            return ['type' => 'paragraph', 'runs' => [['text' => $block]]];
        }
        if (!is_array($block)) {
            $this->note($path, 'dropped non-object block (' . get_debug_type($block) . ')');

            return null;
        }

        $type = $block['type'] ?? null;
        if (!is_string($type)) {
            // A run-shaped or text-shaped object without a type → paragraph.
            if (isset($block['runs']) || isset($block['text'])) {
                $block['type'] = 'paragraph';
                $type = 'paragraph';
                $this->note("{$path}.type", 'defaulted missing block type to "paragraph"');
            } else {
                $this->note($path, 'dropped block without a usable "type"');

                return null;
            }
        }

        if (!in_array($type, Schema::BLOCK_TYPES, true)) {
            $this->note("{$path}.type", "dropped block with unknown type \"{$type}\"");

            return null;
        }

        return match ($type) {
            'heading' => $this->repairHeading($block, $path),
            'paragraph' => $this->repairParagraph($block, $path),
            'list' => $this->repairList($block, $path),
            'table' => $this->repairTable($block, $path),
            'code' => $this->repairCode($block, $path),
            'quote' => $this->repairQuote($block, $path),
            'image' => $this->repairImage($block, $path),
            'pageBreak' => ['type' => 'pageBreak'],
            'hr' => ['type' => 'hr'],
        };
    }

    /** @param  array<string, mixed>  $block */
    private function repairHeading(array $block, string $path): array
    {
        $level = $block['level'] ?? 1;
        if (!is_int($level)) {
            $level = is_numeric($level) ? (int) $level : 1;
            $this->note("{$path}.level", 'coerced heading level to an integer');
        }
        $clamped = max(1, min(Schema::MAX_HEADING_LEVEL, $level));
        if ($clamped !== $level) {
            $this->note("{$path}.level", "clamped heading level {$level} to {$clamped}");
        }
        $block['level'] = $clamped;
        $block['runs'] = $this->repairRuns($block, $path);

        return $block;
    }

    /** @param  array<string, mixed>  $block */
    private function repairParagraph(array $block, string $path): array
    {
        $block['runs'] = $this->repairRuns($block, $path);
        if (isset($block['align']) && !in_array($block['align'], Schema::ALIGNMENTS, true)) {
            $this->note("{$path}.align", 'dropped invalid align value');
            unset($block['align']);
        }

        return $block;
    }

    /** @param  array<string, mixed>  $block */
    private function repairList(array $block, string $path): array
    {
        $items = $block['items'] ?? null;
        if (!is_array($items) || !array_is_list($items)) {
            $this->note("{$path}.items", 'defaulted missing list items to []');
            $items = [];
        }
        $block['items'] = $this->repairListItems($items, "{$path}.items");
        if (isset($block['ordered']) && !is_bool($block['ordered'])) {
            $block['ordered'] = (bool) $block['ordered'];
            $this->note("{$path}.ordered", 'coerced ordered flag to boolean');
        }

        return $block;
    }

    /**
     * @param  list<mixed>  $items
     * @return list<array<string, mixed>>
     */
    private function repairListItems(array $items, string $path): array
    {
        $out = [];
        foreach ($items as $i => $item) {
            $itemPath = "{$path}[{$i}]";
            if (is_string($item)) {
                $this->note($itemPath, 'coerced bare string list item to {runs}');
                $out[] = ['runs' => [['text' => $item]]];

                continue;
            }
            if (!is_array($item)) {
                $this->note($itemPath, 'dropped non-object list item');

                continue;
            }
            $item['runs'] = $this->repairRuns($item, $itemPath);
            if (isset($item['children'])) {
                if (is_array($item['children']) && array_is_list($item['children'])) {
                    $item['children'] = $this->repairListItems($item['children'], "{$itemPath}.children");
                    if ($item['children'] === []) {
                        unset($item['children']);
                    }
                } else {
                    unset($item['children']);
                    $this->note("{$itemPath}.children", 'dropped invalid children');
                }
            }
            $out[] = $item;
        }

        return $out;
    }

    /** @param  array<string, mixed>  $block */
    private function repairTable(array $block, string $path): array
    {
        $rows = $block['rows'] ?? null;
        if (!is_array($rows) || !array_is_list($rows)) {
            $this->note("{$path}.rows", 'defaulted missing table rows to []');
            $rows = [];
        }
        $outRows = [];
        foreach ($rows as $r => $row) {
            $rowPath = "{$path}.rows[{$r}]";
            if (!is_array($row)) {
                $this->note($rowPath, 'dropped non-object table row');

                continue;
            }
            $cells = $row['cells'] ?? null;
            if (!is_array($cells) || !array_is_list($cells)) {
                $this->note("{$rowPath}.cells", 'defaulted missing cells to []');
                $cells = [];
            }
            $outCells = [];
            foreach ($cells as $c => $cell) {
                $cellPath = "{$rowPath}.cells[{$c}]";
                if (is_string($cell)) {
                    $this->note($cellPath, 'coerced bare string cell to {blocks: [paragraph]}');
                    $outCells[] = ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => $cell]]]]];

                    continue;
                }
                if (!is_array($cell)) {
                    $this->note($cellPath, 'dropped non-object table cell');

                    continue;
                }
                $cellBlocks = $cell['blocks'] ?? null;
                if (!is_array($cellBlocks) || !array_is_list($cellBlocks)) {
                    if (isset($cell['text']) && is_string($cell['text'])) {
                        $this->note("{$cellPath}.blocks", 'coerced cell "text" shorthand to a paragraph block');
                        $cellBlocks = [['type' => 'paragraph', 'runs' => [['text' => $cell['text']]]]];
                        unset($cell['text']);
                    } else {
                        $this->note("{$cellPath}.blocks", 'defaulted missing cell blocks to []');
                        $cellBlocks = [];
                    }
                }
                $cell['blocks'] = $this->repairBlocks($cellBlocks, "{$cellPath}.blocks");
                $outCells[] = $cell;
            }
            $row['cells'] = $outCells;
            if (isset($row['header']) && !is_bool($row['header'])) {
                $row['header'] = (bool) $row['header'];
                $this->note("{$rowPath}.header", 'coerced header flag to boolean');
            }
            $outRows[] = $row;
        }
        $block['rows'] = $outRows;

        return $block;
    }

    /** @param  array<string, mixed>  $block */
    private function repairCode(array $block, string $path): array
    {
        if (!is_string($block['text'] ?? null)) {
            if (is_scalar($block['text'] ?? null)) {
                $block['text'] = (string) $block['text'];
                $this->note("{$path}.text", 'coerced code text to string');
            } else {
                $block['text'] = '';
                $this->note("{$path}.text", 'defaulted missing code text to ""');
            }
        }
        if (isset($block['language']) && !is_string($block['language'])) {
            unset($block['language']);
            $this->note("{$path}.language", 'dropped non-string code language');
        }

        return $block;
    }

    /** @param  array<string, mixed>  $block */
    private function repairQuote(array $block, string $path): array
    {
        $blocks = $block['blocks'] ?? null;
        if (!is_array($blocks) || !array_is_list($blocks)) {
            if (isset($block['text']) && is_string($block['text'])) {
                $this->note("{$path}.blocks", 'coerced quote "text" shorthand to a paragraph block');
                $blocks = [['type' => 'paragraph', 'runs' => [['text' => $block['text']]]]];
                unset($block['text']);
            } else {
                $this->note("{$path}.blocks", 'defaulted missing quote blocks to []');
                $blocks = [];
            }
        }
        $block['blocks'] = $this->repairBlocks($blocks, "{$path}.blocks");

        return $block;
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>|null
     */
    private function repairImage(array $block, string $path): ?array
    {
        if (!is_string($block['src'] ?? null) || preg_match('#^data:image/(png|jpe?g);base64,#', $block['src']) !== 1) {
            $this->note("{$path}.src", 'dropped image without a usable PNG/JPEG data URL src');

            return null;
        }
        foreach (['widthPx', 'heightPx'] as $dim) {
            if (isset($block[$dim]) && (!is_numeric($block[$dim]) || (float) $block[$dim] <= 0)) {
                unset($block[$dim]);
                $this->note("{$path}.{$dim}", "dropped invalid {$dim}");
            }
        }
        if (isset($block['alt']) && !is_string($block['alt'])) {
            unset($block['alt']);
            $this->note("{$path}.alt", 'dropped non-string alt');
        }

        return $block;
    }

    /**
     * Repair the `runs` of a run-bearing block/item — including the
     * `"text"` string shorthand agents love to emit.
     *
     * @param  array<string, mixed>  $owner
     * @return list<array<string, mixed>>
     */
    private function repairRuns(array $owner, string $path): array
    {
        $runs = $owner['runs'] ?? null;
        if (!is_array($runs) || !array_is_list($runs)) {
            if (isset($owner['text']) && is_string($owner['text'])) {
                $this->note("{$path}.runs", 'coerced "text" string shorthand to runs');

                return [['text' => $owner['text']]];
            }
            if (is_string($runs)) {
                $this->note("{$path}.runs", 'coerced string runs to a single run');

                return [['text' => $runs]];
            }
            $this->note("{$path}.runs", 'defaulted missing runs to []');

            return [];
        }

        $out = [];
        foreach ($runs as $i => $run) {
            $runPath = "{$path}.runs[{$i}]";
            if (is_string($run)) {
                $this->note($runPath, 'coerced bare string run to {text}');
                $out[] = ['text' => $run];

                continue;
            }
            if (!is_array($run)) {
                $this->note($runPath, 'dropped non-object run');

                continue;
            }
            if (!is_string($run['text'] ?? null)) {
                if (is_scalar($run['text'] ?? null)) {
                    $run['text'] = (string) $run['text'];
                    $this->note("{$runPath}.text", 'coerced run text to string');
                } else {
                    $this->note($runPath, 'dropped run without text');

                    continue;
                }
            }
            foreach (Schema::RUN_FLAGS as $flag) {
                if (isset($run[$flag]) && !is_bool($run[$flag])) {
                    $run[$flag] = (bool) $run[$flag];
                    $this->note("{$runPath}.{$flag}", "coerced {$flag} flag to boolean");
                }
            }
            foreach (['color', 'highlight'] as $colorKey) {
                if (isset($run[$colorKey]) && (!is_string($run[$colorKey]) || preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $run[$colorKey]) !== 1)) {
                    unset($run[$colorKey]);
                    $this->note("{$runPath}.{$colorKey}", "dropped invalid {$colorKey}");
                }
            }
            if (isset($run['link']) && !is_string($run['link'])) {
                unset($run['link']);
                $this->note("{$runPath}.link", 'dropped non-string link');
            }
            $out[] = $run;
        }

        return $out;
    }

    private function note(string $path, string $message): void
    {
        $this->notes[] = ['path' => $path, 'message' => $message];
    }
}
