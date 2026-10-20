<?php

declare(strict_types=1);

namespace LastWord\Reader;

use DOMDocument;
use DOMElement;
use LastWord\Helpers\Chart;

/**
 * A DrawingML chart part (`word/charts/chartN.xml`) back as the ECharts option
 * that writes it -- the inverse of {@see \LastWord\Writer\ChartPart}, and the
 * same shape dark-slide's reader returns for a deck's chart.
 *
 * Read from the series' CACHES, never the embedded workbook: the caches are what
 * Word and LibreOffice draw, and a reader that opened the workbook would answer
 * differently from the picture whenever the two had drifted.
 *
 * The option comes back CANONICAL, so `read(write($doc)) == $doc` for a chart
 * already in that form: a colour is a series' `itemStyle.color` (never the
 * palette it may have come from) and is omitted when it is the default
 * palette's; a name is omitted when it is the default "Series N"; stacking reads
 * back as `stack: "total"`; the legend as `{show: true}`.
 *
 * Bar, line and pie only. Any other chart -- radar, scatter, 3-D, a part that is
 * not XML -- returns null and the reader skips the drawing. Mirrored in
 * last-word-js and last-word-py.
 */
final class ChartReader
{
    public const NS_C = 'http://schemas.openxmlformats.org/drawingml/2006/chart';

    private const KINDS = ['barChart' => 'bar', 'lineChart' => 'line', 'pieChart' => 'pie'];

    /**
     * @return array{option: array<string, mixed>, numberFormat: ?string}|null
     */
    public static function read(string $xml): ?array
    {
        if (stripos($xml, '<!DOCTYPE') !== false) {
            return null;
        }
        $dom = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $ok = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$ok || $dom->documentElement === null) {
            return null;
        }

        $chart = self::child($dom->documentElement, 'chart');
        $plotArea = $chart !== null ? self::child($chart, 'plotArea') : null;
        if ($chart === null || $plotArea === null) {
            return null;
        }

        // The first plot decides; a plot this package does not model ends the read.
        $plot = null;
        foreach (self::elements($plotArea) as $node) {
            if (str_ends_with($node->localName, 'Chart')) {
                $plot = $node;
                break;
            }
        }
        if ($plot === null || !isset(self::KINDS[$plot->localName])) {
            return null;
        }
        $kind = self::KINDS[$plot->localName];
        $horizontal = $kind === 'bar' && self::val(self::child($plot, 'barDir')) === 'bar';
        $stacked = $kind === 'bar' && in_array(self::val(self::child($plot, 'grouping')), ['stacked', 'percentStacked'], true);

        $sers = self::children($plot, 'ser');
        if ($sers === []) {
            return null;
        }
        $categories = self::points($sers[0], 'cat');

        $series = [];
        foreach ($sers as $idx => $ser) {
            $series[] = self::series($ser, $idx, $kind, $stacked, $categories);
        }

        $option = [];
        $title = self::titleText(self::child($chart, 'title'));
        if ($title !== '') {
            $option['title'] = ['text' => $title];
        }
        $legend = self::child($chart, 'legend');
        if ($legend !== null && self::val(self::child($legend, 'delete')) !== '1') {
            $option['legend'] = ['show' => true];
        }
        $valAx = self::child($plotArea, 'valAx');
        if ($kind !== 'pie') {
            $categoryAxis = ['type' => 'category', 'data' => $categories];
            $catTitle = self::titleText(self::child($plotArea, 'catAx') !== null ? self::child(self::child($plotArea, 'catAx'), 'title') : null);
            if ($catTitle !== '') {
                $categoryAxis['name'] = $catTitle;
            }
            $valueAxis = ['type' => 'value'];
            $valTitle = self::titleText($valAx !== null ? self::child($valAx, 'title') : null);
            if ($valTitle !== '') {
                $valueAxis['name'] = $valTitle;
            }
            $option['xAxis'] = $horizontal ? $valueAxis : $categoryAxis;
            $option['yAxis'] = $horizontal ? $categoryAxis : $valueAxis;
        }
        $option['series'] = $series;

        // The number format: the value axis's own, else the cache's when it is
        // not General (a pie has no axis to carry one).
        $numberFormat = null;
        $numFmt = $valAx !== null ? self::child($valAx, 'numFmt') : null;
        if ($numFmt !== null && $numFmt->getAttribute('sourceLinked') !== '1' && $numFmt->getAttribute('formatCode') !== '') {
            $numberFormat = $numFmt->getAttribute('formatCode');
        } else {
            $val = self::child($sers[0], 'val');
            $cache = $val !== null ? self::descendant($val, 'formatCode') : null;
            $code = $cache?->textContent ?? '';
            if ($code !== '' && $code !== 'General') {
                $numberFormat = $code;
            }
        }

