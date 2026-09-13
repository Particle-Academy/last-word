<?php

declare(strict_types=1);

namespace LastWord\Reader\Doc;

use LastWord\Exceptions\UnsupportedFormatException;
use RuntimeException;

/**
 * The parts of a Word 97-2003 binary document (MS-DOC) a reader needs, decoded
 * into plain PHP: the main text as characters with their file positions, every
 * paragraph's style and list membership, character formatting by position, and
 * the style and list tables those refer to.
 *
 * Deliberately not a Word object model. `DocReader` turns this into the same
 * document shape the other readers return; this class only answers "what is at
 * character N".
 *
 * ## What is read
 *
 * - **FIB** (File Information Block): the Word 97+ layout only. Word 6 and 95
 *   files use an older FIB and are refused by name, as are encrypted files.
 * - **Piece table** (`Clx`): the main document text, including fast-saved files
 *   whose text is split and reordered across pieces, and both piece encodings
 *   (8-bit compressed and UTF-16).
 * - **Style sheet** (`STSH`): each style's built-in identifier, which is how a
 *   heading is recognised independent of the style's localised name.
 * - **Paragraph formatting** (`PlcfBtePapx` → PAPX FKPs): style, list id and
 *   level, table membership and row ends, header rows.
 * - **Character formatting** (`PlcfBteChpx` → CHPX FKPs): bold, italic,
 *   underline, strike.
 * - **Lists** (`PlfLfo`, `PlfLst`): whether each list level is numbered or a
 *   bullet.
 *
 * ## What is not
 *
 * Headers, footers, footnotes, comments and text boxes (their text lives after
 * the main document's character range and is skipped); formatting inherited from
 * a paragraph or character style (only direct formatting is read, which is where
 * inline emphasis lives); images and embedded objects; fonts, sizes and colours.
 *
 * ## Hostile input
 *
 * Every offset read from the file is bounds-checked. A truncated structure is
 * either skipped (formatting, which the text can live without) or refused (the
 * piece table, without which there is no text). Counts are capped by the bytes
 * available, never trusted to size a loop on their own.
 */
final class WordBinary
{
    /** The FibBase.nFib Word 97 and every later version write. */
    private const NFIB_WORD97 = 0x00C1;

    /** Built-in style identifiers (`sti`) for Heading 1 through Heading 9. */
    private const STI_HEADING_FIRST = 1;

    private const STI_HEADING_LAST = 9;

    private const NFC_BULLET = 0x17;

    private const NFC_NONE = 0xFF;

    public readonly string $document;

    public readonly string $table;

    /** @var list<array{cpStart: int, cpEnd: int, fc: int, compressed: bool}> */
    private array $pieces = [];

    private int $textLength = 0;

    /** @var list<int> istd → sti */
    private array $styleIds = [];

    /** @var list<string> istd → style name */
    private array $styleNames = [];

    /** @var list<array{fcStart: int, fcEnd: int, props: array<string, mixed>}> sorted by fcStart */
    private array $paragraphRuns = [];

    /** @var list<array{fcStart: int, fcEnd: int, props: array<string, bool>}> sorted by fcStart */
    private array $characterRuns = [];

    /** @var array<int, int> ilfo (1-based) → lsid */
    private array $listIds = [];

    /** @var array<int, list<int>> lsid → nfc per level */
    private array $listFormats = [];

    /** @var array<int, int> fc → index into $pieces cache */
    private int $fibFlags = 0;

    /** @var array<int, array{0: int, 1: int}> FibRgFcLcb97 index → [fc, lcb] */
    private array $fcLcb = [];

    public function __construct(CompoundFile $file)
    {
        $document = $file->stream('WordDocument');
        if ($document === null) {
            throw new RuntimeException('The compound file has no WordDocument stream.');
        }
        $this->document = $document;

        $this->readFib();

        $tableName = ($this->fibFlags & 0x0200) !== 0 ? '1Table' : '0Table';
        $table = $file->stream($tableName);
        if ($table === null) {
            throw new RuntimeException("The Word document has no {$tableName} stream.");
        }
        $this->table = $table;

        $this->readPieces();
        $this->readStyles();
        $this->paragraphRuns = $this->readFkps(13, true);
        $this->characterRuns = $this->readFkps(12, false);
        $this->readLists();
    }

