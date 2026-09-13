<?php

declare(strict_types=1);

namespace LastWord\Reader;

use RuntimeException;

/**
 * RTF -> the same document shape the other readers return.
 *
 * A tokenizer over control words, control symbols, groups and text, with the
 * state RTF actually scopes to groups: character formatting, paragraph
 * properties, the current destination and the Unicode fallback count. A regex
 * sweep cannot do this; one that strips control words leaves the braces, and one
 * that strips braces too eats the text inside `{\fonttbl ...}`, so the output
 * looks clean and carries font names in the middle of a sentence.
 *
 * ## What comes through
 *
 * - paragraphs, and headings: a paragraph whose style is named "heading N" or
 *   that carries an outline level
 * - bold, italic, underline and strike applied directly. RTF repeats a style's
 *   formatting inline, so what a paragraph or character style sets (a heading's
 *   bold) is subtracted again, and a hyperlink's underline is not reported:
 *   LibreOffice writes the link style's underline inline without naming the
 *   style, so it cannot be told from one the author applied
 * - hyperlinks from `HYPERLINK` fields; other fields keep their displayed result
 * - lists with nesting, numbered or bulleted from the list table
 * - tables, with header rows where the file marks one (`\trhdr`)
 * - Unicode (`\uN`, with the `\ucN` fallback characters skipped, surrogate pairs
 *   joined) and `\'hh` bytes in the document's code page (`\ansicpg`, 1252 when
 *   absent; see CodePage for which pages decode)
 * - line breaks, tabs, page breaks, and the title from `{\info{\title}}`
 *
 * ## What does not
 *
 * Images and objects, footnotes, annotations, headers and footers, fonts, sizes
 * and colours, merged cells, Word 6/95-style `\pn` numbering (those items read
 * as paragraphs), a font's own `\fcharset` (text is decoded in the
 * document code page), and header rows in a file that does not mark them with
 * `\trhdr` (LibreOffice does not). Nested tables are flattened into their outer
 * cell.
 *
 * ## Hostile input
 *
 * Group nesting is capped; `\bin` data is skipped by its declared length, capped
 * by what remains; numeric parameters are bounded; every read is in range.
 */
final class RtfReader
{
    private const MAX_DEPTH = 10_000;

    /** Groups whose whole content is metadata or not body text. */
    private const SKIP_DESTINATIONS = [
        'fonttbl', 'colortbl', 'pict', 'header', 'headerl', 'headerr', 'headerf', 'footer', 'footerl',
        'footerr', 'footerf', 'footnote', 'annotation', 'themedata', 'colorschememapping', 'latentstyles',
        'datastore', 'generator', 'rsidtbl', 'listtext', 'pntext', 'pn', 'object', 'shp', 'nonshppict',
        'xe', 'tc', 'txe', 'filetbl', 'revtbl', 'protusertbl', 'docvar', 'userprops', 'mmathPr',
        'nesttableprops', 'fldtype', 'author', 'operator', 'keywords', 'comment', 'doccomm', 'subject',
        'company', 'category', 'manager', 'hlinkbase', 'creatim', 'revtim', 'printim', 'buptim',
    ];

    /** Bytes that end a run of plain text: group and control delimiters, line ends, and 8-bit bytes. */
    private const SPECIAL = "{}\\\r\n"
        ."\x80\x81\x82\x83\x84\x85\x86\x87\x88\x89\x8A\x8B\x8C\x8D\x8E\x8F\x90\x91\x92\x93\x94\x95\x96\x97"
        ."\x98\x99\x9A\x9B\x9C\x9D\x9E\x9F\xA0\xA1\xA2\xA3\xA4\xA5\xA6\xA7\xA8\xA9\xAA\xAB\xAC\xAD\xAE\xAF"
        ."\xB0\xB1\xB2\xB3\xB4\xB5\xB6\xB7\xB8\xB9\xBA\xBB\xBC\xBD\xBE\xBF\xC0\xC1\xC2\xC3\xC4\xC5\xC6\xC7"
        ."\xC8\xC9\xCA\xCB\xCC\xCD\xCE\xCF\xD0\xD1\xD2\xD3\xD4\xD5\xD6\xD7\xD8\xD9\xDA\xDB\xDC\xDD\xDE\xDF"
        ."\xE0\xE1\xE2\xE3\xE4\xE5\xE6\xE7\xE8\xE9\xEA\xEB\xEC\xED\xEE\xEF\xF0\xF1\xF2\xF3\xF4\xF5\xF6\xF7"
        ."\xF8\xF9\xFA\xFB\xFC\xFD\xFE\xFF";

