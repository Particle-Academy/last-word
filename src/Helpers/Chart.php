<?php

declare(strict_types=1);

namespace LastWord\Helpers;

/**
 * The `chart` block's model: an Apache ECharts `option`, the same field
 * dark-slide's chart element takes, so one prompt teaches charts in both
 * formats and a chart can move from a deck into a report unchanged.
 *
 * This class turns an option into the normalised spec the writer emits, and
 * holds the few facts the writer, the reader and the validator must agree on:
 * the supported series types, the default palette, the default aspect, and how
 * a number is spelled. Mirrored line for line in last-word-js and last-word-py.
 *
 * What it reads, and nothing else:
 *
 *   series[].type        bar | line | pie (default bar); every series one type
 *   series[].name        the series name (legend, workbook header)
 *   series[].data        numbers, {value}, or {name, value} for a pie slice;
 *                        data[i].itemStyle.color colours one point
 *   series[].itemStyle   {color: "#RRGGBB"}, the series' colour
 *   series[].label.show  data labels
 *   series[].smooth      a smoothed line
 *   series[].stack       any non-empty name stacks the bars
 *   xAxis / yAxis        {type, data, name}; a CATEGORY yAxis makes the bars
 *                        horizontal, as it does in ECharts
 *   categories           a shorthand for the category axis data, as dark-slide
 *   title.text, legend, color   the title, a legend (unless show:false), the palette
 *
 * @phpstan-type ChartSeries array{name: string, values: list<float>, color: string, labels: bool, smooth: bool, pointColors: array<int, string>}
 * @phpstan-type ChartSpec array{kind: string, horizontal: bool, stacked: bool, title: string, categories: list<string>, categoryTitle: string, valueTitle: string, legend: bool, series: list<ChartSeries>}
 */
final class Chart
{
    /**
     * The series types a document chart can draw. dark-slide adds `scatter`,
     * which has no category axis and so no place in this workbook layout; it is
     * a validation error here rather than a silent placeholder.
     */
    public const SUPPORTED_TYPES = ['bar', 'line', 'pie'];

    /**
     * Series colours, in turn, when neither the series nor `option.color` names
     * one: Office's own accent order, so an untouched chart looks like a chart
     * Word made. Upper-case hex, no '#'.
     */
    public const DEFAULT_PALETTE = ['4472C4', 'ED7D31', 'A5A5A5', 'FFC000', '5B9BD5', '70AD47', '264478', '9E480E'];

    /** Height as a fraction of width when the block gives no heightPx. */
    public const DEFAULT_ASPECT = 0.6;

