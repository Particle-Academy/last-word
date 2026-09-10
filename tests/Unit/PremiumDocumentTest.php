<?php

declare(strict_types=1);

use LastWord\Agent;

/**
 * Can this package produce a document someone would be PROUD to send?
 *
 * ## Why this is not covered by the tests next to it
 *
 * `AgentTest` and `RoundTripTest` prove each feature works ON ITS OWN, and
 * `DeterminismTest` proves the bytes are stable. None of them asks the question
 * a user actually has, which is whether you can turn all of it on at once and
 * get a document with flair rather than a wall of Calibri.
 *
 * The failures live in the second question. A docx is a pile of parts that
 * reference each other by id — a numbered list is a `<w:numPr>` pointing at a
 * definition in `numbering.xml`, a heading is a `<w:pStyle>` pointing at
 * `styles.xml` — and every one of those links can be written wrong in a way
 * that still opens.
 *
 * **A dropped feature is invisible.** Word shows no error; the document is
 * merely plain. Nobody files a bug against a report that looks boring — they
 * conclude the library is boring. So these assert the ARTIFACT: unzip, and look
 * for the feature in the XML. "It wrote a file" is a check that passes just as
 * happily for a document with no formatting at all.
 */

/** One table cell, the long way the schema actually wants. */
function lwCell(string $text, array $extra = []): array
{
    return array_merge(['blocks' => [['type' => 'paragraph', 'runs' => [['text' => $text]]]]], $extra);
}

/** Everything a rich document uses, in one file. */
function premiumDoc(): array
{
    return [
        'title' => 'Q3 Revenue Review',
        'defaultFont' => 'Inter',
        'defaultSize' => 11,
        'page' => [
            'size' => 'a4',
            'orientation' => 'landscape',
            // POINTS, per the schema's own `boxSides` description — not twips.
            // 72pt is an inch (1440 twips); 54pt is three quarters (1080).
            'margins' => ['top' => 72, 'bottom' => 72, 'left' => 54, 'right' => 54],
        ],
        'blocks' => [
            ['type' => 'heading', 'level' => 1, 'runs' => [['text' => 'Q3 Revenue Review']]],
            ['type' => 'heading', 'level' => 2, 'runs' => [['text' => 'By region']]],

            // Every run flag the schema has, in ONE paragraph. They land in a
            // single `<w:rPr>` whose child order OOXML fixes, so this is where a
            // mis-ordered or overwritten property shows up.
            ['type' => 'paragraph', 'align' => 'justify', 'runs' => [
                ['text' => 'Bold ', 'bold' => true],
                ['text' => 'italic ', 'italic' => true],
                ['text' => 'underlined ', 'underline' => true],
                ['text' => 'struck ', 'strike' => true],
                ['text' => 'small caps ', 'smallCaps' => true],
                ['text' => 'red ', 'color' => '#C00000'],
                ['text' => 'large ', 'size' => 18, 'font' => 'Playfair Display'],
                ['text' => 'highlighted', 'highlight' => '#FFFF00'],
            ]],

            ['type' => 'table', 'widths' => [2, 1, 1], 'rows' => [
                ['header' => true, 'cells' => [lwCell('Region'), lwCell('Revenue'), lwCell('Growth')]],
                ['cells' => [lwCell('North'), lwCell('$1,250,000.50'), lwCell('18.4%')]],
                ['cells' => [lwCell('EMEA'), lwCell('$2,100,000.00'), lwCell('31.1%')]],
            ]],

            ['type' => 'list', 'ordered' => true, 'items' => [
                ['runs' => [['text' => 'EMEA led on growth']]],
                ['runs' => [['text' => 'North led on absolute revenue']]],
            ]],
            ['type' => 'list', 'ordered' => false, 'items' => [
                ['runs' => [['text' => 'A bulleted note']]],
            ]],

            ['type' => 'quote', 'blocks' => [
                ['type' => 'paragraph', 'runs' => [['text' => 'The quarter turned on EMEA.']]],
            ]],
            ['type' => 'code', 'text' => "const growth = 0.311;", 'language' => 'javascript'],
            ['type' => 'hr'],
            ['type' => 'pageBreak'],
            ['type' => 'paragraph', 'runs' => [['text' => 'Appendix']]],
        ],
    ];
}

