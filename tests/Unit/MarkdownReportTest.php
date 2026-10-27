<?php

declare(strict_types=1);

use LastWord\Agent;
use LastWord\Exchange\Report;

/*
 * The markdown bridge with a report (plan section 7: "Doc -> markdown
 * (existing): underline, colour, alignment are dropped SILENTLY today
 * (ToMarkdown.php:20); this gains a report"). The markdown itself does not
 * change: toMarkdown() and toMarkdownWithReport()['markdown'] are the same
 * string, so the fromMarkdown fixpoint is untouched.
 */

function lwmrKinds(array $report): array
{
    $out = [];
    foreach (['dropped', 'approximated'] as $list) {
        foreach ($report[$list] as $e) {
            $out[$e['kind']] = $e['where'] ?? [];
        }
    }

    return $out;
}

it('returns the same markdown as toMarkdown, plus a report with all three keys', function (): void {
    $doc = lwCanonical();
    $r = Agent::toMarkdownWithReport($doc);

    expect($r['markdown'])->toBe(Agent::toMarkdown($doc))
        ->and(array_keys($r['report']))->toBe(['dropped', 'approximated', 'guard']);
});

it('reports a lossless document as lossless', function (): void {
    $r = Agent::toMarkdownWithReport(['blocks' => [
        ['type' => 'heading', 'level' => 2, 'runs' => [['text' => 'Plan']]],
        ['type' => 'paragraph', 'runs' => [['text' => 'Bold', 'bold' => true], ['text' => ' link', 'link' => 'https://example.com']]],
        ['type' => 'list', 'items' => [['runs' => [['text' => 'a']]]]],
        ['type' => 'code', 'text' => 'x = 1'],
        ['type' => 'hr'],
    ]]);

    expect($r['report'])->toBe(Report::empty());
});

it('reports what the bridge used to drop silently, located, without text', function (): void {
    $r = Agent::toMarkdownWithReport(['blocks' => [
        ['type' => 'paragraph', 'align' => 'center', 'runs' => [
            ['text' => 'secret one', 'underline' => true],
            ['text' => 'secret two', 'color' => '#FF0000'],
            ['text' => "line\nbreak"],
        ]],
        ['type' => 'table', 'widths' => [1, 2], 'rows' => [
            ['header' => true, 'cells' => [['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'h']]]], 'shading' => '#EEEEEE'], ['blocks' => []]]],
            ['header' => true, 'cells' => [['blocks' => [['type' => 'list', 'items' => [['runs' => [['text' => 'n']]]]]], 'colSpan' => 2]]],
        ]],
        ['type' => 'image', 'src' => lwRedPngDataUrl(), 'widthPx' => 40],
    ]]);
    $kinds = lwmrKinds($r['report']);

    expect($kinds)->toBe([
        'cell-style-dropped' => ['/blocks/1/rows/0/cells/0'],
        'nested-block' => ['/blocks/1/rows/1/cells/0/blocks/0'],
        'run-style-dropped' => ['/blocks/0/runs/0', '/blocks/0/runs/1'],
        'header-rows-flattened' => ['/blocks/1/rows/1'],
        'layout-not-preserved' => ['/blocks/0', '/blocks/0/runs/2', '/blocks/1', '/blocks/2'],
        'merge-flattened' => ['/blocks/1/rows/1/cells/0'],
    ])
        ->and(json_encode($r['report']))->not->toContain('secret');
});

it('reports a chart carried as its data table, and one that cannot be drawn as dropped', function (): void {
    $chart = ['type' => 'chart', 'option' => ['xAxis' => ['type' => 'category', 'data' => ['Q1']], 'yAxis' => ['type' => 'value'], 'series' => [['type' => 'bar', 'data' => [1]]]]];
    $broken = ['type' => 'chart', 'option' => ['series' => []]];

    $kinds = lwmrKinds(Agent::toMarkdownWithReport(['blocks' => [$chart, $broken]])['report']);

    expect($kinds)->toBe(['chart' => ['/blocks/1'], 'chart-as-table' => ['/blocks/0']]);
});

it('locates losses inside a quote by their path in the document', function (): void {
    $kinds = lwmrKinds(Agent::toMarkdownWithReport(['blocks' => [
        ['type' => 'quote', 'blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'q', 'highlight' => '#FFFF00']]]]],
    ]])['report']);

    expect($kinds)->toBe(['run-style-dropped' => ['/blocks/0/blocks/0/runs/0']]);
});