    /** Number of characters in the main document. */
    public function textLength(): int
    {
        return $this->textLength;
    }

    /**
     * The main document text, one entry per character position.
     *
     * A UTF-16 surrogate pair occupies two character positions in the file, so
     * its code point is returned at the first and an empty string at the second;
     * callers joining the text get it once, and positions stay aligned with the
     * file's. Half a pair on its own is U+FFFD.
     *
     * @return \Generator<int, array{char: string, fc: int}>
     */
    public function characters(): \Generator
    {
        foreach ($this->pieces as $piece) {
            $cpEnd = min($piece['cpEnd'], $this->textLength);
            $pairedLow = false;
            for ($cp = $piece['cpStart']; $cp < $cpEnd; $cp++) {
                if ($piece['compressed']) {
                    $fc = $piece['fc'] + ($cp - $piece['cpStart']);
                    if ($fc >= strlen($this->document)) {
                        return;
                    }
                    yield $cp => ['char' => self::cp1252(ord($this->document[$fc])), 'fc' => $fc];

                    continue;
                }

                $fc = $piece['fc'] + 2 * ($cp - $piece['cpStart']);
                if ($fc + 2 > strlen($this->document)) {
                    return;
                }
                $unit = self::u16($this->document, $fc);

                if ($pairedLow) {
                    $pairedLow = false;
                    yield $cp => ['char' => '', 'fc' => $fc];

                    continue;
                }
                if ($unit >= 0xD800 && $unit <= 0xDBFF && $cp + 1 < $cpEnd) {
                    $low = self::u16($this->document, $fc + 2);
                    if ($low >= 0xDC00 && $low <= 0xDFFF) {
                        $pairedLow = true;
                        yield $cp => ['char' => self::utf8(0x10000 + (($unit - 0xD800) << 10) + ($low - 0xDC00)), 'fc' => $fc];

                        continue;
                    }
                }
                yield $cp => ['char' => self::utf8($unit), 'fc' => $fc];
            }
        }
    }

    /**
     * Paragraph properties at a file position: `istd`, `ilfo`, `ilvl`,
     * `inTable`, `rowEnd`, `header`.
     *
     * @return array{istd: int, ilfo: int, ilvl: int, inTable: bool, rowEnd: bool, header: bool}
     */
    public function paragraphAt(int $fc): array
    {
        $run = self::find($this->paragraphRuns, $fc);

        return array_merge(
            ['istd' => 0, 'ilfo' => 0, 'ilvl' => 0, 'inTable' => false, 'rowEnd' => false, 'header' => false],
            $run['props'] ?? [],
        );
    }

    /** @return array<string, bool> the character formatting flags set at a file position */
    public function charactersAt(int $fc): array
    {
        return self::find($this->characterRuns, $fc)['props'] ?? [];
    }

    /** The heading level a paragraph style gives, or null when it is not a heading style. */
    public function headingLevel(int $istd): ?int
    {
        $sti = $this->styleIds[$istd] ?? null;
        if ($sti !== null && $sti >= self::STI_HEADING_FIRST && $sti <= self::STI_HEADING_LAST) {
            return $sti;
        }
        // A user style named "Heading N" (as some converters write) is a heading too.
        if (preg_match('/^heading\s*([1-9])$/i', trim($this->styleNames[$istd] ?? ''), $m) === 1) {
            return (int) $m[1];
        }

        return null;
    }

    /** Whether list `ilfo` at level `ilvl` is numbered (true) or bulleted (false). */
    public function listIsOrdered(int $ilfo, int $ilvl): bool
    {
        $lsid = $this->listIds[$ilfo] ?? null;
        $formats = $lsid !== null ? ($this->listFormats[$lsid] ?? []) : [];
        $nfc = $formats[$ilvl] ?? $formats[0] ?? self::NFC_BULLET;

        return $nfc !== self::NFC_BULLET && $nfc !== self::NFC_NONE;
    }

    // ─── FIB ──────────────────────────────────────────────────────────────

