<?php

declare(strict_types=1);

use LastWord\Agent;
use LastWord\Layout\Metrics;

/**
 * Agent::layout(): the page count last-word-ui draws, computed in PHP.
 *
 * The browser numbers pinned here were measured in Chromium 155 (Windows,
 * devicePixelRatio 1) in the bundled Carlito with kerning and ligatures off,
 * which is how last-word-ui 0.3.0 draws Calibri and unspecified text.
 */
function lw_para(string $text, array $extra = []): array
{
    return ['type' => 'paragraph', 'runs' => [['text' => $text]]] + $extra;
}

function lw_words(int $n, int $seed = 1): string
{
    $pool = ['revenue', 'quarter', 'board', 'growth', 'margin', 'the', 'and', 'of', 'operating', 'capital',
        'plan', 'regional', 'demand', 'held', 'up', 'despite', 'a', 'softer', 'September', 'cost', 'in', 'to'];
    $out = [];
    for ($i = 0; $i < $n; $i++) {
        $out[] = $pool[($i * 7 + $seed * 13 + intdiv($i, 3)) % count($pool)];
    }

    return ucfirst(implode(' ', $out)) . '.';
}

describe('the Carlito metrics', function () {
    it('measures a run as the browser does: font size to the hundredth, each advance to 1/65536 px', function () {
        // 512 x "i" at 11pt (14.6667px): Chromium measured 1722.546875px.
        // The naive 470/2048 * 14.6667 * 512 is 1723.33 -- Blink sizes the
        // font at 14.66px (two decimals) before it shapes.
        expect(Metrics::load()->textWidth(str_repeat('i', 512), 11.0, false, false))->toBe(1722.546875)
            // At an integral px size the advance is exact: 512 x "m" at 12px (9pt).
            ->and(Metrics::load()->textWidth(str_repeat('m', 512), 9.0, false, false))->toBe(9.5859375 * 512);
    });

    it('uses the bold and italic advances, which differ from the regular', function () {
        $m = Metrics::load();
        expect($m->textWidth('a', 12.0, true, false))->toBeGreaterThan($m->textWidth('a', 12.0, false, false))
            ->and($m->textWidth('a', 12.0, false, true))->toBeGreaterThan($m->textWidth('a', 12.0, false, false));
    });

    it('takes a line box from the browser measurements, in 1/64 px', function () {
        $m = Metrics::load();
        // A body line at the writer's default (11pt, 1.08 x 1.17): 1185/64 px.
        expect($m->lineBox(11.0, 1.17 * 259 / 240))->toBe(1185)
            // 9pt is 969, a FLOOR of 969.70; 8pt is 862, a ceiling of 861.95.
            // No single rounding rule fits Blink here, which is why it is a table.
            ->and($m->lineBox(9.0, 1.17 * 259 / 240))->toBe(969)
            ->and($m->lineBox(8.0, 1.17 * 259 / 240))->toBe(862)
            ->and($m->lineBox(9.0, 1.17))->toBe(898);
    });

    it('carries its provenance: Carlito 1.104 and the sha256 of each face', function () {
        $p = Metrics::load()->provenance();
        expect($p['version'])->toBe('Version 1.104')
            ->and($p['sha256']['Carlito-Regular.ttf'])->toBe('f6418f708baede9789daef5d458c0f53d2a888af9820e8062934e504fedc6595');
    });
});

