<?php

declare(strict_types=1);

namespace LastWord\Layout;

use RuntimeException;

/**
 * Carlito's metrics, and the browser arithmetic last-word-ui's pages come out of.
 *
 * Data: `resources/layout/carlito.json`, two parts.
 *
 * - `styles`: per face, the advance width (font units) of every BMP codepoint
 *   Carlito 1.104 maps, read from the font's own `cmap` / `hmtx` tables, plus
 *   its units per em and vertical metrics. `provenance` names the files by
 *   sha256. Carlito is metric-compatible with Calibri, so these are Calibri's
 *   advances too (all printable ASCII and Latin-1, in all four styles).
 * - `lineBoxes`: the height of one line box in Chromium for a font size and a
 *   unitless line-height, MEASURED, because Blink's half-leading arithmetic
 *   rounds each side separately and no closed form reproduced it exactly.
 *
 * Lengths follow Blink: vertical sizes are LayoutUnits (1/64 px, as ints).
 * A text advance is the font's advance at the font size truncated to 1/100 px
 * (Blink keys its font cache at that precision), rounded to 1/65536 px.
 */
final class Metrics
{
    /** The writer's default line spacing, as last-word-ui draws it: 1.08 x Word's single (1.17). */
    public const SINGLE_LINE = 1.17;

    public const DEFAULT_LINE_MULTIPLE = 259 / 240;

    private static ?self $instance = null;

    /** @var array<string, array<int, int>> face => codepoint => advance (font units) */
    private array $advances = [];

    /** @var array<string, int> */
    private array $notdef = [];

    private int $upem;

    private int $ascent;

    private int $descent;

    /** @var array<string, array<string, int>> multiple => size => units */
    private array $lineBoxes;

    /** @var array<string, mixed> */
    private array $provenance;

    /** @var array<string, float> */
    private array $cache = [];

    private function __construct(string $path)
    {
        $raw = is_file($path) ? file_get_contents($path) : false;
        if ($raw === false) {
            throw new RuntimeException('[last-word] resources/layout/carlito.json is missing from this install.');
        }
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        foreach ($data['styles'] as $face => $style) {
            $map = [];
            foreach ($style['ranges'] as [$first, $widths]) {
                foreach ($widths as $i => $w) {
                    $map[$first + $i] = $w;
                }
            }
            $this->advances[$face] = $map;
            $this->notdef[$face] = (int) $style['notdef'];
        }
        $regular = $data['styles']['regular'];
        $this->upem = (int) $regular['unitsPerEm'];
        // Windows and Blink take the OS/2 win metrics; Carlito's hhea says the same.
        $this->ascent = (int) $regular['win']['ascent'];
        $this->descent = (int) $regular['win']['descent'];
        $this->lineBoxes = $data['lineBoxes']['units'];
        $this->provenance = $data['provenance'];
    }

    public static function load(): self
    {
        return self::$instance ??= new self(dirname(__DIR__, 2) . '/resources/layout/carlito.json');
    }

    /** @return array<string, mixed> font name, version, source, licence, sha256 per file */
    public function provenance(): array
    {
        return $this->provenance;
    }

    /** Points to CSS px (96 dpi). */
    public static function px(float $pt): float
    {
        return $pt * 96 / 72;
    }

    /** A CSS length in points as a Blink LayoutUnit (1/64 px, truncated). */
    public static function lu(float $pt): int
    {
        $v = self::px($pt) * 64;

        return (int) ($v >= 0 ? floor($v + 1e-6) : -floor(-$v + 1e-6));
    }

    /** CSS px as LayoutUnits (truncated). */
    public static function luPx(float $px): int
    {
        return (int) floor($px * 64 + 1e-6);
    }

    /** The px size Blink shapes at: the CSS size truncated to 1/100 px. */
    public static function shapingSize(float $pt): float
    {
        return floor(self::px($pt) * 100 + 1e-6) / 100;
    }

    private static function face(bool $bold, bool $italic): string
    {
        return $bold ? ($italic ? 'boldItalic' : 'bold') : ($italic ? 'italic' : 'regular');
    }

    /** Whether Carlito maps this codepoint (else a browser would draw it in a fallback font). */
    public function covers(int $cp): bool
    {
        return isset($this->advances['regular'][$cp]);
    }

    /** One character's advance in px at `$pt`. */
    public function charWidth(int $cp, float $pt, bool $bold, bool $italic): float
    {
        $face = self::face($bold, $italic);
        $key = $face . ':' . $cp . ':' . $pt;
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }
        // A codepoint Carlito does not map is drawn in some fallback font the
        // machine chooses; half an em is the estimate, and it is not exact.
        $units = $this->advances[$face][$cp] ?? (int) round($this->upem / 2);

        return $this->cache[$key] = round($units * self::shapingSize($pt) / $this->upem * 65536) / 65536;
    }

    /** A monospace character's advance (Consolas: 1126/2048 em), for code. Not exact: Consolas is not bundled. */
    public function monoWidth(float $pt): float
    {
        return round(1126 * self::shapingSize($pt) / 2048 * 65536) / 65536;
    }

    /** The advance width in px of a whole string, in one face, without letter spacing. */
    public function textWidth(string $text, float $pt, bool $bold, bool $italic): float
    {
        $w = 0.0;
        foreach (self::codepoints($text) as $cp) {
            $w += $this->charWidth($cp, $pt, $bold, $italic);
        }

        return $w;
    }

    /**
     * One line box, in LayoutUnits, for text at `$pt` under a unitless CSS
     * line-height `$multiple`. Measured where the table has it; elsewhere
     * the nearest LayoutUnit to size x multiple.
     */
    public function lineBox(float $pt, float $multiple): int
    {
        $m = sprintf('%.6f', $multiple);
        $s = sprintf('%.1f', $pt);
        if (isset($this->lineBoxes[$m][$s])) {
            return (int) $this->lineBoxes[$m][$s];
        }

        return (int) floor(self::px($pt) * $multiple * 64 + 0.5);
    }

    /** The font's ascent at `$pt`, rounded to a whole px as Blink does, in LayoutUnits. */
    public function ascent(float $pt): int
    {
        return (int) round($this->ascent * self::shapingSize($pt) / $this->upem) * 64;
    }

    /** The font's descent at `$pt`, rounded to a whole px, in LayoutUnits. */
    public function descent(float $pt): int
    {
        return (int) round($this->descent * self::shapingSize($pt) / $this->upem) * 64;
    }

    /** @return list<int> */
    public static function codepoints(string $text): array
    {
        if ($text === '') {
            return [];
        }
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false) {
            // Not UTF-8: read it byte by byte rather than refuse a layout.
            return array_values(unpack('C*', $text) ?: []);
        }

        return array_map(self::ord(...), $chars);
    }

    /** The codepoint of one UTF-8 character (no mbstring: it is not a requirement of this package). */
    private static function ord(string $c): int
    {
        $b = array_values(unpack('C*', $c) ?: [0]);

        return match (count($b)) {
            1 => $b[0],
            2 => (($b[0] & 0x1F) << 6) | ($b[1] & 0x3F),
            3 => (($b[0] & 0x0F) << 12) | (($b[1] & 0x3F) << 6) | ($b[2] & 0x3F),
            default => (($b[0] & 0x07) << 18) | (($b[1] & 0x3F) << 12) | (($b[2] & 0x3F) << 6) | ($b[3] & 0x3F),
        };
    }
}