/** Read one part out of a written document, without leaving a file behind. */
function lwPartOf(array $doc, string $part): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'lw-premium-').'.docx';

    try {
        // Fail loudly on an invalid fixture rather than asserting against a
        // document the writer never got to see.
        expect(Agent::validate($doc))->toBe([]);
        Agent::write($doc, $tmp);

        $zip = new ZipArchive();
        expect($zip->open($tmp))->toBeTrue();
        $xml = $zip->getFromName($part);
        $zip->close();

        // A missing part and an empty one are different failures, and the
        // difference is the whole diagnosis: absent means the writer never
        // emitted it, empty means it emitted nothing into it.
        expect($xml)->not->toBeFalse("document has no {$part}");

        return (string) $xml;
    } finally {
        @unlink($tmp);
    }
}

function lwHas(string $haystack, string $needle): bool
{
    return str_contains($haystack, $needle);
}

describe('a rich document keeps EVERY formatting feature, together', function () {
    it('keeps all eight run properties in one paragraph', function () {
        // The composition check. Every one of these is a child of the same
        // `<w:rPr>` element, whose order OOXML fixes — so this is where a
        // property written in the wrong slot, or overwritten by the next one,
        // shows up. The per-feature tests each write one run and cannot see it.
        $body = lwPartOf(premiumDoc(), 'word/document.xml');

        $expected = [
            'bold' => '<w:b/>',
            'italic' => '<w:i/>',
            'underline' => '<w:u ',
            'strike' => '<w:strike/>',
            'small caps' => '<w:smallCaps/>',
            'red text' => 'C00000',
            'custom font' => 'Playfair Display',
            'larger size' => 'w:val="36"',
        ];

        $missing = [];
        foreach ($expected as $label => $needle) {
            if (! lwHas($body, $needle)) {
                $missing[] = $label;
            }
        }

        expect($missing, 'these run properties were accepted and never reached document.xml: '.implode(', ', $missing))
            ->toBe([]);
    });

    it('shades a highlight rather than using the 16-colour `w:highlight`', function () {
        // Pinned deliberately, because it looks like a gap and is a decision.
        // `<w:highlight>` takes SIXTEEN NAMED COLOURS and nothing else, so a
        // schema that accepts `#RRGGBB` cannot render through it without either
        // rejecting most values or snapping them to the nearest of sixteen.
        // `<w:shd>` takes any hex and prints.
        $body = lwPartOf(premiumDoc(), 'word/document.xml');

        expect(lwHas($body, 'FFFF00'))->toBeTrue('the highlight colour never reached the document');
        expect(lwHas($body, '<w:highlight'))->toBeFalse(
            'if this now passes, `highlight` moved to w:highlight — check what happens to a colour outside the 16'
        );
    });

    it('makes headings real Word styles, so the navigation pane works', function () {
        // A "heading" that is only a large bold paragraph produces a document
        // with no outline: no navigation pane, no automatic table of contents,
        // and nothing for a screen reader to jump between. It looks identical.
        $body = lwPartOf(premiumDoc(), 'word/document.xml');
        $styles = lwPartOf(premiumDoc(), 'word/styles.xml');

        expect(lwHas($body, '<w:pStyle w:val="Heading1"/>'))->toBeTrue('h1 is not a styled heading');
        expect(lwHas($body, '<w:pStyle w:val="Heading2"/>'))->toBeTrue('h2 is not a styled heading');
        expect(lwHas($styles, 'w:styleId="Heading1"'))->toBeTrue('Heading1 is referenced but never defined');
        expect(lwHas($styles, '<w:outlineLvl'))->toBeTrue('headings carry no outline level');
    });

    it('numbers an ordered list through numbering.xml, not literal "1." text', function () {
        // A list faked with typed numbers renumbers wrong the moment anyone
        // inserts an item, and is the single most common way a generated
        // document betrays that it was generated.
        $body = lwPartOf(premiumDoc(), 'word/document.xml');
        $numbering = lwPartOf(premiumDoc(), 'word/numbering.xml');

        expect(lwHas($body, '<w:numPr>'))->toBeTrue('list items carry no numbering reference');
        expect(lwHas($numbering, 'decimal'))->toBeTrue('no decimal numbering format defined');
        expect(lwHas($numbering, 'bullet'))->toBeTrue('no bullet numbering format defined');

        // The reference has to RESOLVE. A numId pointing at nothing is the
        // classic silent break: Word drops the list formatting entirely.
        preg_match('/<w:numId w:val="(\d+)"\/>/', $body, $m);
        expect($m[1] ?? null)->not->toBeNull('no numId on any list paragraph');
        expect(lwHas($numbering, '<w:num w:numId="'.$m[1].'"'))->toBeTrue(
            "list references numId {$m[1]}, which numbering.xml never defines"
        );
    });

    it('repeats the table header on every page it breaks across', function () {
        // `<w:tblHeader/>` is a docx-only capability — PPTX has no pagination at
        // all — and it is the difference between a long table that stays
        // readable and one whose column labels vanish after page one.
        $body = lwPartOf(premiumDoc(), 'word/document.xml');

        expect(lwHas($body, '<w:trPr><w:tblHeader/></w:trPr>'))->toBeTrue('the header row does not repeat');
    });

    it('fixes the table layout so the requested column widths hold', function () {
        // Without `w:tblLayout fixed`, Word refits columns to their content and
        // the widths become advisory — a 2:1:1 table silently renders even.
        $body = lwPartOf(premiumDoc(), 'word/document.xml');

        expect(lwHas($body, '<w:tblLayout w:type="fixed"/>'))->toBeTrue('column widths are advisory');
        expect(lwHas($body, '<w:tblBorders>'))->toBeTrue('the table has no borders');
    });

    it('sets the page up as A4 landscape with the margins it was given', function () {
        $body = lwPartOf(premiumDoc(), 'word/document.xml');

        expect(lwHas($body, 'w:orient="landscape"'))->toBeTrue('orientation ignored');
        // A4 landscape is 16838 x 11906 twips. Landscape means the two are
        // SWAPPED, not merely flagged — a document flagged landscape at portrait
        // dimensions prints wrong.
        expect(lwHas($body, 'w:w="16838"'))->toBeTrue('page width is not A4 landscape');
        expect(lwHas($body, 'w:left="1080"'))->toBeTrue('margins ignored');
    });

    it('puts the default font in docDefaults, where it governs the whole document', function () {
        // Set anywhere else, it applies to the runs the writer happened to touch
        // and nothing else — so a document looks right until someone types in it.
        $styles = lwPartOf(premiumDoc(), 'word/styles.xml');

        expect(lwHas($styles, '<w:docDefaults>'))->toBeTrue('no docDefaults block');
        expect(lwHas($styles, 'Inter'))->toBeTrue('the default font never reached the style table');
    });
});

describe('the guard against a feature that is accepted and dropped', function () {
    it('proves the assertions can FAIL — a plain document has none of it', function () {
        // Without this, every assertion above could be passing on boilerplate
        // that appears in any document, and the suite would be green for a file
        // with no formatting whatsoever. This is the control.
        $plain = ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'just text']]]]];

        $body = lwPartOf($plain, 'word/document.xml');

        expect(lwHas($body, 'Playfair Display'))->toBeFalse('a plain document somehow contains the display font');
        expect(lwHas($body, '<w:tblHeader/>'))->toBeFalse('a plain document somehow repeats a table header');
        expect(lwHas($body, '<w:numPr>'))->toBeFalse('a plain document somehow contains a list');
        expect(lwHas($body, 'landscape'))->toBeFalse('a plain document is somehow landscape');
    });
});
