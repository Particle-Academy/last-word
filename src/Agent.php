<?php

declare(strict_types=1);

namespace LastWord;

use InvalidArgumentException;
use LastWord\Exceptions\SchemaException;
use LastWord\Exchange\Content;
use LastWord\Exchange\Exporter;
use LastWord\Exchange\ExportPolicy;
use LastWord\Exchange\FromPortable;
use LastWord\Exchange\InputRefused;
use LastWord\Exchange\OptionsInvalid;
use LastWord\Exchange\Portable;
use LastWord\Exchange\ToPortable;
use LastWord\Exceptions\TemplateException;
use LastWord\Markdown\FromMarkdown;
use LastWord\Markdown\ToMarkdown;
use LastWord\Ops\DocDiff;
use LastWord\Ops\DocOpSchema;
use LastWord\Ops\DocReducer;
use LastWord\Exceptions\UnsupportedFormatException;
use LastWord\Reader\DocReader;
use LastWord\Reader\DocxReader;
use LastWord\Reader\Format;
use LastWord\Reader\OdtReader;
use LastWord\Reader\RtfReader;
use LastWord\Schema\Repairer;
use LastWord\Schema\Schema;
use LastWord\Schema\Validator;
use LastWord\Writer\DocxTemplate;
use LastWord\Writer\DocxWriter;

/**
 * Agent — the structured-tool surface for LastWord.
 *
 * Designed for LLM tool-use: validate-then-write semantics, structured
 * error format, JSON Schema export for tool definitions. Every method is
 * static for the simplest possible call shape from agent infrastructure.
 *
 * Mirrors {@see \HolySheet\Agent} and {@see \DarkSlide\Agent} so the three
 * libraries feel like sibling tools — "write me an xlsx", "write me a
 * pptx" and "write me a docx" take the same code shape on the caller side.
 * The JSON document model is shared verbatim with the Node mirror,
 * @particle-academy/last-word.
 */
final class Agent
{
    /**
     * This package's own version, kept in ONE place and pinned to the changelog
     * by `VersionIsSingleSourcedTest`.
     *
     * A number living in two files with nothing comparing them drifts; that is
     * the same failure the envelope's `kit.json` rule exists to stop.
     */
    public const VERSION = '0.14.0';

    /**
     * Validate a document without writing anything. Returns a structured
     * error list — empty when the document is valid. Pass the JSON Schema
     * from {@see jsonSchema()} to your LLM tool registration to give the
     * agent field-level hints up front.
     *
     * @param  array<string, mixed>  $doc
     * @return list<array{path: string, message: string}>
     */
    public static function validate(array $doc): array
    {
        return (new Validator())->validate($doc);
    }

    /**
     * Validate + apply heuristic repairs. Returns:
     *
     *   - `ok: true, schema: array, errors: []`     — document was valid as-is
     *   - `ok: true, schema: array, errors: [...]`  — document had recoverable issues; the returned schema is the repaired version, errors lists what changed (including any dropped unknown blocks)
     *   - `ok: false, schema: array, errors: [...]` — document couldn't be repaired safely
     *
     * Repair heuristics: bare strings coerced to runs/paragraphs, `"text"`
     * shorthand coerced to runs, heading levels clamped to 1-6, unknown
     * block types dropped (with the drop retained as an error), missing
     * `blocks` defaulted to [].
     *
     * Designed for agentic feedback loops: hand the agent back the errors
     * if !ok so it can correct its next emission.
     *
     * Inline `{"type": "portable", "content": ...}` blocks are expanded here,
     * deterministically, through {@see fromPortable()}: `schema` holds
     * ordinary blocks, `errors` gains one note per expansion, and the result
     * gains a `report` (the conversions' reports, every `where` a JSON Pointer
     * into the document you passed). Portable content that does not validate
     * is left in place and makes the result `ok: false`.
     *
     * @param  array<string, mixed>  $doc
     * @return array{ok: bool, schema: array<string, mixed>, errors: list<array{path: string, message: string}>, report?: array<string, mixed>}
     */
    public static function validateAndRepair(array $doc): array
    {
        $x = Portable::expand($doc);
        $report = $x['expanded'] ? ['report' => $x['report']] : [];
        $doc = $x['doc'];

        $errors = self::validate($doc);
        if (empty($errors)) {
            return ['ok' => true, 'schema' => $doc, 'errors' => $x['notes']] + $report;
        }

        ['doc' => $repaired, 'notes' => $notes] = (new Repairer())->repair($doc);
        $remaining = self::validate($repaired);

        return [
            'ok' => empty($remaining),
            'schema' => $repaired,
            'errors' => array_merge($x['notes'], $notes, $remaining),
        ] + $report;
    }

