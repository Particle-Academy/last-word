<?php

declare(strict_types=1);

namespace LastWord\Reader;

use LastWord\Exceptions\UnsupportedFormatException;
use LastWord\Reader\Doc\CompoundFile;
use LastWord\Reader\Doc\WordBinary;

/**
 * Word 97-2003 binary `.doc` -> the same document shape `DocxReader` returns.
 *
 * The format that "actually shows up": still what a lot of people have on disk
 * and what older systems export. Before this, a `.doc` was refused by name, which
 * was honest but left a host with nothing to give a model.
 *
 * ## What comes through
 *
 * - paragraphs and their text, from every piece of a fast-saved file
 * - headings, by the style's built-in identifier (Heading 1-9), so a localised
 *   style name ("Überschrift 1") is still a heading
 * - bold, italic, underline and strike applied directly to text
 * - hyperlinks, from `HYPERLINK` fields; other fields keep their displayed
 *   result and drop their instructions
 * - bulleted and numbered lists with nesting
 * - tables, with header rows and a paragraph per cell paragraph
 * - page breaks
 *
 * ## What does not
 *
 * Formatting inherited from styles (only direct formatting is read), fonts,
 * sizes and colours, images and embedded objects, text boxes, headers, footers,
 * footnotes, endnotes and comments, merged cells (each cell is read as written),
 * and the document title. Nested tables are flattened into their outer cell.
 *
 * ## Refused
 *
 * A compound file that is not a Word document (`.xls`, `.ppt`, `.msg`), a Word 6
 * or 95 file, and an encrypted file each raise `UnsupportedFormatException`. A
 * damaged container raises `RuntimeException` naming what was wrong.
 */
final class DocReader
{
    /** ilfo 2047 means "explicitly not in a list" in Word 2003+ files. */
    private const ILFO_NOT_A_LIST = 2047;

    /** @var list<array<string, mixed>> */
    private array $blocks = [];

    /** @var list<array{text: string, flags: array<string, bool>, link: ?string}> */
    private array $runs = [];

    /** @var list<array{ilvl: int, ordered: bool, runs: list<array<string, mixed>>}> */
    private array $listEntries = [];

    /** @var list<array<string, mixed>>|null rows of the table being read */
    private ?array $tableRows = null;

    /** @var list<array<string, mixed>> cells of the row being read */
    private array $rowCells = [];

    /** @var list<array<string, mixed>> blocks of the cell being read */
    private array $cellBlocks = [];

    /** @var list<array{instruction: string, inResult: bool, link: ?string}> */
    private array $fields = [];

    private WordBinary $word;

    /** @return array<string, mixed> */
    public function read(string $bytes): array
    {
        $file = CompoundFile::fromBytes($bytes);
        if (! $file->hasStream('WordDocument')) {
            throw self::notWord($file);
        }

        $word = new WordBinary($file);
        $this->word = $word;

        foreach ($word->characters() as $character) {
            $this->consume($word, $character['char'], $character['fc']);
        }
        // A document whose last paragraph lacks a mark still has that paragraph.
        if ($this->runs !== []) {
            $this->endParagraph($word->paragraphAt(0), null);
        }
        $this->flushLists();
        $this->flushTable();

        return ['blocks' => $this->blocks];
    }

    private function consume(WordBinary $word, string $char, int $fc): void
    {
        switch ($char) {
            case "\x13": // field begin
                $this->fields[] = ['instruction' => '', 'inResult' => false, 'link' => null];

                return;
            case "\x14": // field separator: instruction done, result follows
                if ($this->fields !== []) {
                    $top = count($this->fields) - 1;
                    $this->fields[$top]['inResult'] = true;
                    $this->fields[$top]['link'] = self::hyperlink($this->fields[$top]['instruction']);
                }

                return;
            case "\x15": // field end
                array_pop($this->fields);

                return;
        }

        // Inside a field's instruction nothing is displayed.
        $top = count($this->fields) - 1;
        if ($top >= 0 && ! $this->fields[$top]['inResult']) {
            $this->fields[$top]['instruction'] .= $char;

            return;
        }

        switch ($char) {
            case "\r":
                $this->endParagraph($word->paragraphAt($fc), null);

                return;
            case "\x07":
                $this->endParagraph($word->paragraphAt($fc), 'cell');

                return;
            case "\x0C": // page or section break
                $this->endParagraph($word->paragraphAt($fc), null);
                $this->flushLists();
                $this->flushTable();
                $this->blocks[] = ['type' => 'pageBreak'];

                return;
            case "\x0B": // line break inside a paragraph
                $this->append("\n", $word, $fc);

                return;
            case "\x1E": // non-breaking hyphen
                $this->append('-', $word, $fc);

                return;
            case "\x1F": // optional hyphen: invisible unless the line breaks there
            case "\x01": // picture or embedded object anchor
            case "\x02": // automatic footnote reference
            case "\x03": // footnote separator
            case "\x04": // footnote continuation
            case "\x05": // annotation reference
            case "\x08": // drawn object anchor
            case '':     // second half of a surrogate pair
                return;
        }

        $this->append($char, $word, $fc);
    }

