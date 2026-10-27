<?php

declare(strict_types=1);

namespace LastWord\Exchange;

use stdClass;

/**
 * Portable content (`fancyContent: 1`, fancy-conformance
 * `suites/exchange/SPEC.md`): validation, limits, per-cell truncation and the
 * canonical display string. Pinned by `exchange/content` and
 * `exchange/limits`.
 *
 * Portable content arrives from other packages, from agents and from hostile
 * files, so every object is CLOSED (an unknown key is `content_invalid`) and
 * the FIRST failure is reported, in the order SPEC section 7 fixes.
 *
 * Input may be decoded either way PHP decodes JSON: associative arrays or
 * stdClass objects. Only the object decoding can tell `{}` from `[]`; with
 * arrays, an empty array is accepted wherever an object or a list is.
 */
final class Content
{
    public const DEFAULT_LIMITS = [
        'items' => 5000,
        'rows' => 2000,
        'columns' => 64,
        'cells' => 10000,
        'images' => 16,
        'imageBytes' => 5242880,
        'stringBytes' => 4194304,
        'cellBytes' => 32768,
    ];

    /** List nesting deeper than this is refused (content_invalid): a recursion bound. */
    public const MAX_LIST_DEPTH = 32;

    private const ENVELOPE_KEYS = ['fancyContent', 'items', 'images', 'source', 'report'];

    private const RUN_FLAGS = ['bold', 'italic', 'underline', 'strike', 'code'];

    private const CELL_TYPES = ['string', 'number', 'bool', 'date', 'datetime', 'empty', 'error'];

    private const FORMAT_KINDS = ['number', 'currency', 'percent', 'date', 'datetime', 'text'];

    private const ALIGNS = ['left', 'center', 'right'];

    private const MAGIC = ['image/png' => "\x89PNG\r\n\x1A\n", 'image/jpeg' => "\xFF\xD8\xFF"];

    /**
     * The only link a portable run may carry (SPEC 3.3): fine-print's
     * LINK_PATTERN, case-sensitive, printable ASCII, at most 2048 bytes.
     * last-word's own model accepts any link; portable content does not.
     */
    public const LINK_PATTERN = '#^(https?://|mailto:)[\x21-\x7E]+$#D';

    public const MAX_LINK_BYTES = 2048;

    /** @var array<string, int> */
    private array $imageBytes = [];

    /** @var list<array{item: int, rows: int, width: int|float}> */
    private array $tables = [];

    /** @var array<string, true> */
    private array $refs = [];

    /**
     * `{ok: true}` or `{ok: false, code, path}`. Bad options THROW
     * {@see OptionsInvalid}: they are a programming error, not content.
     *
     * @return array{ok: bool, code?: string, path?: string}
     */
    public static function validate(mixed $content, mixed $options = null): array
    {
        $limits = self::limits($options);

        try {
            $v = new self;
            $v->structure($content);
            $v->checkLimits($content, $limits);
        } catch (Invalid $e) {
            return ['ok' => false, 'code' => $e->errorCode, 'path' => $e->pointer];
        }

        return ['ok' => true];
    }

    /**
     * The effective limits from `{limits: {...}}`: defaults, overridden by the
     * host. Throws on an unknown option or limit, or a limit that is not a
     * positive integer.
     *
     * @return array<string, int>
     */
    public static function limits(mixed $options): array
    {
        if ($options === null) {
            return self::DEFAULT_LIMITS;
        }
        if (! self::isObject($options)) {
            throw new OptionsInvalid('options must be an object');
        }
        $limits = self::DEFAULT_LIMITS;
        foreach (self::props($options) as $key => $value) {
            if ($key !== 'limits') {
                throw new OptionsInvalid('unknown option "'.$key.'"');
            }
            if ($value === null) {
                continue;
            }
            if (! self::isObject($value)) {
                throw new OptionsInvalid('limits must be an object');
            }
            foreach (self::props($value) as $name => $n) {
                if (! array_key_exists($name, self::DEFAULT_LIMITS)) {
                    throw new OptionsInvalid('unknown limit "'.$name.'"');
                }
                if (! self::isInteger($n) || $n < 1) {
                    throw new OptionsInvalid('limit "'.$name.'" must be a positive integer');
                }
                $limits[$name] = (int) $n;
            }
        }

        return $limits;
    }

