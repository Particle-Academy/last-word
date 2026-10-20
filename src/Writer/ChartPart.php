<?php

declare(strict_types=1);

namespace LastWord\Writer;

use LastWord\Helpers\Chart;
use LastWord\Helpers\Xml;

/**
 * The parts one `chart` block becomes: `word/charts/chartN.xml` (DrawingML
 * chart), its relationships, and the embedded workbook Word opens on "Edit
 * Data". Pure string building -- {@see DocxWriter} owns numbering, packaging and
 * the zip.
 *
 * The workbook is laid out the way Word lays out its own: series names across
 * row 1 from B1, categories down column A from A2, one column of values per
 * series. Every series references those cells (`Sheet1!$B$2:$B$5`) AND carries
 * a cache of them, so a reader that never opens the workbook -- LibreOffice,
 * this package's own reader -- still has the numbers.
 *
 * Element order follows the ECMA-376 sequences (CT_ChartSpace, CT_BarSer,
 * CT_LineSer, CT_CatAx, CT_ValAx...) exactly: Word rejects an out-of-order chart
 * as unreadable content without naming the part. Mirrored byte for byte in
 * last-word-js and last-word-py.
 */
final class ChartPart
{
    private const NS_C = 'http://schemas.openxmlformats.org/drawingml/2006/chart';

    private const NS_A = 'http://schemas.openxmlformats.org/drawingml/2006/main';

    private const NS_R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    private const NS_SHEET = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const CAT_AX = '111111111';

    private const VAL_AX = '222222222';

    /** The gridline colour: present enough to read a value off, quiet enough to ignore. */
    private const GRID = 'D9D9D9';

    /**
     * @param  array<string, mixed>  $spec  {@see Chart::spec()}
     */
    public static function chartXml(array $spec, ?string $numberFormat): string
    {
        $plot = match ($spec['kind']) {
            'line' => self::lineChart($spec, $numberFormat),
            'pie' => self::pieChart($spec, $numberFormat),
            default => self::barChart($spec, $numberFormat),
        };
        $title = $spec['title'] !== ''
            ? '<c:title>' . self::rich($spec['title'], false) . '<c:overlay val="0"/></c:title><c:autoTitleDeleted val="0"/>'
            : '<c:autoTitleDeleted val="1"/>';

        return Xml::declaration()
            . '<c:chartSpace xmlns:c="' . self::NS_C . '" xmlns:a="' . self::NS_A . '" xmlns:r="' . self::NS_R . '">'
            . '<c:date1904 val="0"/>'
            // Absent, Word draws the chart with rounded corners.
            . '<c:roundedCorners val="0"/>'
            . '<c:chart>'
            . $title
            . '<c:plotArea><c:layout/>' . $plot . '</c:plotArea>'
            . ($spec['legend'] ? '<c:legend><c:legendPos val="b"/><c:overlay val="0"/></c:legend>' : '')
            . '<c:plotVisOnly val="1"/>'
            . '<c:dispBlanksAs val="gap"/>'
            . '</c:chart>'
            // No frame: a report's chart sits in the text, not in a box.
            . '<c:spPr><a:noFill/><a:ln><a:noFill/></a:ln></c:spPr>'
            . '<c:externalData r:id="rId1"><c:autoUpdate val="0"/></c:externalData>'
            . '</c:chartSpace>';
    }

    /** The chart part's relationships: its one embedded workbook. */
    public static function relsXml(int $n): string
    {
        return Xml::declaration()
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/package" Target="../embeddings/Microsoft_Excel_Worksheet' . $n . '.xlsx"/>'
            . '</Relationships>';
    }

