<?php

declare(strict_types=1);

use LastWord\Agent;

/**
 * The RTF reader's rules one at a time, on RTF small enough to read.
 *
 * The converted fixture proves they compose on a real file; these pin each rule
 * on input where only that rule decides the answer, including what Word writes
 * and LibreOffice does not (`\trhdr`, `\cs` character styles, `\outlinelevel`).
 */
function rtfBlocks(string $body, string $header = ''): array
{
    return Agent::read('{\rtf1\ansi'.$header.' '.$body.'}')['blocks'];
}

describe('text', function () {
    it('decodes \\\'hh in the Windows-1252 code page by default', function () {
        expect(rtfBlocks("\\'93Hi\\'94 \\'80\\par")[0]['runs'][0]['text'])->toBe('“Hi” €');
    });

    it('decodes \\\'hh in the code page \\ansicpg declares', function () {
        // "Привет" in Windows-1251.
        expect(rtfBlocks("\\'cf\\'f0\\'e8\\'e2\\'e5\\'f2\\par", '\ansicpg1251')[0]['runs'][0]['text'])->toBe('Привет');
    });

    it('replaces a double-byte character with ONE U+FFFD, not one per byte', function () {
        // Shift-JIS あ is 82 A0. The page is not decoded, but the character count is kept.
        expect(rtfBlocks("a\\'82\\'a0b\\par", '\ansicpg932')[0]['runs'][0]['text'])->toBe("a\u{FFFD}b");
    });

    it('reads \\uN and skips its fallback by \\ucN', function () {
        expect(rtfBlocks('\uc1 caf\u233\\\'e9\par')[0]['runs'][0]['text'])->toBe('café');
        expect(rtfBlocks('\uc2 x\u8212--y\par')[0]['runs'][0]['text'])->toBe('x—y');
        expect(rtfBlocks('\uc0 x\u8212 y\par')[0]['runs'][0]['text'])->toBe('x—y');
    });

    it('scopes \\uc to its group', function () {
        expect(rtfBlocks('{\uc2 a\u8212??}b\u8212?c\par')[0]['runs'][0]['text'])->toBe('a—b—c');
    });

    it('joins a surrogate pair written as two negative \\u values', function () {
        expect(rtfBlocks('\uc1\u-10180?\u-8311?\par')[0]['runs'][0]['text'])->toBe('🎉');
    });

    it('replaces half a surrogate pair', function () {
        expect(rtfBlocks('\uc0\u-10180 x\par')[0]['runs'][0]['text'])->toBe("\u{FFFD}x");
    });

    it('writes line breaks, tabs and escaped braces as text', function () {
        expect(rtfBlocks('a\line b\tab c \{d\}\\\\\par')[0]['runs'][0]['text'])->toBe("a\nb\tc {d}\\");
    });

    it('skips destinations that are not body text', function () {
        $blocks = rtfBlocks('{\fonttbl{\f0 Arial;}}{\colortbl;\red0\green0\blue0;}{\*\generator Hand;}{\*\unknowndest secret}{\header head}{\footnote note}body\par');

        expect($blocks)->toBe([['type' => 'paragraph', 'runs' => [['text' => 'body']]]]);
    });

    it('reads the title from the info group', function () {
        expect(Agent::read('{\rtf1{\info{\title Annual Plan}{\author Someone}}Body\par}')['title'])->toBe('Annual Plan');
    });
});

