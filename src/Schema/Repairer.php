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
        $doc = Comments::repair($doc, $this->notes); // review comments: anchors and the list
        $doc = Revisions::repair($doc, $this->notes); // tracked changes: only what is safe to fix

        if (is_array($doc['page'] ?? null)) {
            if (array_key_exists('start', $doc['page'])) {
                unset($doc['page']['start']);
                $this->note('page.start', 'removed start from the document page: it says how a break\'s stretch begins');
            }
            $doc['page'] = $this->repairPage($doc['page'], 'page');
        }
        $doc = $this->repairColumnsFit($doc);

        return ['doc' => $this->repairBookmarks($doc), 'notes' => $this->notes];
    }

    /**
     * The document-wide half of bookmarks and internal links, once every run is
     * repaired: past {@see Schema::MAX_TOCS} a toc is dropped; a bookmark whose
     * name is taken (ignoring case) or that is past {@see Schema::MAX_BOOKMARKS}
     * loses its name; and an internal link to no bookmark loses its link and
     * keeps its text. Each with a note.
     *
     * @param  array<string, mixed>  $doc
     * @return array<string, mixed>
     */
    private function repairBookmarks(array $doc): array
    {
        $tocs = 0;
        $kept = [];
        foreach ($doc['blocks'] as $i => $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'toc' && ++$tocs > Schema::MAX_TOCS) {
                $this->note("blocks[{$i}]", 'dropped a toc: a document holds at most ' . Schema::MAX_TOCS);

                continue;
            }
            $kept[] = $block;
        }
        $doc['blocks'] = $kept;

        $names = [];
        $seen = [];
        $doc = RunWalk::map($doc, function (array $run, string $path, string $where) use (&$names, &$seen): array {
            if ($where !== 'body' || !Schema::isBookmarkName($run['bookmark'] ?? null)) {
                return $run;
            }
            $key = strtolower($run['bookmark']);
            if (isset($seen[$key])) {
                $this->note("{$path}.bookmark", "dropped bookmark \"{$run['bookmark']}\": the name is already used at {$seen[$key]}");
                unset($run['bookmark']);
            } elseif (count($seen) >= Schema::MAX_BOOKMARKS) {
                $this->note("{$path}.bookmark", 'dropped a bookmark: a document holds at most ' . Schema::MAX_BOOKMARKS);
                unset($run['bookmark']);
            } else {
                $seen[$key] = $path;
                $names[$key] = true;
            }

            return $run;
        });

        return RunWalk::map($doc, function (array $run, string $path) use ($names): array {
            $link = $run['link'] ?? null;
            if (is_string($link) && str_starts_with($link, '#') && !isset($names[strtolower(substr($link, 1))])) {
                $this->note("{$path}.link", "dropped the link to \"{$link}\": no bookmark of that name; the text stays");
                unset($run['link']);
            }

            return $run;
        });
    }

    /**
     * A table of contents: `levels` coerced and clamped to 1-6 (dropped when it
     * is not a number); one nested in a quote or a table cell is dropped.
     *
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>|null
     */
    private function repairToc(array $block, string $path): ?array
    {
        if ($this->context === 'body' && preg_match('/^blocks\[\d+\]$/', $path) !== 1) {
            $this->note($path, 'dropped a toc: it goes at the top level of the body, not in a quote or a table cell');

            return null;
        }
        $out = ['type' => 'toc'];
        if (array_key_exists('levels', $block)) {
            $levels = $block['levels'];
            if (!is_int($levels) && !(is_numeric($levels) && (int) $levels == $levels)) {
                $this->note("{$path}.levels", 'dropped toc levels that are not a whole number');

                return $out;
            }
            $clamped = max(1, min(Schema::MAX_HEADING_LEVEL, (int) $levels));
            if ($clamped !== $levels) {
                $this->note("{$path}.levels", "set toc levels {$levels} to {$clamped}");
            }
            $out['levels'] = $clamped;
        }

        return $out;
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
        if (array_key_exists('columns', $page)) {
            $columns = $this->repairColumnsShape($page['columns'], "{$at}.columns");
            if ($columns === null) {
                unset($page['columns']);
            } else {
                $page['columns'] = $columns;
            }
        }
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
            'toc' => $this->repairToc($block, $path),
            'columnBreak' => $this->repairColumnBreak($path),
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
        if (array_key_exists('start', $page)) {
            $start = $page['start'];
            if (!is_string($start) || !in_array($start, Schema::SECTION_STARTS, true)) {
                unset($page['start']);
                $this->note("{$path}.page.start", 'removed a start that is not nextPage or continuous: the break starts a new page');
            } elseif ($start === 'continuous' && array_diff(array_intersect(array_map('strval', array_keys($page)), Schema::PAGE_BREAK_NEAR_MISS_KEYS), Schema::CONTINUOUS_KEYS) !== []) {
                unset($page['start']);
                $this->note("{$path}.page.start", 'removed start continuous: the break changes more than columns, so it starts a new page and keeps every change');
            }
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
            if (array_key_exists('bookmark', $run)) {
                $name = $run['bookmark'];
                if ($this->context !== 'body') {
                    unset($run['bookmark']);
                    $this->note("{$runPath}.bookmark", $this->context === 'band'
                        ? 'dropped a bookmark: a header or footer cannot hold one'
                        : 'dropped a bookmark: a footnote cannot hold one');
                } elseif (!Schema::isBookmarkName($name) || str_starts_with($name, Schema::BOOKMARK_RESERVED_PREFIX)) {
                    unset($run['bookmark']);
                    $this->note("{$runPath}.bookmark", 'dropped a bookmark whose name is not a letter, then letters, digits or _, at most 40 characters (or is reserved)');
                }
            }
            if (array_key_exists('field', $run)) {
                $field = $run['field'];
                unset($run['field']);
                if ($this->context === 'band' && Schema::isFieldToken($field)) {
                    $run['text'] = $field;
                    $this->note("{$runPath}.field", 'moved the field into the text as its token: a header or footer takes the token in its text');
                } elseif (Schema::isFieldToken($field)) {
                    $run['field'] = $field;
                } else {
                    $this->note("{$runPath}.field", 'dropped a field that is not exactly one field token; the text stays');
                }
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
        foreach (['bookmark', 'field'] as $key) {
            if (array_key_exists($key, $run)) {
                unset($run[$key]);
                $this->note("{$path}.{$key}", "dropped {$key} from a footnote mark");
            }
        }
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

    /**
     * A column break nested in a quote, a table cell, a header or a footnote is
     * dropped, with a note: Word breaks the section's columns, not a box's. One in
     * a stretch with a single column goes in {@see repairColumnsFit()}.
     *
     * @return array{type: string}|null
     */
    private function repairColumnBreak(string $path): ?array
    {
        if ($this->context !== 'body' || preg_match('/^blocks\[\d+\]$/', $path) !== 1) {
            $this->note($path, 'dropped a columnBreak: it goes at the top level of the body, not in a quote or a table cell');

            return null;
        }

        return ['type' => 'columnBreak'];
    }

    /**
     * A `columns` value's shape: not an object, or no usable count, and it goes; a
     * count past {@see Schema::MAX_COLUMNS} is clamped; unknown keys go; a negative
     * or non-numeric gap becomes 0 or the default; bad `widths` or `spaces` go, so
     * the columns are equal. Whether it FITS is the page's question, answered once
     * every page is known ({@see repairColumnsFit()}). Each change with a note.
     *
     * @return array<string, mixed>|null null = no columns
     */
    private function repairColumnsShape(mixed $columns, string $path): ?array
    {
        if (!is_array($columns) || $columns === [] || array_is_list($columns)) {
            $this->note($path, 'removed columns that are not an object: one column');

            return null;
        }
        $count = $columns['count'] ?? null;
        if (is_string($count) && preg_match('/^\s*\d+\s*$/', $count) === 1) {
            $count = (int) $count;
        } elseif (is_float($count) && is_finite($count) && $count == floor($count) && abs($count) < 1.0E9) {
            $count = (int) $count;
        }
        if (!is_int($count) || $count < 1) {
            $this->note($path, 'removed columns with no whole count of 1 or more: one column');

            return null;
        }
        if ($count > Schema::MAX_COLUMNS) {
            $this->note("{$path}.count", "set columns count {$count} to " . Schema::MAX_COLUMNS . ', the most Word allows');
            $count = Schema::MAX_COLUMNS;
        }
        $unknown = count(array_diff(array_map('strval', array_keys($columns)), Schema::COLUMNS_KEYS));
        if ($unknown > 0) {
            // The keys are the document's own strings: counted, never echoed.
            $this->note($path, "dropped {$unknown} key" . ($unknown === 1 ? '' : 's') . ' columns does not take (count, space, separator, widths, spaces)');
        }
        if ($count === 1) {
            if (count($columns) > 1) {
                $this->note($path, 'one column takes no other key: kept {"count": 1}');
            }

            return ['count' => 1];
        }
        $out = ['count' => $count];
        if (array_key_exists('space', $columns)) {
            $space = $columns['space'];
            if (Schema::isFiniteNumber($space) && $space >= 0) {
                $out['space'] = $space;
            } elseif (Schema::isFiniteNumber($space)) {
                $out['space'] = 0;
                $this->note("{$path}.space", 'set a negative column gap to 0');
            } else {
                $this->note("{$path}.space", 'dropped a column gap that is not a number: ' . Schema::COLUMN_SPACE_DEFAULT . 'pt');
            }
        }
        if (array_key_exists('separator', $columns)) {
            $out['separator'] = (bool) $columns['separator'];
            if (!is_bool($columns['separator'])) {
                $this->note("{$path}.separator", 'coerced the column separator to true or false');
            }
            if ($out['separator'] === false) {
                unset($out['separator']);
            }
        }
        $widths = $columns['widths'] ?? null;
        if ($widths !== null) {
            $ok = is_array($widths) && array_is_list($widths) && count($widths) === $count;
            foreach ($ok ? $widths : [] as $w) {
                $ok = $ok && Schema::isFiniteNumber($w) && $w >= Schema::MIN_COLUMN_WIDTH;
            }
            if ($ok) {
                $out['widths'] = $widths;
            } else {
                $this->note("{$path}.widths", 'dropped column widths that are not one number of at least ' . Schema::MIN_COLUMN_WIDTH . 'pt per column: equal columns');
            }
        }
        if (array_key_exists('spaces', $columns)) {
            $spaces = $columns['spaces'];
            $ok = isset($out['widths']) && !array_key_exists('space', $columns)
                && is_array($spaces) && array_is_list($spaces) && count($spaces) === $count - 1;
            foreach ($ok ? $spaces : [] as $gap) {
                $ok = $ok && Schema::isFiniteNumber($gap) && $gap >= 0;
            }
            if ($ok) {
                $out['spaces'] = $spaces;
            } else {
                $this->note("{$path}.spaces", 'dropped column gaps that are not one number of 0 or more per gap, with widths and without space');
            }
        }

        // In the agent's own order: a repair changes values, not the layout of the JSON.
        $ordered = [];
        foreach (array_keys($columns) as $key) {
            if (array_key_exists($key, $out)) {
                $ordered[$key] = $out[$key];
            }
        }

        return $ordered + $out;
    }

    /**
     * What only the whole document can say about columns: each stretch's columns
     * fit ITS text width -- inherited ones included, fixed on the break that
     * narrows the page -- and a column break sits in a stretch that has columns.
     * Columns that do not fit become the nearest that do ({@see Schema::fitColumns()});
     * a column break with one column is dropped. Nothing else moves, so no text is
     * lost. One pass over the stretches.
     *
     * @param  array<string, mixed>  $doc
     * @return array<string, mixed>
     */
    private function repairColumnsFit(array $doc): array
    {
        $counts = [];
        $carried = is_array($doc['page'] ?? null) ? ($doc['page']['columns'] ?? null) : null;
        foreach (Schema::stretches($doc) as $stretch) {
            $at = $stretch['break'];
            $own = $at === null ? ($doc['page'] ?? null) : ($doc['blocks'][$at]['page'] ?? null);
            $columns = is_array($own) && array_key_exists('columns', $own) ? $own['columns'] : $carried;
            if (is_array($columns)) {
                $width = Schema::textWidth($stretch['doc']);
                $fitted = Schema::fitColumns($columns, $width);
                if ($fitted !== $columns) {
                    $path = $at === null ? 'page.columns' : "blocks[{$at}].page.columns";
                    $this->note($path, 'set columns to ' . json_encode($fitted) . ': as given they do not fit the text width of ' . round($width, 2) . 'pt');
                    if ($at === null) {
                        $doc['page']['columns'] = $fitted;
                    } else {
                        $doc['blocks'][$at]['page']['columns'] = $fitted;
                    }
                    $columns = $fitted;
                }
            }
            $carried = $columns;
            $count = Schema::columnCount($columns);
            foreach (array_keys($stretch['blocks']) as $i) {
                $counts[$i] = $count;
            }
        }
        $kept = [];
        foreach ($doc['blocks'] as $i => $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'columnBreak' && ($counts[$i] ?? 1) < 2) {
                $this->note("blocks[{$i}]", 'dropped a columnBreak: its stretch has one column');

                continue;
            }
            $kept[] = $block;
        }
        $doc['blocks'] = $kept;

        return $doc;
    }

    private function note(string $path, string $message): void
    {
        $this->notes[] = ['path' => $path, 'message' => $message];
    }
}