    /**
     * The embedded workbook's parts, in archive order.
     *
     * @param  array<string, mixed>  $spec
     * @return array<string, string>
     */
    public static function workbookParts(array $spec, ?string $numberFormat): array
    {
        $rows = self::rowCount($spec);
        $styled = $numberFormat !== null ? ' s="1"' : '';

        $sheet = '<row r="1">';
        foreach ($spec['series'] as $i => $series) {
            $sheet .= self::stringCell(Chart::column($i + 1) . '1', $series['name']);
        }
        $sheet .= '</row>';
        for ($r = 0; $r < $rows; $r++) {
            $rowNumber = $r + 2;
            $sheet .= '<row r="' . $rowNumber . '">' . self::stringCell('A' . $rowNumber, self::category($spec, $r));
            foreach ($spec['series'] as $i => $series) {
                if (array_key_exists($r, $series['values'])) {
                    $sheet .= '<c r="' . Chart::column($i + 1) . $rowNumber . '"' . $styled . '><v>' . Chart::num($series['values'][$r]) . '</v></c>';
                }
            }
            $sheet .= '</row>';
        }

        $numFmts = $numberFormat !== null
            ? '<numFmts count="1"><numFmt numFmtId="164" formatCode="' . Xml::attr($numberFormat) . '"/></numFmts>'
            : '';
        $xfs = '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . ($numberFormat !== null ? '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>' : '');

        return [
            '[Content_Types].xml' => Xml::declaration()
                . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                . '<Default Extension="xml" ContentType="application/xml"/>'
                . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
                . '</Types>',
            '_rels/.rels' => Xml::declaration()
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                . '</Relationships>',
            'xl/workbook.xml' => Xml::declaration()
                . '<workbook xmlns="' . self::NS_SHEET . '" xmlns:r="' . self::NS_R . '">'
                . '<sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets>'
                . '</workbook>',
            'xl/_rels/workbook.xml.rels' => Xml::declaration()
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
                . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
                . '</Relationships>',
            'xl/worksheets/sheet1.xml' => Xml::declaration()
                . '<worksheet xmlns="' . self::NS_SHEET . '"><sheetData>' . $sheet . '</sheetData></worksheet>',
            'xl/styles.xml' => Xml::declaration()
                . '<styleSheet xmlns="' . self::NS_SHEET . '">'
                . $numFmts
                . '<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
                . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
                . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
                . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
                . '<cellXfs count="' . ($numberFormat !== null ? 2 : 1) . '">' . $xfs . '</cellXfs>'
                . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
                . '</styleSheet>',
        ];
    }

    // ─── Plots ───────────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $spec */
    private static function barChart(array $spec, ?string $numberFormat): string
    {
        $sers = '';
        foreach ($spec['series'] as $idx => $series) {
            $dPts = '';
            foreach ($series['pointColors'] as $i => $color) {
                $dPts .= '<c:dPt><c:idx val="' . $i . '"/><c:invertIfNegative val="0"/><c:bubble3D val="0"/>'
                    . '<c:spPr>' . self::solid($color) . '</c:spPr></c:dPt>';
            }
            $sers .= '<c:ser><c:idx val="' . $idx . '"/><c:order val="' . $idx . '"/>'
                . self::seriesName($series, $idx)
                . '<c:spPr>' . self::solid($series['color']) . '</c:spPr>'
                . '<c:invertIfNegative val="0"/>'
                . $dPts
                // outEnd is not a legal position on a stacked bar: Word refuses the file.
                . self::dataLabels($series, $spec['stacked'] ? 'ctr' : 'outEnd', $numberFormat)
                . self::categoryRef($spec)
                . self::valueRef($spec, $series, $idx, $numberFormat)
                . '</c:ser>';
        }

        return '<c:barChart>'
            . '<c:barDir val="' . ($spec['horizontal'] ? 'bar' : 'col') . '"/>'
            . '<c:grouping val="' . ($spec['stacked'] ? 'stacked' : 'clustered') . '"/>'
            . '<c:varyColors val="0"/>'
            . $sers
            . '<c:gapWidth val="150"/>'
            . ($spec['stacked'] ? '<c:overlap val="100"/>' : '')
            . '<c:axId val="' . self::CAT_AX . '"/><c:axId val="' . self::VAL_AX . '"/>'
            . '</c:barChart>'
            . self::axes($spec, $numberFormat, self::nonNegative($spec));
    }

    /** @param  array<string, mixed>  $spec */
    private static function lineChart(array $spec, ?string $numberFormat): string
    {
        $sers = '';
        foreach ($spec['series'] as $idx => $series) {
            $dPts = '';
            foreach ($series['pointColors'] as $i => $color) {
                $dPts .= '<c:dPt><c:idx val="' . $i . '"/>' . self::marker($color) . '<c:bubble3D val="0"/></c:dPt>';
            }
            $sers .= '<c:ser><c:idx val="' . $idx . '"/><c:order val="' . $idx . '"/>'
                . self::seriesName($series, $idx)
                . '<c:spPr><a:ln w="28575" cap="rnd">' . self::solid($series['color']) . '<a:round/></a:ln></c:spPr>'
                . self::marker($series['color'])
                . $dPts
                . self::dataLabels($series, 't', $numberFormat)
                . self::categoryRef($spec)
                . self::valueRef($spec, $series, $idx, $numberFormat)
                . '<c:smooth val="' . ($series['smooth'] ? '1' : '0') . '"/>'
                . '</c:ser>';
        }

        return '<c:lineChart>'
            . '<c:grouping val="standard"/>'
            . '<c:varyColors val="0"/>'
            . $sers
            . '<c:marker val="1"/>'
            . '<c:axId val="' . self::CAT_AX . '"/><c:axId val="' . self::VAL_AX . '"/>'
            . '</c:lineChart>'
            . self::axes($spec, $numberFormat, false);
    }