    private const NFC_BULLET = 23;

    private const NFC_NONE = 255;

    private const SYMBOLS = [
        'emdash' => "\u{2014}", 'endash' => "\u{2013}", 'bullet' => "\u{2022}", 'lquote' => "\u{2018}",
        'rquote' => "\u{2019}", 'ldblquote' => "\u{201C}", 'rdblquote' => "\u{201D}", 'emspace' => ' ',
        'enspace' => ' ', 'qmspace' => ' ', 'tab' => "\t", 'line' => "\n",
    ];

    private string $src = '';

    private int $pos = 0;

    /** @var array<string, mixed> the current group's state */
    private array $state;

    /** @var list<array<string, mixed>> enclosing groups' states */
    private array $stack = [];

    /** @var list<array{text: string, flags: array<string, bool>, cs: ?int, link: ?string}> */
    private array $runs = [];

    /** @var list<array<string, mixed>> */
    private array $blocks = [];

    /** @var list<array{ilvl: int, ordered: bool, runs: list<array<string, mixed>>}> */
    private array $listEntries = [];

    /** @var list<array<string, mixed>>|null */
    private ?array $tableRows = null;

    /** @var list<array<string, mixed>> */
    private array $rowCells = [];

    /** @var list<array<string, mixed>> */
    private array $cellBlocks = [];

    private bool $rowHeader = false;

    /** @var list<array{instruction: string}> */
    private array $fields = [];

    /** @var array<string, array{name: string, flags: array<string, bool>, outline: ?int}> keyed s1 (paragraph), c16 (character) */
    private array $styles = [];

    /** @var array<string, mixed>|null the stylesheet entry being read */
    private ?array $styleEntry = null;

    /** @var array<int, list<int>> listid → level nfc codes */
    private array $lists = [];

    /** @var list<int> level nfc codes of the list being read */
    private array $listLevels = [];

    /** @var array<int, int> ls → listid */
    private array $overrides = [];

    private string $title = '';

    private int $codepage = CodePage::DEFAULT;

    /** A `\uN` high surrogate waiting for the low surrogate that completes it. */
    private ?int $highSurrogate = null;

    /** `\'hh` bytes not yet decoded: a double-byte character spans two of them. */
    private string $pendingBytes = '';

    /** @return array<string,mixed> */
    public function read(string $bytes): array
    {
        $this->src = str_starts_with($bytes, "\xEF\xBB\xBF") ? substr($bytes, 3) : $bytes;
        $this->pos = 0;
        $this->state = self::initialState();
        $this->stack = [];
        $this->runs = $this->blocks = $this->listEntries = $this->rowCells = $this->cellBlocks = [];
        $this->fields = $this->styles = $this->lists = $this->listLevels = $this->overrides = [];
        $this->tableRows = $this->styleEntry = null;
        $this->rowHeader = false;
        $this->title = $this->pendingBytes = '';
        $this->codepage = CodePage::DEFAULT;
        $this->highSurrogate = null;

        $this->parse();
        $this->flushBytes();
        if ($this->runs !== []) {
            $this->endParagraph(null);
        }
        $this->flushLists();
        $this->flushTable();

        $doc = [];
        if (trim($this->title) !== '') {
            $doc['title'] = trim($this->title);
        }
        $doc['blocks'] = $this->blocks;

        return $doc;
    }

    /** @return array<string, mixed> */
    private static function initialState(): array
    {
        return [
            'dest' => 'body', 'uc' => 1, 'skip' => 0,
            'b' => false, 'i' => false, 'ul' => false, 'strike' => false,
            'intbl' => false, 'style' => 0, 'ls' => 0, 'ilvl' => 0, 'outline' => null,
            'cs' => null, 'link' => null, 'opensField' => false, 'ignorable' => false, 'first' => true,
        ];
    }