    /**
     * The document the writer is given: validated, its portable blocks
     * expanded, and validated again (an expansion is ordinary blocks, which
     * still face every writer check).
     *
     * @param  array<string, mixed>  $doc
     * @return array<string, mixed>
     *
     * @throws SchemaException
     */
    private static function writable(array $doc): array
    {
        self::assertValid($doc);
        $x = Portable::expand($doc);
        if ($x['expanded']) {
            self::assertValid($x['doc']);
        }

        return $x['doc'];
    }

    /**
     * @param  array<string, mixed>  $doc
     *
     * @throws SchemaException
     */
    private static function assertValid(array $doc): void
    {
        $errors = self::validate($doc);
        if (!empty($errors)) {
            throw new SchemaException(
                'Document failed schema validation. Call Agent::validateAndRepair() for a recoverable form.',
                $errors,
            );
        }
    }

    /**
     * Return the DOCX bytes for a document. Throws SchemaException on
     * validation errors — call {@see validateAndRepair()} first for a
     * recoverable form.
     *
     * Deterministic: the same document always yields the same bytes.
     *
     * Options:
     *   - `tempDir` (string): override the temp dir ZipArchive assembles the
     *     archive in (for hosts where the system temp isn't writable).
     *   - `template` (string): a `.dotx`/`.docx` -- raw bytes or a filesystem
     *     path -- whose `word/styles.xml` and `word/theme/theme1.xml` the
     *     document is rendered onto, so it comes out in a house look rather than
     *     the built-in one (last-word#3).
     *
     *     Binds BY STYLE NAME: headings, paragraphs, quotes and lists already use
     *     Word's own style ids, so a template defining `Normal`, `Title`,
     *     `Heading1..n`, `Quote`, `ListParagraph` and `Hyperlink` binds with no
     *     mapping to configure. Definitions the template lacks are supplied from
     *     the built-in set, because a `w:pStyle` naming an undefined style renders
     *     unstyled in Word rather than erroring.
     *
     *     NOT taken by default: the template's section (page size, margins,
     *     headers, footers) -- see `templateParts`. Never taken: its body,
     *     `word/numbering.xml` (this document's lists reference numbering ids
     *     defined here) and `word/settings.xml` (beyond the one even/odd header
     *     flag a carried even-page header needs).
     *
     *     An unusable template THROWS {@see TemplateException} rather than falling
     *     back -- a document that silently comes out in the wrong style is the
     *     failure this option exists to end. A host can validate a
     *     customer-supplied template at upload by calling this and catching it.
     *   - `templateParts` (list): which parts of `template` to take, from
     *     `styles` (with the theme), `headers`, `footers` and `page`. Default
     *     `['styles']`, which is the behaviour before it existed. The letterhead
     *     is `['styles', 'headers', 'footers', 'page']` (last-word#5): the
     *     template's header and footer parts and their images, byte for byte,
     *     on every page, and its page size and margins.
     *
     *     The document's own settings win: a `page.header` / `page.footer`
     *     replaces the template's of that kind (all of its default / first /
     *     even variants), and `page.size`, `page.orientation` and each of
     *     `page.margins` override that one value. Ignored when `template` is
     *     absent or empty; an unknown part name throws.
     *
     * @param  array<string, mixed>  $doc
     * @param  array{tempDir?: ?string, template?: ?string, templateParts?: list<string>}  $options
     *
     * @throws SchemaException
     * @throws TemplateException
     */
    public static function toBytes(array $doc, array $options = []): string
    {
        $doc = self::writable($doc);

        return (new DocxWriter($options['tempDir'] ?? null, self::templateFrom($options)))->toBytes($doc);
    }