    /** @param  array<string, mixed>  $spec */
    private static function pieChart(array $spec, ?string $numberFormat): string
    {
        $series = $spec['series'][0];
        $dPts = '';
        foreach ($series['pointColors'] as $i => $color) {
            // A white hairline between slices, as Word draws them.
            $dPts .= '<c:dPt><c:idx val="' . $i . '"/><c:bubble3D val="0"/>'
                . '<c:spPr>' . self::solid($color) . '<a:ln w="12700">' . self::solid('FFFFFF') . '</a:ln></c:spPr></c:dPt>';
        }

        return '<c:pieChart>'
            . '<c:varyColors val="1"/>'
            . '<c:ser><c:idx val="0"/><c:order val="0"/>'
            . self::seriesName($series, 0)
            . $dPts
            . self::dataLabels($series, 'bestFit', $numberFormat)
            . self::categoryRef($spec)
            . self::valueRef($spec, $series, 0, $numberFormat)
            . '</c:ser>'
            . '<c:firstSliceAng val="0"/>'
            . '</c:pieChart>';
    }

    /** @param  array<string, mixed>  $spec */
    private static function axes(array $spec, ?string $numberFormat, bool $zeroBase): string
    {
        $catPos = $spec['horizontal'] ? 'l' : 'b';
        $valPos = $spec['horizontal'] ? 'b' : 'l';

        return '<c:catAx>'
            . '<c:axId val="' . self::CAT_AX . '"/>'
            . '<c:scaling><c:orientation val="minMax"/></c:scaling>'
            . '<c:delete val="0"/>'
            . '<c:axPos val="' . $catPos . '"/>'
            . self::axisTitle($spec['categoryTitle'], $catPos)
            . '<c:majorTickMark val="out"/>'
            . '<c:minorTickMark val="none"/>'
            . '<c:tickLblPos val="nextTo"/>'
            . '<c:crossAx val="' . self::VAL_AX . '"/>'
            . '<c:crosses val="autoZero"/>'
            . '<c:auto val="1"/>'
            . '<c:lblAlgn val="ctr"/>'
            . '<c:lblOffset val="100"/>'
            . '<c:noMultiLvlLbl val="0"/>'
            . '</c:catAx>'
            . '<c:valAx>'
            . '<c:axId val="' . self::VAL_AX . '"/>'
            . '<c:scaling><c:orientation val="minMax"/>' . ($zeroBase ? '<c:min val="0"/>' : '') . '</c:scaling>'
            . '<c:delete val="0"/>'
            . '<c:axPos val="' . $valPos . '"/>'
            . '<c:majorGridlines><c:spPr><a:ln w="9525">' . self::solid(self::GRID) . '</a:ln></c:spPr></c:majorGridlines>'
            . self::axisTitle($spec['valueTitle'], $valPos)
            . ($numberFormat !== null ? '<c:numFmt formatCode="' . Xml::attr($numberFormat) . '" sourceLinked="0"/>' : '')
            . '<c:majorTickMark val="none"/>'
            . '<c:minorTickMark val="none"/>'
            . '<c:tickLblPos val="nextTo"/>'
            . '<c:crossAx val="' . self::CAT_AX . '"/>'
            . '<c:crosses val="autoZero"/>'
            . '<c:crossBetween val="between"/>'
            . '</c:valAx>';
    }

    // ─── Fragments ───────────────────────────────────────────────────────

    /** A title's rich text; a title on a vertical axis is turned to read upwards. */
    private static function rich(string $text, bool $vertical): string
    {
        return '<c:tx><c:rich>'
            . ($vertical ? '<a:bodyPr rot="-5400000" vert="horz"/>' : '<a:bodyPr/>')
            . '<a:p><a:r><a:t>' . Xml::text($text) . '</a:t></a:r></a:p>'
            . '</c:rich></c:tx>';
    }

