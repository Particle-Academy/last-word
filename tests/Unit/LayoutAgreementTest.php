<?php

declare(strict_types=1);

use LastWord\Agent;
use LastWord\Layout\Packer;
use LastWord\Layout\PageLayout;
use LastWord\Tests\Support\GeneratedLetterhead;

/**
 * Agent::layout() against what last-word-ui actually drew.
 *
 * `tests/fixtures/layout/viewer-agreement.json` holds real documents (MOIC's
 * letterhead memo and two-page letter among them) and, for each, what the
 * viewer laid out in Chromium in the bundled Carlito: the page count, the
 * block each page starts with, and the exact input its packer was given. The
 * goldens come from RUNNING the viewer (last-word-ui demo/agreement.html);
 * a disagreement is a bug to find, never a fixture to edit.
 *
 * The same bytes are in last-word-ui (tests/fixtures/viewer-agreement.json);
 * both pin this hash over LF-normalised content.
 */
const LAYOUT_AGREEMENT_SHA256 = 'c262515fc734b352f4d074e0f8238951f6a3a7b32fb7eed1b2dcd5256ca18131';

function lw_agreement(): array
{
    static $fixture = null;

    return $fixture ??= json_decode((string) file_get_contents(__DIR__ . '/../fixtures/layout/viewer-agreement.json'), true, 512, JSON_THROW_ON_ERROR);
}

/** @return array<string, mixed> */
function lw_options(array $case): array
{
    if (! isset($case['options'])) {
        return [];
    }

    return ['template' => GeneratedLetterhead::bytes(['addressBlock' => true])] + array_diff_key($case['options'], ['template' => true]);
}

/**
 * A packer item in one canonical shape, lengths rounded to 1e-6 px, so the
 * viewer's (absent keys, ids) and ours compare as data.
 *
 * @param  array<string, mixed>  $item
 * @return array<string, mixed>
 */
function lw_canonical(array $item, bool $withMarkPositions): array
{
    $r = static fn ($v): float => round((float) $v, 6);
    if ($item['type'] === 'break') {
        return ['type' => 'break'];
    }
    if ($item['type'] === 'table') {
        return ['type' => 'table', 'headerRows' => (int) ($item['headerRows'] ?? 0), 'rows' => array_map(static fn (array $row): array => [
            'height' => $r($row['height']),
            'joinPrevious' => (bool) ($row['joinPrevious'] ?? false),
            'footnotes' => array_map(static fn (array $n): array => ['id' => (int) $n['id'], 'height' => $r($n['height'])], $row['footnotes'] ?? []),
        ], $item['rows'])];
    }

    return [
        'type' => 'block',
        'height' => $r($item['height']),
        'breakpoints' => array_map($r, $item['breakpoints'] ?? []),
        'keepNext' => (bool) ($item['keepNext'] ?? false),
        'footnotes' => array_map(static fn (array $n): array => ['id' => (int) $n['id'], 'height' => $r($n['height'])] + ($withMarkPositions ? ['at' => $r($n['at'])] : []), $item['footnotes'] ?? []),
    ];
}

it('reads the shared fixture, the same bytes last-word-ui pins', function () {
    $raw = str_replace("\r\n", "\n", (string) file_get_contents(__DIR__ . '/../fixtures/layout/viewer-agreement.json'));
    expect(hash('sha256', $raw))->toBe(LAYOUT_AGREEMENT_SHA256)
        ->and(count(lw_agreement()['cases']))->toBeGreaterThanOrEqual(9);
});

it('gives the page count and page starts the viewer drew', function (string $id) {
    $case = array_values(array_filter(lw_agreement()['cases'], fn ($c) => $c['id'] === $id))[0];
    $r = Agent::layout($case['doc'], lw_options($case));

    expect(['pages' => $r['pages'], 'pageStarts' => $r['pageStarts']])
        ->toBe(['pages' => $case['viewer']['pages'], 'pageStarts' => $case['viewer']['pageStarts']])
        ->and(array_column($r['pageList'], 'continued'))->toBe($case['viewer']['continued'])
        ->and(array_column($r['pageList'], 'blocks'))->toBe($case['viewer']['pageBlocks'])
        // Every case here is Calibri text: nothing in it is an estimate.
        ->and($r['approximate'])->toBe([]);
})->with(fn () => array_column(lw_agreement()['cases'], 'id'));

it('measures every block, row, line breakpoint and band exactly as the viewer did', function (string $id) {
    $case = array_values(array_filter(lw_agreement()['cases'], fn ($c) => $c['id'] === $id))[0];
    $x = PageLayout::measure($case['viewerDoc'] ?? $case['doc']);
    $viewer = $case['viewer']['packer'];

    expect(array_map(fn ($i) => lw_canonical($i, true), $x["items"]))
        ->toBe(array_map(fn ($i) => lw_canonical($i, true), $viewer["items"]))
        ->and(round($x['bodies']['first'] * 4 / 3, 6))->toBe(round((float) $viewer['options']['firstPageHeight'], 6))
        ->and(round($x['bodies']['even'] * 4 / 3, 6))->toBe(round((float) $viewer['options']['evenPageHeight'], 6))
        ->and(round($x['bodies']['odd'] * 4 / 3, 6))->toBe(round((float) $viewer['options']['pageHeight'], 6))
        ->and(round($x['footnoteSeparator'], 6))->toBe(round((float) $viewer['options']['footnoteSeparator'], 6));
})->with(fn () => array_column(lw_agreement()['cases'], 'id'));

it('packs the viewer\'s own measurements into the viewer\'s pages: the PHP packer is the TS packer', function (string $id) {
    $case = array_values(array_filter(lw_agreement()['cases'], fn ($c) => $c['id'] === $id))[0];
    $items = array_map(static fn (array $i): array => $i + ['breakpoints' => [], 'footnotes' => []], $case['viewer']['packer']['items']);
    $pages = Packer::paginate($items, $case['viewer']['packer']['options']);

    expect(count($pages))->toBe($case['viewer']['pages'])
        ->and(array_map(static fn (array $p) => $p['fragments'][0]['item'] ?? null, $pages))->toBe($case['viewer']['pageStarts']);
})->with(fn () => array_column(lw_agreement()['cases'], 'id'));