    /**
     * Write a document to disk as a DOCX file (synchronous). Throws
     * SchemaException on validation errors. Same options as {@see toBytes()}.
     *
     * @param  array<string, mixed>  $doc
     * @param  array{tempDir?: ?string, template?: ?string, templateParts?: list<string>}  $options
     * @return array{path: string, bytes: int, blocks: int}
     *
     * @throws SchemaException
     * @throws TemplateException
     */
    public static function write(array $doc, string $path, array $options = []): array
    {
        $doc = self::writable($doc);

        return (new DocxWriter($options['tempDir'] ?? null, self::templateFrom($options)))->write($doc, $path);
    }

    /**
     * The `template` option as an opened template, or null when absent.
     *
     * An empty string counts as absent, so a host passing through a blank form
     * field gets the built-in look rather than an exception.
     *
     * @param  array<string, mixed>  $options
     *
     * @throws TemplateException
     */
    private static function templateFrom(array $options): ?DocxTemplate
    {
        $template = $options['template'] ?? null;

        if ($template === null || $template === '') {
            return null;
        }

        if (! is_string($template)) {
            throw new TemplateException(
                'The template option must be a .dotx/.docx as raw bytes or a filesystem path.',
            );
        }

        return DocxTemplate::open($template, $options['templateParts'] ?? DocxTemplate::DEFAULT_PARTS);
    }

    /**
     * Parse a document back into the Doc model. Takes the raw bytes or, as a
     * convenience, a filesystem path, and decides the format from the CONTENT:
     * .docx, legacy .doc (Word 97-2003), .odt and .rtf all return the same shape.
     * Best-effort on Word-authored files: headings, runs with formatting,
     * hyperlinks, nested lists, tables, images and page breaks come through;
     * unknown constructs degrade to plain paragraphs. What each non-docx reader
     * recovers is listed on DocReader, OdtReader and RtfReader.
     *
     * @throws UnsupportedFormatException for bytes that are not one of those
     *   formats, naming what they are when that is knowable (`xls`, `pptx`, …)
     * @throws \RuntimeException for a file in a supported format that is damaged
     *
     * @return array<string, mixed>
     */
    public static function read(string $bytesOrPath): array
    {
        $bytes = self::bytesFrom($bytesOrPath);

        return match ($format = Format::detect($bytes)) {
            Format::DOCX => (new DocxReader())->read($bytes),
            Format::ODT => (new OdtReader())->read($bytes),
            Format::RTF => (new RtfReader())->read($bytes),
            // A compound file: DocReader reads a Word document and names anything
            // else (.xls, .ppt, .msg) itself, because only the container knows.
            Format::DOC => (new DocReader())->read($bytes),
            Format::UNKNOWN => throw new UnsupportedFormatException(
                Format::UNKNOWN,
                'Agent::read() could not recognise these bytes as a document. '
                .'It reads .docx, .doc (Word 97-2003), .odt and .rtf.',
            ),
            default => throw new UnsupportedFormatException(
                $format,
                "This is a .{$format} file, not a word-processing document. "
                .'Agent::read() reads .docx, .doc (Word 97-2003), .odt and .rtf.',
            ),
        };
    }

    /**
     * Bytes, whether we were handed bytes or a path.
     *
     * A path used to be read and passed straight to the docx reader without
     * looking at what it contained, so a legacy .doc on disk failed INSIDE the
     * zip reader and the error named a broken archive rather than the real
     * problem. Sniffing happens after this, on CONTENT, never on the extension
     * — an extension is a claim by whoever named the file.
     */
    private static function bytesFrom(string $bytesOrPath): string
    {
        // A NUL byte means this is already binary content, not a path. Checked
        // first because is_file() on a multi-megabyte string is wasteful and on
        // some platforms errors on a path that long.
        if (str_contains($bytesOrPath, "\0")) {
            return $bytesOrPath;
        }

        if (strlen($bytesOrPath) <= 4096 && is_file($bytesOrPath)) {
            return \LastWord\Reader\FileInput::read($bytesOrPath);
        }

        return $bytesOrPath;
    }

    /**
     * Alias of {@see read()} for symmetry with {@see toBytes()}.
     *
     * @return array<string, mixed>
     */
    public static function fromBytes(string $bytes): array
    {
        return self::read($bytes);
    }