    private function readFib(): void
    {
        $d = $this->document;
        if (strlen($d) < 34 || self::u16($d, 0) !== 0xA5EC) {
            throw new RuntimeException('The WordDocument stream does not start with a Word File Information Block.');
        }

        if (self::u16($d, 2) < self::NFIB_WORD97) {
            throw new UnsupportedFormatException(
                'doc',
                'This is a Word 6 or Word 95 .doc, which predates the Word 97 binary format last-word reads. '
                .'Re-save it as .docx and read it again.',
            );
        }

        $this->fibFlags = self::u16($d, 10);
        if (($this->fibFlags & 0x0100) !== 0) {
            throw new UnsupportedFormatException(
                'doc',
                'This .doc is password-protected (encrypted), so its text cannot be read. '
                .'Remove the password, save it as .docx and read it again.',
            );
        }

        $offset = 32;
        $csw = self::u16($d, $offset);
        $offset += 2 + $csw * 2;
        $cslw = self::u16($d, $offset);
        $rgLw = $offset + 2;
        $offset = $rgLw + $cslw * 4;
        if ($cslw < 4 || $offset + 2 > strlen($d)) {
            throw new RuntimeException('The Word File Information Block is truncated.');
        }
        $this->textLength = self::u32($d, $rgLw + 12); // ccpText

        $count = self::u16($d, $offset);
        $offset += 2;
        for ($i = 0; $i < $count && $offset + ($i + 1) * 8 <= strlen($d); $i++) {
            $this->fcLcb[$i] = [self::u32($d, $offset + $i * 8), self::u32($d, $offset + $i * 8 + 4)];
        }
    }

    /** A structure from the table stream by its FibRgFcLcb97 index, or '' when absent or out of range. */
    private function tableStructure(int $index): string
    {
        [$fc, $lcb] = $this->fcLcb[$index] ?? [0, 0];
        if ($lcb === 0 || $fc + $lcb > strlen($this->table)) {
            return '';
        }

        return substr($this->table, $fc, $lcb);
    }

    // ─── Text ─────────────────────────────────────────────────────────────

    private function readPieces(): void
    {
        $clx = $this->tableStructure(33);
        if ($clx === '') {
            throw new RuntimeException('The Word document has no piece table, so it has no readable text.');
        }

        $offset = 0;
        // Prc records (clxt 0x01) come first and hold property modifiers. Each
        // is at most 0x3FA2 bytes of properties, so every step moves forward.
        while ($offset < strlen($clx) && ord($clx[$offset]) === 0x01) {
            $cbGrpprl = self::u16($clx, $offset + 1);
            if ($cbGrpprl > 0x3FA2) {
                throw new RuntimeException('The Word piece table is malformed.');
            }
            $offset += 3 + $cbGrpprl;
        }
        if ($offset >= strlen($clx) || ord($clx[$offset]) !== 0x02) {
            throw new RuntimeException('The Word piece table is malformed.');
        }

        $lcb = self::u32($clx, $offset + 1);
        $plc = substr($clx, $offset + 5, $lcb);
        if (strlen($plc) !== $lcb || $lcb < 4) {
            throw new RuntimeException('The Word piece table is truncated.');
        }

        // Pieces cover the text in order, each starting where the last ended. A
        // table that overlaps or goes backwards would let one byte range be read
        // over and over, so reading stops at the first piece out of order.
        $count = intdiv($lcb - 4, 12);
        $expected = 0;
        for ($i = 0; $i < $count && $expected < $this->textLength; $i++) {
            $cpStart = self::u32($plc, $i * 4);
            $cpEnd = self::u32($plc, ($i + 1) * 4);
            $raw = self::u32($plc, ($count + 1) * 4 + $i * 8 + 2);
            $compressed = ($raw & 0x40000000) !== 0;
            $fc = $raw & 0x3FFFFFFF;
            if ($cpStart !== $expected || $cpEnd <= $cpStart) {
                break;
            }
            $expected = $cpEnd;
            $this->pieces[] = [
                'cpStart' => $cpStart,
                'cpEnd' => $cpEnd,
                'fc' => $compressed ? intdiv($fc, 2) : $fc,
                'compressed' => $compressed,
            ];
        }

        if ($this->pieces === []) {
            throw new RuntimeException('The Word piece table holds no text.');
        }
    }

    // ─── Styles ───────────────────────────────────────────────────────────

