<?php

declare(strict_types=1);

use ParticleAcademy\Conformance\Conformance;
use LastWord\Agent;
use LastWord\Tests\Support\ConformanceExtract;

/**
 * The shared construct table — `last-word/docx-constructs` in
 * fancy-conformance.
 *
 * The rows are NOT transcribed here. This package, its Node twin and its
 * Python twin all assert the same file, so a mapping that drifts in one engine
 * fails in that engine rather than quietly becoming that engine's behaviour.
 * Adding a construct means adding a row there, once.
 *
 * What lives here is only the extractors: how to get from `toBytes($doc)` to
 * the value a row compares. They are deliberately thin — a normaliser clever
 * enough to paper over a difference is a normaliser that stops the suite
 * finding one.
 */
const SUITE = 'last-word/docx-constructs';

/** Parse a written document's word/document.xml. */
function documentXml(array $doc): DOMElement
{
    $tmp = tempnam(sys_get_temp_dir(), 'lw-conf-') . '.docx';
    file_put_contents($tmp, Agent::toBytes($doc));

    $zip = new ZipArchive();
    expect($zip->open($tmp))->toBeTrue();
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();
    @unlink($tmp);

    $dom = new DOMDocument();
    $dom->loadXML((string) $xml);

    return $dom->documentElement;
}

/**
 * An ordered normalisation of one property container.
 *
 * ORDER IS THE POINT: CT_RPr, CT_PPr, CT_TcPr, CT_TblPr and CT_SectPr are all
 * xsd:sequence, so a map keyed by element name would let two engines emit
 * different XML and still agree here. Attribute order is not pinned, because
 * attributes are unordered in XML.
 */
function normProps(?DOMElement $node): array
{
    if ($node === null) {
        return [];
    }
    $out = [];
    foreach ($node->childNodes as $child) {
        if (!($child instanceof DOMElement)) {
            continue;
        }
        $elements = [];
        foreach ($child->childNodes as $grand) {
            if ($grand instanceof DOMElement) {
                $elements[] = $grand;
            }
        }
        if ($elements !== []) {
            $out[] = [$child->localName, normProps($child)];

            continue;
        }
        $attrs = [];
        foreach ($child->attributes as $attr) {
            $attrs[$attr->localName] = $attr->value;
        }
        $out[] = [$child->localName, $attrs === [] ? true : $attrs];
    }

    return $out;
}

/**
 * Every element with this local name, in document order.
 *
 * @return list<DOMElement>
 */
function collectByName(DOMElement $root, string $name): array
{
    $found = [];
    $walk = function (DOMElement $node) use (&$walk, &$found, $name): void {
        if ($node->localName === $name) {
            $found[] = $node;
        }
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $walk($child);
            }
        }
    };
    $walk($root);

    return $found;
}

function childNamed(?DOMElement $parent, string $name): ?DOMElement
{
    if ($parent === null) {
        return null;
    }
    foreach ($parent->childNodes as $child) {
        if ($child instanceof DOMElement && $child->localName === $name) {
            return $child;
        }
    }

    return null;
}

/**
 * Every part of a written document, by name.
 *
 * @return array<string, string>
 */
function writtenParts(array $doc): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'lw-conf-') . '.docx';
    file_put_contents($tmp, Agent::toBytes($doc));
    $zip = new ZipArchive();
    expect($zip->open($tmp))->toBeTrue();
    $parts = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $parts[(string) $zip->getNameIndex($i)] = (string) $zip->getFromIndex($i);
    }
    $zip->close();
    @unlink($tmp);

    return $parts;
}

function partRoot(?string $xml): ?DOMElement
{
    if ($xml === null) {
        return null;
    }
    $dom = new DOMDocument();
    $dom->loadXML($xml);

    return $dom->documentElement;
}

/** The `footnotesPart` projection, as the manifest defines it. */
function footnotesPart(array $doc): array
{
    $w = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    $parts = writtenParts($doc);
    $text = static function (DOMElement $el): string {
        $t = '';
        foreach (collectByName($el, 't') as $node) {
            $t .= $node->textContent;
        }

        return $t;
    };

    $contentType = null;
    foreach (collectByName(partRoot($parts['[Content_Types].xml']), 'Override') as $o) {
        if ($o->getAttribute('PartName') === '/word/footnotes.xml') {
            $contentType = $o->getAttribute('ContentType');
        }
    }
    $relationship = null;
    foreach (collectByName(partRoot($parts['word/_rels/document.xml.rels']), 'Relationship') as $rel) {
        if (str_ends_with($rel->getAttribute('Type'), '/footnotes')) {
            $relationship = $rel->getAttribute('Target');
        }
    }
    $settings = partRoot($parts['word/settings.xml'] ?? null);
    $footnotePr = $settings !== null ? childNamed($settings, 'footnotePr') : null;

    $notes = [];
    $root = partRoot($parts['word/footnotes.xml'] ?? null);
    foreach ($root?->childNodes ?? [] as $node) {
        if ($node instanceof DOMElement && $node->localName === 'footnote') {
            $notes[] = [
                'id' => $node->getAttributeNS($w, 'id'),
                'type' => $node->hasAttributeNS($w, 'type') ? $node->getAttributeNS($w, 'type') : null,
                'text' => $text($node),
                'content' => normProps($node),
            ];
        }
    }

    return [
        'contentType' => $contentType,
        'relationship' => $relationship,
        'footnotePr' => $footnotePr === null ? null : normProps($footnotePr),
        'references' => array_map(
            static fn (DOMElement $r): string => $r->getAttributeNS($w, 'id'),
            collectByName(partRoot($parts['word/document.xml']), 'footnoteReference'),
        ),
        'footnotes' => $notes,
    ];
}