    /**
     * Cut a string to the longest prefix of whole code points that fits in
     * `$maxBytes` UTF-8 bytes (SPEC section 9, `cellBytes`).
     *
     * @return array{text: string, truncated: bool}
     */
    public static function truncateCell(string $text, int $maxBytes = self::DEFAULT_LIMITS['cellBytes']): array
    {
        if (strlen($text) <= $maxBytes) {
            return ['text' => $text, 'truncated' => false];
        }
        $cut = max(0, $maxBytes);
        while ($cut > 0 && (ord($text[$cut]) & 0xC0) === 0x80) {
            $cut--;
        }

        return ['text' => substr($text, 0, $cut), 'truncated' => true];
    }

    /** Whether a link target is one portable content may carry. */
    public static function linkAllowed(mixed $link): bool
    {
        return is_string($link) && strlen($link) <= self::MAX_LINK_BYTES && preg_match(self::LINK_PATTERN, $link) === 1;
    }

    /**
     * A PNG or JPEG data URI as an `images` entry, or null: strict standard
     * base64 whose decoded bytes start with the mime's magic. Anything else
     * (a GIF, an http URL, a path) is not carried.
     *
     * @return array{mime: string, data: string}|null
     */
    public static function imageFromDataUri(mixed $src): ?array
    {
        if (! is_string($src) || preg_match('#^data:image/(png|jpe?g);base64,(.*)$#D', $src, $m) !== 1) {
            return null;
        }
        $mime = $m[1] === 'png' ? 'image/png' : 'image/jpeg';
        $size = self::base64Size($m[2]);
        if ($size === null || $size < strlen(self::MAGIC[$mime])) {
            return null;
        }
        $head = (string) base64_decode(substr($m[2], 0, 12), true);

        return str_starts_with($head, self::MAGIC[$mime]) ? ['mime' => $mime, 'data' => $m[2]] : null;
    }

    /** canonical(v) (SPEC 6.1): the string a typed value displays as with no format. */
    public static function canonical(string $t, mixed $v): string
    {
        return match ($t) {
            'number' => self::formatFloat($v),
            'bool' => $v ? 'TRUE' : 'FALSE',
            'empty' => '',
            default => (string) $v,
        };
    }

    /** `shared/decimal` formatFloat: 14 places, trailing zeros and point removed, no `-0`. */
    public static function formatFloat(int|float $v): string
    {
        $s = number_format((float) $v, 14, '.', '');
        if (str_contains($s, '.')) {
            $s = rtrim(rtrim($s, '0'), '.');
        }

        return $s === '-0' ? '0' : $s;
    }

    /** UTF-16 code-unit order, which is what the spec's "code-unit order" means. */
    public static function compareKeys(string $a, string $b): int
    {
        if (preg_match('/[\x80-\xFF]/', $a.$b) !== 1) {
            return strcmp($a, $b) <=> 0;
        }

        return strcmp(
            (string) mb_convert_encoding($a, 'UTF-16BE', 'UTF-8'),
            (string) mb_convert_encoding($b, 'UTF-16BE', 'UTF-8'),
        ) <=> 0;
    }

