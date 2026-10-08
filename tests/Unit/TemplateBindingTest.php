<?php

declare(strict_types=1);

use LastWord\Agent;
use LastWord\Exceptions\TemplateException;
use LastWord\Tests\Support\GeneratedTemplate;

/**
 * Rendering onto a house template — `['template' => $dotxBytesOrPath]`.
 *
 * last-word#3. `toBytes()` accepted only `tempDir`, so every document came out
 * in the built-in look and an automation producing customer-facing collateral
 * still needed a human to re-apply the house style.
 *
 * **Bind by style name**, which is what the reporter asked for first: the
 * document model's headings, paragraphs, quotes and lists already use Word's own
 * style ids, so a template that defines `Normal`, `Title`, `Heading1..n`,
 * `Quote`, `ListParagraph` and `Hyperlink` binds by carrying its own
 * `word/styles.xml` and `word/theme/theme1.xml`. The writer supplies definitions
 * only for the ids it emits and the template does not define.
 *
 * The theme travels WITH the styles on purpose. A style that says "the major
 * heading font, accent 1" resolves against whatever theme is in the package, so
 * taking styles alone would produce the template's structure in the default's
 * colours — a wrong answer that looks deliberate.
 */
function templateDoc(): array
{
    return [
        'title' => 'Strawman Business Case',
        'blocks' => [
            ['type' => 'heading', 'level' => 1, 'runs' => [['text' => 'Executive Summary']]],
            // An inline `code` run is what emits the InlineCode character style.
            ['type' => 'paragraph', 'runs' => [
                ['text' => 'The case rests on '],
                ['text' => 'three', 'code' => true],
                ['text' => ' things.'],
            ]],
            // A heading level the generated template deliberately omits.
            ['type' => 'heading', 'level' => 3, 'runs' => [['text' => 'A level the template omits']]],
            ['type' => 'list', 'items' => [
                ['runs' => [['text' => 'One']]],
                ['runs' => [['text' => 'Two']]],
            ]],
            ['type' => 'quote', 'blocks' => [
                ['type' => 'paragraph', 'runs' => [['text' => 'Their words, not ours.']]],
            ]],
            ['type' => 'code', 'language' => 'php', 'text' => "echo 'hello';"],
        ],
    ];
}

/** @return array<string, string> */
function partsOf(string $bytes): array
{
    $path = tempnam(sys_get_temp_dir(), 'lw-out') . '.docx';
    file_put_contents($path, $bytes);

    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::RDONLY);
    $parts = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);
        $parts[$name] = (string) $zip->getFromName($name);
    }
    $zip->close();
    @unlink($path);

    return $parts;
}

it('writes the built-in look when no template is given', function (): void {
    // The regression guard. This feature is additive and must not move a single
    // byte for the callers who do not use it.
    $parts = partsOf(Agent::toBytes(templateDoc()));

    expect($parts['word/styles.xml'])->toContain('Calibri');
    expect($parts)->not->toHaveKey('word/theme/theme1.xml');
});

it('renders onto the template styles instead of its own', function (): void {
    $parts = partsOf(Agent::toBytes(templateDoc(), ['template' => GeneratedTemplate::bytes()]));

    // The template's definitions, not ours.
    expect($parts['word/styles.xml'])->toContain(GeneratedTemplate::FONT);
    expect($parts['word/styles.xml'])->toContain(GeneratedTemplate::HEADING_COLOR);
    expect($parts['word/styles.xml'])->not->toContain('Calibri');

    // And its own styles survive untouched, rather than being filtered to the
    // ones this writer happens to recognise.
    expect($parts['word/styles.xml'])->toContain('HouseNote');
});

