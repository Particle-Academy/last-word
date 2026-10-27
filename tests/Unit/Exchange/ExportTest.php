<?php

declare(strict_types=1);

use LastWord\Agent;
use LastWord\Exchange\ExportPolicy;
use LastWord\Exchange\Html;
use LastWord\Exchange\OptionsInvalid;

/*
 * export(): markdown, text, html and docx, OFF until the host's ExportPolicy
 * allows a target (owner decision 2). Refusal codes in the order
 * exchange/policy pins. pdf is export_unavailable until the PDF bridge
 * (plan step 6).
 */

function lwexAll(): ExportPolicy
{
    return new ExportPolicy(['allow' => ['markdown', 'text', 'html', 'docx', 'pdf']]);
}

function lwexDoc(): array
{
    $cell = fn (string $t) => ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => $t]]]]];

    return ['title' => 'Q4 <review>', 'blocks' => [
        ['type' => 'heading', 'level' => 1, 'runs' => [['text' => 'Results & "next" steps']]],
        ['type' => 'paragraph', 'runs' => [
            ['text' => '<script>alert(1)</script>', 'bold' => true],
            ['text' => ' click', 'link' => 'javascript:alert(1)'],
            ['text' => ' site', 'link' => 'https://example.com/?a=1&b="2"'],
            ['text' => ' mail', 'link' => 'mailto:ops@example.com'],
            ['text' => ' data', 'link' => 'data:text/html,<script>alert(1)</script>'],
            ['text' => ' x'.mb_chr(0x202E)],
        ]],
        ['type' => 'list', 'ordered' => true, 'items' => [['runs' => [['text' => 'one']], 'children' => [['runs' => [['text' => 'one.a']]]]]]],
        ['type' => 'table', 'rows' => [
            ['header' => true, 'cells' => [$cell('Region'), $cell('Revenue')]],
            ['cells' => [$cell('NA'), $cell('4,820')]],
            ['cells' => [['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'Total']]]], 'colSpan' => 2]]],
        ]],
        ['type' => 'image', 'src' => lwRedPngDataUrl(), 'alt' => '" onerror="alert(1)'],
    ]];
}

it('allows nothing by default: no policy, an empty one, one without allow', function (?ExportPolicy $policy): void {
    foreach (['markdown', 'text', 'html', 'docx'] as $target) {
        $r = Agent::export(lwexDoc(), $target, [], $policy);
        expect($r['ok'])->toBeFalse()
            ->and($r['refused']['reason'])->toBe('export_disabled')
            ->and($r['refused']['target'])->toBe($target);
    }
})->with([
    'null' => [null],
    'empty allow' => [new ExportPolicy(['allow' => []])],
    'no allow' => [new ExportPolicy([])],
]);

it('refuses in exchange/policy order: unsupported, disabled, unavailable, refused, limit', function (): void {
    expect(Agent::export(lwexDoc(), 'csv', [], lwexAll())['refused']['reason'])->toBe('export_unsupported')
        ->and(Agent::export(lwexDoc(), 'csv', [], null)['refused']['reason'])->toBe('export_unsupported')
        ->and(Agent::export(lwexDoc(), 'html', [], new ExportPolicy(['allow' => ['text']]))['refused']['reason'])->toBe('export_disabled');

    $pdf = Agent::export(lwexDoc(), 'pdf', [], lwexAll());
    expect($pdf['refused'])->toMatchArray(['reason' => 'export_unavailable', 'target' => 'pdf', 'missing' => 'fine-print']);

    $bad = Agent::export('not a document', 'html', [], lwexAll());
    expect($bad['refused'])->toMatchArray(['reason' => 'export_refused', 'cause' => 'not_a_document']);

    $sel = Agent::export(lwexDoc(), 'html', ['selector' => ['table' => 7]], lwexAll());
    expect($sel['refused'])->toMatchArray(['reason' => 'export_refused', 'cause' => 'selector_not_found']);

    $limited = new ExportPolicy(['allow' => ['html'], 'limits' => ['cells' => 2]]);
    expect(Agent::export(lwexDoc(), 'html', [], $limited)['refused']['reason'])->toBe('content_too_many_cells');
});

it('still answers export_unavailable for pdf when a host injects fine-print, until the bridge ships', function (): void {
    $p = new ExportPolicy(['allow' => ['pdf'], 'modules' => ['fine-print' => true]]);

    expect(Agent::export(lwexDoc(), 'pdf', [], $p)['refused'])->toMatchArray(['reason' => 'export_unavailable', 'missing' => 'fine-print']);
});

it('throws when the agent tries to enable a target through its options', function (): void {
    expect(fn () => Agent::export(lwexDoc(), 'html', ['allow' => ['html']], null))->toThrow(OptionsInvalid::class)
        ->and(fn () => Agent::export(lwexDoc(), 'html', ['policy' => ['allow' => ['html']]], null))->toThrow(OptionsInvalid::class);
});

it('exports escaped html with no script, no event handler and only http, https and mailto links', function (): void {
    $r = Agent::export(lwexDoc(), 'html', [], lwexAll());
    $html = $r['text'];

    expect($r['ok'])->toBeTrue()
        ->and($r['mime'])->toBe('text/html; charset=utf-8')
        ->and($html)->toStartWith('<!DOCTYPE html>')
        ->and($html)->toContain('<title>Q4 &lt;review&gt;</title>')
        ->and($html)->toContain('<h1>Results &amp; &quot;next&quot; steps</h1>')
        ->and($html)->toContain('<strong>&lt;script&gt;alert(1)&lt;/script&gt;</strong>')
        ->and($html)->toContain('<a href="https://example.com/?a=1&amp;b=&quot;2&quot;" rel="noopener noreferrer">')
        ->and($html)->toContain('<a href="mailto:ops@example.com" rel="noopener noreferrer"> mail</a>')
        ->and($html)->toContain(' click')
        ->and($html)->toContain('alt="&quot; onerror=&quot;alert(1)"')
        ->and($html)->toContain('<img src="data:image/png;base64,')
        ->and($html)->toContain('<th>Region</th>')
        ->and($html)->toContain('<td colspan="2">Total</td>')
        ->and($html)->toContain('<ol><li>one<ol><li>one.a</li></ol></li></ol>')
        ->and(strtolower($html))->not->toContain('<script')
        ->and(strtolower($html))->not->toContain('javascript:')
        ->and(strtolower($html))->not->toContain('href="data:')
        // A real attribute (on...="), not the escaped text of one inside a value.
        ->and(preg_match('/\son[a-z]+\s*=\s*["\']/i', $html))->toBe(0)
        ->and($html)->not->toContain(mb_chr(0x202E));

    $kinds = array_column($r['report']['dropped'], 'kind');
    expect($kinds)->toContain('link')
        ->and($r['report']['guard']['findings'])->toBe([['rule' => 'invisible-chars', 'count' => 1]]);
});

it('checks links again in the html writer, not only in validation', function (): void {
    // Unvalidated content straight into the writer: the second check alone
    // must keep a javascript: link out, and say so.
    $r = Html::fromPortable(['fancyContent' => 1, 'items' => [
        ['type' => 'text', 'role' => 'paragraph', 'runs' => [['text' => 'go', 'link' => 'javascript:alert(1)']]],
    ]]);

    expect($r['text'])->toContain('<p>go</p>')
        ->and($r['text'])->not->toContain('javascript')
        ->and($r['report']['dropped'])->toBe([['kind' => 'link', 'count' => 1]]);
});

it('exports plain text, reporting the run styles and links a text file cannot hold', function (): void {
    $r = Agent::export(lwexDoc(), 'text', [], lwexAll());

    expect($r['ok'])->toBeTrue()
        ->and($r['mime'])->toBe('text/plain; charset=utf-8')
        ->and($r['text'])->toContain("Results & \"next\" steps\n\n<script>alert(1)</script> click site mail data x\n\n1. one\n  1. one.a\n\nRegion\tRevenue\nNA\t4,820\nTotal\t\n")
        ->and(array_column($r['report']['dropped'], 'kind'))->toContain('run-style-dropped', 'link', 'image')
        ->and(array_column($r['report']['approximated'], 'kind'))->toContain('merge-flattened');
});

it('exports markdown through the existing bridge, with its report, minus unsafe links', function (): void {
    $r = Agent::export(lwexDoc(), 'markdown', [], lwexAll());

    expect($r['ok'])->toBeTrue()
        ->and($r['mime'])->toBe('text/markdown; charset=utf-8')
        ->and($r['text'])->toContain('[ site](https://example.com/?a=1&b="2")')
        ->and($r['text'])->not->toContain('javascript:')
        ->and($r['text'])->not->toContain('data:text')
        ->and(array_column($r['report']['dropped'], 'kind'))->toContain('link')
        ->and(array_column($r['report']['approximated'], 'kind'))->toContain('merge-flattened');
});

it('exports docx from .odt, .rtf and .doc, and the bytes read back', function (string $file): void {
    $src = file_get_contents(dirname(__DIR__, 2).'/fixtures/formats/'.$file);
    $r = Agent::export($src, 'docx', [], lwexAll());

    expect($r['ok'])->toBeTrue()
        ->and($r['mime'])->toBe('application/vnd.openxmlformats-officedocument.wordprocessingml.document')
        ->and(substr($r['bytes'], 0, 2))->toBe('PK')
        ->and(Agent::read($r['bytes'])['blocks'])->toEqual(Agent::read($src)['blocks']);
})->with(['report.odt', 'report.rtf', 'report.doc']);

it('honours the agent selector on every target', function (): void {
    $r = Agent::export(lwexDoc(), 'text', ['selector' => ['table' => 0]], lwexAll());

    expect($r['text'])->toBe("Region\tRevenue\nNA\t4,820\nTotal\t\n");
});

it('shows the agent only the targets the host enabled', function (): void {
    $enum = fn (?ExportPolicy $p) => Agent::exportToolDefinition($p)['input_schema']['properties']['target']['enum'];

    expect($enum(null))->toBe([])
        ->and($enum(new ExportPolicy(['allow' => ['html', 'markdown']])))->toBe(['markdown', 'html'])
        ->and($enum(lwexAll()))->toBe(['markdown', 'text', 'html', 'docx'])
        ->and(Agent::exportToolDefinition(lwexAll())['name'])->toBe('last_word_export');
});