    private static function axisTitle(string $text, string $position): string
    {
        return $text === '' ? '' : '<c:title>' . self::rich($text, $position === 'l') . '<c:overlay val="0"/></c:title>';
    }

    private static function solid(string $hex): string
    {
        return '<a:solidFill><a:srgbClr val="' . $hex . '"/></a:solidFill>';
    }

    private static function marker(string $color): string
    {
        return '<c:marker><c:symbol val="circle"/><c:size val="5"/><c:spPr>' . self::solid($color) . '<a:ln>' . self::solid($color) . '</a:ln></c:spPr></c:marker>';
    }

    /** @param  array<string, mixed>  $series */
    private static function seriesName(array $series, int $idx): string
    {
        return '<c:tx><c:strRef><c:f>Sheet1!$' . Chart::column($idx + 1) . '$1</c:f>'
            . '<c:strCache><c:ptCount val="1"/><c:pt idx="0"><c:v>' . Xml::text($series['name']) . '</c:v></c:pt></c:strCache>'
            . '</c:strRef></c:tx>';
    }

    /** @param  array<string, mixed>  $series */
    private static function dataLabels(array $series, string $position, ?string $numberFormat): string
    {
        if (!$series['labels']) {
            return '';
        }

        return '<c:dLbls>'
            . ($numberFormat !== null ? '<c:numFmt formatCode="' . Xml::attr($numberFormat) . '" sourceLinked="0"/>' : '')
            . '<c:spPr><a:noFill/><a:ln><a:noFill/></a:ln></c:spPr>'
            . '<c:dLblPos val="' . $position . '"/>'
            . '<c:showLegendKey val="0"/><c:showVal val="1"/><c:showCatName val="0"/>'
            . '<c:showSerName val="0"/><c:showPercent val="0"/><c:showBubbleSize val="0"/>'
            . '</c:dLbls>';
    }

    /** @param  array<string, mixed>  $spec */
    private static function categoryRef(array $spec): string
    {
        $rows = self::rowCount($spec);
        $pts = '';
        for ($r = 0; $r < $rows; $r++) {
            $pts .= '<c:pt idx="' . $r . '"><c:v>' . Xml::text(self::category($spec, $r)) . '</c:v></c:pt>';
        }

        return '<c:cat><c:strRef><c:f>Sheet1!$A$2:$A$' . ($rows + 1) . '</c:f>'
            . '<c:strCache><c:ptCount val="' . $rows . '"/>' . $pts . '</c:strCache>'
            . '</c:strRef></c:cat>';
    }

    /**
     * @param  array<string, mixed>  $spec
     * @param  array<string, mixed>  $series
     */
    private static function valueRef(array $spec, array $series, int $idx, ?string $numberFormat): string
    {
        $rows = self::rowCount($spec);
        $col = Chart::column($idx + 1);
        $pts = '';
        foreach ($series['values'] as $i => $value) {
            $pts .= '<c:pt idx="' . $i . '"><c:v>' . Chart::num($value) . '</c:v></c:pt>';
        }

        return '<c:val><c:numRef><c:f>Sheet1!$' . $col . '$2:$' . $col . '$' . ($rows + 1) . '</c:f>'
            . '<c:numCache><c:formatCode>' . Xml::text($numberFormat ?? 'General') . '</c:formatCode>'
            . '<c:ptCount val="' . $rows . '"/>' . $pts . '</c:numCache>'
            . '</c:numRef></c:val>';
    }

    /** Rows of data: the longer of the category list and the longest series. */
    private static function rowCount(array $spec): int
    {
        $rows = count($spec['categories']);
        foreach ($spec['series'] as $series) {
            $rows = max($rows, count($series['values']));
        }

        return $rows;
    }

    /**
     * Row `$r`'s category label. With no categories at all the rows are
     * numbered, so the workbook and the axis still have labels to show.
     */
    private static function category(array $spec, int $r): string
    {
        if ($spec['categories'] === []) {
            return (string) ($r + 1);
        }

        return $spec['categories'][$r] ?? '';
    }

    private static function stringCell(string $ref, string $text): string
    {
        return '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . Xml::text($text) . '</t></is></c>';
    }

    /** Every plotted value is zero or more, so a bar's value axis starts at zero. */
    private static function nonNegative(array $spec): bool
    {
        foreach ($spec['series'] as $series) {
            foreach ($series['values'] as $value) {
                if ($value < 0) {
                    return false;
                }
            }
        }

        return true;
    }
}