describe('Agent::layout', function () {
    it('returns the page count and the block each page starts with', function () {
        $r = Agent::layout(['blocks' => [lw_para('one'), ['type' => 'pageBreak'], lw_para('two')]]);
        expect($r['pages'])->toBe(2)
            ->and($r['pageStarts'])->toBe([0, 2])
            ->and($r['pageList'][1]['blocks'])->toBe([2]);
    });

    it('lays an empty document out on one empty page', function () {
        $r = Agent::layout(['blocks' => []]);
        expect($r['pages'])->toBe(1)->and($r['pageStarts'])->toBe([null]);
    });

    it('breaks a long document by content height', function () {
        $blocks = [];
        for ($i = 0; $i < 60; $i++) {
            $blocks[] = lw_para(lw_words(40, $i));
        }
        $r = Agent::layout(['blocks' => $blocks]);
        expect($r['pages'])->toBeGreaterThan(2)
            ->and(count($r['pageStarts']))->toBe($r['pages'])
            ->and($r['pageStarts'][0])->toBe(0);
    });

    it('keeps a heading with the paragraph after it', function () {
        // Fill page 1 to just under its foot, then a heading and a paragraph.
        $blocks = [];
        for ($i = 0; $i < 33; $i++) {
            $blocks[] = lw_para('Line ' . $i);
        }
        $blocks[] = ['type' => 'heading', 'level' => 2, 'runs' => [['text' => 'Next section']]];
        $blocks[] = lw_para(lw_words(60));
        $r = Agent::layout(['blocks' => $blocks]);
        $h = count($blocks) - 2;
        foreach ($r['pageList'] as $page) {
            // The heading never ends a page.
            expect(end($page['blocks']))->not->toBe($h);
        }
    });

    it('gives page 1 the body a tall first-page letterhead leaves it', function () {
        $blocks = [];
        for ($i = 0; $i < 70; $i++) {
            $blocks[] = lw_para('Line ' . $i);
        }
        $letterhead = ['blocks' => array_fill(0, 8, lw_para('ACME LTD, 1 Example Street'))];
        $plain = Agent::layout(['blocks' => $blocks]);
        $tall = Agent::layout(['blocks' => $blocks, 'page' => ['firstHeader' => $letterhead]]);
        // Page 1 loses the letterhead's height; page 2 keeps the full body.
        expect($tall['pageList'][0]['capacity'])->toBeLessThan($tall['pageList'][1]['capacity'])
            ->and($tall['pageList'][1]['capacity'])->toBe($plain['pageList'][1]['capacity'])
            ->and(count($tall['pageList'][0]['blocks']))->toBeLessThan(count($plain['pageList'][0]['blocks']))
            ->and($tall['pageStarts'][1])->toBeLessThan($plain['pageStarts'][1]);
    });

    it('repeats a table header on the next page, and never splits a row', function () {
        $rows = [['header' => true, 'cells' => [['blocks' => [lw_para('Name')]], ['blocks' => [lw_para('Value')]]]]];
        for ($i = 0; $i < 80; $i++) {
            $rows[] = ['cells' => [['blocks' => [lw_para('Row ' . $i, ['spaceAfter' => 0])]], ['blocks' => [lw_para((string) ($i * 7), ['spaceAfter' => 0])]]]];
        }
        $r = Agent::layout(['blocks' => [['type' => 'table', 'rows' => $rows]]]);
        expect($r['pages'])->toBeGreaterThan(1)
            ->and($r['pageStarts'])->toBe(array_fill(0, $r['pages'], 0))
            ->and($r['pageList'][1]['continued'])->toBeTrue();
    });

    it('puts a footnote on the page of its mark, taking room from that page', function () {
        $note = ['blocks' => [lw_para(lw_words(30))]];
        $r = Agent::layout(['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'Revenue grew'], ['text' => '', 'footnote' => $note]]]]]);
        expect($r['pageList'][0]['footnotes'])->toBe([1]);
    });

    it('lays out a document as written with a template, via the bands the viewer would read back', function () {
        $template = LastWord\Tests\Support\GeneratedLetterhead::bytes();
        $doc = ['blocks' => [lw_para('Hello')]];
        $r = Agent::layout($doc, ['template' => $template, 'templateFirstPageOnly' => true]);
        expect($r['pages'])->toBe(1)
            ->and($r['pageList'][0]['capacity'])->toBeGreaterThan(0.0);
    });

    it('says what it could only estimate: another font, code, a date field', function () {
        $exact = Agent::layout(['blocks' => [lw_para('Calibri text')]]);
        $estimated = Agent::layout([
            'defaultFont' => 'Garamond',
            'page' => ['footer' => ['right' => '{date:d MMMM yyyy}']],
            'blocks' => [lw_para('x'), ['type' => 'code', 'text' => 'echo 1;'], ['type' => 'paragraph', 'runs' => [['text' => 'y', 'font' => 'Arial']]]],
        ]);
        expect($exact['approximate'])->toBe([])
            ->and($exact['font'])->toBe('Carlito 1.104')
            ->and($estimated['approximate'])->toBe(['code', 'date or time field', 'font:Arial', 'font:Garamond']);
    });

    it('refuses what is not a document', function () {
        Agent::layout(['blocks' => 'nope']);
    })->throws(LastWord\Exceptions\SchemaException::class);
});