describe('formatting', function () {
    it('scopes bold, italic, underline and strike to their group', function () {
        expect(rtfBlocks('a{\b b{\i c}}{\ul d}{\strike e}\b0 f\par')[0]['runs'])->toBe([
            ['text' => 'a'],
            ['text' => 'b', 'bold' => true],
            ['text' => 'c', 'bold' => true, 'italic' => true],
            ['text' => 'd', 'underline' => true],
            ['text' => 'e', 'strike' => true],
            ['text' => 'f'],
        ]);
    });

    it('does not read \\ulc (an underline COLOUR) as underlining', function () {
        expect(rtfBlocks('{\ulc2 plain}{\uldb under}{\ul\ulnone also}\par')[0]['runs'])->toBe([
            ['text' => 'plain'],
            ['text' => 'under', 'underline' => true],
            ['text' => 'also'],
        ]);
    });

    it('subtracts what a character style sets, as Word repeats it inline', function () {
        $sheet = '{\stylesheet{\s0 Normal;}{\*\cs15\b Strong;}}';
        expect(rtfBlocks($sheet.'x{\cs15\b y}{\b z}\par')[0]['runs'])->toBe([['text' => 'xy'], ['text' => 'z', 'bold' => true]]);
    });

    it('turns a HYPERLINK field into a link on its result', function () {
        $field = '{\field{\*\fldinst HYPERLINK "https://example.com/a" }{\fldrslt {\ul\cf2 site}}}';

        expect(rtfBlocks('see '.$field.' now\par')[0]['runs'])->toBe([
            ['text' => 'see '],
            ['text' => 'site', 'link' => 'https://example.com/a'],
            ['text' => ' now'],
        ]);
    });

    it('keeps a bookmark hyperlink as an anchor', function () {
        // RTF escapes the field switch's backslash: \\l, not \l (a control word).
        $field = '{\field{\*\fldinst HYPERLINK \\\\l "intro"}{\fldrslt top}}';

        expect(rtfBlocks($field.'\par')[0]['runs'])->toBe([['text' => 'top', 'link' => '#intro']]);
    });

    it('keeps another field\'s result and drops its instruction', function () {
        expect(rtfBlocks('page {\field{\*\fldinst PAGE}{\fldrslt 3}}\par')[0]['runs'])->toBe([['text' => 'page 3']]);
    });
});

describe('structure', function () {
    it('makes a paragraph a heading by its style name, without the style\'s bold', function () {
        $sheet = '{\stylesheet{\s0 Normal;}{\s2\b\fs28 heading 2;}}';

        // \pard resets paragraph properties only; \plain resets the character ones.
        expect(rtfBlocks($sheet.'\pard\plain\s2\b\fs28 Title\par\pard\plain\s0 Body\par'))->toBe([
            ['type' => 'heading', 'level' => 2, 'runs' => [['text' => 'Title']]],
            ['type' => 'paragraph', 'runs' => [['text' => 'Body']]],
        ]);
    });

    it('makes a paragraph a heading by its outline level', function () {
        expect(rtfBlocks('\pard\outlinelevel0 Top\par')[0])->toBe(['type' => 'heading', 'level' => 1, 'runs' => [['text' => 'Top']]]);
    });

    it('no longer guesses that a bold paragraph is a heading', function () {
        // 0.4 promoted any bold-led paragraph to a level-1 heading. Bold is
        // emphasis; a heading is a style or an outline level.
        expect(rtfBlocks('\b Just bold\b0\par')[0])->toBe(['type' => 'paragraph', 'runs' => [['text' => 'Just bold', 'bold' => true]]]);
    });

    it('reads lists, nesting by \\ilvl and numbered by the list table', function () {
        $tables = '{\*\listtable{\list{\listlevel\levelnfc23{\leveltext \\\'01\u8226 ?;}}\listid10}'
            .'{\list{\listlevel\levelnfc0{\leveltext \\\'02\\\'00.;}}\listid20}}'
            .'{\*\listoverridetable{\listoverride\listid10\ls1}{\listoverride\listid20\ls2}}';
        $body = '\pard\ls1\ilvl0{\listtext \u8226?\tab}One\par'
            .'\pard\ls1\ilvl1{\listtext o\tab}Inner\par'
            .'\pard\ls2\ilvl0{\listtext 1.\tab}First\par'
            .'\pard Done\par';

        expect(rtfBlocks($tables.$body))->toBe([
            ['type' => 'list', 'items' => [['runs' => [['text' => 'One']], 'children' => [['runs' => [['text' => 'Inner']]]]]]],
            ['type' => 'list', 'ordered' => true, 'items' => [['runs' => [['text' => 'First']]]]],
            ['type' => 'paragraph', 'runs' => [['text' => 'Done']]],
        ]);
    });

    it('reads tables, with a header row where \\trhdr marks one', function () {
        $body = '\trowd\trhdr\cellx1000\cellx2000\pard\intbl A\cell B\cell\row'
            .'\trowd\cellx1000\cellx2000\pard\intbl 1\cell 2\cell\row'
            .'\pard After\par';

        expect(rtfBlocks($body))->toBe([
            ['type' => 'table', 'rows' => [
                ['header' => true, 'cells' => [
                    ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'A']]]]],
                    ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'B']]]]],
                ]],
                ['cells' => [
                    ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => '1']]]]],
                    ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => '2']]]]],
                ]],
            ]],
            ['type' => 'paragraph', 'runs' => [['text' => 'After']]],
        ]);
    });

    it('writes a page break as its own block', function () {
        expect(array_column(rtfBlocks('a\par\page b\par'), 'type'))->toBe(['paragraph', 'pageBreak', 'paragraph']);
    });
});