    /**
     * Translate an option into the writer's spec; null when nothing drawable can
     * be derived. Never throws.
     *
     * @param  mixed  $option
     * @return ChartSpec|null
     */
    public static function spec(mixed $option): ?array
    {
        if (!is_array($option) || ($option !== [] && array_is_list($option))) {
            return null;
        }
        $raw = $option['series'] ?? null;
        if (is_array($raw) && $raw !== [] && !array_is_list($raw)) {
            $raw = [$raw];
        }
        if (!is_array($raw) || $raw === []) {
            return null;
        }

        $kind = null;
        foreach ($raw as $series) {
            if (!is_array($series)) {
                return null;
            }
            $type = $series['type'] ?? 'bar';
            if (!is_string($type) || !in_array($type, self::SUPPORTED_TYPES, true)) {
                return null;
            }
            $kind ??= $type;
            if ($type !== $kind) {
                return null;
            }
        }
        if ($kind === 'pie' && count($raw) !== 1) {
            return null;
        }

        $xAxis = self::axis($option['xAxis'] ?? null);
        $yAxis = self::axis($option['yAxis'] ?? null);
        $horizontal = $kind === 'bar' && ($yAxis['type'] ?? null) === 'category';
        $categoryAxis = $horizontal ? $yAxis : $xAxis;
        $valueAxis = $horizontal ? $xAxis : $yAxis;

        $categories = [];
        $source = $categoryAxis['data'] ?? null;
        if (!is_array($source)) {
            $source = $option['categories'] ?? null;
        }
        if (is_array($source)) {
            foreach ($source as $label) {
                $text = self::label($label);
                if ($text !== null) {
                    $categories[] = $text;
                }
            }
        }

        $palette = [];
        foreach (is_array($option['color'] ?? null) ? $option['color'] : [] as $color) {
            $hex = self::hex($color);
            if ($hex !== null) {
                $palette[] = $hex;
            }
        }

        if ($kind === 'pie') {
            // A pie's labels are its slice names, never an axis.
            $categories = [];
        }

        $series = [];
        foreach (array_values($raw) as $idx => $one) {
            $data = $one['data'] ?? null;
            if (!is_array($data) || !array_is_list($data)) {
                return null;
            }
            $values = [];
            $pointColors = [];
            foreach ($data as $i => $point) {
                $value = self::pointValue($point);
                if ($value === null) {
                    return null;
                }
                $values[] = $value;
                $color = is_array($point) && is_array($point['itemStyle'] ?? null) ? self::hex($point['itemStyle']['color'] ?? null) : null;
                if ($color !== null) {
                    $pointColors[$i] = $color;
                }
                if ($kind === 'pie' && $idx === 0) {
                    $name = self::label(is_array($point) ? ($point['name'] ?? null) : null);
                    $categories[$i] = $name ?? 'Slice ' . ($i + 1);
                }
            }

            if ($kind === 'pie') {
                // Every slice takes its own colour: a point's, the palette's, the default's.
                foreach (array_keys($values) as $i) {
                    $pointColors[$i] ??= $palette !== [] ? $palette[$i % count($palette)] : self::DEFAULT_PALETTE[$i % count(self::DEFAULT_PALETTE)];
                }
                ksort($pointColors);
            }

            $own = is_array($one['itemStyle'] ?? null) ? self::hex($one['itemStyle']['color'] ?? null) : null;
            $name = self::label($one['name'] ?? null);
            $series[] = [
                'name' => $name !== null && $name !== '' ? $name : 'Series ' . ($idx + 1),
                'values' => $values,
                'color' => $own ?? ($palette !== [] ? $palette[$idx % count($palette)] : self::DEFAULT_PALETTE[$idx % count(self::DEFAULT_PALETTE)]),
                'labels' => is_array($one['label'] ?? null) && ($one['label']['show'] ?? false) === true,
                'smooth' => $kind === 'line' && ($one['smooth'] ?? false) === true,
                'pointColors' => $pointColors,
            ];
        }

        $stacked = false;
        if ($kind === 'bar') {
            foreach ($raw as $one) {
                if (is_string($one['stack'] ?? null) && $one['stack'] !== '') {
                    $stacked = true;
                }
            }
        }

        $legend = $option['legend'] ?? null;

        return [
            'kind' => $kind,
            'horizontal' => $horizontal,
            'stacked' => $stacked,
            'title' => self::title($option['title'] ?? null),
            'categories' => array_values($categories),
            'categoryTitle' => is_string($categoryAxis['name'] ?? null) ? $categoryAxis['name'] : '',
            'valueTitle' => is_string($valueAxis['name'] ?? null) ? $valueAxis['name'] : '',
            'legend' => is_array($legend) && ($legend['show'] ?? true) !== false,
            'series' => $series,
        ];
    }

    /**
     * An axis object: the object itself, or the first of a list of them.
     *
     * @return array<string, mixed>
     */
    private static function axis(mixed $axis): array
    {
        if (!is_array($axis)) {
            return [];
        }
        if ($axis !== [] && array_is_list($axis)) {
            return is_array($axis[0]) ? $axis[0] : [];
        }

        return $axis;
    }

    /** `option.title` as text: `{text}`, a list of those, or a bare string. */
    public static function title(mixed $title): string
    {
        if (is_string($title)) {
            return $title;
        }
        if (is_array($title)) {
            $first = array_is_list($title) && $title !== [] ? $title[0] : $title;
            if (is_array($first) && is_string($first['text'] ?? null)) {
                return $first['text'];
            }
        }

        return '';
    }