    private function parse(): void
    {
        $length = strlen($this->src);
        while ($this->pos < $length) {
            $c = $this->src[$this->pos];
            $isByte = ord($c) >= 0x80 || ($c === '\\' && ($this->src[$this->pos + 1] ?? '') === "'");
            if (! $isByte) {
                $this->flushBytes();
            }

            if ($c === '{') {
                if (count($this->stack) >= self::MAX_DEPTH) {
                    throw new RuntimeException('RTF nests groups too deeply to read.');
                }
                $this->stack[] = $this->state;
                $this->state['opensField'] = false;
                $this->state['ignorable'] = false;
                $this->state['first'] = true;
                $this->state['skip'] = 0;
                $this->pos++;

                continue;
            }

            if ($c === '}') {
                $this->closeGroup();
                $this->pos++;

                continue;
            }

            if ($c === '\\') {
                $this->controlWord();

                continue;
            }

            if ($c === "\r" || $c === "\n") {
                $this->pos++;

                continue;
            }
            $this->state['first'] = false;
            if ($isByte) {
                $this->byte(ord($c));
                $this->pos++;
            } elseif ($this->state['skip'] > 0) {
                $this->text($c);
                $this->pos++;
            } else {
                // A run of plain text in one step rather than a call per letter.
                $span = strcspn($this->src, self::SPECIAL, $this->pos);
                $this->text(substr($this->src, $this->pos, $span));
                $this->pos += $span;
            }
        }
    }

    private function closeGroup(): void
    {
        if ($this->stack === []) {
            return; // an unbalanced brace ends nothing
        }
        $closing = $this->state;
        $this->state = array_pop($this->stack);

        if ($closing['opensField']) {
            array_pop($this->fields);
        }
        if ($closing['dest'] === 'style' && $this->styleEntry !== null) {
            $this->styles[$this->styleEntry['key']] = [
                'name' => trim(rtrim($this->styleEntry['name'], ';')),
                'flags' => array_filter(['bold' => $this->styleEntry['b'], 'italic' => $this->styleEntry['i'], 'underline' => $this->styleEntry['ul'], 'strike' => $this->styleEntry['strike']]),
                'outline' => $this->styleEntry['outline'],
            ];
            $this->styleEntry = null;
        }
        if ($closing['dest'] === 'listlevel' && $this->state['dest'] === 'list') {
            $this->listLevels[] = $closing['nfc'] ?? self::NFC_BULLET;
        }
    }

    private function controlWord(): void
    {
        $src = $this->src;
        $length = strlen($src);
        $next = $this->pos + 1 < $length ? $src[$this->pos + 1] : '';

        // Control symbols.
        if (! ctype_alpha($next)) {
            $this->pos += 2;
            switch ($next) {
                case '\\':
                case '{':
                case '}':
                    $this->state['first'] = false;
                    $this->text($next);
                    break;
                case "'":
                    $hex = substr($src, $this->pos, 2);
                    $this->pos += 2;
                    if (strlen($hex) === 2 && ctype_xdigit($hex)) {
                        $this->state['first'] = false;
                        $this->byte((int) hexdec($hex));
                    }
                    break;
                case '*':
                    // An ignorable destination: skipped unless the next word is one
                    // this reader knows.
                    $this->state['ignorable'] = true;
                    break;
                case '~':
                    $this->text("\u{00A0}");
                    break;
                case '_':
                    $this->text('-');
                    break;
                case '-':
                    break; // optional hyphen
                case "\r":
                case "\n":
                    $this->word('par', null);
                    break;
                default:
                    break;
            }

            return;
        }

        preg_match('/[a-zA-Z]{1,32}/A', $src, $m, 0, $this->pos + 1);
        $word = $m[0];
        $after = $this->pos + 1 + strlen($word);
        $param = null;
        if (preg_match('/-?[0-9]{1,10}/A', $src, $pm, 0, $after) === 1) {
            $param = (int) $pm[0];
            $after += strlen($pm[0]);
        }
        if ($after < $length && $src[$after] === ' ') {
            $after++;
        }
        $this->pos = $after;

        if ($word === 'bin') {
            $this->pos = min($length, $this->pos + max(0, (int) $param));

            return;
        }

        $this->word($word, $param);
    }

