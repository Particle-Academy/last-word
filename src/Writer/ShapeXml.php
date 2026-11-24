<?php

declare(strict_types=1);

namespace LastWord\Writer;

use LastWord\Helpers\Xml;
use LastWord\Schema\Anchor;
use LastWord\Schema\Shapes;

/**
 * The WordprocessingML of one `shape` block: a `w:drawing` holding a
 * `wps:wsp` (the Word 2010 wordprocessingShape namespace) inside `wp:inline`
 * or, with an anchor, `wp:anchor`. The text box's content arrives already
 * rendered; nothing here reads text.
 *
 * Every value written is a number computed from validated model values, an
 * enum value the model allows, or text escaped by {@see Xml}. No VML fallback
 * (`mc:AlternateContent`) is written: Word 2010 and later, and LibreOffice, read
 * `wps:wsp` itself.
 */
final class ShapeXml
{
    public const NS_WPS = 'http://schemas.microsoft.com/office/word/2010/wordprocessingShape';

    private const EMU_PER_PX = 9525;

    /** Word's default text box insets: 0.1 inch left and right, 0.05 inch top and bottom. */
    private const INSET_LR = 91440;

    private const INSET_TB = 45720;

    /** Left and right gap to wrapped text: 0.125 inch, Word's default for a floating object. */
    private const WRAP_GAP = 114300;

    private const DASH = ['dashed' => 'dash', 'dotted' => 'sysDot'];

    /** A text box's inner width in twips, for laying out its tables: the box less the insets. */
    public static function innerTwips(array $shape): int
    {
        $px = is_int($shape['widthPx'] ?? null) || is_float($shape['widthPx'] ?? null) ? (float) $shape['widthPx'] : Shapes::DEFAULT_WIDTH_PX;

        return max(1, (int) round($px * 15) - 2 * (int) (self::INSET_LR / 635));
    }

    /**
     * @param  array<string, mixed>  $shape  a valid shape block
     * @param  string|null  $content  the text box's rendered blocks, or null for a shape without text
     * @param  int  $docPrId  the package-unique drawing id
     * @param  int  $n  the shape's number (its name, and its stacking order when it floats)
     */
    public static function drawing(array $shape, ?string $content, int $docPrId, int $n): string
    {
        $geometry = in_array($shape['geometry'] ?? null, Shapes::GEOMETRIES, true) ? $shape['geometry'] : 'rect';
        $cx = self::emu($shape['widthPx'] ?? Shapes::DEFAULT_WIDTH_PX);
        $cy = self::emu($shape['heightPx'] ?? Shapes::DEFAULT_HEIGHT_PX);
        $alt = is_string($shape['alt'] ?? null) && $shape['alt'] !== '' ? ' descr="' . Xml::attr($shape['alt']) . '"' : '';
        $name = ($content !== null ? 'Text Box ' : 'Shape ') . $n;
        $docPr = '<wp:docPr id="' . $docPrId . '" name="' . $name . '"' . $alt . '/>';

        $flip = (($shape['flipH'] ?? false) === true ? ' flipH="1"' : '') . (($shape['flipV'] ?? false) === true ? ' flipV="1"' : '');
        $fill = Shapes::isHex($shape['fill'] ?? null)
            ? '<a:solidFill><a:srgbClr val="' . strtoupper(substr($shape['fill'], 1)) . '"/></a:solidFill>'
            : '<a:noFill/>';
        $wsp = '<wps:wsp xmlns:wps="' . self::NS_WPS . '">'
            . '<wps:cNvSpPr' . ($content !== null ? ' txBox="1"' : '') . '/>'
            . '<wps:spPr>'
            . '<a:xfrm' . $flip . '><a:off x="0" y="0"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm>'
            . '<a:prstGeom prst="' . $geometry . '"><a:avLst/></a:prstGeom>'
            . $fill
            . self::outline(is_array($shape['outline'] ?? null) ? $shape['outline'] : [])
            . '</wps:spPr>';
        if ($content !== null) {
            $anchorText = match ($shape['verticalAlign'] ?? 'top') {
                'middle' => 'ctr',
                'bottom' => 'b',
                default => 't',
            };
            $wsp .= '<wps:txbx><w:txbxContent>' . $content . '</w:txbxContent></wps:txbx>'
                . '<wps:bodyPr rot="0" vert="horz" wrap="square" lIns="' . self::INSET_LR . '" tIns="' . self::INSET_TB . '" rIns="' . self::INSET_LR . '" bIns="' . self::INSET_TB . '" anchor="' . $anchorText . '" anchorCtr="0"><a:noAutofit/></wps:bodyPr>';
        } else {
            $wsp .= '<wps:bodyPr/>';
        }
        $wsp .= '</wps:wsp>';
        $graphic = '<a:graphic><a:graphicData uri="' . self::NS_WPS . '">' . $wsp . '</a:graphicData></a:graphic>';
        $extent = '<wp:extent cx="' . $cx . '" cy="' . $cy . '"/><wp:effectExtent l="0" t="0" r="0" b="0"/>';

        if (!is_array($shape['anchor'] ?? null)) {
            return '<w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0">'
                . $extent . $docPr . '<wp:cNvGraphicFramePr/>' . $graphic
                . '</wp:inline></w:drawing>';
        }

        $anchor = Anchor::full($shape['anchor']);
        $wrap = $anchor['wrap'];
        $gap = in_array($wrap, ['square', 'tight', 'through'], true) ? self::WRAP_GAP : 0;

        return '<w:drawing><wp:anchor distT="0" distB="0" distL="' . $gap . '" distR="' . $gap . '" simplePos="0"'
            . ' relativeHeight="' . $n . '" behindDoc="' . ($wrap === 'behind' ? '1' : '0') . '" locked="0" layoutInCell="1" allowOverlap="' . ($anchor['allowOverlap'] ? '1' : '0') . '">'
            . '<wp:simplePos x="0" y="0"/>'
            . self::position('H', $anchor['horizontal'])
            . self::position('V', $anchor['vertical'])
            . $extent
            . self::wrap($wrap)
            . $docPr . '<wp:cNvGraphicFramePr/>' . $graphic
            . '</wp:anchor></w:drawing>';
    }

