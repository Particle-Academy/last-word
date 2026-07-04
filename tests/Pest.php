<?php

declare(strict_types=1);

/*
 * Shared test helpers.
 *
 * lwNormalizeDoc() canonicalizes a Doc for semantic comparison: run-merge
 * normalization (adjacent runs with identical formatting merge), falsey
 * flags dropped, `align: left` dropped — the normalization the round-trip
 * test vectors explicitly allow.
 */

/** Load the canonical fixture (the cross-language parity document). */
function lwCanonical(): array
{
    $json = file_get_contents(__DIR__ . '/fixtures/canonical.json');

    return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
}

/** The small real red 2x2 PNG used across image tests. */
function lwRedPngDataUrl(): string
{
    return 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAEElEQVR42mP4z8AARAwQCgAf7gP9Y167WwAAAABJRU5ErkJggg==';
}

function lwNormalizeDoc(array $doc): array
{
    $out = [];
    if (isset($doc['title']) && is_string($doc['title']) && $doc['title'] !== '') {
        $out['title'] = $doc['title'];
    }
    $out['blocks'] = lwNormalizeBlocks($doc['blocks'] ?? []);

    return $out;
}

function lwNormalizeBlocks(array $blocks): array
{
    $out = [];
    foreach ($blocks as $block) {
        if (!is_array($block)) {
            continue;
        }
        $out[] = lwNormalizeBlock($block);
    }

    return $out;
}

function lwNormalizeBlock(array $block): array
{
    $type = $block['type'] ?? null;
    $norm = ['type' => $type];

    switch ($type) {
        case 'heading':
            $norm['level'] = (int) ($block['level'] ?? 1);
            $norm['runs'] = lwNormalizeRuns($block['runs'] ?? []);

            break;
        case 'paragraph':
            $norm['runs'] = lwNormalizeRuns($block['runs'] ?? []);
            if (isset($block['align']) && $block['align'] !== 'left') {
                $norm['align'] = $block['align'];
            }

            break;
        case 'list':
            if (!empty($block['ordered'])) {
                $norm['ordered'] = true;
            }
            $norm['items'] = lwNormalizeItems($block['items'] ?? []);

            break;
        case 'table':
            $rows = [];
            foreach (($block['rows'] ?? []) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $normRow = [];
                if (!empty($row['header'])) {
                    $normRow['header'] = true;
                }
                $cells = [];
                foreach (($row['cells'] ?? []) as $cell) {
                    $cells[] = ['blocks' => lwNormalizeBlocks(is_array($cell) ? ($cell['blocks'] ?? []) : [])];
                }
                $normRow['cells'] = $cells;
                $rows[] = $normRow;
            }
            $norm['rows'] = $rows;

            break;
        case 'code':
            if (isset($block['language']) && is_string($block['language']) && $block['language'] !== '') {
                $norm['language'] = $block['language'];
            }
            $norm['text'] = (string) ($block['text'] ?? '');

            break;
        case 'quote':
            $norm['blocks'] = lwNormalizeBlocks($block['blocks'] ?? []);

            break;
        case 'image':
            $norm['src'] = (string) ($block['src'] ?? '');
            foreach (['widthPx', 'heightPx'] as $dim) {
                if (isset($block[$dim]) && is_numeric($block[$dim])) {
                    $norm[$dim] = (int) round((float) $block[$dim]);
                }
            }
            if (isset($block['alt']) && is_string($block['alt']) && $block['alt'] !== '') {
                $norm['alt'] = $block['alt'];
            }

            break;
        default:
            break;
    }

    return $norm;
}

function lwNormalizeItems(array $items): array
{
    $out = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $norm = ['runs' => lwNormalizeRuns($item['runs'] ?? [])];
        if (!empty($item['children']) && is_array($item['children'])) {
            $children = lwNormalizeItems($item['children']);
            if ($children !== []) {
                $norm['children'] = $children;
            }
        }
        $out[] = $norm;
    }

    return $out;
}

function lwNormalizeRuns(array $runs): array
{
    $cleaned = [];
    foreach ($runs as $run) {
        if (!is_array($run) || !is_string($run['text'] ?? null) || $run['text'] === '') {
            continue;
        }
        $norm = ['text' => $run['text']];
        foreach (['bold', 'italic', 'underline', 'strike', 'code'] as $flag) {
            if (!empty($run[$flag])) {
                $norm[$flag] = true;
            }
        }
        if (isset($run['link']) && is_string($run['link']) && $run['link'] !== '') {
            $norm['link'] = $run['link'];
        }
        foreach (['color', 'highlight'] as $key) {
            if (isset($run[$key]) && is_string($run[$key]) && $run[$key] !== '') {
                $norm[$key] = strtoupper($run[$key]);
            }
        }
        $cleaned[] = $norm;
    }

    // Run-merge normalization: adjacent runs with identical formatting merge.
    $merged = [];
    foreach ($cleaned as $run) {
        $last = count($merged) - 1;
        if ($last >= 0) {
            $a = $merged[$last];
            $b = $run;
            unset($a['text'], $b['text']);
            if ($a == $b) {
                $merged[$last]['text'] .= $run['text'];

                continue;
            }
        }
        $merged[] = $run;
    }

    return $merged;
}