    private function readStyles(): void
    {
        $stsh = $this->tableStructure(1);
        if (strlen($stsh) < 4) {
            return;
        }

        $cbStshi = self::u16($stsh, 0);
        $count = self::u16($stsh, 2);
        $cbBase = self::u16($stsh, 4);
        $offset = 2 + $cbStshi;

        for ($istd = 0; $istd < $count && $offset + 2 <= strlen($stsh); $istd++) {
            $cbStd = self::u16($stsh, $offset);
            $std = substr($stsh, $offset + 2, $cbStd);
            $offset += 2 + $cbStd;
            if ($cbStd === 0 || strlen($std) < 2) {
                $this->styleIds[$istd] = -1;
                $this->styleNames[$istd] = '';

                continue;
            }

            $this->styleIds[$istd] = self::u16($std, 0) & 0x0FFF;

            // The name follows the fixed-size base: a character count, then
            // UTF-16 characters.
            $nameAt = $cbBase;
            $length = self::u16($std, $nameAt);
            $this->styleNames[$istd] = $length > 0 && $nameAt + 2 + $length * 2 <= strlen($std)
                ? self::utf16(substr($std, $nameAt + 2, $length * 2))
                : '';
        }
    }

    // ─── Formatting ───────────────────────────────────────────────────────

    /**
     * Read a PlcBte and every FKP it points at.
     *
     * @return list<array{fcStart: int, fcEnd: int, props: array<string, mixed>}>
     */
    private function readFkps(int $index, bool $paragraphs): array
    {
        $plc = $this->tableStructure($index);
        if (strlen($plc) < 8) {
            return [];
        }

        $count = intdiv(strlen($plc) - 4, 8);
        $runs = [];
        $seenPages = [];
        for ($i = 0; $i < $count; $i++) {
            $pn = self::u32($plc, ($count + 1) * 4 + $i * 4) & 0x3FFFFF;
            if (isset($seenPages[$pn])) {
                continue;
            }
            $seenPages[$pn] = true;

            $page = substr($this->document, $pn * 512, 512);
            if (strlen($page) !== 512) {
                continue;
            }
            $crun = ord($page[511]);
            if ($crun === 0 || 4 * ($crun + 1) > 511) {
                continue;
            }

            for ($j = 0; $j < $crun; $j++) {
                $fcStart = self::u32($page, $j * 4);
                $fcEnd = self::u32($page, ($j + 1) * 4);
                $runs[] = [
                    'fcStart' => $fcStart,
                    'fcEnd' => $fcEnd,
                    'props' => $paragraphs ? $this->papx($page, $crun, $j) : $this->chpx($page, $crun, $j),
                ];
            }
        }

        usort($runs, static fn (array $a, array $b): int => $a['fcStart'] <=> $b['fcStart']);

        return $runs;
    }

    /** @return array<string, mixed> */
    private function papx(string $page, int $crun, int $j): array
    {
        // rgbx: one 13-byte BxPap (a 1-byte offset and a 12-byte PHE) per run.
        $bxAt = 4 * ($crun + 1) + 13 * $j;
        if ($bxAt >= 511) {
            return [];
        }
        $at = ord($page[$bxAt]) * 2;
        if ($at === 0 || $at >= 511) {
            return [];
        }

        $cb = ord($page[$at]);
        if ($cb === 0) {
            $size = 2 * ord($page[$at + 1] ?? "\0");
            $start = $at + 2;
        } else {
            $size = 2 * $cb - 1;
            $start = $at + 1;
        }
        $grpprl = substr($page, $start, max(0, min($size, 511 - $start)));
        if (strlen($grpprl) < 2) {
            return [];
        }

        $props = ['istd' => self::u16($grpprl, 0)];
        foreach (self::sprms(substr($grpprl, 2)) as [$sprm, $operand]) {
            match ($sprm) {
                0x460B => $props['ilfo'] = self::s16($operand, 0),     // sprmPIlfo
                0x260A => $props['ilvl'] = min(8, ord($operand[0] ?? "\0")), // sprmPIlvl
                0x2416 => $props['inTable'] = ord($operand[0] ?? "\0") !== 0, // sprmPFInTable
                0x2417 => $props['rowEnd'] = ord($operand[0] ?? "\0") !== 0,  // sprmPFTtp
                0x3404 => $props['header'] = ord($operand[0] ?? "\0") !== 0,  // sprmTTableHeader
                default => null,
            };
        }

        return $props;
    }