    private function append(string $text, WordBinary $word, int $fc): void
    {
        $link = null;
        for ($i = count($this->fields) - 1; $i >= 0 && $link === null; $i--) {
            $link = $this->fields[$i]['link'];
        }
        $flags = $word->charactersAt($fc);

        // One entry per formatting change, not per character: a long paragraph
        // would otherwise hold an array per letter.
        $last = count($this->runs) - 1;
        if ($last >= 0 && $this->runs[$last]['flags'] === $flags && $this->runs[$last]['link'] === $link) {
            $this->runs[$last]['text'] .= $text;

            return;
        }
        $this->runs[] = ['text' => $text, 'flags' => $flags, 'link' => $link];
    }

    /**
     * @param  array{istd: int, ilfo: int, ilvl: int, inTable: bool, rowEnd: bool, header: bool}  $props
     * @param  'cell'|null  $mark
     */
    private function endParagraph(array $props, ?string $mark): void
    {
        $runs = Structure::mergeRuns(array_map(
            static fn (array $r): array => ['text' => $r['text'], ...$r['flags'], ...($r['link'] !== null ? ['link' => $r['link']] : [])],
            $this->runs,
        ));
        $this->runs = [];
        $hasText = trim(Structure::text($runs)) !== '';

        if ($props['inTable']) {
            $this->flushLists();
            $this->tableRows ??= [];

            if ($mark === 'cell' && $props['rowEnd']) {
                $row = ['cells' => $this->rowCells];
                if ($props['header']) {
                    $row = ['header' => true, ...$row];
                }
                $this->tableRows[] = $row;
                $this->rowCells = [];
                $this->cellBlocks = [];

                return;
            }

            if ($hasText) {
                $this->cellBlocks[] = ['type' => 'paragraph', 'runs' => $runs];
            }
            if ($mark === 'cell') {
                $this->rowCells[] = ['blocks' => $this->cellBlocks];
                $this->cellBlocks = [];
            }

            return;
        }

        $this->flushTable();

        if (! $hasText) {
            return;
        }

        if ($props['ilfo'] > 0 && $props['ilfo'] !== self::ILFO_NOT_A_LIST) {
            $this->listEntries[] = [
                'ilvl' => $props['ilvl'],
                // Orderedness is the list's, read at its top level, as DocxReader
                // reads a numbering definition.
                'ordered' => $this->word->listIsOrdered($props['ilfo'], 0),
                'runs' => $runs,
            ];

            return;
        }

        $this->flushLists();

        $level = $this->word->headingLevel($props['istd']);
        $this->blocks[] = $level !== null
            ? ['type' => 'heading', 'level' => min(6, $level), 'runs' => $runs]
            : ['type' => 'paragraph', 'runs' => $runs];
    }

    private function flushLists(): void
    {
        if ($this->listEntries !== []) {
            array_push($this->blocks, ...Structure::lists($this->listEntries));
            $this->listEntries = [];
        }
    }

    private function flushTable(): void
    {
        if ($this->tableRows === null) {
            return;
        }
        if ($this->rowCells !== []) {
            $this->tableRows[] = ['cells' => $this->rowCells];
        }
        if ($this->tableRows !== []) {
            $this->blocks[] = ['type' => 'table', 'rows' => $this->tableRows];
        }
        $this->tableRows = null;
        $this->rowCells = [];
        $this->cellBlocks = [];
    }

    /** The target of a `HYPERLINK` field instruction, or null for any other field. */
    private static function hyperlink(string $instruction): ?string
    {
        if (preg_match('/^\s*HYPERLINK\b(.*)$/is', $instruction, $m) !== 1) {
            return null;
        }
        $rest = $m[1];
        $target = preg_match('/^\s*"([^"]*)"/', $rest, $url) === 1 ? $url[1] : null;
        if (preg_match('/\\\\l\s+"([^"]*)"/i', $rest, $anchor) === 1) {
            $target = ($target ?? '').'#'.$anchor[1];
        }

        return $target !== null && $target !== '' ? $target : null;
    }

    private static function notWord(CompoundFile $file): UnsupportedFormatException
    {
        $names = $file->streamNames();
        [$format, $what] = match (true) {
            in_array('Workbook', $names, true), in_array('Book', $names, true) => ['xls', 'an Excel 97-2003 workbook (.xls)'],
            in_array('PowerPoint Document', $names, true) => ['ppt', 'a PowerPoint 97-2003 presentation (.ppt)'],
            in_array('__properties_version1.0', $names, true) => ['msg', 'an Outlook message (.msg)'],
            default => ['cfb', 'a compound file that is not a Word document'],
        };

        return new UnsupportedFormatException(
            $format,
            "This is {$what}, not a Word document. last-word reads .docx, .doc, .odt and .rtf.",
        );
    }
}
