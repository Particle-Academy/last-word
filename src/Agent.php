<?php

declare(strict_types=1);

namespace LastWord;

use InvalidArgumentException;
use LastWord\Exceptions\SchemaException;
use LastWord\Markdown\FromMarkdown;
use LastWord\Markdown\ToMarkdown;
use LastWord\Reader\DocxReader;
use LastWord\Schema\Repairer;
use LastWord\Schema\Schema;
use LastWord\Schema\Validator;
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
     * @param  array<string, mixed>  $doc
     * @return array{ok: bool, schema: array<string, mixed>, errors: list<array{path: string, message: string}>}
     */
    public static function validateAndRepair(array $doc): array
    {
        $errors = self::validate($doc);
        if (empty($errors)) {
            return ['ok' => true, 'schema' => $doc, 'errors' => []];
        }

        ['doc' => $repaired, 'notes' => $notes] = (new Repairer())->repair($doc);
        $remaining = self::validate($repaired);

        return [
            'ok' => empty($remaining),
            'schema' => $repaired,
            'errors' => array_merge($notes, $remaining),
        ];
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
     *
     * @param  array<string, mixed>  $doc
     * @param  array{tempDir?: ?string}  $options
     *
     * @throws SchemaException
     */
    public static function toBytes(array $doc, array $options = []): string
    {
        $errors = self::validate($doc);
        if (!empty($errors)) {
            throw new SchemaException(
                'Document failed schema validation. Call Agent::validateAndRepair() for a recoverable form.',
                $errors,
            );
        }

        return (new DocxWriter($options['tempDir'] ?? null))->toBytes($doc);
    }

    /**
     * Write a document to disk as a DOCX file (synchronous). Throws
     * SchemaException on validation errors. Same options as {@see toBytes()}.
     *
     * @param  array<string, mixed>  $doc
     * @param  array{tempDir?: ?string}  $options
     * @return array{path: string, bytes: int, blocks: int}
     *
     * @throws SchemaException
     */
    public static function write(array $doc, string $path, array $options = []): array
    {
        $errors = self::validate($doc);
        if (!empty($errors)) {
            throw new SchemaException(
                'Document failed schema validation. Call Agent::validateAndRepair() for a recoverable form.',
                $errors,
            );
        }

        return (new DocxWriter($options['tempDir'] ?? null))->write($doc, $path);
    }

    /**
     * Parse a real .docx back into the Doc model. Takes the raw bytes (a
     * string starting with the zip signature) or, as a convenience, a
     * filesystem path. Best-effort on Word-authored files: headings, runs
     * with formatting, hyperlinks, nested lists, tables, images and page
     * breaks come through; unknown constructs degrade to plain paragraphs.
     *
     * @return array<string, mixed>
     */
    public static function read(string $bytesOrPath): array
    {
        if (str_starts_with($bytesOrPath, "PK\x03\x04")) {
            return (new DocxReader())->read($bytesOrPath);
        }
        if (!str_contains($bytesOrPath, "\0") && is_file($bytesOrPath)) {
            $bytes = file_get_contents($bytesOrPath);
            if ($bytes === false) {
                throw new InvalidArgumentException("Could not read file: {$bytesOrPath}");
            }

            return (new DocxReader())->read($bytes);
        }

        throw new InvalidArgumentException('Agent::read() expects DOCX bytes or a path to a .docx file.');
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
    public static function version(): string
    {
        return Schema::VERSION;
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
}