    /**
     * Turn a Word `.dotx` / LibreOffice `.ott` template into its `.docx` /
     * `.odt` twin, or back. `$to` is `document`, `template`, or null to flip.
     *
     * Only the declaration moves: the main part's content type in
     * `[Content_Types].xml`, or the ODF `mimetype` entry together with the
     * manifest's root media-type (ODF 1.3 requires them to agree). Every other
     * member is copied as its original zip record, byte for byte; no reader or
     * writer is in the path, so nothing is reflowed or lost.
     *
     * Macro-enabled packages (.dotm, .docm) are refused, never converted.
     *
     * @throws \LastWord\Exceptions\OfficeTemplateRefused
     * @throws \LastWord\Exceptions\ArchiveRefused
     * @throws UnsupportedFormatException for anything that is not a Word or ODF text package
     */
    public static function convertTemplate(string $bytesOrPath, ?string $to = null): string
    {
        $bytes = self::bytesFrom($bytesOrPath);
        $format = Format::detect($bytes);
        if ($format !== Format::DOCX && $format !== Format::ODT) {
            throw new UnsupportedFormatException(
                $format,
                'Agent::convertTemplate() converts Word (.dotx/.docx) and OpenDocument text (.ott/.odt) packages'
                .($format === Format::UNKNOWN ? '; these bytes are neither.' : "; this is a .{$format} file."),
            );
        }

        return \LastWord\Archive\OfficeTemplate::convert($bytes, $to);
    }

    /**
     * Plain-text summary of a document — title, block counts by type, word
     * count. Useful as an agent tool that "describes" a document without
     * dumping the full JSON back to the model.
     *
     * @param  array<string, mixed>  $doc
     */
    public static function describe(array $doc): string
    {
        $title = (string) ($doc['title'] ?? 'Untitled');
        $blocks = is_array($doc['blocks'] ?? null) ? $doc['blocks'] : [];

        $counts = [];
        foreach ($blocks as $block) {
            $type = is_array($block) ? (string) ($block['type'] ?? 'unknown') : 'unknown';
            $counts[$type] = ($counts[$type] ?? 0) + 1;
        }

        $words = self::countWords($doc);

        $lines = [
            "Document: {$title}",
            'Blocks: ' . count($blocks),
        ];
        if (!empty($counts)) {
            $parts = [];
            foreach ($counts as $type => $n) {
                $parts[] = "{$n} {$type}";
            }
            $lines[] = 'Block types: ' . implode(', ', $parts);
        }
        $lines[] = "Words: {$words}";

        // A header or footer that holds blocks (last-word#6) -- a letterhead's
        // logo and address -- so an agent knows it is there without the JSON.
        foreach (['header', 'footer'] as $kind) {
            $band = is_array($doc['page'] ?? null) ? ($doc['page'][$kind] ?? null) : null;
            if (!is_array($band) || !is_array($band['blocks'] ?? null)) {
                continue;
            }
            $kinds = [];
            foreach ($band['blocks'] as $block) {
                $type = is_array($block) ? (string) ($block['type'] ?? 'unknown') : 'unknown';
                $kinds[$type] = ($kinds[$type] ?? 0) + 1;
            }
            $parts = [];
            foreach ($kinds as $type => $n) {
                $parts[] = "{$n} {$type}";
            }
            $lines[] = "Page {$kind}: " . count($band['blocks']) . ' blocks' . ($parts !== [] ? ' (' . implode(', ', $parts) . ')' : '');
        }

        // What read() found in a header or footer and could not represent
        // (last-word#5), so an agent knows a letterhead logo is there before it
        // writes the document back without one.
        $notes = [];
        foreach (is_array($doc['unrepresented'] ?? null) ? $doc['unrepresented'] : [] as $note) {
            if (is_array($note) && is_string($note['where'] ?? null) && is_string($note['kind'] ?? null) && is_int($note['count'] ?? null)) {
                $notes[] = $note['where'] . ': ' . $note['count'] . ' ' . $note['kind'];
            }
        }
        if ($notes !== []) {
            $lines[] = 'Not represented (kept only by writing onto the original as a template): ' . implode(', ', $notes);
        }

        return implode("\n", $lines);
    }

