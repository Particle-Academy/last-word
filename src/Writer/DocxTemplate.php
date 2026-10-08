<?php

declare(strict_types=1);

namespace LastWord\Writer;

use LastWord\Exceptions\TemplateException;
use ZipArchive;

/**
 * A `.dotx` / `.docx` opened for the parts that carry its LOOK.
 *
 * last-word#3: every document came out in the built-in look, so an automation
 * that produced a structurally correct `.docx` still needed a human to re-apply
 * the house style — which the reporter described as most of its value gone.
 *
 * ## What is taken, and why only this
 *
 * A house template's look lives almost entirely in two parts:
 *
 *   - `word/styles.xml`   the definitions — fonts, sizes, colours, spacing,
 *                         and `w:docDefaults` for everything unstyled
 *   - `word/theme/theme1.xml`  the colour and font scheme those definitions
 *                         reference by name (`majorHAnsi`, `accent1`, …)
 *
 * Taking the styles WITHOUT the theme is the trap worth naming: a style saying
 * "the major heading font, accent 1" resolves against whatever theme ships in
 * the package, so the document would come out in the template's *structure* and
 * the default's *colours* — a wrong answer that looks deliberate.
 *
 * ## What is deliberately NOT taken
 *
 *   - **`word/numbering.xml`.** Our `document.xml` references `w:numId`s that
 *     our own numbering part defines. Swapping in a template's numbering would
 *     repoint every list at an id that means something else there, or nothing.
 *     Lists therefore take their INDENTS AND MARKERS from us and their
 *     typography from the template's `ListParagraph`.
 *   - **`w:sectPr`** — page size, margins, headers and footers. It lives in
 *     `document.xml`, which the writer owns, so it is a larger change than
 *     binding by style name. The reporter asked for style binding first and
 *     called headers, footers and a cover page secondary.
 *   - **`word/settings.xml`.** Mostly `w:rsid` revision junk, and carrying it
 *     would make output depend on a template's editing history.
 *
 * Nothing here parses the styles into a model. The part is passed through as
 * bytes, because Word's own serialisation is already correct and re-emitting it
 * from a DOM would be a chance to be wrong for no gain. The only thing read out
 * of it is WHICH style ids it defines, so the writer can supply its own
 * definitions for the ones it needs and the template does not have.
 */
final class DocxTemplate
{
    private function __construct(
        private readonly string $styles,
        private readonly ?string $theme,
        /** @var list<string> */
        private readonly array $styleIds,
    ) {}

    /**
     * Open a template from raw bytes or a filesystem path.
     *
     * Refuses rather than degrading. A template that cannot be read is the one
     * case where falling back to the built-in look reproduces the exact
     * complaint this feature exists to answer — a document that silently comes
     * out in the wrong style, with nothing said.
     *
     * @throws TemplateException
     */
    public static function open(string $pathOrBytes): self
    {
        $bytes = self::bytesOf($pathOrBytes);

        $tmp = tempnam(sys_get_temp_dir(), 'lw-tpl');
        if ($tmp === false) {
            throw new TemplateException('Could not create a temporary file to read the template.');
        }

        try {
            file_put_contents($tmp, $bytes);

            $zip = new ZipArchive();
            if ($zip->open($tmp, ZipArchive::RDONLY) !== true) {
                throw new TemplateException(
                    'The template is not a readable .dotx/.docx package (it did not open as a zip).',
                );
            }

            try {
                $styles = $zip->getFromName('word/styles.xml');
                if ($styles === false || $styles === '') {
                    throw new TemplateException(
                        'The template has no word/styles.xml, so it defines no styles to bind to.',
                    );
                }

                $theme = $zip->getFromName('word/theme/theme1.xml');
            } finally {
                $zip->close();
            }
        } finally {
            @unlink($tmp);
        }

        return new self($styles, $theme === false || $theme === '' ? null : $theme, self::idsIn($styles));
    }

    /** The template's `word/styles.xml`, verbatim. */
    public function styles(): string
    {
        return $this->styles;
    }

    /** The template's `word/theme/theme1.xml`, or null when it ships none. */
    public function theme(): ?string
    {
        return $this->theme;
    }

    public function hasTheme(): bool
    {
        return $this->theme !== null;
    }

    /** Whether the template defines a paragraph/character style with this id. */
    public function defines(string $styleId): bool
    {
        return in_array($styleId, $this->styleIds, true);
    }

    /**
     * Insert `$styleXml` before the closing `</w:styles>`.
     *
     * A string splice rather than a DOM edit. Re-serialising a template through
     * a DOM reorders attributes and rewrites namespace prefixes, and the result
     * still has to be a part Word accepts — so the fewer bytes of someone
     * else's file we touch, the better.
     *
     * @throws TemplateException
     */
    public function stylesWith(string $styleXml): string
    {
        if ($styleXml === '') {
            return $this->styles;
        }

        $close = strrpos($this->styles, '</w:styles>');
        if ($close === false) {
            throw new TemplateException(
                'The template\'s word/styles.xml has no closing </w:styles> element.',
            );
        }

        return substr($this->styles, 0, $close) . $styleXml . substr($this->styles, $close);
    }

    /**
     * `w:styleId` values declared in the part.
     *
     * Read with a regex on purpose: the question is only "is this id already
     * taken", the input is a part Word wrote, and a wrong answer costs a
     * duplicate definition rather than a wrong document. A full parse would be a
     * DOCTYPE surface on an uploaded file for no extra certainty.
     *
     * @return list<string>
     */
    private static function idsIn(string $stylesXml): array
    {
        preg_match_all('/<w:style\b[^>]*\bw:styleId="([^"]*)"/', $stylesXml, $matches);

        return array_values(array_unique(array_map(
            static fn (string $id): string => html_entity_decode($id, ENT_XML1 | ENT_QUOTES, 'UTF-8'),
            $matches[1],
        )));
    }

    /** @throws TemplateException */
    private static function bytesOf(string $pathOrBytes): string
    {
        // A zip's local file header. Anything starting with it is already the
        // package; anything else is treated as a path, which keeps a caller from
        // having to say which of the two they are passing.
        if (str_starts_with($pathOrBytes, "PK\x03\x04")) {
            return $pathOrBytes;
        }

        if (! is_file($pathOrBytes)) {
            throw new TemplateException(
                'The template is neither a .dotx/.docx package nor a readable file path.',
            );
        }

        $bytes = file_get_contents($pathOrBytes);
        if ($bytes === false || $bytes === '') {
            throw new TemplateException('The template file could not be read, or is empty.');
        }

        return $bytes;
    }
}
