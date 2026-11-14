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

    /** 'body', 'band' or 'footnote': where a footnote run is being repaired, and so whether it may stay. */
    private string $context = 'body';

    /**
     * @param  array<string, mixed>  $doc
     * @return array{doc: array<string, mixed>, notes: list<array{path: string, message: string}>}
     */
    public function repair(array $doc): array
    {
        $this->notes = [];
        $this->context = 'body';

        if (array_key_exists('title', $doc) && !is_string($doc['title'])) {
            if (is_scalar($doc['title'])) {
                $doc['title'] = (string) $doc['title'];
                $this->note('title', 'coerced non-string title to string');
            } else {
                unset($doc['title']);
                $this->note('title', 'dropped non-string title');
            }
        }

        if (array_key_exists('meta', $doc)) {
            $doc = $this->repairMeta($doc);
        }

        if (!isset($doc['blocks']) || !is_array($doc['blocks']) || !array_is_list($doc['blocks'])) {
            $doc['blocks'] = [];
            $this->note('blocks', 'defaulted missing/invalid "blocks" to []');
        }

        $doc['blocks'] = $this->repairBlocks($doc['blocks'], 'blocks');

        if (is_array($doc['page'] ?? null)) {
            $doc['page'] = $this->repairPage($doc['page'], 'page');
        }

        return ['doc' => $doc, 'notes' => $this->notes];
    }

    /**
     * A page object, wherever it sits: its decoration, and the blocks of its bands.
     *
     * @param  array<string, mixed>  $page
     * @return array<string, mixed>
     */
    private function repairPage(array $page, string $at): array
    {
        $page = $this->repairPageDecoration($page, $at);
        foreach (Schema::PAGE_BANDS as $kind) {
            $band = $page[$kind] ?? null;
            if (is_array($band) && is_array($band['blocks'] ?? null) && array_is_list($band['blocks'])) {
                $path = "{$at}.{$kind}.blocks";
                [$outer, $this->context] = [$this->context, 'band'];
                try {
                    $page[$kind]['blocks'] = $this->dropRefusedInBand($this->repairBlocks($band['blocks'], $path), $path);
                } finally {
                    $this->context = $outer;
                }
            }
        }

        return $page;
    }

    /**
     * `meta` (0.16.0): `meta.title` moves to the top-level `title` when there
     * is none (and is dropped when there is: the top-level one is the title);
     * a non-text author is dropped; a `meta` that is not an object is dropped,
     * each with a note.
     *
     * @param  array<string, mixed>  $doc
     * @return array<string, mixed>
     */
    private function repairMeta(array $doc): array
    {
        $meta = $doc['meta'];
        if (!is_array($meta) || ($meta !== [] && array_is_list($meta))) {
            unset($doc['meta']);
            $this->note('meta', 'dropped a meta that is not an object');

            return $doc;
        }
        if (array_key_exists('title', $meta)) {
            if (!array_key_exists('title', $doc) && is_string($meta['title'])) {
                $doc = ['title' => $meta['title']] + $doc;
                $this->note('meta.title', 'moved meta.title to the document title');
            } else {
                $this->note('meta.title', 'dropped meta.title: the document title is the top-level "title"');
            }
            unset($meta['title']);
        }
        if (array_key_exists('author', $meta) && !is_string($meta['author'])) {
            if (is_scalar($meta['author'])) {
                $meta['author'] = (string) $meta['author'];
                $this->note('meta.author', 'coerced a non-text author to text');
            } else {
                unset($meta['author']);
                $this->note('meta.author', 'dropped a non-text author');
            }
        }
        $doc['meta'] = $meta;

        return $doc;
    }

    /**
     * A `{blocks}` header or footer without what a band cannot hold
     * ({@see Schema::BAND_REFUSED_TYPES}), at any depth, each drop noted. A mixed
     * band (slots AND blocks) is left alone: which half was meant is a guess.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @return list<array<string, mixed>>
     */
    private function dropRefusedInBand(array $blocks, string $path): array
    {
        $out = [];
        foreach ($blocks as $i => $block) {
            $at = "{$path}[{$i}]";
            $type = $block['type'] ?? null;
            if (in_array($type, Schema::BAND_REFUSED_TYPES, true)) {
                $this->note($at, "dropped a {$type} block: a header or footer cannot hold one");

                continue;
            }
            if ($type === 'quote' && is_array($block['blocks'] ?? null)) {
                $block['blocks'] = $this->dropRefusedInBand($block['blocks'], "{$at}.blocks");
            }
            if ($type === 'table' && is_array($block['rows'] ?? null)) {
                foreach ($block['rows'] as $r => $row) {
                    foreach (is_array($row['cells'] ?? null) ? $row['cells'] : [] as $c => $cell) {
                        if (is_array($cell['blocks'] ?? null)) {
                            $block['rows'][$r]['cells'][$c]['blocks'] = $this->dropRefusedInBand($cell['blocks'], "{$at}.rows[{$r}].cells[{$c}].blocks");
                        }
                    }
                }
            }
            $out[] = $block;
        }

        return $out;
    }

    /**
     * An invalid `page.background` or `page.border` is removed, with a note,
     * as fine-print's repair does: a page drawn without its decoration is
     * better than no document, and guessing a colour or a frame is not repair.
     *
     * @param  array<string, mixed>  $page
     * @return array<string, mixed>
     */
    private function repairPageDecoration(array $page, string $at): array
    {
        foreach (['background', 'border'] as $key) {
            if (!array_key_exists($key, $page)) {
                continue;
            }
            $probe = ['page' => array_intersect_key($page, array_flip(['size', 'orientation', $key])), 'blocks' => []];
            $bad = array_filter(
                (new Validator())->validate($probe),
                static fn (array $e): bool => str_starts_with($e['path'], 'page.' . $key),
            );
            if ($bad !== []) {
                unset($page[$key]);
                $this->note("{$at}.{$key}", "removed an invalid page {$key}: " . reset($bad)['message']);
            }
        }

        return $page;
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

        if ($type === 'portable') {
            // Content that expanded is already gone; what is left did not
            // validate, and is refused rather than guessed at or dropped.
            return $block;
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
            'chart' => $this->repairChart($block, $path),
            'pageBreak' => $this->repairPageBreak($block, $path),
            'hr' => ['type' => 'hr'],
        };
    }

    /**
     * A page break. `page` stays, and page keys typed on the break itself move into
     * it, each with a note: rewriting a break to the bare break would silently undo
     * an orientation change and still report success. A background goes, also with
     * a note: Word has one page colour per document. Anything else on the break is
     * dropped, as it always was. As fine-print's repair does.
     *
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     */
    private function repairPageBreak(array $block, string $path): array
    {
        $page = $block['page'] ?? null;
        $moved = [];
        foreach (Schema::PAGE_BREAK_NEAR_MISS_KEYS as $key) {
            if (!array_key_exists($key, $block)) {
                continue;
            }
            if (is_array($page) && array_key_exists($key, $page)) {
                $this->note("{$path}.{$key}", "dropped {$key} from the break: its page already says what it is");

                continue;
            }
            $moved[$key] = $block[$key];
            $this->note("{$path}.{$key}", "moved {$key} into the break's \"page\": a break's page settings go there");
        }
        if ($moved !== []) {
            $page = is_array($page) ? $page + $moved : $moved;
        }
        if ($page === null) {
            return ['type' => 'pageBreak'];
        }
        if (!Schema::isPageObject($page)) {
            $this->note("{$path}.page", 'removed: page on a break must be an object of page settings, {} for a new stretch with nothing changed');

            return ['type' => 'pageBreak'];
        }
        if (preg_match('/^blocks\[\d+\]$/', $path) !== 1) {
            $this->note("{$path}.page", 'removed: page settings go on a top-level page break; a break nested in a quote or a table cell cannot change the page');

            return ['type' => 'pageBreak'];
        }
        if (array_key_exists('background', $page)) {
            unset($page['background']);
            $this->note("{$path}.page.background", 'removed background from the break: a document has one page colour');
        }

        return ['type' => 'pageBreak', 'page' => $this->repairPage($page, "{$path}.page")];
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
                if (is_int($cell['colSpan'] ?? null) && $cell['colSpan'] > Schema::MAX_COL_SPAN) {
                    $this->note("{$cellPath}.colSpan", "clamped colSpan {$cell['colSpan']} to ".Schema::MAX_COL_SPAN);
                    $cell['colSpan'] = Schema::MAX_COL_SPAN;
                }
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
        // A named image (`image:<name>`, CC-fancy-0039) is kept: whether the name
        // is registered is for validation to say, with `image_unknown`, rather
        // than for repair to settle by dropping the image the model asked for.
        $named = is_string($block['src'] ?? null) && str_starts_with($block['src'], 'image:');
        if (!$named && (!is_string($block['src'] ?? null) || preg_match('#^data:image/(png|jpe?g);base64,#', $block['src']) !== 1)) {
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
     * A chart block. Two slips are common enough to repair rather than reject:
     *
     *   - the SHORTHAND an agent reaches for when it has not read the schema,
     *     `{chartType, title, categories, series: [{name, values, color}]}`,
     *     becomes the ECharts option it stands for. `chartType` takes Word's
     *     words: column, bar (horizontal), line, pie.
     *   - `series[].values` where ECharts says `data`.
     *
     * A chart that still cannot be drawn is dropped, with the reason kept.
     *
     * @param  array<string, mixed>  $block
     */
    private function repairChart(array $block, string $path): ?array
    {
        if (!is_array($block['option'] ?? null) && is_array($block['series'] ?? null)) {
            $block = self::chartFromShorthand($block);
            $this->note("{$path}.option", 'converted the chart shorthand (chartType / categories / series[].values) to an ECharts option');
        }

        $option = $block['option'] ?? null;
        if (is_array($option) && is_array($option['series'] ?? null) && array_is_list($option['series'])) {
            foreach ($option['series'] as $i => $series) {
                if (is_array($series) && !isset($series['data']) && is_array($series['values'] ?? null)) {
                    $series['data'] = $series['values'];
                    unset($series['values']);
                    $option['series'][$i] = $series;
                    $this->note("{$path}.option.series[{$i}].data", 'renamed series "values" to "data", as ECharts names it');
                }
            }
            $block['option'] = $option;
        }

        foreach (['widthPx', 'heightPx'] as $dim) {
            if (isset($block[$dim]) && (!(is_int($block[$dim]) || is_float($block[$dim])) || $block[$dim] <= 0)) {
                unset($block[$dim]);
                $this->note("{$path}.{$dim}", "dropped invalid {$dim}");
            }
        }
        if (isset($block['width']) && (!(is_int($block['width']) || is_float($block['width'])) || $block['width'] <= 0 || $block['width'] > 100)) {
            unset($block['width']);
            $this->note("{$path}.width", 'dropped invalid chart width');
        }
        if (isset($block['numberFormat']) && (!is_string($block['numberFormat']) || $block['numberFormat'] === '')) {
            unset($block['numberFormat']);
            $this->note("{$path}.numberFormat", 'dropped invalid numberFormat');
        }
        if (isset($block['alt']) && !is_string($block['alt'])) {
            unset($block['alt']);
            $this->note("{$path}.alt", 'dropped non-string alt');
        }

        if ((new Validator())->validate(['blocks' => [$block]]) !== []) {
            $this->note($path, 'dropped a chart that cannot be drawn (see Agent::validate() for why)');

            return null;
        }

        return $block;
    }

    /**
     * `{chartType, title, categories, series: [{name, values, color}]}` as the
     * chart block it stands for.
     *
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     */
    private static function chartFromShorthand(array $block): array
    {
        $chartType = is_string($block['chartType'] ?? null) ? strtolower($block['chartType']) : 'column';
        $type = match ($chartType) {
            'line' => 'line',
            'pie' => 'pie',
            default => 'bar',
        };
        $categories = is_array($block['categories'] ?? null) ? array_values($block['categories']) : [];

        $series = [];
        foreach (array_values($block['series']) as $one) {
            if (!is_array($one)) {
                continue;
            }
            $out = ['type' => $type];
            if (isset($one['name'])) {
                $out['name'] = $one['name'];
            }
            $values = is_array($one['values'] ?? null) ? array_values($one['values']) : (is_array($one['data'] ?? null) ? array_values($one['data']) : []);
            if ($type === 'pie') {
                $data = [];
                foreach ($values as $i => $value) {
                    $data[] = ['name' => \LastWord\Helpers\Chart::label($categories[$i] ?? null) ?? 'Slice ' . ($i + 1), 'value' => $value];
                }
                $out['data'] = $data;
            } else {
                $out['data'] = $values;
            }
            if (is_string($one['color'] ?? null)) {
                $out['itemStyle'] = ['color' => $one['color']];
            }
            $series[] = $out;
        }

        $option = [];
        if (is_string($block['title'] ?? null) && $block['title'] !== '') {
            $option['title'] = ['text' => $block['title']];
        }
        if ($type !== 'pie') {
            $category = ['type' => 'category', 'data' => $categories];
            $value = ['type' => 'value'];
            if ($chartType === 'bar') {
                $option['xAxis'] = $value;
                $option['yAxis'] = $category;
            } else {
                $option['xAxis'] = $category;
                $option['yAxis'] = $value;
            }
        }
        $option['series'] = $series;

        $out = ['type' => 'chart', 'option' => $option];
        foreach (['widthPx', 'heightPx', 'width', 'numberFormat', 'alt'] as $key) {
            if (array_key_exists($key, $block)) {
                $out[$key] = $block[$key];
            }
        }

        return $out;
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
            if (array_key_exists('footnote', $run)) {
                array_push($out, ...$this->repairFootnoteRun($run, $runPath));

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

    /**
     * A footnote run, repaired into what it should have been: text the agent
     * typed on the mark moves to a run of its own before it (losing nothing),
     * a missing `text` becomes "", a bare string or a bare list of blocks is
     * wrapped, and a footnote where Word cannot hold one (a header or footer,
     * another footnote) is dropped with a note.
     *
     * @param  array<string, mixed>  $run
     * @return list<array<string, mixed>>
     */
    private function repairFootnoteRun(array $run, string $path): array
    {
        $note = $run['footnote'];
        unset($run['footnote']);
        $out = [];
        if (is_scalar($run['text'] ?? null) && (string) $run['text'] !== '') {
            $run['text'] = (string) $run['text'];
            $out[] = $run;
            $this->note("{$path}.text", 'moved text typed on a footnote mark into a run of its own before it');
        }

        if ($this->context !== 'body') {
            $this->note("{$path}.footnote", $this->context === 'band'
                ? 'dropped a footnote: a header or footer cannot hold one'
                : 'dropped a footnote inside a footnote');

            return $out;
        }

        if (is_string($note) && $note !== '') {
            $note = ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => $note]]]]];
            $this->note("{$path}.footnote", 'coerced a string footnote to {blocks: [paragraph]}');
        } elseif (is_array($note) && $note !== [] && array_is_list($note)) {
            $note = ['blocks' => $note];
            $this->note("{$path}.footnote", 'coerced a list of blocks to {blocks}');
        }
        $blocks = is_array($note) ? ($note['blocks'] ?? null) : null;
        if (is_string($blocks) && $blocks !== '') {
            $blocks = [['type' => 'paragraph', 'runs' => [['text' => $blocks]]]];
        }
        if (!is_array($blocks) || !array_is_list($blocks)) {
            $this->note("{$path}.footnote", 'dropped a footnote without blocks');

            return $out;
        }

        $at = "{$path}.footnote.blocks";
        $this->context = 'footnote';
        try {
            $blocks = $this->dropRefusedInFootnote($this->repairBlocks($blocks, $at), $at);
        } finally {
            $this->context = 'body';
        }
        if ($blocks === []) {
            $this->note("{$path}.footnote", 'dropped a footnote with no blocks left');

            return $out;
        }
        if (!array_key_exists('text', $run)) {
            $this->note("{$path}.text", 'defaulted a footnote run\'s text to ""');
        }
        $out[]= ['text' => '', 'footnote' => ['blocks' => $blocks]];

        return $out;
    }

    /**
     * A footnote's blocks without what a footnote cannot hold
     * ({@see Schema::FOOTNOTE_REFUSED_TYPES}), at any depth, each drop noted.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @return list<array<string, mixed>>
     */
    private function dropRefusedInFootnote(array $blocks, string $path): array
    {
        $out = [];
        foreach ($blocks as $i => $block) {
            $at = "{$path}[{$i}]";
            $type = $block['type'] ?? null;
            if (in_array($type, Schema::FOOTNOTE_REFUSED_TYPES, true)) {
                $this->note($at, "dropped a {$type} block: a footnote cannot hold one");

                continue;
            }
            if ($type === 'quote' && is_array($block['blocks'] ?? null)) {
                $block['blocks'] = $this->dropRefusedInFootnote($block['blocks'], "{$at}.blocks");
            }
            if ($type === 'table' && is_array($block['rows'] ?? null)) {
                foreach ($block['rows'] as $r => $row) {
                    foreach (is_array($row['cells'] ?? null) ? $row['cells'] : [] as $c => $cell) {
                        if (is_array($cell['blocks'] ?? null)) {
                            $block['rows'][$r]['cells'][$c]['blocks'] = $this->dropRefusedInFootnote($cell['blocks'], "{$at}.rows[{$r}].cells[{$c}].blocks");
                        }
                    }
                }
            }
            $out[] = $block;
        }

        return $out;
    }

    private function note(string $path, string $message): void
    {
        $this->notes[] = ['path' => $path, 'message' => $message];
    }
}