    /**
     * Doc model → GFM markdown — the Editor bridge. Headings, emphasis,
     * inline code, links, nested lists, tables, fenced code, blockquotes,
     * images and hr all map; underline / colors / alignment are dropped
     * (markdown has no slot for them).
     *
     * @param  array<string, mixed>  $doc
     */
    public static function toMarkdown(array $doc): string
    {
        return (new ToMarkdown())->convert($doc);
    }

    /**
     * GFM markdown → Doc model — the Editor bridge's inverse. Hand-rolled
     * parser, no external markdown dependency.
     *
     * @return array<string, mixed>
     */
    public static function fromMarkdown(string $markdown): array
    {
        return (new FromMarkdown())->convert($markdown);
    }

    /**
     * JSON Schema export for LLM tool-use registration. Pass this to your
     * MCP server / agent SDK so the model gets typed field hints.
     *
     * @return array<string, mixed>
     */
    public static function jsonSchema(): array
    {
        return Schema::jsonSchema();
    }

    /** Package version. */
    /**
     * The version of THIS PACKAGE.
     *
     * It used to return `Schema::VERSION`, which is the version of the document
     * MODEL — a genuinely separate number that moves when the shape of a `Doc`
     * changes, not when the package ships. Returning it here meant `version()`
     * reported `0.2.0` from a 0.4.x release, and the two numbers had no reason
     * to ever converge.
     *
     * `Schema::VERSION` is still there and still means what it always did; ask
     * for it by name when you want the model version.
     */
    public static function version(): string
    {
        return self::VERSION;
    }

    /**
     * @param  array<string, mixed>  $doc
     */
    private static function countWords(array $doc): int
    {
        $text = [];
        if (is_string($doc['title'] ?? null)) {
            $text[] = $doc['title'];
        }
        self::collectText($doc['blocks'] ?? [], $text);

        $joined = trim(implode(' ', $text));
        if ($joined === '') {
            return 0;
        }

        return count(preg_split('/\s+/u', $joined) ?: []);
    }