it('carries the template theme, declared in the content types and rels', function (): void {
    $parts = partsOf(Agent::toBytes(templateDoc(), ['template' => GeneratedTemplate::bytes()]));

    expect($parts)->toHaveKey('word/theme/theme1.xml');
    expect($parts['word/theme/theme1.xml'])->toContain(GeneratedTemplate::ACCENT1);

    // A part that is present but undeclared makes the package invalid, and Word
    // reports that as "unreadable content" rather than naming the part.
    expect($parts['[Content_Types].xml'])
        ->toContain('<Override PartName="/word/theme/theme1.xml" ContentType="application/vnd.openxmlformats-officedocument.theme+xml"/>');
    expect($parts['word/_rels/document.xml.rels'])->toContain('theme/theme1.xml');
});

it('supplies definitions for the styles it emits and the template lacks', function (): void {
    // The document references CodeBlock, InlineCode and Heading3; the generated
    // template defines none of them. A reference to an undefined style is NOT an
    // error in Word -- the run simply renders unstyled, which is the silent
    // wrong-looking output this whole feature exists to prevent.
    $parts = partsOf(Agent::toBytes(templateDoc(), ['template' => GeneratedTemplate::bytes()]));
    $styles = $parts['word/styles.xml'];

    // Anchor it to the template first. Every assertion below is also true of the
    // built-in styles.xml, so without this the test would pass whether or not a
    // template was applied -- which is the shape of check this repo keeps finding.
    expect($styles)->toContain(GeneratedTemplate::FONT);

    expect($styles)->toContain('w:styleId="CodeBlock"');
    expect($styles)->toContain('w:styleId="InlineCode"');
    expect($styles)->toContain('w:styleId="Heading3"');

    // And it must NOT re-define what the template already has, or the duplicate
    // wins by document order and silently overrides the house style.
    expect(substr_count($styles, 'w:styleId="Heading1"'))->toBe(1);
    expect(substr_count($styles, 'w:styleId="Normal"'))->toBe(1);
    expect(substr_count($styles, 'w:styleId="Quote"'))->toBe(1);
    expect(substr_count($styles, 'w:styleId="ListParagraph"'))->toBe(1);
    expect(substr_count($styles, 'w:styleId="Hyperlink"'))->toBe(1);
});

it('keeps its own numbering so lists still resolve', function (): void {
    // Documented limit: list markers and indents come from us even with a
    // template, because document.xml references numIds our numbering part
    // defines. Taking the template's would repoint every list.
    $parts = partsOf(Agent::toBytes(templateDoc(), ['template' => GeneratedTemplate::bytes()]));

    expect($parts)->toHaveKey('word/numbering.xml');
    expect($parts['word/document.xml'])->toContain('<w:numPr>');
});

it('is deterministic with a template, as without one', function (): void {
    $template = GeneratedTemplate::bytes();

    expect(Agent::toBytes(templateDoc(), ['template' => $template]))
        ->toBe(Agent::toBytes(templateDoc(), ['template' => $template]));
});

it('accepts a template as a path as well as bytes', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'lw-tpl') . '.dotx';
    file_put_contents($path, GeneratedTemplate::bytes());

    try {
        expect(partsOf(Agent::toBytes(templateDoc(), ['template' => $path]))['word/styles.xml'])
            ->toContain(GeneratedTemplate::FONT);
    } finally {
        @unlink($path);
    }
});

it('works with a template that ships no theme', function (): void {
    $parts = partsOf(Agent::toBytes(templateDoc(), ['template' => GeneratedTemplate::bytes(withTheme: false)]));

    expect($parts['word/styles.xml'])->toContain(GeneratedTemplate::FONT);
    expect($parts)->not->toHaveKey('word/theme/theme1.xml');
    expect($parts['[Content_Types].xml'])->not->toContain('theme1.xml');
});

it('refuses a template it cannot use rather than falling back', function (): void {
    // Falling back to the built-in look would reproduce the exact complaint this
    // feature answers: a document that silently comes out wrong. A host can also
    // use this to validate a customer-supplied template at upload.
    expect(fn () => Agent::toBytes(templateDoc(), ['template' => 'not-a-package']))
        ->toThrow(TemplateException::class);

    expect(fn () => Agent::toBytes(templateDoc(), ['template' => GeneratedTemplate::withoutStyles()]))
        ->toThrow(TemplateException::class, 'no word/styles.xml');
});