        return ['option' => $option, 'numberFormat' => $numberFormat];
    }

    /**
     * @param  list<string>  $categories
     * @return array<string, mixed>
     */
    private static function series(DOMElement $ser, int $idx, string $kind, bool $stacked, array $categories): array
    {
        $default = Chart::DEFAULT_PALETTE[$idx % count(Chart::DEFAULT_PALETTE)];
        $one = ['type' => $kind];

        $tx = self::child($ser, 'tx');
        $nameNode = $tx !== null ? self::descendant($tx, 'v') : null;
        $name = $nameNode?->textContent ?? '';
        if ($name !== '' && $name !== 'Series ' . ($idx + 1)) {
            $one['name'] = $name;
        }
        if ($stacked) {
            $one['stack'] = 'total';
        }
        if ($kind === 'line' && self::val(self::child($ser, 'smooth')) === '1') {
            $one['smooth'] = true;
        }
        $dLbls = self::child($ser, 'dLbls');
        if ($dLbls !== null && self::val(self::child($dLbls, 'showVal')) === '1') {
            $one['label'] = ['show' => true];
        }
        if ($kind !== 'pie') {
            $spPr = self::child($ser, 'spPr');
            $fill = $kind === 'line' ? ($spPr !== null ? self::child($spPr, 'ln') : null) : $spPr;
            $color = self::solidColor($fill);
            if ($color !== null && $color !== $default) {
                $one['itemStyle'] = ['color' => '#' . $color];
            }
        }

        $pointColors = [];
        foreach (self::children($ser, 'dPt') as $dPt) {
            $i = self::val(self::child($dPt, 'idx'));
            $holder = $kind === 'line' ? self::child($dPt, 'marker') : $dPt;
            $color = self::solidColor($holder !== null ? self::child($holder, 'spPr') : null);
            if ($i !== null && ctype_digit($i) && $color !== null) {
                $pointColors[(int) $i] = $color;
            }
        }

        $data = [];
        foreach (self::points($ser, 'val') as $i => $text) {
            $value = Chart::parseNum($text);
            if ($kind === 'pie') {
                $point = ['name' => $categories[$i] ?? '', 'value' => $value];
                $slice = $pointColors[$i] ?? null;
                if ($slice !== null && $slice !== Chart::DEFAULT_PALETTE[$i % count(Chart::DEFAULT_PALETTE)]) {
                    $point['itemStyle'] = ['color' => '#' . $slice];
                }
                $data[] = $point;
            } elseif (isset($pointColors[$i])) {
                $data[] = ['value' => $value, 'itemStyle' => ['color' => '#' . $pointColors[$i]]];
            } else {
                $data[] = $value;
            }
        }
        $one['data'] = $data;

        return $one;
    }

    /**
     * A series' cached points under `$host` (cat or val), in index order; an
     * index with no point is ''.
     *
     * @return list<string>
     */
    private static function points(DOMElement $ser, string $host): array
    {
        $node = self::child($ser, $host);
        if ($node === null) {
            return [];
        }
        $countNode = self::descendant($node, 'ptCount');
        $count = $countNode !== null && ctype_digit($countNode->getAttribute('val')) ? (int) $countNode->getAttribute('val') : 0;
        $count = min($count, 100000);
        $out = array_fill(0, $count, '');
        foreach (self::descendants($node, 'pt') as $pt) {
            $i = $pt->getAttribute('idx');
            if (ctype_digit($i) && (int) $i < $count) {
                $v = self::child($pt, 'v');
                $out[(int) $i] = $v?->textContent ?? '';
            }
        }

        return $out;
    }

    /** The upper-case hex of a `<a:solidFill><a:srgbClr>` directly under `$holder`. */
    private static function solidColor(?DOMElement $holder): ?string
    {
        $solid = $holder !== null ? self::child($holder, 'solidFill') : null;
        $clr = $solid !== null ? self::child($solid, 'srgbClr') : null;
        $hex = strtoupper($clr?->getAttribute('val') ?? '');

        return preg_match('/^[0-9A-F]{6}$/', $hex) === 1 ? $hex : null;
    }

    /** A title's text: every `a:t` under its rich text, joined. */
    private static function titleText(?DOMElement $title): string
    {
        if ($title === null) {
            return '';
        }
        $text = '';
        foreach (self::descendants($title, 't') as $t) {
            $text .= $t->textContent;
        }

        return $text;
    }

    private static function val(?DOMElement $el): ?string
    {
        return $el !== null && $el->hasAttribute('val') ? $el->getAttribute('val') : null;
    }

    /** @return list<DOMElement> */
    private static function elements(DOMElement $parent): array
    {
        $out = [];
        foreach ($parent->childNodes as $node) {
            if ($node instanceof DOMElement) {
                $out[] = $node;
            }
        }

        return $out;
    }

    private static function child(DOMElement $parent, string $localName): ?DOMElement
    {
        foreach (self::elements($parent) as $node) {
            if ($node->localName === $localName) {
                return $node;
            }
        }

        return null;
    }

    /** @return list<DOMElement> */
    private static function children(DOMElement $parent, string $localName): array
    {
        return array_values(array_filter(self::elements($parent), static fn (DOMElement $n): bool => $n->localName === $localName));
    }

    private static function descendant(DOMElement $parent, string $localName): ?DOMElement
    {
        return self::descendants($parent, $localName)[0] ?? null;
    }

    /** @return list<DOMElement> */
    private static function descendants(DOMElement $parent, string $localName): array
    {
        $out = [];
        foreach (self::elements($parent) as $node) {
            if ($node->localName === $localName) {
                $out[] = $node;
            }
            array_push($out, ...self::descendants($node, $localName));
        }

        return $out;
    }
}