    /** @return array<string, bool> */
    private function chpx(string $page, int $crun, int $j): array
    {
        $rgbAt = 4 * ($crun + 1) + $j;
        if ($rgbAt >= 511) {
            return [];
        }
        $at = ord($page[$rgbAt]) * 2;
        if ($at === 0 || $at >= 511) {
            return [];
        }
        $cb = ord($page[$at]);
        $grpprl = substr($page, $at + 1, max(0, min($cb, 511 - $at - 1)));

        $props = [];
        foreach (self::sprms($grpprl) as [$sprm, $operand]) {
            $value = ord($operand[0] ?? "\0");
            // Toggle operands: 0 off, 1 on, 0x80 "as the style", 0x81 "opposite
            // of the style". Only direct formatting is read, so 0x81 counts as on.
            $on = $value === 1 || $value === 0x81;
            match ($sprm) {
                0x0835 => $props['bold'] = $on,       // sprmCFBold
                0x0836 => $props['italic'] = $on,     // sprmCFItalic
                0x0837 => $props['strike'] = $on,     // sprmCFStrike
                0x2A3E => $props['underline'] = $value !== 0, // sprmCKul: any underline kind
                default => null,
            };
        }

        return array_filter($props);
    }

    /**
     * Walk a grpprl, yielding each property's id and operand bytes.
     *
     * The operand size comes from the id's `spra` bits. Stops at the first
     * property that would run past the buffer rather than guessing past it.
     *
     * @return \Generator<int, array{0: int, 1: string}>
     */
    private static function sprms(string $grpprl): \Generator
    {
        $offset = 0;
        $length = strlen($grpprl);
        while ($offset + 2 <= $length) {
            $sprm = self::u16($grpprl, $offset);
            $offset += 2;

            $size = match ($sprm >> 13) {
                0, 1 => 1,
                2, 4, 5 => 2,
                3 => 4,
                7 => 3,
                default => self::variableOperandSize($sprm, $grpprl, $offset),
            };
            if ($size < 0 || $offset + $size > $length) {
                return;
            }

            yield [$sprm, substr($grpprl, $offset, $size)];
            $offset += $size;
        }
    }

    private static function variableOperandSize(int $sprm, string $grpprl, int $offset): int
    {
        if ($offset >= strlen($grpprl)) {
            return -1;
        }
        // sprmTDefTable and sprmTDefTable10: a 2-byte count of the rest, plus one.
        if ($sprm === 0xD608 || $sprm === 0xD606) {
            return $offset + 2 <= strlen($grpprl) ? 2 + self::u16($grpprl, $offset) - 1 : -1;
        }
        // sprmPChgTabs with the 255 escape: deleted tabs (4 bytes each), then
        // added tabs (3 bytes each), each list prefixed by its count.
        if ($sprm === 0xC615 && ord($grpprl[$offset]) === 255) {
            $deleted = ord($grpprl[$offset + 1] ?? "\0");
            $addedAt = $offset + 2 + 4 * $deleted;
            $added = ord($grpprl[$addedAt] ?? "\0");

            return 2 + 4 * $deleted + 1 + 3 * $added;
        }

        return 1 + ord($grpprl[$offset]);
    }

    // ─── Lists ────────────────────────────────────────────────────────────

    private function readLists(): void
    {
        $lst = $this->tableStructure(73);
        $lfo = $this->tableStructure(74);
        if (strlen($lst) < 2 || strlen($lfo) < 4) {
            return;
        }

        // PlfLfo: a count, then 16-byte LFO records whose first field is the lsid.
        $lfoCount = min(self::u32($lfo, 0), intdiv(strlen($lfo) - 4, 16));
        for ($i = 0; $i < $lfoCount; $i++) {
            $this->listIds[$i + 1] = self::u32($lfo, 4 + $i * 16);
        }

        // PlfLst: a count and 28-byte LSTF records; the LVLs for every list
        // follow the PlfLst immediately in the table stream, in the same order.
        [$fcLst, $lcbLst] = $this->fcLcb[73];
        $lstCount = min(self::s16($lst, 0), intdiv(strlen($lst) - 2, 28));
        $levelsAt = $fcLst + $lcbLst;

        for ($i = 0; $i < $lstCount; $i++) {
            $record = 2 + $i * 28;
            $lsid = self::u32($lst, $record);
            $simple = (ord($lst[$record + 26]) & 0x01) !== 0;
            $levels = $simple ? 1 : 9;

            $formats = [];
            for ($level = 0; $level < $levels; $level++) {
                if ($levelsAt + 28 > strlen($this->table)) {
                    return;
                }
                $formats[] = ord($this->table[$levelsAt + 4]);
                $cbChpx = ord($this->table[$levelsAt + 24]);
                $cbPapx = ord($this->table[$levelsAt + 25]);
                $levelsAt += 28 + $cbPapx + $cbChpx;
                if ($levelsAt + 2 > strlen($this->table)) {
                    return;
                }
                $levelsAt += 2 + 2 * self::u16($this->table, $levelsAt);
            }
            $this->listFormats[$lsid] = $formats;
        }
    }