    /** @param  array<string, mixed>  $axis  a full axis ({@see Anchor::full()}) */
    private static function position(string $hv, array $axis): string
    {
        // Validation has already refused anything else; these checks keep the
        // markup closed even for a caller that skipped it.
        [$from, $aligns, $default] = $hv === 'H'
            ? [Anchor::HORIZONTAL_FROM, Anchor::HORIZONTAL_ALIGN, Anchor::DEFAULT_HORIZONTAL['relativeTo']]
            : [Anchor::VERTICAL_FROM, Anchor::VERTICAL_ALIGN, Anchor::DEFAULT_VERTICAL['relativeTo']];
        $relative = in_array($axis['relativeTo'] ?? null, $from, true) ? $axis['relativeTo'] : $default;
        $offset = $axis['offset'] ?? 0;
        $offset = (is_int($offset) || is_float($offset)) && is_finite((float) $offset)
            ? max(-Anchor::MAX_OFFSET, min(Anchor::MAX_OFFSET, (float) $offset)) : 0.0;
        $inner = in_array($axis['align'] ?? null, $aligns, true)
            ? '<wp:align>' . $axis['align'] . '</wp:align>'
            : '<wp:posOffset>' . (int) round($offset * Anchor::EMU_PER_PT) . '</wp:posOffset>';

        return '<wp:position' . $hv . ' relativeFrom="' . $relative . '">' . $inner . '</wp:position' . $hv . '>';
    }

    private static function wrap(string $wrap): string
    {
        // wp:wrapTight and wp:wrapThrough carry a polygon: the drawing's own
        // rectangle, in the 21600-unit square Word writes. Unverified: observed
        // in Word's files, not checked against the standard.
        $polygon = '<wp:wrapPolygon edited="0"><wp:start x="0" y="0"/><wp:lineTo x="0" y="21600"/>'
            . '<wp:lineTo x="21600" y="21600"/><wp:lineTo x="21600" y="0"/><wp:lineTo x="0" y="0"/></wp:wrapPolygon>';

        return match ($wrap) {
            'tight' => '<wp:wrapTight wrapText="bothSides">' . $polygon . '</wp:wrapTight>',
            'through' => '<wp:wrapThrough wrapText="bothSides">' . $polygon . '</wp:wrapThrough>',
            'topAndBottom' => '<wp:wrapTopAndBottom/>',
            'behind', 'inFront' => '<wp:wrapNone/>',
            default => '<wp:wrapSquare wrapText="bothSides"/>',
        };
    }

    /** @param  array<string, mixed>  $outline */
    private static function outline(array $outline): string
    {
        $style = in_array($outline['style'] ?? null, Shapes::OUTLINE_STYLES, true) ? $outline['style'] : 'solid';
        if ($style === 'none') {
            return '<a:ln><a:noFill/></a:ln>';
        }
        $width = $outline['width'] ?? Shapes::DEFAULT_OUTLINE_WIDTH;
        $w = (int) round((float) $width * Anchor::EMU_PER_PT);
        $color = Shapes::isHex($outline['color'] ?? null) ? $outline['color'] : Shapes::DEFAULT_OUTLINE_COLOR;
        $xml = '<a:ln w="' . max(1, $w) . '"><a:solidFill><a:srgbClr val="' . strtoupper(substr($color, 1)) . '"/></a:solidFill>';
        if (isset(self::DASH[$style])) {
            $xml .= '<a:prstDash val="' . self::DASH[$style] . '"/>';
        }
        foreach (['headEnd', 'tailEnd'] as $end) {
            $type = $outline[$end] ?? 'none';
            if (in_array($type, Shapes::LINE_ENDS, true) && $type !== 'none') {
                $xml .= '<a:' . $end . ' type="' . $type . '"/>';
            }
        }

        return $xml . '</a:ln>';
    }

    private static function emu(mixed $px): int
    {
        $px = is_int($px) || is_float($px) ? (float) $px : 0.0;

        return max(0, (int) round(min(Shapes::MAX_EXTENT_PX, $px) * self::EMU_PER_PX));
    }
}