    private function word(string $word, ?int $param): void
    {
        $state = &$this->state;
        $first = $state['first'];
        $state['first'] = false;

        // Destinations, decided by the first word of a group.
        if ($state['ignorable']) {
            $state['ignorable'] = false;
            $known = in_array($word, ['fldinst', 'listtable', 'listoverridetable'], true)
                || ($state['dest'] === 'stylesheet' && in_array($word, ['s', 'cs', 'ds', 'ts'], true));
            if (! $known) {
                $state['dest'] = 'skip';

                return;
            }
        }
        if ($state['dest'] === 'skip') {
            return;
        }
        if ($first || in_array($word, self::SKIP_DESTINATIONS, true)) {
            if (in_array($word, self::SKIP_DESTINATIONS, true)) {
                $state['dest'] = 'skip';

                return;
            }
            $destination = match ($word) {
                'stylesheet' => 'stylesheet',
                'listtable' => 'listtable',
                'listoverridetable' => 'listoverridetable',
                'info' => 'info',
                'fldinst' => 'fldinst',
                'fldrslt' => 'body',
                default => null,
            };
            if ($destination !== null) {
                $state['dest'] = $destination;
                if ($word === 'fldrslt' && $this->fields !== []) {
                    $state['link'] = self::hyperlink($this->fields[count($this->fields) - 1]['instruction']) ?? $state['link'];
                }

                return;
            }
        }

        switch ($state['dest']) {
            case 'stylesheet':
                if ($first && in_array($word, ['s', 'cs', 'ds', 'ts'], true)) {
                    $state['dest'] = 'style';
                    $key = match ($word) {
                        's' => 's'.(int) $param,
                        'cs' => 'c'.(int) $param,
                        default => '',
                    };
                    $this->styleEntry = ['key' => $key, 'name' => '', 'b' => false, 'i' => false, 'ul' => false, 'strike' => false, 'outline' => null];
                }

                return;
            case 'style':
                if ($this->styleEntry !== null) {
                    match (true) {
                        $word === 'b' => $this->styleEntry['b'] = $param !== 0,
                        $word === 'i' => $this->styleEntry['i'] = $param !== 0,
                        $word === 'strike' || $word === 'striked' => $this->styleEntry['strike'] = $param !== 0,
                        self::isUnderlineOn($word) => $this->styleEntry['ul'] = $param !== 0,
                        $word === 'ulnone' => $this->styleEntry['ul'] = false,
                        $word === 'outlinelevel' => $this->styleEntry['outline'] = $param,
                        default => null,
                    };
                }

                return;
            case 'listtable':
                if ($word === 'list') {
                    $state['dest'] = 'list';
                    $this->listLevels = [];
                }

                return;
            case 'list':
                if ($word === 'listlevel') {
                    $state['dest'] = 'listlevel';
                } elseif ($word === 'listid' && $param !== null) {
                    $this->lists[$param] = $this->listLevels;
                }

                return;
            case 'listlevel':
                if ($word === 'levelnfc' || $word === 'levelnfcn') {
                    $state['nfc'] = (int) $param;
                } elseif ($word === 'leveltext' || $word === 'levelnumbers') {
                    $state['dest'] = 'listlevelskip';
                }

                return;
            case 'listoverridetable':
                if ($word === 'listoverride') {
                    $state['dest'] = 'listoverride';
                }

                return;
            case 'listoverride':
                if ($word === 'listid') {
                    $state['overrideList'] = (int) $param;
                } elseif ($word === 'ls' && $param !== null) {
                    $this->overrides[$param] = (int) ($state['overrideList'] ?? 0);
                }

                return;
            case 'info':
                if ($word === 'title') {
                    $state['dest'] = 'title';
                }

                return;
            case 'fldinst':
            case 'title':
            case 'listlevelskip':
                return;
        }

        // Body.
        if ($word === 'u' && $param !== null) {
            $unit = $param < 0 ? $param + 65536 : $param;
            $this->unicode($unit);
            $state['skip'] = $state['uc'];

            return;
        }
        if ($state['skip'] > 0) {
            $state['skip']--;

            return;
        }

        switch ($word) {
            case 'uc':
                $state['uc'] = max(0, min(10, (int) $param));
                break;
            case 'ansicpg':
                $this->codepage = (int) $param;
                break;
            case 'field':
                $this->fields[] = ['instruction' => ''];
                $state['opensField'] = true;
                break;
            case 'par':
            case 'sect':
                $this->endParagraph(null);
                break;
            case 'cell':
            case 'nestcell':
                $this->endParagraph($word === 'cell' ? 'cell' : 'nestcell');
                break;
            case 'row':
                $this->endRow();
                break;
            case 'trowd':
                $this->rowHeader = false;
                break;
            case 'trhdr':
                $this->rowHeader = true;
                break;
            case 'page':
                $this->endParagraph(null);
                $this->flushLists();
                $this->flushTable();
                $this->blocks[] = ['type' => 'pageBreak'];
                break;
            case 'pard':
                $state['intbl'] = false;
                $state['style'] = 0;
                $state['ls'] = 0;
                $state['ilvl'] = 0;
                $state['outline'] = null;
                break;
            case 'plain':
                $state['b'] = $state['i'] = $state['ul'] = $state['strike'] = false;
                $state['cs'] = null;
                break;
            case 'cs':
                $state['cs'] = (int) $param;
                break;
            case 'intbl':
                $state['intbl'] = true;
                break;
            case 's':
                $state['style'] = (int) $param;
                break;
            case 'ls':
                $state['ls'] = (int) $param;
                break;
            case 'ilvl':
                $state['ilvl'] = max(0, min(8, (int) $param));
                break;
            case 'outlinelevel':
                $state['outline'] = $param;
                break;
            case 'b':
                $state['b'] = $param !== 0;
                break;
            case 'i':
                $state['i'] = $param !== 0;
                break;
            case 'strike':
            case 'striked':
                $state['strike'] = $param !== 0;
                break;
            case 'ulnone':
                $state['ul'] = false;
                break;
            default:
                if (self::isUnderlineOn($word)) {
                    $state['ul'] = $param !== 0;
                } elseif (isset(self::SYMBOLS[$word])) {
                    $this->text(self::SYMBOLS[$word]);
                }
        }
    }