/**
 * The extractor for a function, taking the row's whole INPUT. `input.doc` is
 * the document; since fancy-conformance 0.41.0 a row may also carry
 * `input.template` (a document model, written and passed as the template) and
 * `input.options` (further write options) -- see {@see ConformanceExtract}. The
 * functions that predate that convention project `input.doc` alone, as before.
 */
function extractorFor(string $fn): callable
{
    $doc = static fn (callable $f): callable => static fn (array $input): mixed => $f($input['doc']);

    return match ($fn) {
        'bandParts' => static fn (array $input): array => ConformanceExtract::bandParts($input),
        'bandFields' => static fn (array $input): array => ConformanceExtract::bandFields($input),
        'coreProps' => static fn (array $input): ?array => ConformanceExtract::coreProps($input),

        'readBack' => static fn (array $input): array => Agent::read(ConformanceExtract::write($input)),

        // The comparator is the suite's own, so "equal" means the same thing
        // here as it does for every other row.
        'roundTripFixpoint' => static fn (array $input): array => [
            'fixpoint' => Conformance::equals(Agent::read(ConformanceExtract::write($input)), $input['doc']),
        ],

        default => $doc(docExtractorFor($fn)),
    };
}

function docExtractorFor(string $fn): callable
{
    return match ($fn) {
        'runProps' => static fn (array $doc): array => array_map(
            static function (DOMElement $r): array {
                $text = '';
                foreach (collectByName($r, 't') as $t) {
                    $text .= $t->textContent;
                }

                return ['text' => $text, 'rPr' => normProps(childNamed($r, 'rPr'))];
            },
            collectByName(documentXml($doc), 'r'),
        ),

        'paragraphProps' => static fn (array $doc): array => array_map(
            static fn (DOMElement $p): array => normProps(childNamed($p, 'pPr')),
            collectByName(documentXml($doc), 'p'),
        ),

        'tableProps' => static fn (array $doc): array => array_map(
            static function (DOMElement $tbl): array {
                $grid = [];
                foreach (collectByName($tbl, 'gridCol') as $col) {
                    $grid[] = $col->getAttribute('w:w');
                }

                return ['tblPr' => normProps(childNamed($tbl, 'tblPr')), 'grid' => $grid];
            },
            collectByName(documentXml($doc), 'tbl'),
        ),

        'cellProps' => static fn (array $doc): array => array_map(
            static fn (DOMElement $tc): array => normProps(childNamed($tc, 'tcPr')),
            collectByName(documentXml($doc), 'tc'),
        ),

        'sectionProps' => static fn (array $doc): array => normProps(
            childNamed(childNamed(documentXml($doc), 'body'), 'sectPr'),
        ),

        'footnotesPart' => static fn (array $doc): array => footnotesPart($doc),

        default => throw new RuntimeException("no extractor for fn \"{$fn}\""),
    };
}

it('runs every row in the shared table', function (): void {
    $summary = Conformance::runTable(
        SUITE,
        static fn (array $case): mixed => extractorFor((string) $case['fn'])($case['input']),
        'php',
    );

    // The whole summary, not just a count: a failure names the row and prints
    // both sides, which is the difference between "the suite is red" and
    // knowing which construct moved.
    expect($summary['ok'])->toBeTrue("\n" . Conformance::formatSummary($summary) . "\n");
});

it('compared something — the loop is not empty', function (): void {
    // Without this, an empty or unloadable table reports success over zero
    // assertions, which is worse than a red build because nobody
    // investigates green.
    $cases = Conformance::cases(SUITE);
    expect(count($cases))->toBeGreaterThan(40);

    foreach ($cases as $case) {
        expect(fn () => extractorFor((string) $case['fn']))->not->toThrow(RuntimeException::class);
    }
});

it('the extractors discriminate — a wrong document fails its own row', function (): void {
    // The control. Every extractor is a projection, and a projection that
    // returns a constant passes every row it is given.
    $shaded = extractorFor('cellProps')(
        ['doc' => ['blocks' => [['type' => 'table', 'rows' => [['cells' => [['blocks' => [], 'shading' => '#123456']]]]]]]],
    );
    $plain = extractorFor('cellProps')(
        ['doc' => ['blocks' => [['type' => 'table', 'rows' => [['cells' => [['blocks' => []]]]]]]]],
    );

    expect($shaded)->not->toEqual($plain);
});