    /** A point's number: a bare number, or `{value: number}`. Null when it has none. */
    public static function pointValue(mixed $point): ?float
    {
        if (is_int($point) || is_float($point)) {
            return (float) $point;
        }
        if (is_array($point) && (is_int($point['value'] ?? null) || is_float($point['value'] ?? null))) {
            return (float) $point['value'];
        }

        return null;
    }

    /** `#RRGGBB` as upper-case hex without the '#', or null. */
    public static function hex(mixed $color): ?string
    {
        return is_string($color) && preg_match('/^#([0-9A-Fa-f]{6})$/', $color, $m) === 1 ? strtoupper($m[1]) : null;
    }

    /**
     * A number as the chart caches and the workbook spell it: the SHORTEST
     * decimal that reads back as the same double, and never in exponent form.
     *
     * Not `(string)`, `json_encode` or `sprintf('%F')`: each engine's own
     * float-to-string differs (`1.0E-7`, `1e-7`, `1e-07`), and a fixed number of
     * decimals loses data. The digits come from `%.{p}e` at the smallest precision
     * that round-trips -- correctly rounded in PHP, Node and Python alike -- and
     * the exponent is then written out positionally, so all three engines produce
     * the same bytes.
     */
    public static function num(float $value): string
    {
        if (!is_finite($value)) {
            return '0';
        }
        if ($value == floor($value) && abs($value) < 1.0e15) {
            return (string) (int) $value;
        }
        $s = sprintf('%.16e', $value);
        for ($p = 0; $p <= 16; $p++) {
            $candidate = sprintf('%.' . $p . 'e', $value);
            if ((float) $candidate === $value) {
                $s = $candidate;
                break;
            }
        }

        return self::positional($s);
    }

    /** `-1.2345e-7` → `-0.00000012345`. */
    private static function positional(string $exp): string
    {
        if (preg_match('/^(-?)(\d)(?:\.(\d+))?e([+-]?\d+)$/', $exp, $m) !== 1) {
            return $exp;
        }
        $sign = $m[1];
        $digits = rtrim($m[2] . ($m[3] ?? ''), '0');
        if ($digits === '') {
            return '0';
        }
        $point = (int) $m[4] + 1;
        $len = strlen($digits);
        if ($point <= 0) {
            $out = '0.' . str_repeat('0', -$point) . $digits;
        } elseif ($point >= $len) {
            $out = $digits . str_repeat('0', $point - $len);
        } else {
            $out = substr($digits, 0, $point) . '.' . substr($digits, $point);
        }

        return $sign . $out;
    }

    /**
     * A label -- a category, a series or slice name -- as text: a string as it
     * is, a number spelled by {@see num()}; null for anything else. Spelling a
     * number through each engine's own string conversion is how `2025.0` became
     * "2025" in one engine and "2025.0" in another.
     */
    public static function label(mixed $value): ?string
    {
        return match (true) {
            is_string($value) => $value,
            is_int($value) => (string) $value,
            is_float($value) => self::num($value),
            default => null,
        };
    }

    /**
     * A cached number back as the model holds it: an int when it is whole and in
     * range. Only a plain decimal counts; anything else (hex, a blank) reads as 0,
     * the same in every engine.
     */
    public static function parseNum(string $text): int|float
    {
        $text = trim($text);
        $value = preg_match('/^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?$/', $text) === 1 ? (float) $text : 0.0;
        if ($value == floor($value) && abs($value) < 1.0e15) {
            return (int) $value;
        }

        return $value;
    }

    /** 0 → A, 25 → Z, 26 → AA. */
    public static function column(int $index): string
    {
        $out = '';
        $n = $index + 1;
        while ($n > 0) {
            $rem = ($n - 1) % 26;
            $out = chr(65 + $rem) . $out;
            $n = intdiv($n - 1, 26);
        }

        return $out;
    }
}