    private static function isUnderlineOn(string $word): bool
    {
        // \ul and its styled variants. \ulc sets the underline COLOUR and \ulnone
        // turns underlining off, so neither is one.
        return $word === 'ul' || (str_starts_with($word, 'ul') && $word !== 'ulc' && $word !== 'ulnone'
            && in_array($word, ['uld', 'uldash', 'uldashd', 'uldashdd', 'uldb', 'ulhwave', 'ulldash', 'ulth', 'ulthd', 'ulthdash', 'ulthdashd', 'ulthdashdd', 'ulthldash', 'ululdbwave', 'ulw', 'ulwave'], true));
    }

    private function unicode(int $unit): void
    {
        if ($unit >= 0xDC00 && $unit <= 0xDFFF && $this->highSurrogate !== null) {
            $codePoint = 0x10000 + (($this->highSurrogate - 0xD800) << 10) + ($unit - 0xDC00);
            $this->highSurrogate = null;
            $this->text(CodePage::utf8($codePoint));

            return;
        }
        if ($unit >= 0xD800 && $unit <= 0xDBFF) {
            if ($this->highSurrogate !== null) {
                $this->emit("\u{FFFD}");
            }
            $this->highSurrogate = $unit;

            return;
        }
        $this->text(CodePage::utf8($unit)); // a lone low surrogate decodes as U+FFFD
    }

    private function byte(int $byte): void
    {
        if ($this->state['skip'] > 0) {
            $this->state['skip']--;

            return;
        }
        $this->pendingBytes .= chr($byte);
    }

    private function flushBytes(): void
    {
        if ($this->pendingBytes === '') {
            return;
        }
        $bytes = $this->pendingBytes;
        $this->pendingBytes = '';
        $this->emit(CodePage::decode($bytes, $this->codepage));
    }

    private function text(string $text): void
    {
        if ($this->state['skip'] > 0) {
            $this->state['skip']--;

            return;
        }
        $this->emit($text);
    }