    // ─── Helpers ──────────────────────────────────────────────────────────

    /**
     * The run containing a file position, by binary search.
     *
     * @param  list<array{fcStart: int, fcEnd: int, props: array<string, mixed>}>  $runs
     * @return array{fcStart: int, fcEnd: int, props: array<string, mixed>}|null
     */
    private static function find(array $runs, int $fc): ?array
    {
        $low = 0;
        $high = count($runs) - 1;
        while ($low <= $high) {
            $mid = ($low + $high) >> 1;
            $run = $runs[$mid];
            if ($fc < $run['fcStart']) {
                $high = $mid - 1;
            } elseif ($fc >= $run['fcEnd']) {
                $low = $mid + 1;
            } else {
                return $run;
            }
        }

        return null;
    }

    /**
     * A byte from an 8-bit ("compressed") piece. MS-DOC stores these as
     * Windows-1252, whose 0x80-0x9F range differs from Latin-1.
     */
    private static function cp1252(int $byte): string
    {
        static $high = [
            0x80 => 0x20AC, 0x82 => 0x201A, 0x83 => 0x0192, 0x84 => 0x201E, 0x85 => 0x2026, 0x86 => 0x2020,
            0x87 => 0x2021, 0x88 => 0x02C6, 0x89 => 0x2030, 0x8A => 0x0160, 0x8B => 0x2039, 0x8C => 0x0152,
            0x8E => 0x017D, 0x91 => 0x2018, 0x92 => 0x2019, 0x93 => 0x201C, 0x94 => 0x201D, 0x95 => 0x2022,
            0x96 => 0x2013, 0x97 => 0x2014, 0x98 => 0x02DC, 0x99 => 0x2122, 0x9A => 0x0161, 0x9B => 0x203A,
            0x9C => 0x0153, 0x9E => 0x017E, 0x9F => 0x0178,
        ];

        return self::utf8($high[$byte] ?? $byte);
    }

    public static function utf8(int $cp): string
    {
        return match (true) {
            $cp < 0x80 => chr($cp),
            $cp < 0x800 => chr(0xC0 | ($cp >> 6)).chr(0x80 | ($cp & 0x3F)),
            $cp >= 0xD800 && $cp <= 0xDFFF, $cp > 0x10FFFF => "\u{FFFD}",
            $cp < 0x10000 => chr(0xE0 | ($cp >> 12)).chr(0x80 | (($cp >> 6) & 0x3F)).chr(0x80 | ($cp & 0x3F)),
            default => chr(0xF0 | ($cp >> 18)).chr(0x80 | (($cp >> 12) & 0x3F)).chr(0x80 | (($cp >> 6) & 0x3F)).chr(0x80 | ($cp & 0x3F)),
        };
    }

    private static function utf16(string $raw): string
    {
        $out = '';
        $length = strlen($raw) - (strlen($raw) % 2);
        for ($i = 0; $i < $length; $i += 2) {
            $out .= self::utf8(self::u16($raw, $i));
        }

        return $out;
    }

    private static function u16(string $b, int $o): int
    {
        return $o >= 0 && $o + 2 <= strlen($b) ? (ord($b[$o]) | (ord($b[$o + 1]) << 8)) : 0;
    }

    private static function s16(string $b, int $o): int
    {
        $v = self::u16($b, $o);

        return $v >= 0x8000 ? $v - 0x10000 : $v;
    }

    private static function u32(string $b, int $o): int
    {
        return $o >= 0 && $o + 4 <= strlen($b)
            ? (ord($b[$o]) | (ord($b[$o + 1]) << 8) | (ord($b[$o + 2]) << 16) | (ord($b[$o + 3]) << 24))
            : 0;
    }
}
