<?php

declare(strict_types=1);

namespace LastWord\Reader;

/**
 * RTF -> the same document shape the other readers return.
 *
 * Text extraction, honestly scoped. RTF carries far more than we take —
 * tables, styles, embedded objects — and this reads paragraphs and bold-led
 * headings. That is stated here rather than discovered, because a reader that
 * silently keeps a fraction is the failure this whole change exists to end.
 *
 * The parse is a small state machine rather than a regex sweep. A regex that
 * strips control words leaves the braces; one that strips braces too eats the
 * text inside `{\fonttbl ...}` groups, which are not content at all — so the
 * output looks clean and carries font names in the middle of a sentence.
 */
final class RtfReader
{
    /** Control words whose entire GROUP is metadata, never body text. */
    private const SKIP_GROUPS = [
        'fonttbl', 'colortbl', 'stylesheet', 'info', 'pict', 'header', 'footer',
        'footnote', 'themedata', 'colorschememapping', 'latentstyles',
        'datastore', 'generator', 'listtable', 'listoverridetable', 'rsidtbl',
    ];

    /** @return array<string,mixed> */
    public function read(string $bytes): array
    {
        $blocks = [];

        foreach ($this->paragraphs($bytes) as $p) {
            $text = trim((string) preg_replace('/\s+/u', ' ', $p['text']));
            if ($text === '') {
                continue;
            }

            $blocks[] = $p['bold']
                ? ['type' => 'heading', 'level' => 1, 'runs' => [['text' => $text]]]
                : ['type' => 'paragraph', 'runs' => [['text' => $text]]];
        }

        return ['blocks' => $blocks];
    }

    /** @return list<array{text: string, bold: bool}> */
    private function paragraphs(string $s): array
    {
        $backslash = chr(92);

        $out = [];
        $text = '';
        $bold = false;
        $depth = 0;
        $skipDepth = null;
        $len = strlen($s);

        $flush = function () use (&$out, &$text, &$bold): void {
            if (trim($text) !== '') {
                $out[] = ['text' => $text, 'bold' => $bold];
            }
            $text = '';
        };

        for ($i = 0; $i < $len; $i++) {
            $c = $s[$i];

            if ($c === '{') {
                $depth++;
                continue;
            }

            if ($c === '}') {
                if ($skipDepth !== null && $depth <= $skipDepth) {
                    $skipDepth = null;
                }
                $depth--;
                continue;
            }

            if ($c !== $backslash) {
                if ($skipDepth === null && $c !== "\r" && $c !== "\n") {
                    $text .= $c;
                }
                continue;
            }

            // From here: a backslash. Either an escaped literal or a control word.
            $next = $i + 1 < $len ? $s[$i + 1] : '';

            if ($next === $backslash || $next === '{' || $next === '}') {
                if ($skipDepth === null) {
                    $text .= $next;
                }
                $i++;
                continue;
            }

            // \'hh — one byte in the document's codepage.
            if ($next === "'") {
                $hex = substr($s, $i + 2, 2);
                if ($skipDepth === null && ctype_xdigit($hex)) {
                    $text .= chr((int) hexdec($hex));
                }
                $i += 3;
                continue;
            }

            if (preg_match('/[a-zA-Z]+/A', $s, $m, 0, $i + 1) !== 1) {
                continue; // a lone backslash before punctuation
            }

            $word = strtolower($m[0]);
            $after = $i + 1 + strlen($m[0]);

            $param = null;
            if (preg_match('/-?[0-9]+/A', $s, $pm, 0, $after) === 1) {
                $param = $pm[0];
                $after += strlen($pm[0]);
            }
            // A single trailing space is a delimiter, not text.
            if ($after < $len && $s[$after] === ' ') {
                $after++;
            }
            $i = $after - 1;

            if (in_array($word, self::SKIP_GROUPS, true)) {
                // Everything to the end of THIS group is metadata.
                //
                // `$depth` is the depth of the group we are already inside, and
                // the closing brace is tested BEFORE the decrement — so this
                // must be `$depth`, not `$depth - 1`. Off by one, the skip is
                // never cleared by its own group's `}` and instead swallows the
                // entire rest of the document: `{\fonttbl ...}` silenced every
                // paragraph after it, and the reader returned no blocks at all
                // while looking like it had parsed fine.
                $skipDepth = $depth;
                continue;
            }

            if ($skipDepth !== null) {
                continue;
            }

            if ($word === 'par' || $word === 'line') {
                $flush();
                continue;
            }

            if ($word === 'pard') {
                // `\pard` resets paragraph formatting. `\par` ends a paragraph
                // and carries run state forward — which is why they are not the
                // same reset, and why bold survives a `\par` but not a `\pard`.
                $flush();
                $bold = false;
                continue;
            }

            if ($word === 'b') {
                $bold = $param !== '0';
                continue;
            }

            if ($word === 'tab') {
                $text .= "\t";
                continue;
            }

            // Every other control word is formatting we deliberately do not take.
        }

        $flush();

        return $out;
    }
}