    private function emit(string $text): void
    {
        if ($this->highSurrogate !== null) {
            // Half a pair followed by anything but its other half.
            $this->highSurrogate = null;
            $this->emit("\u{FFFD}");
        }
        $state = $this->state;

        switch ($state['dest']) {
            case 'body':
                $flags = ['bold' => $state['b'], 'italic' => $state['i'], 'underline' => $state['ul'], 'strike' => $state['strike']];
                $last = count($this->runs) - 1;
                if ($last >= 0 && $this->runs[$last]['flags'] === $flags && $this->runs[$last]['cs'] === $state['cs'] && $this->runs[$last]['link'] === $state['link']) {
                    $this->runs[$last]['text'] .= $text;
                    break;
                }
                $this->runs[] = ['text' => $text, 'flags' => $flags, 'cs' => $state['cs'], 'link' => $state['link']];
                break;
            case 'fldinst':
                if ($this->fields !== []) {
                    $this->fields[count($this->fields) - 1]['instruction'] .= $text;
                }
                break;
            case 'style':
                if ($this->styleEntry !== null) {
                    $this->styleEntry['name'] .= $text;
                }
                break;
            case 'title':
                $this->title .= $text;
                break;
        }
    }

    // ─── Blocks ───────────────────────────────────────────────────────────

    /** @param 'cell'|'nestcell'|null $mark */
    private function endParagraph(?string $mark): void
    {
        $state = $this->state;
        $style = $this->styles['s'.$state['style']] ?? ['name' => '', 'flags' => [], 'outline' => null];
        $styles = $this->styles;

        $runs = Structure::mergeRuns(array_map(static function (array $r) use ($style, $styles): array {
            $run = ['text' => $r['text']];
            $characterStyle = $r['cs'] !== null ? ($styles['c'.$r['cs']]['flags'] ?? []) : [];
            foreach ($r['flags'] as $flag => $on) {
                // A flag a style sets is the style's, not the text's; a link's
                // underline is the link's.
                if ($on && empty($style['flags'][$flag]) && empty($characterStyle[$flag])
                    && ! ($flag === 'underline' && $r['link'] !== null)) {
                    $run[$flag] = true;
                }
            }
            if ($r['link'] !== null) {
                $run['link'] = $r['link'];
            }

            return $run;
        }, $this->runs));
        $this->runs = [];
        $hasText = trim(Structure::text($runs)) !== '';

        if ($state['intbl'] || $mark !== null) {
            $this->flushLists();
            $this->tableRows ??= [];
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

        if ($state['ls'] > 0) {
            $this->listEntries[] = ['ilvl' => $state['ilvl'], 'ordered' => $this->listIsOrdered($state['ls']), 'runs' => $runs];

            return;
        }
        $this->flushLists();

        $outline = $state['outline'] ?? $style['outline'];
        $level = null;
        if ($outline !== null && $outline >= 0 && $outline < 9) {
            $level = $outline + 1;
        } elseif (preg_match('/^heading\s*([1-9])$/i', $style['name'], $m) === 1) {
            $level = (int) $m[1];
        }

        $this->blocks[] = $level !== null
            ? ['type' => 'heading', 'level' => min(6, $level), 'runs' => $runs]
            : ['type' => 'paragraph', 'runs' => $runs];
    }

    private function endRow(): void
    {
        $this->tableRows ??= [];
        if ($this->rowCells !== []) {
            $this->tableRows[] = $this->rowHeader ? ['header' => true, 'cells' => $this->rowCells] : ['cells' => $this->rowCells];
        }
        $this->rowCells = [];
        $this->cellBlocks = [];
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
            $this->endRow();
        }
        if ($this->tableRows !== []) {
            $this->blocks[] = ['type' => 'table', 'rows' => $this->tableRows];
        }
        $this->tableRows = null;
    }

    private function listIsOrdered(int $ls): bool
    {
        $levels = $this->lists[$this->overrides[$ls] ?? -1] ?? [];
        $nfc = $levels[0] ?? self::NFC_BULLET;

        return $nfc !== self::NFC_BULLET && $nfc !== self::NFC_NONE;
    }

    /** The target of a `HYPERLINK` field instruction, or null for any other field. */
    private static function hyperlink(string $instruction): ?string
    {
        if (preg_match('/^\s*HYPERLINK\b(.*)$/is', $instruction, $m) !== 1) {
            return null;
        }
        $target = preg_match('/^\s*"([^"]*)"/', $m[1], $url) === 1 ? $url[1] : null;
        if (preg_match('/\\\\l\s+"([^"]*)"/i', $m[1], $anchor) === 1) {
            $target = ($target ?? '').'#'.$anchor[1];
        }

        return $target !== null && $target !== '' ? $target : null;
    }
}