    /** JSON Pointer escaping of one reference token (RFC 6901). */
    public static function token(string|int $key): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], (string) $key);
    }

    /** Plain PHP arrays from either JSON decoding. */
    public static function toArray(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $value = get_object_vars($value);
        }
        if (! is_array($value)) {
            return $value;
        }
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = self::toArray($v);
        }

        return $out;
    }

    /**
     * The anchor column of every cell, laid out HTML-style. Only for content
     * that already validated (the grid is known to be a rectangle).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<list<int>>
     */
    public static function anchors(array $rows): array
    {
        $out = [];
        $spans = []; // [lastRow, start, end)
        foreach ($rows as $r => $row) {
            $above = [];
            foreach ($spans as $s) {
                if ($s[0] >= $r) {
                    $above[] = [$s[1], $s[2]];
                }
            }
            usort($above, fn ($a, $b) => $a[0] <=> $b[0]);
            $j = 0;
            $cursor = 0;
            $out[$r] = [];
            foreach ($row['cells'] as $cell) {
                while ($j < count($above) && $above[$j][0] <= $cursor) {
                    $cursor = max($cursor, $above[$j][1]);
                    $j++;
                }
                $cs = (int) ($cell['colSpan'] ?? 1);
                $rs = (int) ($cell['rowSpan'] ?? 1);
                $out[$r][] = $cursor;
                if ($rs > 1) {
                    $spans[] = [$r + $rs - 1, $cursor, $cursor + $cs];
                }
                $cursor += $cs;
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // Structure (SPEC section 7, steps 1-6)

    private function structure(mixed $c): void
    {
        if (! self::isObject($c)) {
            throw new Invalid('content_invalid', '');
        }
        $p = self::props($c);
        $fc = $p['fancyContent'] ?? null;
        if (! ((is_int($fc) && $fc === 1) || (is_float($fc) && $fc === 1.0))) {
            throw new Invalid('content_invalid', '/fancyContent');
        }
        $this->closed($p, self::ENVELOPE_KEYS, '');

        if (array_key_exists('images', $p)) {
            $this->images($p['images']);
        }
        if (! array_key_exists('items', $p) || ! self::isList($p['items'])) {
            throw new Invalid('content_invalid', '/items');
        }
        foreach ($p['items'] as $i => $item) {
            $this->item($item, '/items/'.$i, (int) $i, $p);
        }
        $keys = array_map('strval', array_keys($this->imageBytes));
        usort($keys, self::compareKeys(...));
        foreach ($keys as $key) {
            if (! isset($this->refs[$key])) {
                throw new Invalid('content_invalid', '/images/'.self::token($key));
            }
        }
        if (array_key_exists('source', $p)) {
            $this->source($p['source']);
        }
        if (array_key_exists('report', $p)) {
            $this->report($p['report']);
        }
    }

    private function images(mixed $images): void
    {
        if (! self::isObject($images)) {
            throw new Invalid('content_invalid', '/images');
        }
        $entries = self::props($images);
        $keys = array_keys($entries);
        usort($keys, self::compareKeys(...));
        foreach ($keys as $key) {
            $path = '/images/'.self::token($key);
            if (preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $key) !== 1) {
                throw new Invalid('content_invalid', $path);
            }
            $entry = $entries[$key];
            if (! self::isObject($entry)) {
                throw new Invalid('content_invalid', $path);
            }
            $e = self::props($entry);
            $this->closed($e, ['mime', 'data'], $path);
            $mime = $e['mime'] ?? null;
            if (! is_string($mime) || ! isset(self::MAGIC[$mime])) {
                throw new Invalid('content_invalid', $path.'/mime');
            }
            $data = $e['data'] ?? null;
            $size = is_string($data) ? self::base64Size($data) : null;
            if ($size === null || $size < strlen(self::MAGIC[$mime])) {
                throw new Invalid('content_invalid', $path.'/data');
            }
            // Only the head is decoded: the magic needs nine bytes at most, and
            // the size limit is judged later from the length, before any decode.
            $head = (string) base64_decode(substr($data, 0, 12), true);
            if (! str_starts_with($head, self::MAGIC[$mime])) {
                throw new Invalid('content_invalid', $path.'/data');
            }
            $this->imageBytes[$key] = $size;
        }
    }

    /** Decoded length of strict standard base64, or null when it is not that. */
    private static function base64Size(string $data): ?int
    {
        $len = strlen($data);
        if ($len === 0 || $len % 4 !== 0) {
            return null;
        }
        $pad = $data[$len - 1] === '=' ? ($data[$len - 2] === '=' ? 2 : 1) : 0;
        $body = $len - $pad;
        if (strspn($data, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/', 0, $body) !== $body) {
            return null;
        }

        return intdiv($len, 4) * 3 - $pad;
    }

    /** @param array<string, mixed> $envelope */
    private function item(mixed $item, string $path, int $index, array $envelope): void
    {
        if (! self::isObject($item)) {
            throw new Invalid('content_invalid', $path);
        }
        $p = self::props($item);
        $type = $p['type'] ?? null;
        match ($type) {
            'text' => $this->textItem($p, $path),
            'list' => $this->listItem($p, $path),
            'image' => $this->imageItem($p, $path, $envelope),
            'table' => $this->table($p, $path, $index),
            default => throw new Invalid('content_invalid', $path.'/type'),
        };
    }

    /** @param array<string, mixed> $p */
    private function textItem(array $p, string $path): void
    {
        $role = $p['role'] ?? null;
        if ($role !== 'heading' && $role !== 'paragraph') {
            throw new Invalid('content_invalid', $path.'/role');
        }
        $this->closed($p, ['type', 'role', 'level', 'runs'], $path);
        if ($role === 'heading') {
            $level = $p['level'] ?? null;
            if (! self::isInteger($level) || $level < 1 || $level > 6) {
                throw new Invalid('content_invalid', $path.'/level');
            }
        } elseif (array_key_exists('level', $p)) {
            throw new Invalid('content_invalid', $path.'/level');
        }
        $this->runs($p['runs'] ?? null, $path.'/runs', true);
    }

    /** @param array<string, mixed> $p */
    private function listItem(array $p, string $path): void
    {
        $this->closed($p, ['type', 'ordered', 'items'], $path);
        if (array_key_exists('ordered', $p) && ! is_bool($p['ordered'])) {
            throw new Invalid('content_invalid', $path.'/ordered');
        }
        $items = $p['items'] ?? null;
        if (! self::isList($items) || $items === []) {
            throw new Invalid('content_invalid', $path.'/items');
        }
        $this->listEntries($items, $path.'/items', 1);
    }

    /** @param list<mixed> $entries */
    private function listEntries(array $entries, string $path, int $depth): void
    {
        if ($depth > self::MAX_LIST_DEPTH) {
            throw new Invalid('content_invalid', $path);
        }
        foreach ($entries as $k => $entry) {
            $at = $path.'/'.$k;
            if (! self::isObject($entry)) {
                throw new Invalid('content_invalid', $at);
            }
            $e = self::props($entry);
            $this->closed($e, ['runs', 'children'], $at);
            $this->runs($e['runs'] ?? null, $at.'/runs', true);
            if (array_key_exists('children', $e)) {
                if (! self::isList($e['children'])) {
                    throw new Invalid('content_invalid', $at.'/children');
                }
                $this->listEntries($e['children'], $at.'/children', $depth + 1);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $p
     * @param  array<string, mixed>  $envelope
     */
    private function imageItem(array $p, string $path, array $envelope): void
    {
        $this->closed($p, ['type', 'ref', 'alt', 'widthPx', 'heightPx'], $path);
        $ref = $p['ref'] ?? null;
        if (! is_string($ref) || ! array_key_exists($ref, $this->imageBytes)) {
            throw new Invalid('content_invalid', $path.'/ref');
        }
        $this->refs[$ref] = true;
        if (array_key_exists('alt', $p) && ! is_string($p['alt'])) {
            throw new Invalid('content_invalid', $path.'/alt');
        }
        foreach (['widthPx', 'heightPx'] as $dim) {
            if (array_key_exists($dim, $p) && (! self::isNumber($p[$dim]) || $p[$dim] <= 0)) {
                throw new Invalid('content_invalid', $path.'/'.$dim);
            }
        }
    }

    /** @param array<string, mixed> $p */
    private function table(array $p, string $path, int $index): void
    {
        $this->closed($p, ['type', 'caption', 'headerRows', 'columns', 'rows'], $path);
        if (array_key_exists('caption', $p) && ! is_string($p['caption'])) {
            throw new Invalid('content_invalid', $path.'/caption');
        }
        $rows = $p['rows'] ?? null;
        if (array_key_exists('headerRows', $p)) {
            $h = $p['headerRows'];
            if (! self::isInteger($h) || $h < 0 || (self::isList($rows) && $h > count($rows))) {
                throw new Invalid('content_invalid', $path.'/headerRows');
            }
        }
        if (array_key_exists('columns', $p)) {
            if (! self::isList($p['columns'])) {
                throw new Invalid('content_invalid', $path.'/columns');
            }
            foreach ($p['columns'] as $k => $col) {
                $at = $path.'/columns/'.$k;
                if (! self::isObject($col)) {
                    throw new Invalid('content_invalid', $at);
                }
                $c = self::props($col);
                $this->closed($c, ['width', 'align'], $at);
                if (array_key_exists('width', $c) && (! self::isNumber($c['width']) || $c['width'] <= 0)) {
                    throw new Invalid('content_invalid', $at.'/width');
                }
                if (array_key_exists('align', $c) && ! in_array($c['align'], self::ALIGNS, true)) {
                    throw new Invalid('content_invalid', $at.'/align');
                }
            }
        }
        if (! self::isList($rows) || $rows === []) {
            throw new Invalid('content_invalid', $path.'/rows');
        }
        $cellsByRow = [];
        foreach ($rows as $r => $row) {
            $at = $path.'/rows/'.$r;
            if (! self::isObject($row)) {
                throw new Invalid('content_invalid', $at);
            }
            $rp = self::props($row);
            $this->closed($rp, ['cells'], $at);
            $cells = $rp['cells'] ?? null;
            if (! self::isList($cells) || $cells === []) {
                throw new Invalid('content_invalid', $at.'/cells');
            }
            $spans = [];
            foreach ($cells as $k => $cell) {
                $spans[] = $this->cell($cell, $at.'/cells/'.$k);
            }
            $cellsByRow[] = $spans;
        }
        $width = $this->grid($cellsByRow, $path);
        if (array_key_exists('columns', $p) && count($p['columns']) !== $width) {
            throw new Invalid('content_invalid', $path.'/columns');
        }
        $this->tables[] = ['item' => $index, 'rows' => count($rows), 'width' => $width];
    }

    /**
     * Lay the rows out HTML-style; the grid width, or the first row that does
     * not fit. Work is O(cells log cells) whatever the spans say: covered slots
     * are intervals, never enumerated.
     *
     * @param  list<list<array{0: int|float, 1: int|float}>>  $rows  [colSpan, rowSpan] per cell
     */
    private function grid(array $rows, string $path): int|float
    {
        $last = count($rows) - 1;
        $active = []; // [lastRow, start, end)
        $width = 0;
        foreach ($rows as $r => $cells) {
            $at = $path.'/rows/'.$r;
            $above = [];
            foreach ($active as $s) {
                if ($s[0] >= $r) {
                    $above[] = [$s[1], $s[2]];
                }
            }
            usort($above, fn ($a, $b) => $a[0] <=> $b[0]);
            $own = [];
            $j = 0;
            $cursor = 0;
            foreach ($cells as [$cs, $rs]) {
                while ($j < count($above) && $above[$j][0] <= $cursor) {
                    $cursor = max($cursor, $above[$j][1]);
                    $j++;
                }
                $end = $cursor + $cs;
                if ($j < count($above) && $above[$j][0] < $end) {
                    throw new Invalid('content_invalid', $at); // overlaps a span from above
                }
                if ($r + $rs - 1 > $last) {
                    throw new Invalid('content_invalid', $at); // reaches below the last row
                }
                $own[] = [$cursor, $end];
                if ($rs > 1) {
                    $active[] = [$r + $rs - 1, $cursor, $end];
                }
                $cursor = $end;
            }
            $all = array_merge($above, $own);
            usort($all, fn ($a, $b) => $a[0] <=> $b[0]);
            $reach = 0;
            foreach ($all as [$s, $e]) {
                if ($s != $reach) {
                    throw new Invalid('content_invalid', $at); // a hole: not a rectangle
                }
                $reach = $e;
            }
            if ($r === 0) {
                $width = $reach;
            } elseif ($reach != $width) {
                throw new Invalid('content_invalid', $at);
            }
            $active = array_values(array_filter($active, fn ($s) => $s[0] > $r));
        }

        return $width;
    }

    /** @return array{0: int|float, 1: int|float} the cell's colSpan and rowSpan */
    private function cell(mixed $cell, string $path): array
    {
        if (! self::isObject($cell)) {
            throw new Invalid('content_invalid', $path);
        }
        $p = self::props($cell);
        $t = $p['t'] ?? null;
        if (! in_array($t, self::CELL_TYPES, true)) {
            throw new Invalid('content_invalid', $path.'/t');
        }
        $this->closed($p, ['t', 'v', 'text', 'format', 'formula', 'colSpan', 'rowSpan', 'align', 'runs'], $path);

        $has = array_key_exists('v', $p);
        $v = $p['v'] ?? null;
        $ok = match ($t) {
            'empty' => ! $has,
            'string' => $has && is_string($v),
            'number' => $has && self::isNumber($v),
            'bool' => $has && is_bool($v),
            'date' => $has && is_string($v) && self::isDate($v),
            'datetime' => $has && is_string($v) && self::isDateTime($v),
            'error' => $has && is_string($v) && str_starts_with($v, '#'),
        };
        if (! $ok) {
            throw new Invalid('content_invalid', $path.'/v');
        }
        if (array_key_exists('text', $p) && ! is_string($p['text'])) {
            throw new Invalid('content_invalid', $path.'/text');
        }
        if (array_key_exists('format', $p)) {
            $this->format($p['format'], $path.'/format');
        }
        if (array_key_exists('formula', $p) && (! is_string($p['formula']) || $p['formula'] === '')) {
            throw new Invalid('content_invalid', $path.'/formula');
        }
        foreach (['colSpan', 'rowSpan'] as $span) {
            if (array_key_exists($span, $p) && (! self::isInteger($p[$span]) || $p[$span] < 1)) {
                throw new Invalid('content_invalid', $path.'/'.$span);
            }
        }
        if (array_key_exists('align', $p) && ! in_array($p['align'], self::ALIGNS, true)) {
            throw new Invalid('content_invalid', $path.'/align');
        }
        if (array_key_exists('runs', $p)) {
            $joined = $this->runs($p['runs'], $path.'/runs', false);
            $display = array_key_exists('text', $p) ? $p['text'] : self::canonical($t, $v);
            if ($joined !== $display) {
                throw new Invalid('content_invalid', $path.'/runs');
            }
        }

        return [$p['colSpan'] ?? 1, $p['rowSpan'] ?? 1];
    }

    private function format(mixed $format, string $path): void
    {
        if (! self::isObject($format)) {
            throw new Invalid('content_invalid', $path);
        }
        $f = self::props($format);
        $this->closed($f, ['kind', 'decimals', 'currency'], $path);
        $kind = $f['kind'] ?? null;
        if (! in_array($kind, self::FORMAT_KINDS, true)) {
            throw new Invalid('content_invalid', $path.'/kind');
        }
        if (array_key_exists('decimals', $f) && (! self::isInteger($f['decimals']) || $f['decimals'] < 0 || $f['decimals'] > 10)) {
            throw new Invalid('content_invalid', $path.'/decimals');
        }
        if (array_key_exists('currency', $f)
            && ($kind !== 'currency' || ! is_string($f['currency']) || preg_match('/^[A-Z]{3}$/D', $f['currency']) !== 1)) {
            throw new Invalid('content_invalid', $path.'/currency');
        }
    }

    /** Validate a run list; returns the concatenated text. */
    private function runs(mixed $runs, string $path, bool $required): string
    {
        if (! self::isList($runs)) {
            throw new Invalid('content_invalid', $path);
        }
        $joined = '';
        foreach ($runs as $k => $run) {
            $at = $path.'/'.$k;
            if (! self::isObject($run)) {
                throw new Invalid('content_invalid', $at);
            }
            $p = self::props($run);
            $this->closed($p, ['text', ...self::RUN_FLAGS, 'link'], $at);
            if (! is_string($p['text'] ?? null)) {
                throw new Invalid('content_invalid', $at.'/text');
            }
            foreach (self::RUN_FLAGS as $flag) {
                if (array_key_exists($flag, $p) && ! is_bool($p[$flag])) {
                    throw new Invalid('content_invalid', $at.'/'.$flag);
                }
            }
            if (array_key_exists('link', $p) && ! self::linkAllowed($p['link'])) {
                throw new Invalid('content_invalid', $at.'/link');
            }
            $joined .= $p['text'];
        }

        return $joined;
    }

    private function source(mixed $source): void
    {
        if (! self::isObject($source)) {
            throw new Invalid('content_invalid', '/source');
        }
        $p = self::props($source);
        $this->closed($p, ['package', 'kind', 'version', 'sourceKind', 'selector'], '/source');
        foreach (['package', 'kind', 'version'] as $key) {
            if (array_key_exists($key, $p) && ! is_string($p[$key])) {
                throw new Invalid('content_invalid', '/source/'.$key);
            }
        }
        if (array_key_exists('sourceKind', $p) && ! in_array($p['sourceKind'], ['document', 'model'], true)) {
            throw new Invalid('content_invalid', '/source/sourceKind');
        }
    }

    private function report(mixed $report): void
    {
        if (! self::isObject($report)) {
            throw new Invalid('content_invalid', '/report');
        }
        $p = self::props($report);
        $this->closed($p, ['dropped', 'approximated', 'guard'], '/report');
        foreach (['dropped', 'approximated'] as $list) {
            if (! array_key_exists($list, $p)) {
                continue;
            }
            $at = '/report/'.$list;
            if (! self::isList($p[$list])) {
                throw new Invalid('content_invalid', $at);
            }
            foreach ($p[$list] as $k => $entry) {
                $e = $this->entry($entry, $at.'/'.$k, ['kind', 'count', 'where']);
                if (Report::classifyKind($e['kind'] ?? null) !== $list) {
                    throw new Invalid('content_invalid', $at.'/'.$k.'/kind');
                }
                $this->count($e, $at.'/'.$k);
                if (array_key_exists('where', $e)) {
                    $w = $e['where'];
                    if (! self::isList($w) || count($w) > Report::MAX_WHERE || array_filter($w, fn ($x) => ! is_string($x)) !== []) {
                        throw new Invalid('content_invalid', $at.'/'.$k.'/where');
                    }
                }
            }
        }
        if (array_key_exists('guard', $p)) {
            if (! self::isObject($p['guard'])) {
                throw new Invalid('content_invalid', '/report/guard');
            }
            $g = self::props($p['guard']);
            $this->closed($g, ['findings'], '/report/guard');
            $findings = $g['findings'] ?? [];
            if (! self::isList($findings)) {
                throw new Invalid('content_invalid', '/report/guard/findings');
            }
            foreach ($findings as $k => $f) {
                $at = '/report/guard/findings/'.$k;
                $e = $this->entry($f, $at, ['rule', 'count']);
                if (! is_string($e['rule'] ?? null) || $e['rule'] === '') {
                    throw new Invalid('content_invalid', $at.'/rule');
                }
                $this->count($e, $at);
            }
        }
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    private function entry(mixed $entry, string $path, array $keys): array
    {
        if (! self::isObject($entry)) {
            throw new Invalid('content_invalid', $path);
        }
        $e = self::props($entry);
        $this->closed($e, $keys, $path);

        return $e;
    }

    /** @param array<string, mixed> $e */
    private function count(array $e, string $path): void
    {
        if (! self::isInteger($e['count'] ?? null) || $e['count'] < 1) {
            throw new Invalid('content_invalid', $path.'/count');
        }
    }

    /**
     * The first key not in `$allowed`, in code-unit order, is the failure.
     *
     * @param  array<string, mixed>  $props
     * @param  list<string>  $allowed
     */
    private function closed(array $props, array $allowed, string $path): void
    {
        $unknown = array_values(array_diff(array_map('strval', array_keys($props)), $allowed));
        if ($unknown === []) {
            return;
        }
        usort($unknown, self::compareKeys(...));
        throw new Invalid('content_invalid', $path.'/'.self::token($unknown[0]));
    }

    // ------------------------------------------------------------------
    // Limits (SPEC section 9, step 7 of section 7)

    /** @param array<string, int> $limits */
    private function checkLimits(mixed $content, array $limits): void
    {
        $p = self::props($content);
        if (count($p['items']) > $limits['items']) {
            throw new Invalid('content_too_many_items', '/items');
        }
        foreach ($this->tables as $t) {
            if ($t['rows'] > $limits['rows']) {
                throw new Invalid('content_too_many_rows', '/items/'.$t['item']);
            }
        }
        foreach ($this->tables as $t) {
            if ($t['width'] > $limits['columns']) {
                throw new Invalid('content_too_many_columns', '/items/'.$t['item']);
            }
        }
        $cells = 0;
        foreach ($this->tables as $t) {
            $cells += $t['rows'] * $t['width'];
        }
        if ($cells > $limits['cells']) {
            throw new Invalid('content_too_many_cells', '/items');
        }
        if (count($this->imageBytes) > $limits['images']) {
            throw new Invalid('content_too_many_images', '/images');
        }
        $keys = array_map('strval', array_keys($this->imageBytes));
        usort($keys, self::compareKeys(...));
        foreach ($keys as $key) {
            if ($this->imageBytes[$key] > $limits['imageBytes']) {
                throw new Invalid('image_too_large', '/images/'.self::token($key));
            }
        }
        if (self::stringBytes($p['items']) > $limits['stringBytes']) {
            throw new Invalid('content_too_large', '');
        }
    }

    /** UTF-8 bytes of every string VALUE under a node; keys are not counted. */
    private static function stringBytes(mixed $node): int
    {
        if (is_string($node)) {
            return strlen($node);
        }
        if ($node instanceof stdClass) {
            $node = get_object_vars($node);
        }
        if (! is_array($node)) {
            return 0;
        }
        $n = 0;
        foreach ($node as $v) {
            $n += self::stringBytes($v);
        }

        return $n;
    }

    // ------------------------------------------------------------------
    // JSON helpers, for either decoding

    public static function isObject(mixed $x): bool
    {
        return $x instanceof stdClass || (is_array($x) && ($x === [] || ! array_is_list($x)));
    }

    public static function isList(mixed $x): bool
    {
        return is_array($x) && array_is_list($x);
    }

    /** @return array<string, mixed> */
    public static function props(mixed $x): array
    {
        $out = [];
        foreach ($x instanceof stdClass ? get_object_vars($x) : (array) $x as $k => $v) {
            $out[(string) $k] = $v;
        }

        return $out;
    }

    /** A JSON number with no fractional part (`2.0` is an integer), within the exact-integer range. */
    public static function isInteger(mixed $x): bool
    {
        return is_int($x) || (is_float($x) && is_finite($x) && floor($x) === $x && abs($x) <= 9007199254740992.0);
    }

    private static function isNumber(mixed $x): bool
    {
        return is_int($x) || (is_float($x) && is_finite($x));
    }

    private static function isDate(string $v): bool
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $v, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    private static function isDateTime(string $v): bool
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})Z$/D', $v, $m) === 1
            && checkdate((int) $m[2], (int) $m[3], (int) $m[1])
            && (int) $m[4] < 24 && (int) $m[5] < 60 && (int) $m[6] < 60;
    }
}