    /**
     * @param  mixed  $blocks
     * @param  list<string>  $text
     */
    private static function collectText(mixed $blocks, array &$text): void
    {
        if (!is_array($blocks)) {
            return;
        }
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            self::collectRuns($block['runs'] ?? null, $text);
            if (is_string($block['text'] ?? null)) {
                $text[] = $block['text'];
            }
            self::collectText($block['blocks'] ?? null, $text);
            self::collectItems($block['items'] ?? null, $text);
            if (is_array($block['rows'] ?? null)) {
                foreach ($block['rows'] as $row) {
                    if (is_array($row) && is_array($row['cells'] ?? null)) {
                        foreach ($row['cells'] as $cell) {
                            if (is_array($cell)) {
                                self::collectText($cell['blocks'] ?? null, $text);
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * @param  mixed  $items
     * @param  list<string>  $text
     */
    private static function collectItems(mixed $items, array &$text): void
    {
        if (!is_array($items)) {
            return;
        }
        foreach ($items as $item) {
            if (is_array($item)) {
                self::collectRuns($item['runs'] ?? null, $text);
                self::collectItems($item['children'] ?? null, $text);
            }
        }
    }

    /**
     * The ops that turn document `$a` into document `$b`.
     *
     * - `reduce($a, diff($a, $b))` equals `$b` (key order aside).
     * - Documents that write the same file diff to `[]`, so
     *   `diff($d, read(toBytes($d))) === []`: a save without a change records
     *   nothing.
     * - Rewording one paragraph is one `blocks.replace`, including a paragraph
     *   inside a table cell, a quote or a list; moving one is one `blocks.move`.
     *
     * Store `diff($new, $old)` to keep a version as the ops that restore it.
     * Both documents must be valid: the "same file" check writes them.
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     * @return list<array<string, mixed>>
     */
    public static function diff(array $a, array $b): array
    {
        return DocDiff::diff($a, $b);
    }

    /**
     * Apply one op, or a list of them, to a document; returns a new document. An
     * op whose path or index does not resolve is skipped.
     *
     * @param  array<string, mixed>  $doc
     * @param  array<string, mixed>|list<array<string, mixed>>  $opOrOps
     * @return array<string, mixed>
     */
    public static function reduce(array $doc, array $opOrOps): array
    {
        $ops = $opOrOps === [] || array_is_list($opOrOps) ? $opOrOps : [$opOrOps];

        return DocReducer::applyAll($doc, $ops);
    }

    /**
     * JSON Schema for one document op.
     *
     * @return array<string, mixed>
     */
    public static function opSchema(): array
    {
        return DocOpSchema::jsonSchema();
    }

    /**
     * Whether two documents write the same file: runs the reader merges, a
     * header row's bold and an empty paragraph the writer drops do not make them
     * different.
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    public static function equivalent(array $a, array $b): bool
    {
        return DocDiff::equivalent($a, $b);
    }

    /**
     * @param  mixed  $runs
     * @param  list<string>  $text
     */
    private static function collectRuns(mixed $runs, array &$text): void
    {
        if (!is_array($runs)) {
            return;
        }
        foreach ($runs as $run) {
            if (is_array($run) && is_string($run['text'] ?? null)) {
                $text[] = $run['text'];
            }
        }
    }

    // ------------------------------------------------------------------
    // Exchange: portable content (`fancyContent: 1`) in and out, and exports.
    // Normative text: fancy-conformance suites/exchange/SPEC.md.

    /**
     * Portable content to last-word blocks, to edit before placing them (or
     * put `{"type": "portable", "content": ...}` straight into `blocks` and let
     * validateAndRepair() / toBytes() do this). Tables keep their header rows,
     * spans and widths; numeric columns are right-aligned; typed values become
     * their display text. Formulas are never carried, only their values.
     *
     * Returns `{ok: true, blocks, report}`, the report locating every loss in
     * the content (`typed-as-text`, `formula-as-value`), or `{ok: false,
     * refused: {reason, path}}` for content that does not validate: closed
     * objects, http/https/mailto links only, PNG/JPEG bytes matching their
     * mime, and the exchange limits (`options.limits` overrides them).
     *
     * @param  array<string, mixed>|\stdClass  $content
     * @param  array{limits?: array<string, int>}  $options
     * @return array<string, mixed>
     */
    public static function fromPortable(array|\stdClass $content, array $options = []): array
    {
        return FromPortable::convert($content, $options);
    }

    /**
     * Check portable content without converting it: `{ok: true}` or
     * `{ok: false, code, path}`. Bad options throw {@see OptionsInvalid}.
     *
     * @return array{ok: bool, code?: string, path?: string}
     */
    public static function validateContent(mixed $content, mixed $options = null): array
    {
        return Content::validate($content, $options);
    }

    /**
     * A Doc to portable content: the pure half of {@see extract()}, for a host
     * that already holds the model. Selector: null (every block), `{table: n}`,
     * `{underHeading: "Results", nth: 0}`, `{blocks: [a, b]}`, 0-based and
     * inclusive.
     *
     * @param  array<string, mixed>  $doc
     * @param  array{includeHidden?: bool, headerRows?: int, limits?: array<string, int>}  $options
     * @return array<string, mixed>  `{ok: true, content}` or `{ok: false, refused: {reason}}`
     *
     * @throws OptionsInvalid
     */
    public static function toPortable(array $doc, mixed $selector = null, array $options = []): array
    {
        return ToPortable::fromDoc($doc, $selector, $options);
    }

    /**
     * A cheap index of a document an agent reads instead of the whole thing:
     * `{ok: true, blocks, headings: [{index, level, text, selector}], tables:
     * [{table, index, rows, columns, headerRows, heading, selector}], guard}`.
     * Each entry's `selector` is what {@see extract()} takes to grab it (a
     * heading's grabs its section). Heading text is guarded (invisible, bidi
     * and tag characters stripped and counted). Unreadable input is `{ok:
     * false, refused: {reason}}`, never an exception.
     *
     * @param  string|array<string, mixed>  $input  bytes, a path, or a Doc
     * @return array<string, mixed>
     */
    public static function catalog(string|array $input): array
    {
        try {
            $loaded = Exporter::load($input);
        } catch (InputRefused $e) {
            return ['ok' => false, 'refused' => ['reason' => $e->reason]];
        }

        return ['ok' => true] + Exporter::catalog($loaded['doc']);
    }

    /**
     * Grab part of a document as portable content (`fancyContent: 1`), for
     * holy-sheet, dark-slide, fine-print or another last-word to place.
     *
     * Selector: null (every block), `{table: n}`, `{underHeading: "Results",
     * nth: 0}` (the first table after that heading, before the next heading of
     * the same or higher level; exact match, ASCII case-insensitive), or
     * `{blocks: [a, b]}`; 0-based, inclusive. Reads .docx, .doc, .odt and
     * .rtf (bytes or a path), or a Doc you already hold.
     *
     * Guarded: invisible, bidi-control and Unicode tag characters are
     * stripped and counted; links outside http, https and mailto are removed
     * (their text kept). The report locates every loss by JSON Pointer into
     * the Doc and never carries document text. Not gated: it is a read.
     *
     * @param  string|array<string, mixed>  $input
     * @param  array{includeHidden?: bool, headerRows?: int, limits?: array<string, int>}  $options
     * @return array<string, mixed>  `{ok: true, content, report}` or `{ok: false, refused: {reason}}`
     *
     * @throws OptionsInvalid for an unknown or malformed option
     */
    public static function extract(string|array $input, mixed $selector = null, array $options = []): array
    {
        return Exporter::extract($input, $selector, $options);
    }

    /**
     * {@see toMarkdown()} plus what the conversion could not carry, in the
     * exchange report shape: `{markdown, report: {dropped, approximated,
     * guard}}`. Underline, colour, size, font, alignment and spacing, cell
     * styling, spans, nested blocks, extra header rows and line breaks are each
     * reported by kind and located by JSON Pointer; never by their text. The
     * markdown is exactly what toMarkdown() returns.
     *
     * @param  array<string, mixed>  $doc
     * @return array{markdown: string, report: array<string, mixed>}
     */
    public static function toMarkdownWithReport(array $doc): array
    {
        return (new ToMarkdown())->convertWithReport($doc);
    }

    /**
     * Export a document as `markdown`, `text`, `html` or `docx` (`pdf` once
     * the fine-print bridge ships; until then it is `export_unavailable`).
     *
     * OFF by default: the HOST enables targets with an {@see ExportPolicy}; a
     * null policy allows nothing, and nothing in `$options` (the agent's
     * `selector`, `headerRows`, `display`) can enable anything. An unknown
     * option throws.
     *
     *   - markdown: the GFM bridge over the selection, guarded, links limited
     *     to http, https and mailto, with the bridge's report
     *   - text: plain UTF-8; tables as TAB-separated rows
     *   - html: a standalone page; every string escaped, no scripts or event
     *     handlers, links only http, https and mailto, images as data URIs
     *   - docx: the document (from .doc, .odt, .rtf or .docx) rewritten
     *
     * Success: `{ok: true, target, mime, text|bytes, report}`. Refusal: `{ok:
     * false, refused: {reason, target, message, missing?, cause?}}`, checked in
     * the order exchange/policy pins: `export_unsupported`, `export_disabled`,
     * `export_unavailable`, `export_refused` (the input or the selector was
     * refused; `cause` is that code), then a limit code from the policy's
     * `limits`.
     *
     * @param  string|array<string, mixed>  $input  bytes, a path, or a Doc
     * @param  array{selector?: mixed, headerRows?: int, display?: bool}  $options
     * @return array<string, mixed>
     *
     * @throws OptionsInvalid
     */
    public static function export(string|array $input, string $target, array $options = [], ?ExportPolicy $policy = null): array
    {
        return Exporter::export($input, $target, $options, $policy);
    }

    /**
     * The export tool for an agent: its `target` enum lists ONLY the targets
     * this policy enables, so a disabled one is never shown. With a null
     * policy the enum is empty.
     *
     * @return array{name: string, description: string, input_schema: array<string, mixed>}
     */
    public static function exportToolDefinition(?ExportPolicy $policy = null): array
    {
        return Exporter::toolDefinition($policy);
    }

    /**
     * The agent guide: what makes this kind of file professional, and the schema
     * idioms that produce it, distilled from rubric-judged builds. Give it to a
     * model with the schema; it works with any agent loop. The same bytes ship in
     * the Node and Python packages (each pins the hash).
     */
    public static function guide(): string
    {
        $path = dirname(__DIR__).'/agent-guide/professional-documents.md';
        $text = is_file($path) ? file_get_contents($path) : false;
        if ($text === false) {
            throw new \RuntimeException('[last-word] agent-guide/professional-documents.md is missing from this install.');
        }

        return $text;
    }
}
